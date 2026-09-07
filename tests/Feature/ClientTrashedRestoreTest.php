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
 * Удалённые клиенты и их возврат.
 *
 * Удаление клиента мягкое, и удалённый продолжает держать свой ИНН и номер компании.
 * Пока его не вернёшь, того же клиента заново не завести, а из интерфейса он не виден
 * вовсе: 07.09.2026 на бою из-за этого не заводился ИП Керимов, удалённый в июле,
 * и разбирать пришлось запросом в базу. Отсюда две вещи, которые проверяем здесь:
 * отказ называет причину и предлагает вернуть, а список удалённых доступен из окна.
 */
class ClientTrashedRestoreTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $admin;
    private Employee $accountant;

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

        $module = Module::firstOrCreate(['name' => 'clients'], ['display_name' => 'Клиенты', 'is_active' => true]);

        $make = function (string $roleName) use ($module) {
            $role = Role::firstOrCreate(['name' => $roleName], ['display_name' => $roleName]);

            $employee = Employee::create([
                'full_name' => 'Тест ' . $roleName, 'position' => $roleName,
                'email' => uniqid('trash_') . '@test.kg', 'password' => bcrypt('x'),
                'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
            ]);
            $employee->modules()->attach($module->id);

            return $employee;
        };

        $this->admin = $make(Role::ADMIN);
        $this->accountant = $make(Role::ACCOUNTANT);
    }

    private function client(string $name = 'ОсОО Тест', array $attributes = []): Client
    {
        return Client::create(array_merge([
            'name' => $name . ' ' . uniqid(),
            'inn'  => (string) random_int(100000000000, 999999999999),
        ], $attributes));
    }

    private function trashed(array $attributes = []): Client
    {
        $client = $this->client('ОсОО Удалённая', $attributes);
        $client->delete();

        return $client;
    }

    public function test_trashed_list_shows_deleted_clients(): void
    {
        $gone = $this->trashed();
        $alive = $this->client('ОсОО Живая');

        $rows = collect($this->actingAs($this->admin, 'employee')->getJson('/clients/trashed')->assertOk()->json());

        $this->assertContains($gone->id, $rows->pluck('id')->all());
        $this->assertNotContains($alive->id, $rows->pluck('id')->all());
        $this->assertTrue($rows->firstWhere('id', $gone->id)['can_restore']);
    }

    /** Рядовому сотруднику удалённые не показываем: вернуть их он всё равно не может. */
    public function test_accountant_cannot_see_or_restore_trashed(): void
    {
        $gone = $this->trashed();

        $this->actingAs($this->accountant, 'employee')->getJson('/clients/trashed')->assertForbidden();
        $this->actingAs($this->accountant, 'employee')->postJson('/clients/' . $gone->id . '/restore')->assertForbidden();
        $this->assertNotNull($gone->fresh()->deleted_at);
    }

    public function test_restore_brings_the_client_back(): void
    {
        $gone = $this->trashed();

        $this->actingAs($this->admin, 'employee')
            ->postJson('/clients/' . $gone->id . '/restore')
            ->assertOk()
            ->assertJsonPath('client.id', $gone->id);

        $this->assertNull($gone->fresh()->deleted_at);
    }

    /** Смета и история остаются на месте: клиент тот же, а не заведённый заново. */
    public function test_restore_keeps_the_estimate(): void
    {
        $gone = $this->trashed();
        $estimate = \App\Models\Estimate::create(['client_id' => $gone->id, 'total' => 0]);

        $this->actingAs($this->admin, 'employee')->postJson('/clients/' . $gone->id . '/restore')->assertOk();

        $this->assertSame($gone->id, $estimate->fresh()->client_id);
    }

    /**
     * Занятый удалённым ИНН объясняется по-человечески, а фронту приезжает, кого вернуть.
     * Прежний текст «Клиент с таким ИНН уже существует» отправлял искать двойника,
     * которого в списке нет.
     */
    public function test_inn_of_a_deleted_client_explains_and_offers_to_restore(): void
    {
        $gone = $this->trashed();
        $target = $this->client('ОсОО Правим');

        $response = $this->actingAs($this->admin, 'employee')->putJson('/clients/' . $target->id, [
            'name' => $target->name,
            'inn'  => $gone->inn,
        ]);

        $response->assertStatus(422)->assertJsonPath('trashed.id', $gone->id);
        $this->assertStringContainsString('удалён', $response->json('errors.inn.0'));
        $this->assertStringContainsString($gone->name, $response->json('errors.inn.0'));
    }

    /** Номер компании удалённого клиента ведёт себя так же. */
    public function test_company_number_of_a_deleted_client_offers_to_restore(): void
    {
        $number = random_int(100000, 999999);
        $gone = $this->trashed(['company_number' => $number]);
        $target = $this->client('ОсОО Нумеруем');

        $this->actingAs($this->admin, 'employee')
            ->putJson('/clients/' . $target->id, [
                'name' => $target->name,
                'inn'  => $target->inn,
                'company_number' => $number,
            ])
            ->assertStatus(422)
            ->assertJsonPath('trashed.id', $gone->id);
    }

    /** Создание клиента возвращает в окно с той же подсказкой и с введённым. */
    public function test_creating_with_a_deleted_inn_returns_to_the_form(): void
    {
        $gone = $this->trashed();

        $this->actingAs($this->admin, 'employee')
            ->post('/clients', ['name' => 'ОсОО Новая ' . uniqid(), 'inn' => $gone->inn])
            ->assertRedirect()
            ->assertSessionHasErrors('inn', null, 'createClient')
            ->assertSessionHas('trashedClient', fn ($row) => $row['id'] === $gone->id);
    }

    /** Живой двойник по-прежнему отвечает коротко: возвращать тут нечего. */
    public function test_live_duplicate_keeps_the_old_message(): void
    {
        $alive = $this->client('ОсОО Занявшая');
        $target = $this->client('ОсОО Правим');

        $response = $this->actingAs($this->admin, 'employee')->putJson('/clients/' . $target->id, [
            'name' => $target->name,
            'inn'  => $alive->inn,
        ]);

        $response->assertStatus(422)->assertJsonMissingPath('trashed');
        $this->assertSame('Клиент с таким ИНН уже существует', $response->json('errors.inn.0'));
    }

    /** Удалённый клиент чужой фирмы, чтобы проверить перегородку между аккаунтами. */
    private function trashedInOtherTenant(array $attributes = []): Client
    {
        $tenant = Tenant::create([
            'name' => 'Чужая фирма ' . uniqid(),
            'slug' => 'other-' . uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        return TenantContext::for($tenant, function () use ($attributes) {
            $client = Client::create(array_merge([
                'name' => 'ОсОО Чужая удалённая ' . uniqid(),
                'inn'  => (string) random_int(100000000000, 999999999999),
            ], $attributes));
            $client->delete();

            return $client;
        });
    }

    /** В корзине только свои: чужих удалённых клиентов не показываем никому. */
    public function test_trashed_list_never_shows_other_tenants(): void
    {
        $mine = $this->trashed();
        $theirs = $this->trashedInOtherTenant();

        $ids = collect($this->actingAs($this->admin, 'employee')->getJson('/clients/trashed')->assertOk()->json())
            ->pluck('id')->all();

        $this->assertContains($mine->id, $ids);
        $this->assertNotContains($theirs->id, $ids);
    }

    /** Чужого удалённого клиента не вернуть даже прямой ссылкой. */
    public function test_other_tenant_client_cannot_be_restored(): void
    {
        $theirs = $this->trashedInOtherTenant();

        $this->actingAs($this->admin, 'employee')
            ->postJson('/clients/' . $theirs->id . '/restore')
            ->assertNotFound();

        $this->assertNotNull(Client::acrossTenants()->withTrashed()->find($theirs->id)->deleted_at);
    }

    /**
     * Чужой удалённый клиент не мешает завести своего с тем же ИНН.
     *
     * Две бухфирмы в одном городе спокойно ведут одного и того же клиента, и
     * подсказка «верните удалённого» про чужую компанию была бы утечкой: человек
     * узнал бы и название, и дату удаления.
     */
    public function test_other_tenant_deleted_inn_does_not_block(): void
    {
        $theirs = $this->trashedInOtherTenant();

        $this->actingAs($this->admin, 'employee')
            ->post('/clients', ['name' => 'ОсОО Своя ' . uniqid(), 'inn' => $theirs->inn])
            ->assertRedirect()
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('trashedClient');

        $this->assertNotNull(Client::where('inn', $theirs->inn)->first());
    }

    /** То же с номером компании: у каждой фирмы своя нумерация. */
    public function test_other_tenant_deleted_company_number_does_not_block(): void
    {
        $number = random_int(100000, 999999);
        $this->trashedInOtherTenant(['company_number' => $number]);

        $this->actingAs($this->admin, 'employee')
            ->post('/clients', [
                'name' => 'ОсОО Нумерованная ' . uniqid(),
                'inn' => (string) random_int(100000000000, 999999999999),
                'company_number' => $number,
            ])
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $this->assertSame($number, Client::where('company_number', $number)->first()?->company_number);
    }

    /** Кнопка «Удалённые» — только тем, кто заводит и удаляет клиентов. */
    public function test_trashed_button_is_hidden_from_accountant(): void
    {
        $forAdmin = $this->actingAs($this->admin, 'employee')->get('/clients')->assertOk()->getContent();
        $forAccountant = $this->actingAs($this->accountant, 'employee')->get('/clients')->assertOk()->getContent();

        // Ищем именно кнопку: метод openTrashedModal живёт в общем x-data и есть у всех,
        // а рисуется кнопка только под правами.
        $button = 'Клиенты, которых удалили';

        $this->assertStringContainsString($button, $forAdmin);
        $this->assertStringNotContainsString($button, $forAccountant);
    }
}
