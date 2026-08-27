<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Module;
use App\Models\Role;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Пароль Тундук ЕСИ живёт отдельно от пароля ЭЦП.
 *
 * Раньше одно поле значило и то, и другое. Разведя их по карточке, главное было
 * не задеть уже заполненные пароли ЭЦП: тесты держат именно эту границу.
 */
class ClientTundukPasswordTest extends TestCase
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
            'full_name' => 'Тест Админ', 'position' => 'Админ',
            'email' => 'tunduk_admin_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);

        Module::firstOrCreate(['name' => 'clients'], ['display_name' => 'Клиенты', 'is_active' => true]);
    }

    private function client(array $attributes = []): Client
    {
        return Client::create(array_merge([
            'name' => 'ОсОО Тест ' . uniqid(),
            'inn'  => strtoupper(substr(md5(uniqid()), 0, 12)),
            'responsible_employee_id' => $this->admin->id,
        ], $attributes));
    }

    private function saveEds(Client $client, array $data)
    {
        return $this->actingAs($this->admin, 'employee')
            ->patchJson(route('clients.update-section', $client), array_merge([
                'section' => 'eds',
            ], $data));
    }

    /** Пароль Тундука сохраняется и возвращается расшифрованным. */
    public function test_tunduk_password_is_saved(): void
    {
        $client = $this->client();

        $this->saveEds($client, ['tunduk_password' => 'tunduk-secret'])
            ->assertOk();

        $this->assertSame('tunduk-secret', $client->fresh()->tunduk_password);
    }

    /** В базе он лежит зашифрованным, а не как есть. */
    public function test_tunduk_password_is_encrypted_in_database(): void
    {
        $client = $this->client();

        $this->saveEds($client, ['tunduk_password' => 'tunduk-secret'])->assertOk();

        $raw = DB::table('clients')->where('id', $client->id)->value('tunduk_password');

        $this->assertNotSame('tunduk-secret', $raw);
        $this->assertStringNotContainsString('tunduk-secret', (string) $raw);
    }

    /** Заполненный пароль ЭЦП остаётся на месте: разведение полей его не трогает. */
    public function test_saving_tunduk_keeps_eds_password(): void
    {
        $client = $this->client(['eds_password' => 'eds-secret']);

        $this->saveEds($client, [
            'eds_password'    => 'eds-secret',
            'tunduk_password' => 'tunduk-secret',
        ])->assertOk();

        $fresh = $client->fresh();

        $this->assertSame('eds-secret', $fresh->eds_password);
        $this->assertSame('tunduk-secret', $fresh->tunduk_password);
    }

    /** Пустое поле Тундука не стирает пароль ЭЦП и ложится в базу как null. */
    public function test_empty_tunduk_does_not_touch_eds_password(): void
    {
        $client = $this->client(['eds_password' => 'eds-secret']);

        $this->saveEds($client, [
            'eds_password'    => 'eds-secret',
            'tunduk_password' => null,
        ])->assertOk();

        $fresh = $client->fresh();

        $this->assertSame('eds-secret', $fresh->eds_password);
        $this->assertNull($fresh->tunduk_password);
    }
}
