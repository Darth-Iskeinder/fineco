<?php

namespace Tests\Feature;

use App\Models\BuhAdhocTask;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\Module;
use App\Models\Role;
use App\Models\Service;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Блок «Компании» в профиле сотрудника: раньше показывалась только команда клиента
 * (client_employee), поэтому ответственные лица и исполнители БП в список не попадали.
 * Проверяем, что собираются все способы прикрепления — и только они.
 */
class EmployeeProfileClientsTest extends TestCase
{
    use DatabaseTransactions;

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

    private function employee(string $name): Employee
    {
        $role = Role::firstOrCreate(['name' => Role::ACCOUNTANT], ['display_name' => 'Бухгалтер']);

        return Employee::create([
            'full_name' => $name, 'position' => 'Бухгалтер',
            'email' => uniqid('emp_') . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
    }

    private function client(string $name, array $attrs = []): Client
    {
        return Client::create($attrs + [
            'name' => $name . ' ' . uniqid(),
            'inn'  => (string) random_int(100000000000, 999999999999),
        ]);
    }

    public function test_profile_shows_clients_from_every_kind_of_attachment(): void
    {
        $viewer   = $this->employee('Смотрящий');
        $employee = $this->employee('Проверяемый');

        $viewer->modules()->attach(
            Module::firstOrCreate(['name' => 'employees'], ['display_name' => 'Сотрудники', 'is_active' => true])->id
        );

        // 1. Ответственный за клиента
        $responsible = $this->client('Ответственный клиент', ['responsible_employee_id' => $employee->id]);

        // 2. В команде клиента (client_employee)
        $team = $this->client('Командный клиент');
        $team->employees()->attach($employee->id);

        // 3. Исполнитель БП в смете
        $assigned = $this->client('Клиент с БП');
        $service  = Service::create(['name' => 'Тест БП ' . uniqid(), 'periodicity' => 'Ежемесячно', 'cost' => 0, 'is_active' => true]);
        $estimate = Estimate::create(['client_id' => $assigned->id, 'total' => 0]);
        $estimate->items()->create([
            'service_id' => $service->id, 'assignee_id' => $employee->id, 'type' => 'recurring',
            'name' => 'Позиция', 'periodicity' => 'Ежемесячно', 'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 0,
        ]);

        // Задачи прикреплением не считаются: такие клиенты в профиле не появляются
        $withLog = $this->client('Клиент с задачей');
        $logEstimate = Estimate::create(['client_id' => $withLog->id, 'total' => 0]);
        $logItem = $logEstimate->items()->create([
            'service_id' => $service->id, 'type' => 'recurring', 'name' => 'Позиция',
            'periodicity' => 'Ежемесячно', 'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 0,
        ]);
        BuhTaskLog::create([
            'employee_id' => $employee->id, 'client_id' => $withLog->id,
            'estimate_item_id' => $logItem->id, 'year' => 2026, 'month' => 5, 'status' => 'completed',
        ]);

        $withAdhoc = $this->client('Клиент с внеплановой');
        BuhAdhocTask::create([
            'employee_id' => $employee->id, 'client_id' => $withAdhoc->id,
            'name' => 'Внеплановая', 'cost' => 0, 'year' => 2026, 'month' => 5, 'due_day' => 20,
            'status' => 'pending', 'paused_seconds' => 0,
        ]);

        // Чужой клиент в списке быть не должен
        $foreign = $this->client('Чужой клиент', ['responsible_employee_id' => $viewer->id]);

        $response = $this->actingAs($viewer, 'employee')->get("/employees/{$employee->id}")->assertOk();

        foreach ([$responsible, $team, $assigned] as $client) {
            $response->assertSee($client->name, false);
        }

        // Ни чужой клиент, ни клиенты, где сотрудник просто вёл задачи
        $response->assertDontSee($foreign->name, false);
        $response->assertDontSee($withLog->name, false);
        $response->assertDontSee($withAdhoc->name, false);
    }

    public function test_client_is_listed_once_with_all_its_roles(): void
    {
        $viewer   = $this->employee('Смотрящий');
        $employee = $this->employee('Проверяемый');
        $viewer->modules()->attach(
            Module::firstOrCreate(['name' => 'employees'], ['display_name' => 'Сотрудники', 'is_active' => true])->id
        );

        // Один клиент прикреплён двумя способами — строка должна быть одна
        $client = $this->client('Двойная связь', ['responsible_employee_id' => $employee->id]);
        $client->employees()->attach($employee->id);

        $html = $this->actingAs($viewer, 'employee')->get("/employees/{$employee->id}")->assertOk()->getContent();

        $this->assertSame(1, substr_count($html, $client->name), 'Клиент должен встречаться в данных один раз');
    }
}
