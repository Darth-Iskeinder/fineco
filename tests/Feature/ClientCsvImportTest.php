<?php

namespace Tests\Feature;

use App\Models\ActivityType;
use App\Models\Client;
use App\Models\ClientStatus;
use App\Models\ClientImport;
use App\Models\ClientImportRow;
use App\Models\Employee;
use App\Models\Module;
use App\Models\Role;
use App\Models\Tariff;
use App\Models\TaxSystem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Tests\TestCase;

/**
 * Загрузка клиентов из CSV: сначала проверка без записи, потом подтверждение.
 */
class ClientCsvImportTest extends TestCase
{
    use DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql'];
    }

    protected function setUpTraits()
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'erp_fineco',
            'database.connections.mysql.url' => null,
        ]);
        \Illuminate\Support\Facades\DB::purge('mysql');

        return parent::setUpTraits();
    }

    /** Загружать клиентов вправе только админ и руководитель. */
    private function importer(): Employee
    {
        $role = Role::firstOrCreate(['name' => Role::ADMIN], ['display_name' => 'Администратор']);

        $employee = Employee::create([
            'full_name' => 'Импортёр', 'position' => 'Администратор',
            'email' => uniqid('imp_') . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);

        $employee->modules()->attach(
            Module::firstOrCreate(['name' => 'clients'], ['display_name' => 'Клиенты', 'is_active' => true])->id
        );

        return $employee;
    }

    private function csv(array $lines, string $header = "id;Название;ИНН;Тариф;Ответственный;Активен;Телефон;Заметка"): UploadedFile
    {
        $content = "\xEF\xBB\xBF" . $header . "\n" . implode("\n", $lines) . "\n";

        return UploadedFile::fake()->createWithContent('clients.csv', $content);
    }

    private function inn(): string
    {
        return (string) random_int(100000000000, 999999999999);
    }

    /** @return array{0: \Illuminate\Testing\TestResponse, 1: string} ответ и токен загрузки */
    private function preview(Employee $who, UploadedFile $file): array
    {
        $response = $this->actingAs($who, 'employee')->post('/clients/import/preview', ['file' => $file]);
        $response->assertOk();

        return [$response, $response->viewData('token')];
    }

    public function test_preview_shows_the_plan_and_writes_nothing(): void
    {
        $who  = $this->importer();
        $name = 'Новый из файла ' . uniqid();

        [$response] = $this->preview($who, $this->csv([";{$name};{$this->inn()};;;да;;"]));

        $plan = $response->viewData('plan');

        $this->assertCount(1, $plan);
        $this->assertSame('create', $plan[0]['verdict']);

        // Главное свойство экрана проверки: база ещё не тронута.
        $this->assertDatabaseMissing('clients', ['name' => $name]);
    }

    public function test_apply_creates_clients_and_logs_the_import(): void
    {
        $who    = $this->importer();
        $tariff = Tariff::create(['name' => 'Тариф импорта ' . uniqid(), 'code' => uniqid('t_'), 'price' => 0, 'is_active' => true]);
        $name   = 'Импортированный ' . uniqid();
        $inn    = $this->inn();

        // Колонка «Тариф» в файле есть — импорт её не читает, но и не спотыкается.
        [, $token] = $this->preview($who, $this->csv([
            ";{$name};{$inn};{$tariff->name};{$who->full_name};да;+996700111222;из файла",
        ]));

        $this->actingAs($who, 'employee')
            ->post("/clients/import/{$token}/apply", ['update_existing' => 0])
            ->assertRedirect(route('clients.index'));

        $client = Client::where('inn', $inn)->firstOrFail();

        $this->assertSame($name, $client->name);
        $this->assertNull($client->tariff_id, 'Тариф из файла не должен проставляться');
        $this->assertSame($who->id, $client->responsible_employee_id);
        $this->assertSame('из файла', $client->notes);
        // Плоская колонка телефона ложится в список контактов.
        $this->assertSame('+996700111222', $client->contacts[0]['value']);

        $import = ClientImport::latest('id')->first();
        $this->assertSame(1, $import->created_count);
        $this->assertSame(0, $import->updated_count);
        $this->assertSame(ClientImportRow::ACTION_CREATED, $import->rows()->first()->action);
    }

    public function test_existing_inn_is_skipped_unless_updating_is_chosen(): void
    {
        $who      = $this->importer();
        $existing = Client::create(['name' => 'Уже есть ' . uniqid(), 'inn' => $this->inn()]);

        [$response] = $this->preview($who, $this->csv([";Другое имя;{$existing->inn};;;да;;"]));

        $plan = $response->viewData('plan');

        // «duplicate» — не приговор, а развилка: судьбу решает галочка на экране.
        $this->assertSame('duplicate', $plan[0]['verdict']);
        $this->assertSame('error', \App\Services\ClientImportPlanner::verdict($plan[0], false));
        $this->assertSame('update', \App\Services\ClientImportPlanner::verdict($plan[0], true));
    }

    public function test_updating_existing_overwrites_and_remembers_previous_values(): void
    {
        $who      = $this->importer();
        $existing = Client::create(['name' => 'Старое имя ' . uniqid(), 'inn' => $this->inn(), 'notes' => 'старая заметка']);
        $newName  = 'Новое имя ' . uniqid();

        [, $token] = $this->preview($who, $this->csv([";{$newName};{$existing->inn};;;да;;новая заметка"]));

        $this->actingAs($who, 'employee')->post("/clients/import/{$token}/apply", ['update_existing' => 1]);

        $existing->refresh();
        $this->assertSame($newName, $existing->name);
        $this->assertSame('новая заметка', $existing->notes);

        // Снимок прежних значений — основа будущего отката.
        $row = ClientImport::latest('id')->first()->rows()->first();
        $this->assertSame('старая заметка', $row->before['notes']);
        $this->assertStringStartsWith('Старое имя', $row->before['name']);
    }

    public function test_row_with_id_updates_that_exact_client(): void
    {
        $who     = $this->importer();
        $client  = Client::create(['name' => 'По id ' . uniqid(), 'inn' => $this->inn()]);
        $newName = 'Переименован ' . uniqid();

        [$response, $token] = $this->preview($who, $this->csv(["{$client->id};{$newName};{$client->inn};;;да;;"]));

        $this->assertSame('update', $response->viewData('plan')[0]['verdict']);

        // Обновление по id не требует галочки: человек указал конкретного клиента.
        $this->actingAs($who, 'employee')->post("/clients/import/{$token}/apply", ['update_existing' => 0]);

        $this->assertSame($newName, $client->refresh()->name);
    }

    /**
     * Строгие справочники: незнакомое значение отбивает строку и само в
     * справочник не заводится. Здесь это режим налогообложения — от него зависит,
     * какие БП подтянутся клиенту в смету.
     */
    public function test_unknown_reference_book_value_is_an_error_not_a_new_entry(): void
    {
        $who    = $this->importer();
        $before = TaxSystem::count();

        [$response] = $this->preview($who, $this->csv(
            [";Клиент {$this->inn()};{$this->inn()};Режима-которого-нет;;да;;"],
            'id;Название;ИНН;Режим налогообложения;Ответственный;Активен;Телефон;Заметка',
        ));

        $plan = $response->viewData('plan');

        $this->assertSame('error', $plan[0]['verdict']);
        $this->assertStringContainsString('Режим налогообложения', $plan[0]['reason']);
        $this->assertSame($before, TaxSystem::count(), 'Справочник пополнился сам собой');
    }

    /**
     * Вид деятельности ни на что в системе не влияет, поэтому чужое название не
     * повод терять клиента: строка грузится, поле остаётся пустым, справочник
     * сам собой не пополняется.
     */
    public function test_unknown_activity_type_is_skipped_instead_of_rejecting_the_row(): void
    {
        $who    = $this->importer();
        $before = ActivityType::count();
        $inn    = $this->inn();

        [$response, $token] = $this->preview($who, $this->csv(
            [";Клиент {$inn};{$inn};Вида-которого-нет;;да;;"],
            'id;Название;ИНН;Вид деятельности;Ответственный;Активен;Телефон;Заметка',
        ));

        $plan = $response->viewData('plan');

        $this->assertSame('create', $plan[0]['verdict'], 'Строка отбита из-за вида деятельности: ' . $plan[0]['reason']);
        $this->assertArrayNotHasKey('activity_type_id', $plan[0]['attributes']);
        $this->assertSame($before, ActivityType::count(), 'Справочник пополнился сам собой');

        $this->actingAs($who, 'employee')
            ->post("/clients/import/{$token}/apply", ['update_existing' => 0])
            ->assertRedirect(route('clients.index'));

        $this->assertNull(Client::where('inn', $inn)->value('activity_type_id'));
    }

    /**
     * Известный вид деятельности по-прежнему подставляется: мягкость касается
     * только незнакомых значений.
     */
    public function test_known_activity_type_is_still_filled(): void
    {
        $who      = $this->importer();
        $inn      = $this->inn();
        $activity = ActivityType::create(['name' => 'Торговля ' . uniqid(), 'code' => uniqid('act_'), 'is_active' => true]);

        [$response] = $this->preview($who, $this->csv(
            [";Клиент {$inn};{$inn};{$activity->name};;да;;"],
            'id;Название;ИНН;Вид деятельности;Ответственный;Активен;Телефон;Заметка',
        ));

        $plan = $response->viewData('plan');

        $this->assertSame($activity->id, $plan[0]['attributes']['activity_type_id']);
    }

    /**
     * Тариф импорт не читает: в чужих таблицах в этой колонке лежит ставка налога
     * («0.02»), а не название тарифа, и раньше из-за неё отбивался весь файл.
     */
    public function test_tariff_column_is_ignored_instead_of_rejecting_the_row(): void
    {
        $who = $this->importer();
        $inn = $this->inn();

        [$response] = $this->preview($who, $this->csv([";Клиент {$inn};{$inn};0.02;;да;;"]));

        $plan = $response->viewData('plan');

        $this->assertSame('create', $plan[0]['verdict'], 'Строка отбита из-за тарифа: ' . $plan[0]['reason']);
    }

    /**
     * Статус клиента и признак активности — про одно и то же.
     *
     * Раньше импорт заполнял только флаг: в списке клиент значился активным, а в
     * карточке статус стоял пустым — два экрана про одного клиента говорили разное.
     */
    public function test_status_column_fills_the_client_status(): void
    {
        $who    = $this->importer();
        $inn    = $this->inn();
        $active = ClientStatus::where('closes_service', false)->orderBy('sort_order')->firstOrFail();

        [, $token] = $this->preview($who, $this->csv(
            [";Клиент {$inn};{$inn};{$active->name};да;;"],
            'id;Название;ИНН;Статус клиента;Активен;Телефон;Заметка',
        ));

        $this->actingAs($who, 'employee')->post("/clients/import/{$token}/apply", ['update_existing' => 0]);

        $client = Client::where('inn', $inn)->firstOrFail();

        $this->assertSame($active->id, $client->client_status_id);
        $this->assertTrue((bool) $client->is_active);
    }

    /** Завершающий статус закрывает обслуживание: снимает активность и ставит дату. */
    public function test_closing_status_ends_the_service(): void
    {
        $who     = $this->importer();
        $inn     = $this->inn();
        $closing = ClientStatus::where('closes_service', true)->orderBy('sort_order')->firstOrFail();

        [, $token] = $this->preview($who, $this->csv(
            [";Клиент {$inn};{$inn};{$closing->name};да;;"],
            'id;Название;ИНН;Статус клиента;Активен;Телефон;Заметка',
        ));

        $this->actingAs($who, 'employee')->post("/clients/import/{$token}/apply", ['update_existing' => 0]);

        $client = Client::where('inn', $inn)->firstOrFail();

        $this->assertSame($closing->id, $client->client_status_id);
        $this->assertFalse((bool) $client->is_active, 'Завершённый клиент остался активным');
        $this->assertSame(now()->toDateString(), $client->service_end_date?->toDateString());
    }

    /** Колонки статуса в файле нет, но «Активен: да» — случай однозначный. */
    public function test_active_without_status_column_still_gets_a_status(): void
    {
        $who = $this->importer();
        $inn = $this->inn();

        [, $token] = $this->preview($who, $this->csv([";Клиент {$inn};{$inn};;;да;;"]));

        $this->actingAs($who, 'employee')->post("/clients/import/{$token}/apply", ['update_existing' => 0]);

        $client = Client::where('inn', $inn)->firstOrFail();

        $this->assertNotNull($client->client_status_id, 'Статус в карточке остался пустым');
        $this->assertFalse((bool) $client->clientStatus->closes_service);
    }

    /**
     * «Активен: нет» без статуса не трогаем: приостановлен клиент или завершён —
     * из файла не следует, а разница велика (второе закрывает обслуживание).
     */
    public function test_inactive_without_status_column_is_left_alone(): void
    {
        $who = $this->importer();
        $inn = $this->inn();

        [, $token] = $this->preview($who, $this->csv([";Клиент {$inn};{$inn};;;нет;;"]));

        $this->actingAs($who, 'employee')->post("/clients/import/{$token}/apply", ['update_existing' => 0]);

        $client = Client::where('inn', $inn)->firstOrFail();

        $this->assertNull($client->client_status_id);
        $this->assertFalse((bool) $client->is_active);
    }

    public function test_rows_without_name_or_inn_are_rejected(): void
    {
        $who = $this->importer();

        [$response] = $this->preview($who, $this->csv([
            ";;{$this->inn()};;;да;;",
            ";Без ИНН;;;;да;;",
        ]));

        $plan = $response->viewData('plan');

        $this->assertSame('error', $plan[0]['verdict']);
        $this->assertStringContainsString('название', $plan[0]['reason']);
        $this->assertSame('error', $plan[1]['verdict']);
        $this->assertStringContainsString('ИНН', $plan[1]['reason']);
    }

    public function test_same_inn_twice_in_one_file_takes_only_the_first_row(): void
    {
        $who = $this->importer();
        $inn = $this->inn();

        [$response] = $this->preview($who, $this->csv([
            ";Первый {$inn};{$inn};;;да;;",
            ";Второй {$inn};{$inn};;;да;;",
        ]));

        $plan = $response->viewData('plan');

        $this->assertSame('create', $plan[0]['verdict']);
        $this->assertSame('error', $plan[1]['verdict']);
        $this->assertStringContainsString('строке 2', $plan[1]['reason']);
    }

    public function test_file_from_a_foreign_excel_is_still_read(): void
    {
        $who  = $this->importer();
        $name = 'Через запятую ' . uniqid();

        // Другой разделитель, другой регистр заголовков, лишняя колонка и
        // человеческая дата — файл всё равно должен читаться.
        [$response] = $this->preview($who, $this->csv(
            ["{$name},{$this->inn()},31.12.2026,что-то лишнее"],
            'НАЗВАНИЕ,инн,Дата начала обслуживания,Комментарий менеджера',
        ));

        $plan = $response->viewData('plan');

        $this->assertSame('create', $plan[0]['verdict']);
        $this->assertSame('2026-12-31', $plan[0]['attributes']['service_start_date']);
    }

    public function test_file_without_required_columns_is_refused(): void
    {
        $who = $this->importer();

        $this->actingAs($who, 'employee')
            ->post('/clients/import/preview', ['file' => $this->csv(['Иванов;директор'], 'ФИО;Должность')])
            ->assertSessionHasErrors('file');
    }

    public function test_skipped_rows_can_be_downloaded_with_reasons(): void
    {
        $who = $this->importer();

        [, $token] = $this->preview($who, $this->csv([
            ";;{$this->inn()};;;да;;",
            ";Хороший {$this->inn()};{$this->inn()};;;да;;",
        ]));

        $csv = $this->actingAs($who, 'employee')->get("/clients/import/{$token}/errors")->streamedContent();

        $this->assertStringContainsString('Причина', $csv);
        $this->assertStringContainsString('название', $csv);
        // В файл ошибок попадают только пропущенные строки.
        $this->assertStringNotContainsString('Хороший', $csv);
    }

    public function test_history_shows_who_loaded_the_file_and_when(): void
    {
        $who  = $this->importer();
        $name = 'Из истории ' . uniqid();

        [, $token] = $this->preview($who, $this->csv([";{$name};{$this->inn()};;;да;;"]));
        $this->actingAs($who, 'employee')->post("/clients/import/{$token}/apply", ['update_existing' => 0]);

        $this->actingAs($who, 'employee')
            ->get('/clients/imports')
            ->assertOk()
            ->assertSee($who->full_name)
            ->assertSee('clients.csv')
            ->assertSee(now()->format('d.m.Y'));
    }

    public function test_import_details_list_the_clients_it_touched(): void
    {
        $who  = $this->importer();
        $name = 'Затронутый ' . uniqid();

        [, $token] = $this->preview($who, $this->csv([";{$name};{$this->inn()};;;да;;"]));
        $this->actingAs($who, 'employee')->post("/clients/import/{$token}/apply", ['update_existing' => 0]);

        $import = ClientImport::latest('id')->first();

        $this->actingAs($who, 'employee')
            ->get("/clients/imports/{$import->id}")
            ->assertOk()
            ->assertSee($name)
            ->assertSee('создан');
    }

    public function test_history_needs_the_clients_module(): void
    {
        $role = Role::firstOrCreate(['name' => Role::ACCOUNTANT], ['display_name' => 'Бухгалтер']);

        $outsider = Employee::create([
            'full_name' => 'Без доступа', 'position' => 'Курьер',
            'email' => uniqid('out_') . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);

        $this->actingAs($outsider, 'employee')->get('/clients/imports')->assertForbidden();
    }

    public function test_token_from_another_session_is_not_accepted(): void
    {
        $who = $this->importer();
        $name = 'Чужой ' . uniqid();

        [, $token] = $this->preview($who, $this->csv([";{$name};{$this->inn()};;;да;;"]));

        // Токен живёт в сессии загрузившего. Подобрать ссылку и записать чужой
        // файл в свою фирму нельзя — у другой сессии его просто нет.
        $this->flushSession();

        $this->actingAs($this->importer(), 'employee')
            ->post("/clients/import/{$token}/apply", ['update_existing' => 0])
            ->assertNotFound();

        $this->assertDatabaseMissing('clients', ['name' => $name]);
    }
}
