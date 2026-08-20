<?php

namespace Tests\Feature;

use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Module;
use App\Models\Periodicity;
use App\Models\Role;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Смена исполнителя БП не должна воскрешать закрытое прошлое.
 *
 * Было: список задач считал сроки назад по расписанию и подставлял каждому периоду
 * ТЕКУЩЕГО исполнителя, а отметку «выполнено» искал лично по нему. После переназначения
 * закрытый прежним бухгалтером месяц всплывал у нового как «Просрочено» (и висел
 * невыполненным во вкладке главбуха). Теперь закрытая задача — общий факт слота.
 *
 * По боевому mysql в транзакции (как DashboardTest): страница /buhtasks поднимает
 * слишком много связей для sqlite-памяти.
 */
class TaskReassignmentTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $head;
    private Employee $accountant;
    private Client $client;
    private EstimateItem $item;

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
        $role = Role::firstOrCreate(['name' => Role::ACCOUNTANT], ['display_name' => 'Бухгалтер']);
        $module = Module::firstOrCreate(
            ['name' => 'buhtasks'],
            ['display_name' => 'БухЗадачник', 'is_active' => true],
        );

        $make = fn (string $prefix) => Employee::create([
            'full_name' => 'Тест ' . $prefix, 'position' => $prefix,
            'email' => $prefix . '_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);

        $this->head       = $make('head');
        $this->accountant = $make('acc');
        $this->head->modules()->attach($module->id);
        $this->accountant->modules()->attach($module->id);

        $this->client = Client::create([
            'name' => 'ТОО Переназначение ' . uniqid(),
            'inn' => strtoupper(substr(md5(uniqid()), 0, 12)),
            'responsible_employee_id' => $this->head->id,
        ]);

        // Ежемесячный БП со сроком 5-го числа; сначала его ведёт сам главбух.
        $service = Service::create([
            'name' => 'Тест отчёт ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_day' => [5], 'is_active' => true,
        ]);
        $estimate = Estimate::create(['client_id' => $this->client->id, 'total' => 0]);
        // Смета не сегодняшняя: месяц создания холостой (Estimate::tasksStartFrom),
        // а здесь нужны задачи и за прошлый месяц — проверяем переназначение, не посадку.
        $estimate->forceFill(['created_at' => now()->subMonths(3)])->save();
        $this->item = $estimate->items()->create([
            'service_id' => $service->id, 'type' => 'recurring',
            'name' => $service->name, 'periodicity' => 'Ежемесячно',
            'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 0,
            'assignee_id' => $this->head->id,
        ]);
    }

    /** Прошлый месяц: он уже просрочен, но не раньше отсечки backlog (июль 2026). */
    private function lastMonth(): CarbonImmutable
    {
        return CarbonImmutable::now()->subMonth()->startOfMonth();
    }

    /** Задача прошлого месяца по этому БП, как её видит сотрудник. */
    private function pastTaskFor(Employee $employee): ?array
    {
        $past = $this->lastMonth();

        return collect($this->tasksOf($employee))
            ->first(fn ($t) => ($t['item_id'] ?? null) === $this->item->id
                && $t['year'] === $past->year && $t['month'] === $past->month);
    }

    private function tasksOf(Employee $employee): array
    {
        return $this->actingAs($employee, 'employee')
            ->get(route('buhtasks.index'))
            ->assertOk()
            ->viewData('tasks');
    }

    private function teamTasksOf(Employee $employee): array
    {
        return $this->actingAs($employee, 'employee')
            ->get(route('buhtasks.index'))
            ->assertOk()
            ->viewData('teamTasks');
    }

    /** Лог прошлого месяца в указанном статусе на указанном исполнителе. */
    private function pastLog(Employee $doer, string $status): BuhTaskLog
    {
        $past = $this->lastMonth();

        return BuhTaskLog::create([
            'employee_id' => $doer->id,
            'client_id' => $this->client->id,
            'estimate_item_id' => $this->item->id,
            'year' => $past->year, 'month' => $past->month,
            'status' => $status,
            'completed_at' => $status === 'completed' ? $past->day(5) : null,
        ]);
    }

    public function test_reassignment_does_not_resurrect_closed_past_period(): void
    {
        $this->pastLog($this->head, 'completed');
        $this->item->update(['assignee_id' => $this->accountant->id]);

        // Закрытый прошлый месяц у нового исполнителя не всплывает
        $this->assertNull($this->pastTaskFor($this->accountant));

        // ...и не висит невыполненным во вкладке главбуха «Задачи бухгалтеров»
        $past = $this->lastMonth();
        $stale = collect($this->teamTasksOf($this->head))
            ->first(fn ($t) => $t['name'] === $this->item->name
                && $t['year'] === $past->year && $t['month'] === $past->month);
        $this->assertNull($stale);
    }

    public function test_new_assignee_still_gets_current_month(): void
    {
        $this->pastLog($this->head, 'completed');
        $this->item->update(['assignee_id' => $this->accountant->id]);

        $now = CarbonImmutable::now();
        $current = collect($this->tasksOf($this->accountant))
            ->first(fn ($t) => ($t['item_id'] ?? null) === $this->item->id
                && $t['year'] === $now->year && $t['month'] === $now->month);

        $this->assertNotNull($current);
        $this->assertSame('pending', $current['status']);
    }

    public function test_client_handover_does_not_resurrect_closed_past_period(): void
    {
        // Передача компании другому сотруднику: исполнитель у позиции не задан, поэтому
        // им становится новый ответственный клиента — тот же путь, что и переназначение БП.
        $this->item->update(['assignee_id' => null]);
        $this->pastLog($this->head, 'completed');

        $this->client->update(['responsible_employee_id' => $this->accountant->id]);

        $this->assertNull($this->pastTaskFor($this->accountant));

        $now = CarbonImmutable::now();
        $current = collect($this->tasksOf($this->accountant))
            ->first(fn ($t) => ($t['item_id'] ?? null) === $this->item->id
                && $t['year'] === $now->year && $t['month'] === $now->month);
        $this->assertNotNull($current, 'текущий месяц новому ответственному приходить должен');
    }

    public function test_unfinished_work_of_previous_assignee_stays_personal(): void
    {
        // Прежний исполнитель начал, но не закончил: новый видит СВОЮ задачу с нуля,
        // а не чужой таймер, к которому у него всё равно нет доступа.
        $this->pastLog($this->head, 'paused');
        $this->item->update(['assignee_id' => $this->accountant->id]);

        $task = $this->pastTaskFor($this->accountant);

        $this->assertNotNull($task);
        $this->assertSame('pending', $task['status']);
        $this->assertNull($task['log_id']);
    }
}
