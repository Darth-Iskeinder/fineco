<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\Module;
use App\Models\Role;
use App\Models\TaxSystem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Данные для фильтров списка смет (/buhsmeta). Сам отбор идёт в браузере — список
 * грузится целиком, — поэтому здесь проверяется то, чем этот отбор питается:
 * доступность фильтра по ответственному и состав вариантов в селектах.
 */
class BuhSmetaFiltersTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $admin;
    private Employee $manager;
    private Employee $head;

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

        $make = function (string $roleName, string $prefix) use ($module) {
            $role = Role::firstOrCreate(['name' => $roleName], ['display_name' => $prefix]);
            $employee = Employee::create([
                'full_name' => 'Фильтр ' . $prefix, 'position' => $prefix,
                'email' => 'sf_' . $prefix . '_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
                'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
            ]);
            $employee->modules()->attach($module->id);

            return $employee;
        };

        $this->admin   = $make(Role::ADMIN, 'admin');
        $this->manager = $make(Role::MANAGER, 'manager');
        $this->head    = $make(Role::ACCOUNTANT, 'head');
    }

    private function clientWithEstimate(?int $responsibleId, int $items = 0, ?int $taxSystemId = null): Client
    {
        $client = Client::create([
            'name' => 'ТОО Смета ' . uniqid(),
            'inn' => strtoupper(substr(md5(uniqid()), 0, 12)),
            'responsible_employee_id' => $responsibleId,
            'tax_system_id' => $taxSystemId,
            'is_active' => true,
        ]);

        $estimate = Estimate::create(['client_id' => $client->id, 'total' => 0]);
        for ($i = 0; $i < $items; $i++) {
            $estimate->items()->create([
                'type' => 'recurring', 'name' => 'Позиция ' . $i,
                'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => $i,
            ]);
        }

        return $client;
    }

    private function pageData(Employee $employee, string $key)
    {
        return $this->actingAs($employee, 'employee')
            ->get(route('buhsmeta.index'))
            ->assertOk()
            ->viewData($key);
    }

    public function test_person_filter_is_offered_only_to_admin_and_manager(): void
    {
        $this->clientWithEstimate($this->head->id);

        $this->assertTrue($this->pageData($this->admin, 'canFilterByPerson'));
        $this->assertTrue($this->pageData($this->manager, 'canFilterByPerson'));
        // У главбуха в списке и так только свои компании — селект с одним человеком не нужен
        $this->assertFalse($this->pageData($this->head, 'canFilterByPerson'));
    }

    public function test_options_are_built_from_visible_clients_only(): void
    {
        $withSystem = TaxSystem::create([
            'code' => strtoupper(substr(md5(uniqid()), 0, 8)),
            'name' => 'РН Смета ' . uniqid(),
            'is_active' => true,
        ]);
        $unused = TaxSystem::create([
            'code' => strtoupper(substr(md5(uniqid()), 0, 8)),
            'name' => 'РН Неиспользуемый ' . uniqid(),
            'is_active' => true,
        ]);

        $this->clientWithEstimate($this->head->id, 1, $withSystem->id);

        $taxNames = collect($this->pageData($this->admin, 'taxSystemOptions'))->pluck('name');
        $this->assertContains($withSystem->name, $taxNames->all());
        $this->assertNotContains($unused->name, $taxNames->all());

        $people = collect($this->pageData($this->admin, 'responsibleOptions'))->pluck('name');
        $this->assertContains($this->head->full_name, $people->all());
        // Сотрудник без компаний в списке в фильтре не появляется
        $this->assertNotContains($this->manager->full_name, $people->all());
    }

}
