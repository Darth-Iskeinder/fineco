<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Module;
use App\Models\Role;
use App\Models\TaxSystem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Правка клиента из списка сохраняется без перезагрузки страницы.
 *
 * Раньше форма уходила обычной отправкой и сервер отвечал редиректом: список
 * рисовался заново с самого верха, а вместе с прокруткой слетали поиск и фильтры.
 * У фирмы со ста клиентами это означало «листай вниз заново» после каждой правки.
 *
 * Теперь на запрос, который ждёт данные, контроллер отвечает готовой строкой
 * списка. Обычная отправка формы (без JS) по-прежнему получает редирект.
 */
class ClientInlineUpdateTest extends TestCase
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
            'email' => uniqid('cli_') . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);

        $this->admin->modules()->attach(
            Module::firstOrCreate(['name' => 'clients'], ['display_name' => 'Клиенты', 'is_active' => true])->id
        );
    }

    private function client(string $name = 'ОсОО Тест'): Client
    {
        return Client::create([
            'name' => $name . ' ' . uniqid(),
            'inn'  => (string) random_int(100000000000, 999999999999),
        ]);
    }

    private function payload(Client $client, array $overrides = []): array
    {
        return array_merge([
            'name'      => $client->name,
            'inn'       => $client->inn,
            'is_active' => 1,
        ], $overrides);
    }

    /** Запрос за данными получает готовую строку списка, а не редирект. */
    public function test_json_request_gets_the_updated_row(): void
    {
        $client = $this->client();
        $taxSystem = TaxSystem::active()->first();

        $response = $this->actingAs($this->admin, 'employee')
            ->putJson('/clients/' . $client->id, $this->payload($client, [
                'name' => 'ОсОО Новое имя',
                'tax_system_id' => $taxSystem?->id,
            ]))
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('message', 'Данные клиента обновлены')
            ->assertJsonPath('client.id', $client->id)
            ->assertJsonPath('client.name', 'ОсОО Новое имя');

        // Строка приходит в том же виде, в каком её рисует список: с готовыми
        // названиями связанных записей, а не с одними идентификаторами.
        foreach (['tax_system_name', 'tariff_name', 'responsible_name', 'estimate_items_count'] as $key) {
            $this->assertArrayHasKey($key, $response->json('client'));
        }

        $this->assertSame('ОсОО Новое имя', $client->fresh()->name);
    }

    /** Прочерк вместо пустой связи: список показывает его как есть. */
    public function test_empty_relations_come_as_dash(): void
    {
        $client = $this->client();

        $this->actingAs($this->admin, 'employee')
            ->putJson('/clients/' . $client->id, $this->payload($client))
            ->assertOk()
            ->assertJsonPath('client.tax_system_name', '—')
            ->assertJsonPath('client.tariff_name', '—')
            ->assertJsonPath('client.responsible_name', '—');
    }

    /** Ошибка валидации возвращается данными, чтобы окно показало её на месте. */
    public function test_validation_error_comes_as_json(): void
    {
        $first  = $this->client('Первый');
        $second = $this->client('Второй');

        $this->actingAs($this->admin, 'employee')
            ->putJson('/clients/' . $second->id, $this->payload($second, ['inn' => $first->inn]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('inn');

        $this->assertSame($second->inn, $second->fresh()->inn, 'Клиент не должен был измениться');
    }

    /** Без JS форма уходит обычной отправкой, и ответ по-прежнему редирект. */
    public function test_plain_form_submit_still_redirects(): void
    {
        $client = $this->client();

        $this->actingAs($this->admin, 'employee')
            ->put('/clients/' . $client->id, $this->payload($client, ['name' => 'ОсОО Без JS']))
            ->assertRedirect(route('clients.index'))
            ->assertSessionHas('success', 'Данные клиента обновлены');

        $this->assertSame('ОсОО Без JS', $client->fresh()->name);
    }

    /** Страница списка отдаёт готовые строки, тем же форматом, что и правка. */
    public function test_index_passes_ready_rows(): void
    {
        $client = $this->client();

        $rows = $this->actingAs($this->admin, 'employee')
            ->get('/clients')
            ->assertOk()
            ->viewData('clientRows');

        $row = collect($rows)->firstWhere('id', $client->id);

        $this->assertNotNull($row, 'Клиент должен быть в строках списка');
        $this->assertSame($client->name, $row['name']);
        $this->assertSame('—', $row['responsible_name']);
    }
}
