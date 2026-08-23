<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Module;
use App\Models\Role;
use App\Models\Service;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Тип обслуживания у клиента: любое сочетание отметок «бухучёт», «налоговый учёт»,
 * «расчёт ЗП».
 *
 * Второй шаг: отметки сохраняются и показываются, но на подтягивание БП в смету
 * пока не влияют. Последний тест здесь именно об этом — он держит границу этапа
 * и упадёт, если сужение включится раньше времени.
 */
class ClientServiceScopeTest extends TestCase
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
            'email' => 'css_admin_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
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

    private function saveContract(Client $client, array $scope)
    {
        return $this->actingAs($this->admin, 'employee')
            ->patchJson(route('clients.update-section', $client), array_merge([
                'section' => 'contract',
            ], $scope));
    }

    /** Новый клиент заводится сразу на полном обслуживании: отмечено всё. */
    public function test_new_client_serves_everything(): void
    {
        $client = $this->client();

        $this->assertSame(['accounting', 'tax', 'payroll'], $client->serviceTypeKeys());
        $this->assertTrue($client->servesEverything());
        $this->assertSame(['accounting', 'tax', 'payroll'], $client->fresh()->serviceTypeKeys());
    }

    /**
     * Страховка на случай, если отметки всё же окажутся пустыми: клиента, у которого
     * не отмечено ничего, ведём целиком, а не «никак». Так работали все клиенты до
     * того, как отметки появились.
     */
    public function test_client_without_any_mark_still_serves_everything(): void
    {
        $client = $this->client([
            'serves_accounting' => false, 'serves_tax' => false, 'serves_payroll' => false,
        ]);

        $this->assertSame([], $client->serviceTypeKeys());
        $this->assertTrue($client->servesEverything());
    }

    public function test_saves_selected_service_types(): void
    {
        $client = $this->client();

        $this->saveContract($client, [
            'serves_accounting' => false,
            'serves_tax'        => true,
            'serves_payroll'    => true,
        ])->assertSuccessful();

        $client->refresh();

        $this->assertSame(['tax', 'payroll'], $client->serviceTypeKeys());
        $this->assertFalse($client->servesEverything());
        $this->assertSame(['Налоговый учёт', 'Расчёт зарплаты'], $client->serviceTypeLabels());
    }

    /** Все три отметки — это и есть полное обслуживание, отдельного значения под него нет. */
    public function test_all_three_marks_mean_full_service(): void
    {
        $client = $this->client();

        $this->saveContract($client, [
            'serves_accounting' => true,
            'serves_tax'        => true,
            'serves_payroll'    => true,
        ])->assertSuccessful();

        $this->assertTrue($client->refresh()->servesEverything());
    }

    /** Снять все отметки можно, и это то же самое, что полное обслуживание. */
    public function test_clearing_all_marks_is_allowed(): void
    {
        $client = $this->client([
            'serves_accounting' => true, 'serves_tax' => true, 'serves_payroll' => true,
        ]);

        $this->saveContract($client, [
            'serves_accounting' => false,
            'serves_tax'        => false,
            'serves_payroll'    => false,
        ])->assertSuccessful();

        $client->refresh();

        $this->assertSame([], $client->serviceTypeKeys());
        $this->assertTrue($client->servesEverything());
    }

    /** Карточка открывается и отдаёт отметки во фронт. */
    public function test_client_card_exposes_marks(): void
    {
        $client = $this->client([
            'serves_accounting' => false, 'serves_tax' => true, 'serves_payroll' => false,
        ]);

        $this->actingAs($this->admin, 'employee')
            ->get(route('clients.show', $client))
            ->assertSuccessful()
            ->assertSee('Полное обслуживание')
            ->assertSee('"serves_tax":true', false);
    }

    /**
     * Клиент на полном обслуживании не теряет ничего.
     *
     * Главная защита от регрессии: у Fineco и подобных фирм состав сметы не должен
     * измениться ни на строку, чем бы ни был размечен каталог.
     */
    public function test_full_service_client_gets_everything(): void
    {
        $client = $this->client();

        $accounting = Service::create([
            'name' => 'Разноска выписки ' . uniqid(), 'cost' => 100, 'is_active' => true,
            'category' => 'Обязательная', 'service_type' => 'accounting',
        ]);

        $pulled = collect(
            $this->actingAs($this->admin, 'employee')
                ->get(route('clients.estimate.edit', $client))
                ->assertSuccessful()
                ->viewData('tariffBPs')
        )->pluck('service_id')->all();

        $this->assertContains($accounting->id, $pulled);
    }

    /**
     * Сужение работает в самой смете, а не только в отчёте.
     *
     * Бухгалтерский БП обязательной категории клиенту, у которого отмечен один
     * налоговый учёт, не подтягивается. БП без типа подтягивается по-прежнему.
     */
    public function test_estimate_is_narrowed_by_service_type(): void
    {
        $client = $this->client([
            'serves_accounting' => false, 'serves_tax' => true, 'serves_payroll' => false,
        ]);

        $accounting = Service::create([
            'name'         => 'Разноска выписки ' . uniqid(),
            'cost'         => 100,
            'is_active'    => true,
            'category'     => 'Обязательная',
            'service_type' => 'accounting',
        ]);

        $general = Service::create([
            'name'         => 'Приём первички ' . uniqid(),
            'cost'         => 100,
            'is_active'    => true,
            'category'     => 'Обязательная',
            'service_type' => null,
        ]);

        $pulled = collect(
            $this->actingAs($this->admin, 'employee')
                ->get(route('clients.estimate.edit', $client))
                ->assertSuccessful()
                ->viewData('tariffBPs')
        )->pluck('service_id')->all();

        $this->assertNotContains($accounting->id, $pulled, 'Чужой тип не подтягивается');
        $this->assertContains($general->id, $pulled, 'БП без типа подтягивается как раньше');
    }
}
