<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Service;
use App\Services\ClientServiceCatalog;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Отчёт «что изменится после включения сужения по типу обслуживания».
 *
 * Сужение ещё не включено: эти тесты проверяют не смету, а расчёт последствий,
 * который показывает команда clients:scope-preview. Когда калитку включат,
 * тот же расчёт станет правилом подтягивания, поэтому случаи здесь те же самые,
 * что мы проговаривали: тип сильнее категории «Обязательная» и сильнее особых
 * условий, а БП без типа не отсекается никогда.
 */
class ServiceScopePreviewTest extends TestCase
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
            'email' => 'ssp_admin_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
    }

    private function client(array $attributes = []): Client
    {
        return Client::create(array_merge([
            'name' => 'ОсОО Тест ' . uniqid(),
            'inn'  => strtoupper(substr(md5(uniqid()), 0, 12)),
            'responsible_employee_id' => $this->admin->id,
        ], $attributes));
    }

    /** Обязательный БП тянется всем, независимо от режима налогообложения. */
    private function mandatoryService(?string $type, array $attributes = []): Service
    {
        return Service::create(array_merge([
            'name'         => 'БП ' . uniqid(),
            'cost'         => 100,
            'is_active'    => true,
            'category'     => 'Обязательная',
            'service_type' => $type,
        ], $attributes));
    }

    private function dropped(Client $client): array
    {
        return (new ClientServiceCatalog())->narrowedAwayFor($client)->pluck('id')->all();
    }

    /** Клиент на полном обслуживании не теряет ничего, что бы ни стояло у БП. */
    public function test_full_service_client_loses_nothing(): void
    {
        $client = $this->client();
        $this->mandatoryService('accounting');
        $this->mandatoryService('tax');
        $this->mandatoryService('payroll');

        $this->assertSame([], $this->dropped($client));
    }

    /** Отмечены все три — это то же полное обслуживание. */
    public function test_all_three_marks_lose_nothing(): void
    {
        $client = $this->client([
            'serves_accounting' => true, 'serves_tax' => true, 'serves_payroll' => true,
        ]);
        $this->mandatoryService('accounting');

        $this->assertSame([], $this->dropped($client));
    }

    /** Тип сильнее категории «Обязательная»: чужой тип уходит, свой и общий остаются. */
    public function test_type_wins_over_mandatory_category(): void
    {
        $client = $this->client([
            'serves_accounting' => false, 'serves_tax' => true, 'serves_payroll' => false,
        ]);

        $accounting = $this->mandatoryService('accounting');
        $tax        = $this->mandatoryService('tax');
        $general    = $this->mandatoryService(null);

        $dropped = $this->dropped($client);

        $this->assertContains($accounting->id, $dropped);
        $this->assertNotContains($tax->id, $dropped, 'БП своего типа остаётся');
        $this->assertNotContains($general->id, $dropped, 'БП без типа не отсекается никогда');
    }

    /**
     * Тип сильнее особого условия.
     *
     * У клиента есть сотрудники, но расчёт зарплаты мы ему не ведём: зарплатный БП,
     * который подтянулся бы по признаку «Сотрудники», уходит. Иначе вышло бы, что
     * зарплату клиенту не продавали, а задачи по ней идут.
     */
    public function test_type_wins_over_special_condition(): void
    {
        $client = $this->client([
            'has_employees'     => true,
            'serves_accounting' => true, 'serves_tax' => true, 'serves_payroll' => false,
        ]);

        $payroll = Service::create([
            'name' => 'Расчёт зарплаты ' . uniqid(), 'cost' => 100, 'is_active' => true,
            'is_employees' => true, 'service_type' => 'payroll',
        ]);

        $this->assertContains($payroll->id, $this->dropped($client));
    }

    /** Отчёт ничего не меняет и отрабатывает на живых данных. */
    public function test_command_runs_and_changes_nothing(): void
    {
        $client = $this->client([
            'serves_accounting' => false, 'serves_tax' => true, 'serves_payroll' => false,
        ]);
        $accounting = $this->mandatoryService('accounting');

        $this->artisan('clients:scope-preview')->assertSuccessful();

        $this->assertSame('accounting', $accounting->fresh()->service_type);
        $this->assertTrue($client->fresh()->serves_tax);
        $this->assertFalse($client->fresh()->serves_accounting);
    }
}
