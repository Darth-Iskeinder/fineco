<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientStatus;
use App\Models\Employee;
use App\Models\OrganizationForm;
use App\Models\Role;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Форма организации и статус клиента убраны из настроек совсем.
 *
 * Форму задаёт государство. Статус — не список, а механика: на флаге
 * closes_service висит закрытие обслуживания, а выставить его из настроек
 * было нельзя, только название. Свой статус выглядел рабочим, но обслуживание
 * не закрывал — клиент числился активным, и по нему продолжали идти задачи.
 *
 * Сами таблицы остались: оба значения выбираются селектором в карточке клиента.
 */
class HiddenSettingsSectionsTest extends TestCase
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
            'email' => 'hs_admin_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
    }

    /** Страниц нет — ни на чтение, ни на запись. */
    public function test_sections_are_gone(): void
    {
        foreach (['/settings/organization-forms', '/settings/client-statuses'] as $url) {
            $this->actingAs($this->admin, 'employee')->get($url)->assertNotFound();
            $this->actingAs($this->admin, 'employee')->postJson($url, ['name' => 'Новое'])->assertNotFound();
        }
    }

    /** В меню настроек этих пунктов больше нет. */
    public function test_menu_has_no_links(): void
    {
        $html = $this->actingAs($this->admin, 'employee')
            ->get('/settings/tax-systems')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Форма/тип организации', $html);
        $this->assertStringNotContainsString('Статус клиента', $html);
    }

    /** Данные на месте: карточка клиента выбирает их селектором. */
    public function test_data_is_still_there(): void
    {
        $this->assertTrue(OrganizationForm::query()->exists(), 'Формы организации пропали');
        $this->assertTrue(ClientStatus::query()->exists(), 'Статусы клиента пропали');

        // Ровно один статус закрывает обслуживание — на нём держится вся логика
        $this->assertSame(1, ClientStatus::where('closes_service', true)->count());
    }

    /** Новый клиент по-прежнему получает открытый статус автоматически. */
    public function test_new_client_gets_open_status(): void
    {
        $this->actingAs($this->admin, 'employee')->post('/clients', [
            'name' => 'ТОО Проверка Статуса',
            'inn'  => strtoupper(substr(md5(uniqid()), 0, 12)),
        ]);

        $client = Client::where('name', 'ТОО Проверка Статуса')->first();

        $this->assertNotNull($client);
        $this->assertNotNull($client->client_status_id, 'Клиенту не проставился статус');
        $this->assertFalse($client->clientStatus->closes_service);
    }
}
