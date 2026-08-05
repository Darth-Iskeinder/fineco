<?php

namespace Tests\Feature;

use App\Models\BuhAdhocTask;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Module;
use App\Models\Periodicity;
use App\Models\Role;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Зона видимости главбуха на /buhtasks.
 *
 * Главбух ведёт свои компании и следит за задачами ПО НИМ, кто бы их ни делал.
 * Раньше «своим бухгалтером» становился любой, кому назначен хоть один БП клиента
 * главбуха, и после этого ВСЕ его задачи — по чужим компаниям и вообще без компании,
 * в том числе самозаведённые — падали главбуху во вкладку «Выполненные».
 */
class HeadTeamScopeTest extends TestCase
{
    use DatabaseTransactions;

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

        Periodicity::firstOrCreate(['name' => 'Ежемесячно'], ['kind' => 'monthly']);
        $module = Module::firstOrCreate(
            ['name' => 'buhtasks'],
            ['display_name' => 'БухЗадачник', 'is_active' => true],
        );
        $role = Role::firstOrCreate(['name' => Role::EMPLOYEE], ['display_name' => 'Сотрудник']);

        $make = fn (string $prefix) => Employee::create([
            'full_name' => 'Тест ' . $prefix, 'position' => $prefix,
            'email' => $prefix . '_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);

        $this->head       = $make('head');
        $this->accountant = $make('acc');

        foreach ([$this->head, $this->accountant] as $e) {
            $e->modules()->attach($module->id);
        }
    }

    private function client(string $name, ?int $responsibleId): Client
    {
        return Client::create([
            'name' => $name, 'inn' => strtoupper(substr(md5($name . uniqid()), 0, 12)),
            'responsible_employee_id' => $responsibleId, 'is_active' => true,
        ]);
    }

    private function completedAdhoc(Employee $doer, ?int $clientId, string $name): BuhAdhocTask
    {
        $now = now();

        return BuhAdhocTask::create([
            'employee_id' => $doer->id,
            'client_id'   => $clientId,
            'name'        => $name,
            'cost'        => 0,
            'year'        => $now->year,
            'month'       => $now->month,
            'due_day'     => $now->day,
            'status'      => 'completed',
            'completed_at' => $now,
            'paused_seconds' => 0,
        ]);
    }

    /** @return array<int, string> названия строк во вкладке «Выполненные» */
    private function completedNames(Employee $employee): array
    {
        $response = $this->actingAs($employee, 'employee')
            ->get(route('buhtasks.index'))
            ->assertOk();

        return array_column($response->viewData('completed'), 'name');
    }

    public function test_head_sees_completed_task_on_his_own_client(): void
    {
        $headClient = $this->client('ТОО Главбуха ' . uniqid(), $this->head->id);
        $task = $this->completedAdhoc($this->accountant, $headClient->id, 'Задача по моей компании ' . uniqid());

        $this->assertContains($task->name, $this->completedNames($this->head));
    }

    public function test_head_does_not_see_accountant_task_on_foreign_client(): void
    {
        // У главбуха есть своя компания — иначе зона команды пуста и тест ничего не проверяет
        $this->client('ТОО Главбуха ' . uniqid(), $this->head->id);

        $accClient = $this->client('ТОО Бухгалтера ' . uniqid(), $this->accountant->id);
        $task = $this->completedAdhoc($this->accountant, $accClient->id, 'Задача по чужой компании ' . uniqid());

        $this->assertNotContains($task->name, $this->completedNames($this->head));
    }

    public function test_head_does_not_see_accountant_task_without_client(): void
    {
        $this->client('ТОО Главбуха ' . uniqid(), $this->head->id);

        $task = $this->completedAdhoc($this->accountant, null, 'Задача без компании ' . uniqid());

        $this->assertNotContains($task->name, $this->completedNames($this->head));
    }
}
