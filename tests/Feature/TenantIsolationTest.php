<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Module;
use App\Models\Role;
use App\Models\Service;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Перегородка между фирмами: сотрудник видит только данные своего аккаунта.
 *
 * Это первый шаг разделения — фильтр работает внутри приложения. Консоль и
 * ночной воркер пока идут без фильтра намеренно: если включить строгую проверку
 * до того, как научим их выбирать фирму, генерация задач упадёт молча, и
 * клиенты пропустят сдачу отчётов.
 */
class TenantIsolationTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $mine;
    private Tenant $theirs;
    private Employee $myEmployee;
    private Client $myClient;
    private Client $theirClient;

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
        DB::purge('mysql');

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        TenantContext::forget();

        $this->mine   = Tenant::orderBy('id')->first();
        $this->theirs = Tenant::create([
            'name'   => 'Чужая фирма ' . uniqid(),
            'slug'   => 'other-' . uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $role   = Role::firstOrCreate(['name' => Role::ADMIN], ['display_name' => 'Администратор']);
        $module = Module::firstOrCreate(['name' => 'clients'], ['display_name' => 'Клиенты', 'is_active' => true]);

        $this->myEmployee  = $this->makeEmployee($this->mine, $role);
        $this->myClient    = $this->makeClient($this->mine, 'ОсОО Мой клиент');
        $this->theirClient = $this->makeClient($this->theirs, 'ОсОО Чужой клиент');

        $module->id; // модуль нужен только чтобы доступ к разделу был у обоих
    }

    private function makeEmployee(Tenant $tenant, Role $role): Employee
    {
        return TenantContext::for($tenant, fn () => Employee::create([
            'full_name' => 'Сотрудник ' . uniqid(), 'position' => 'Админ',
            'email' => 'iso_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]));
    }

    private function makeClient(Tenant $tenant, string $name): Client
    {
        return TenantContext::for($tenant, fn () => Client::create([
            'name' => $name,
            'inn'  => strtoupper(substr(md5(uniqid()), 0, 12)),
        ]));
    }

    /** Главное: чужой клиент не показывается в списке. */
    public function test_client_list_shows_only_own_clients(): void
    {
        $clients = $this->actingAs($this->myEmployee, 'employee')
            ->get('/clients')
            ->assertOk()
            ->viewData('clients');

        $ids = $clients->pluck('id')->all();

        $this->assertContains($this->myClient->id, $ids, 'Свой клиент пропал из списка');
        $this->assertNotContains($this->theirClient->id, $ids, 'В списке оказался чужой клиент');
    }

    /** Прямая ссылка на чужого клиента не открывается — 404, а не «нет прав». */
    public function test_other_tenant_client_is_not_reachable_by_url(): void
    {
        $this->actingAs($this->myEmployee, 'employee')
            ->get('/clients/' . $this->theirClient->id)
            ->assertNotFound();
    }

    /** Свой открывается — проверка на случай, если фильтр отрезал лишнее. */
    public function test_own_client_still_opens(): void
    {
        $this->actingAs($this->myEmployee, 'employee')
            ->get('/clients/' . $this->myClient->id)
            ->assertOk();
    }

    /** Поиск тоже фильтруется: обойти список через него нельзя. */
    public function test_search_does_not_leak_other_tenants(): void
    {
        $found = $this->actingAs($this->myEmployee, 'employee')
            ->getJson('/clients/search?q=' . urlencode('ОсОО'))
            ->assertOk()
            ->json();

        $ids = array_column($found, 'id');

        $this->assertNotContains($this->theirClient->id, $ids, 'Поиск выдал чужого клиента');
    }

    /** Новая запись получает фирму того, кто её создал. */
    public function test_created_record_belongs_to_the_authors_tenant(): void
    {
        $this->actingAs($this->myEmployee, 'employee')->post('/clients', [
            'name' => 'ОсОО Новый от меня',
            'inn'  => strtoupper(substr(md5(uniqid()), 0, 12)),
        ]);

        $created = Client::acrossTenants()->where('name', 'ОсОО Новый от меня')->first();

        $this->assertNotNull($created);
        $this->assertSame($this->mine->id, (int) $created->tenant_id);
    }

    /** Справочники тоже разделены — иначе чужие БП попали бы в смету. */
    public function test_dictionaries_are_separated(): void
    {
        $theirService = TenantContext::for($this->theirs, fn () => Service::create([
            'name' => 'Чужой БП ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_day' => [5], 'is_active' => true,
        ]));

        TenantContext::set($this->mine);

        $this->assertNull(
            Service::find($theirService->id),
            'Чужой бизнес-процесс виден из другой фирмы',
        );

        TenantContext::forget();
    }

    /** Осознанный выход за пределы фирмы возможен, но только явным вызовом. */
    public function test_across_tenants_is_an_explicit_opt_out(): void
    {
        TenantContext::set($this->mine);

        $this->assertNull(Client::find($this->theirClient->id));
        $this->assertNotNull(Client::acrossTenants()->find($this->theirClient->id));

        TenantContext::forget();
    }

    /**
     * Ночной воркер обязан ходить по фирмам отдельно. Иначе напоминания второй
     * фирмы создались бы с фирмой по умолчанию — достались бы первой, а вторая
     * осталась бы без задач и пропустила сроки. Молча, без ошибки на экране.
     */
    public function test_worker_walks_tenants_one_by_one(): void
    {
        $this->artisan('tasks:generate', ['--date' => '2026-07-31'])
            ->expectsOutputToContain('Аккаунт:')
            ->assertSuccessful();

        $misplaced = DB::table('task_reminders as r')
            ->join('clients as c', 'r.client_id', '=', 'c.id')
            ->whereColumn('r.tenant_id', '!=', 'c.tenant_id')
            ->count();

        $this->assertSame(0, $misplaced, 'Напоминание оказалось в чужой фирме');
    }

    /** Аккаунт-образец воркер обходит стороной: клиентов и задач в нём нет. */
    public function test_worker_skips_the_template_account(): void
    {
        $template = \App\Models\Tenant::template()->first();

        $this->artisan('tasks:generate', ['--date' => '2026-07-31'])->assertSuccessful();

        $this->assertSame(
            0,
            DB::table('task_reminders')->where('tenant_id', $template->id)->count(),
            'Воркер создал напоминания в аккаунте-образце',
        );
    }

    /**
     * Проверка «ИНН уже занят» смотрит таблицу напрямую, мимо фильтра по фирме.
     * Если её не ограничить, вторая фирма получит отказ из-за вашего клиента —
     * которого она не видит и найти не может. Ровно та же ловушка, что была
     * с удалённым клиентом, только между фирмами и вообще необъяснимая.
     */
    public function test_same_inn_can_be_registered_by_another_tenant(): void
    {
        $theirEmployee = $this->makeEmployee($this->theirs, Role::where('name', Role::ADMIN)->first());

        $response = $this->actingAs($theirEmployee, 'employee')->post('/clients', [
            'name' => 'ОсОО Тот же ИНН',
            'inn'  => $this->myClient->inn,
        ]);

        $response->assertSessionDoesntHaveErrors(['inn'], null, 'createClient');

        $this->assertTrue(
            Client::acrossTenants()
                ->where('inn', $this->myClient->inn)
                ->where('tenant_id', $this->theirs->id)
                ->exists(),
            'Вторая фирма не смогла завести клиента с тем же ИНН',
        );
    }

    /** Выгрузка — это тот же список клиентов: чужих в файле быть не может. */
    public function test_export_carries_only_own_clients(): void
    {
        $csv = $this->actingAs($this->myEmployee, 'employee')
            ->get('/clients/export')
            ->streamedContent();

        $this->assertStringContainsString($this->myClient->name, $csv);
        $this->assertStringNotContainsString($this->theirClient->name, $csv);
    }

    /**
     * Импорт видит только свою базу: ИНН, занятый в чужой фирме, для нас
     * свободен. Иначе загрузка спотыкалась бы о клиентов, которых не показывает.
     */
    public function test_import_does_not_see_other_tenants_inns(): void
    {
        $content = "\xEF\xBB\xBF" . "Название;ИНН\n" . "ОсОО Из файла;{$this->theirClient->inn}\n";

        $plan = $this->actingAs($this->myEmployee, 'employee')
            ->post('/clients/import/preview', [
                'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('clients.csv', $content),
            ])
            ->assertOk()
            ->viewData('plan');

        $this->assertSame('create', $plan[0]['verdict']);
    }

    /** Ссылка на чужого клиента в файле не даёт до него дотянуться. */
    public function test_import_cannot_update_a_client_of_another_tenant(): void
    {
        $content = "\xEF\xBB\xBF" . "id;Название;ИНН\n"
            . "{$this->theirClient->id};Захват;{$this->theirClient->inn}\n";

        $plan = $this->actingAs($this->myEmployee, 'employee')
            ->post('/clients/import/preview', [
                'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('clients.csv', $content),
            ])
            ->assertOk()
            ->viewData('plan');

        $this->assertSame('error', $plan[0]['verdict']);
        $this->assertStringContainsString('в базе нет', $plan[0]['reason']);
        $this->assertSame('ОсОО Чужой клиент', $this->theirClient->refresh()->name);
    }

    /** История загрузок тоже своя: чужие импорты в списке не появляются. */
    public function test_import_history_is_separated(): void
    {
        $theirImport = TenantContext::for($this->theirs, fn () => \App\Models\ClientImport::create([
            'employee_id' => null,
            'file_name'   => 'чужой-файл.csv',
        ]));

        $imports = $this->actingAs($this->myEmployee, 'employee')
            ->get('/clients/imports')
            ->assertOk()
            ->assertDontSee('чужой-файл.csv')
            ->viewData('imports');

        $this->assertNotContains($theirImport->id, $imports->pluck('id')->all());
    }

    /** А внутри своей фирмы дубль по-прежнему не пройдёт. */
    public function test_duplicate_inn_inside_own_tenant_is_still_refused(): void
    {
        $this->actingAs($this->myEmployee, 'employee')
            ->post('/clients', ['name' => 'ОсОО Дубль', 'inn' => $this->myClient->inn])
            ->assertSessionHasErrors(['inn'], null, 'createClient');
    }

    /**
     * Терминал и крон: запрос без указания фирмы обязан упасть, а не уйти по
     * всей базе. Тихая работа по всем аккаунтам сразу — это обновлённые цены
     * не той фирмы и удалённые чужие задачи, причём без единой ошибки.
     */
    public function test_strict_mode_refuses_queries_without_a_tenant(): void
    {
        $this->expectException(\App\Exceptions\TenantContextMissing::class);

        TenantContext::forget();
        TenantContext::strictly(fn () => Client::count());
    }

    /** И запись тоже: строка без хозяина не должна появляться молча. */
    public function test_strict_mode_refuses_writes_without_a_tenant(): void
    {
        $this->expectException(\App\Exceptions\TenantContextMissing::class);

        TenantContext::forget();
        TenantContext::strictly(fn () => Client::create([
            'name' => 'ОсОО Без фирмы',
            'inn'  => strtoupper(substr(md5(uniqid()), 0, 12)),
        ]));
    }

    /** С указанной фирмой всё работает как обычно. */
    public function test_strict_mode_allows_work_inside_a_tenant(): void
    {
        $count = TenantContext::strictly(
            fn () => TenantContext::for($this->mine, fn () => Client::count())
        );

        $this->assertGreaterThan(0, $count);
    }

    /** Работа поверх фирм возможна, но только явно — она видна в коде. */
    public function test_explicit_opt_out_survives_strict_mode(): void
    {
        $count = TenantContext::strictly(
            fn () => TenantContext::withoutTenant(fn () => Client::count())
        );

        $this->assertGreaterThan(0, $count);
    }

    /**
     * В вебе строгости нет намеренно: до авторизации фирма ещё неизвестна —
     * вход ищет сотрудника по почте. После авторизации контекст ставит
     * middleware, так что дыры не остаётся.
     */
    public function test_web_stays_permissive_before_login(): void
    {
        TenantContext::forget();

        $this->assertNotNull(Client::find($this->myClient->id));
        $this->assertNotNull(Client::find($this->theirClient->id));
    }

    /**
     * История задач на карточке клиента — отдельные роуты, отдающие JSON, поэтому
     * перегородку проверяем прямо на них: и список, и карточка одной задачи.
     */
    public function test_task_history_does_not_reach_other_tenants(): void
    {
        $this->actingAs($this->myEmployee, 'employee')
            ->getJson('/clients/' . $this->theirClient->id . '/task-history')
            ->assertNotFound();

        $this->actingAs($this->myEmployee, 'employee')
            ->getJson('/clients/' . $this->theirClient->id . '/task-history/planned/1')
            ->assertNotFound();

        $this->actingAs($this->myEmployee, 'employee')
            ->getJson('/clients/' . $this->myClient->id . '/task-history')
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    /**
     * Документ чужой задачи не отдаётся даже по прямой ссылке. У истории задач
     * второй вход в DocumentController (модуль клиентов вместо задачника) —
     * проверяем, что он не стал лазейкой между фирмами.
     */
    public function test_other_tenant_task_document_is_not_served(): void
    {
        $theirDoc = TenantContext::for($this->theirs, function () {
            $service = Service::create([
                'name' => 'Чужой БП ' . uniqid(), 'periodicity' => 'Ежемесячно',
                'start_day' => [5], 'is_active' => true,
            ]);
            $estimate = \App\Models\Estimate::create(['client_id' => $this->theirClient->id, 'total' => 0]);
            $item = $estimate->items()->create([
                'service_id' => $service->id, 'type' => 'recurring', 'name' => $service->name,
                'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 0,
            ]);
            $log = \App\Models\BuhTaskLog::create([
                'employee_id'      => $this->myEmployee->id,
                'client_id'        => $this->theirClient->id,
                'estimate_item_id' => $item->id,
                'year' => 2026, 'month' => 7, 'status' => 'completed',
                'completed_at' => '2026-07-10 12:00:00',
            ]);

            return $log->documents()->create(['path' => 'buh_task_documents/x/чужой.pdf', 'name' => 'чужой.pdf']);
        });

        // Сотрудник другой фирмы: документа для него не существует.
        $this->actingAs($this->myEmployee, 'employee')
            ->get('/documents/task/' . $theirDoc->id)
            ->assertNotFound();
    }
}
