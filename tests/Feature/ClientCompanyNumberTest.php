<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Module;
use App\Models\Role;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Номер компании: свой номер клиента, который бухфирма ведёт руками.
 *
 * Пришёл на место колонки с id в списке клиентов. Id раскрывал, сколько всего
 * компаний в системе, и ничего не говорил фирме: она зовёт клиентов своими
 * номерами. Отсюда требования, которые проверяем: номер уникален внутри фирмы,
 * по нему ищут и по нему сортируют, а у старых клиентов он пустой.
 */
class ClientCompanyNumberTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $admin;

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

        $role = Role::firstOrCreate(['name' => Role::ADMIN], ['display_name' => 'Администратор']);

        $this->admin = Employee::create([
            'full_name' => 'Тест Админ', 'position' => 'Администратор',
            'email' => uniqid('num_') . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);

        $this->admin->modules()->attach(
            Module::firstOrCreate(['name' => 'clients'], ['display_name' => 'Клиенты', 'is_active' => true])->id
        );
    }

    private function client(string $name = 'ОсОО Тест', array $attributes = []): Client
    {
        return Client::create(array_merge([
            'name' => $name . ' ' . uniqid(),
            'inn'  => (string) random_int(100000000000, 999999999999),
        ], $attributes));
    }

    /** Номер свободен — клиент заводится с ним. */
    public function test_client_is_created_with_a_company_number(): void
    {
        $number = random_int(100000, 999999);

        $this->actingAs($this->admin, 'employee')
            ->post('/clients', [
                'name' => 'ОсОО Номерная ' . uniqid(),
                'inn'  => (string) random_int(100000000000, 999999999999),
                'company_number' => $number,
            ])
            ->assertRedirect();

        $this->assertSame($number, Client::where('company_number', $number)->first()?->company_number);
    }

    /** Пустое поле — это «номера нет», а не ноль. */
    public function test_empty_company_number_stays_empty(): void
    {
        $this->actingAs($this->admin, 'employee')
            ->post('/clients', [
                'name' => $name = 'ОсОО Без номера ' . uniqid(),
                'inn'  => (string) random_int(100000000000, 999999999999),
                'company_number' => '',
            ])
            ->assertRedirect();

        $this->assertNull(Client::where('name', $name)->first()?->company_number);
    }

    /** Два одинаковых номера в одной фирме сделали бы нумерацию бессмысленной. */
    public function test_company_number_is_taken_only_once(): void
    {
        $number = random_int(100000, 999999);
        $this->client('ОсОО Первая', ['company_number' => $number]);

        $this->actingAs($this->admin, 'employee')
            ->post('/clients', [
                'name' => 'ОсОО Вторая ' . uniqid(),
                'inn'  => (string) random_int(100000000000, 999999999999),
                'company_number' => $number,
            ])
            ->assertSessionHasErrors('company_number', null, 'createClient');
    }

    /**
     * У каждой бухфирмы своя нумерация: номер 7 у соседей не мешает завести свой.
     *
     * Проверяет и уникальный индекс в базе: будь он на всю таблицу, а не на пару
     * «фирма + номер», запрос упал бы на вставке.
     */
    public function test_same_number_in_another_firm_does_not_block(): void
    {
        $number = random_int(100000, 999999);

        $tenant = Tenant::create([
            'name' => 'Чужая фирма ' . uniqid(),
            'slug' => 'other-' . uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $theirs = TenantContext::for($tenant, fn () => Client::create([
            'name' => 'ОсОО Соседи ' . uniqid(),
            'inn' => (string) random_int(100000000000, 999999999999),
            'company_number' => $number,
        ]));

        $this->actingAs($this->admin, 'employee')
            ->post('/clients', [
                'name' => $name = 'ОсОО Наша ' . uniqid(),
                'inn'  => (string) random_int(100000000000, 999999999999),
                'company_number' => $number,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $mine = Client::where('name', $name)->first();

        $this->assertSame($number, $mine?->company_number);
        $this->assertNotSame($theirs->tenant_id, $mine->tenant_id);
    }

    /** Свой номер при сохранении клиента занятым не считается. */
    public function test_client_keeps_its_own_number_on_save(): void
    {
        $number = random_int(100000, 999999);
        $client = $this->client('ОсОО Своя', ['company_number' => $number]);

        $this->actingAs($this->admin, 'employee')
            ->putJson('/clients/' . $client->id, [
                'name' => $client->name,
                'inn'  => $client->inn,
                'company_number' => $number,
            ])
            ->assertOk()
            ->assertJsonPath('client.company_number', $number);
    }

    /** Ноль и минус не номера: нумерация начинается с единицы. */
    public function test_zero_and_negative_numbers_are_rejected(): void
    {
        foreach ([0, -5] as $wrong) {
            $this->actingAs($this->admin, 'employee')
                ->post('/clients', [
                    'name' => 'ОсОО Нулевая ' . uniqid(),
                    'inn'  => (string) random_int(100000000000, 999999999999),
                    'company_number' => $wrong,
                ])
                ->assertSessionHasErrors('company_number', null, 'createClient');
        }
    }

    /** Номер правится и из карточки клиента. */
    public function test_company_number_is_editable_from_the_client_card(): void
    {
        $client = $this->client('ОсОО Карточка');
        $number = random_int(100000, 999999);

        $this->actingAs($this->admin, 'employee')
            ->patchJson('/clients/' . $client->id, [
                'section' => 'basic',
                'name'    => $client->name,
                'inn'     => $client->inn,
                'company_number' => $number,
            ])
            ->assertOk();

        $this->assertSame($number, $client->fresh()->company_number);
    }

    /** По номеру ищут так же, как по ИНН: набрал номер — попал в клиента. */
    public function test_search_finds_a_client_by_its_company_number(): void
    {
        $number = random_int(100000, 999999);
        $client = $this->client('ОсОО Искомая', ['company_number' => $number]);

        $found = Client::search((string) $number)->pluck('name')->all();

        $this->assertContains($client->name, $found);
    }

    /** Кусок номера — не номер: «12» не должно тащить 120 и 512. */
    public function test_search_by_company_number_is_exact(): void
    {
        $client = $this->client('ОсОО Длинный номер', ['company_number' => 123456]);

        $this->assertNotContains($client->name, Client::search('1234')->pluck('name')->all());
    }

    /** Длинный ИНН в поиске не должен доезжать до числовой колонки. */
    public function test_long_digits_do_not_break_the_search(): void
    {
        $client = $this->client('ОсОО Длинный ИНН', ['inn' => '12345678901234']);

        $this->assertContains($client->name, Client::search('12345678901234')->pluck('name')->all());
    }

    /** Строка списка отдаёт номер, ему есть что показать в колонке. */
    public function test_company_number_reaches_the_list(): void
    {
        $number = random_int(100000, 999999);
        $client = $this->client('ОсОО В списке', ['company_number' => $number]);

        $rows = $this->actingAs($this->admin, 'employee')
            ->get('/clients')
            ->assertOk()
            ->viewData('clientRows');

        $row = collect($rows)->firstWhere('id', $client->id);

        $this->assertSame($number, $row['company_number']);
    }

    /**
     * Занятый номер должен вернуться понятной фразой, а не молчанием.
     *
     * Правка из списка и правка карточки ходят разными запросами, и оба фронта
     * показывают ровно то, что пришло в `errors`. Пустой ответ выглядел бы как
     * «нажал сохранить, ничего не произошло».
     */
    public function test_duplicate_number_answers_with_a_readable_error(): void
    {
        $number = random_int(100000, 999999);
        $this->client('ОсОО Занявшая номер', ['company_number' => $number]);
        $target = $this->client('ОсОО Опоздавшая');

        $fromList = $this->actingAs($this->admin, 'employee')->putJson('/clients/' . $target->id, [
            'name' => $target->name, 'inn' => $target->inn, 'company_number' => $number,
        ]);

        $fromCard = $this->actingAs($this->admin, 'employee')->patchJson('/clients/' . $target->id, [
            'section' => 'basic', 'name' => $target->name, 'inn' => $target->inn, 'company_number' => $number,
        ]);

        foreach ([$fromList, $fromCard] as $response) {
            $response->assertStatus(422)
                ->assertJsonPath('errors.company_number.0', 'Этот номер компании уже занят другим клиентом');
        }

        $this->assertNull($target->fresh()->company_number);
    }

    /**
     * Отказ на карточке виден на странице, а не в alert().
     *
     * Браузер прячет alert после галочки «не показывать больше диалоги», и тогда
     * отказ сервера выглядит как «сохранение молча не работает» — с этого и
     * начался разбор.
     */
    public function test_card_shows_save_errors_on_the_page(): void
    {
        $card = file_get_contents(resource_path('views/clients/show.blade.php'));

        $this->assertStringContainsString('showSaveError(section', $card);
        $this->assertStringNotContainsString('alert(data.message', $card);
    }

    /** Колонки с id в списке больше нет: она раскрывала размер базы. */
    public function test_list_does_not_show_the_internal_id(): void
    {
        $html = $this->actingAs($this->admin, 'employee')->get('/clients')->assertOk()->getContent();

        $this->assertStringNotContainsString('x-text="client.id"', $html);
        $this->assertStringContainsString('Номер компании', $html);
    }
}
