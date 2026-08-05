<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\Module;
use App\Models\Role;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Видимость списка смет (/buhsmeta): админ и руководитель видят все компании со сметой,
 * остальные — только те, где они ответственные. Компания без ответственного попадает
 * только админу и руководителю.
 */
class BuhSmetaVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $admin;
    private Employee $manager;
    private Employee $head;
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
        \Illuminate\Support\Facades\DB::purge('mysql');

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $module = Module::firstOrCreate(
            ['name' => 'buhsmeta'],
            ['display_name' => 'БухСмета', 'is_active' => true],
        );

        $roles = [
            'admin'    => Role::firstOrCreate(['name' => Role::ADMIN], ['display_name' => 'Админ']),
            'manager'  => Role::firstOrCreate(['name' => Role::MANAGER], ['display_name' => 'Руководитель']),
            'employee' => Role::firstOrCreate(['name' => Role::EMPLOYEE], ['display_name' => 'Сотрудник']),
        ];

        $make = fn (string $role, string $prefix) => Employee::create([
            'full_name' => 'Тест ' . $prefix, 'position' => $prefix,
            'email' => $prefix . '_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $roles[$role]->id, 'status' => Employee::STATUS_ACTIVE,
        ]);

        $this->admin      = $make('admin', 'admin');
        $this->manager    = $make('manager', 'manager');
        $this->head       = $make('employee', 'head');
        $this->accountant = $make('employee', 'acc');

        foreach ([$this->head, $this->accountant] as $e) {
            $e->modules()->attach($module->id);
        }
    }

    /** Компания со сметой — только со сметой она попадает в список. */
    private function clientWithEstimate(string $name, ?int $responsibleId): Client
    {
        $client = Client::create([
            'name' => $name, 'inn' => strtoupper(substr(md5($name . uniqid()), 0, 12)),
            'responsible_employee_id' => $responsibleId, 'is_active' => true,
        ]);
        Estimate::create(['client_id' => $client->id, 'total' => 0]);

        return $client;
    }

    /** @return array<int, string> названия компаний в списке смет */
    private function visibleNames(Employee $employee): array
    {
        $response = $this->actingAs($employee, 'employee')
            ->get(route('buhsmeta.index'))
            ->assertOk();

        return $response->viewData('clients')->pluck('name')->all();
    }

    public function test_admin_and_manager_see_all_estimates(): void
    {
        $headClient = $this->clientWithEstimate('ТОО Главбуха ' . uniqid(), $this->head->id);
        $orphan     = $this->clientWithEstimate('ТОО Ничей ' . uniqid(), null);

        foreach ([$this->admin, $this->manager] as $boss) {
            $names = $this->visibleNames($boss);
            $this->assertContains($headClient->name, $names);
            $this->assertContains($orphan->name, $names);
        }
    }

    public function test_employee_sees_only_clients_he_is_responsible_for(): void
    {
        $mine    = $this->clientWithEstimate('ТОО Мой ' . uniqid(), $this->head->id);
        $foreign = $this->clientWithEstimate('ТОО Чужой ' . uniqid(), $this->accountant->id);

        $names = $this->visibleNames($this->head);
        $this->assertContains($mine->name, $names);
        $this->assertNotContains($foreign->name, $names);
    }

    public function test_client_without_responsible_is_hidden_from_employees(): void
    {
        $this->clientWithEstimate('ТОО Мой ' . uniqid(), $this->head->id);
        $orphan = $this->clientWithEstimate('ТОО Ничей ' . uniqid(), null);

        $this->assertNotContains($orphan->name, $this->visibleNames($this->head));
    }
}
