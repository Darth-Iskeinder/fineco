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
use App\Services\ClientResponsibleTransfer;
use App\Services\TaskHandover;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Статус «Передана». Задачу перевели на другого, а прежний её уже начинал: раньше его
 * запись оставалась «на паузе» и пропадала с экрана, таймер мог идти сутками. Теперь
 * таймер останавливается, запись становится «Передана», прежний видит её во «Выполненных»,
 * новый видит в карточке «До вас».
 */
class TaskHandoverTest extends TestCase
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

    private function currentLog(Employee $doer, string $status = 'paused', int $seconds = 600): BuhTaskLog
    {
        return BuhTaskLog::create([
            'employee_id' => $doer->id,
            'client_id' => $this->client->id,
            'estimate_item_id' => $this->item->id,
            'year' => now()->year, 'month' => now()->month,
            'status' => $status, 'paused_seconds' => $seconds,
            'started_at' => now()->subHour(),
            'resumed_at' => $status === 'running' ? now()->subMinutes(30) : now()->subHour(),
        ]);
    }

    private function page(Employee $employee)
    {
        return $this->actingAs($employee, 'employee')->get(route('buhtasks.index'))->assertOk();
    }

    public function test_reassigning_item_stops_timer_and_hands_over(): void
    {
        $log = $this->currentLog($this->head, 'running', 600);

        $this->item->update(['assignee_id' => $this->accountant->id]);
        (new TaskHandover())->forClient($this->client);

        $log->refresh();
        $this->assertSame(BuhTaskLog::STATUS_HANDED, $log->status);
        $this->assertSame($this->accountant->id, (int) $log->handed_to_id);
        $this->assertNotNull($log->handed_at);
        // 10 минут до этого + 30 минут, что шёл таймер; дальше время не идёт
        $this->assertEqualsWithDelta(600 + 1800, $log->paused_seconds, 5);

        // Повторный вызов ничего не меняет
        $this->assertCount(0, (new TaskHandover())->forClient($this->client));
    }

    public function test_previous_executor_sees_handed_task_in_completed(): void
    {
        $log = $this->currentLog($this->head);
        $this->item->update(['assignee_id' => $this->accountant->id]);
        (new TaskHandover())->forClient($this->client);

        $row = collect($this->page($this->head)->viewData('completed'))
            ->firstWhere('id', 'handed_' . $log->id);

        $this->assertNotNull($row, 'переданная задача должна быть во «Выполненных» у прежнего');
        $this->assertSame('Тест acc', $row['handed_to_name']);
        $this->assertSame(600, $row['elapsed_seconds']);
        $this->assertNull($row['comment_url']);
    }

    public function test_new_executor_sees_time_before_him(): void
    {
        $this->currentLog($this->head, 'paused', 1800);
        $this->item->update(['assignee_id' => $this->accountant->id]);
        (new TaskHandover())->forClient($this->client);

        $task = collect($this->page($this->accountant)->viewData('tasks'))
            ->first(fn ($t) => ($t['item_id'] ?? null) === $this->item->id
                && $t['year'] === now()->year && $t['month'] === now()->month);

        $this->assertNotNull($task);
        $this->assertSame('pending', $task['status']); // начинает со своей записи, с нуля
        $this->assertSame([['name' => 'Тест head', 'seconds' => 1800]], $task['handed_before']);
    }

    public function test_task_returned_to_first_executor_restores_his_record(): void
    {
        $log = $this->currentLog($this->head, 'paused', 900);

        $this->item->update(['assignee_id' => $this->accountant->id]);
        (new TaskHandover())->forClient($this->client);
        $this->item->update(['assignee_id' => $this->head->id]);
        (new TaskHandover())->forClient($this->client);

        $log->refresh();
        $this->assertSame('paused', $log->status);
        $this->assertSame(900, $log->paused_seconds);
        $this->assertNull($log->handed_to_id);
        $this->assertNull($log->handed_at);
    }

    /** Смена ответственного у клиента: позиция без явного исполнителя уходит к новому. */
    public function test_responsible_change_hands_over_started_work(): void
    {
        $this->item->update(['assignee_id' => null]);
        $log = $this->currentLog($this->head);

        $previous = $this->client->responsible_employee_id;
        $this->client->update(['responsible_employee_id' => $this->accountant->id]);
        (new ClientResponsibleTransfer())->apply($this->client, $previous);

        $this->assertSame(BuhTaskLog::STATUS_HANDED, $log->fresh()->status);
        $this->assertSame($this->accountant->id, (int) $log->fresh()->handed_to_id);
    }

    /** Закрытое не трогаем: выполненная задача остаётся выполненной, кто бы ни был исполнителем. */
    public function test_completed_work_is_not_handed_over(): void
    {
        $log = $this->currentLog($this->head);
        $log->update(['status' => 'completed', 'completed_at' => now()]);

        $this->item->update(['assignee_id' => $this->accountant->id]);
        (new TaskHandover())->forClient($this->client);

        $this->assertSame('completed', $log->fresh()->status);
    }

    public function test_orphans_command_only_shows_without_apply(): void
    {
        $log = $this->currentLog($this->head);
        $this->item->update(['assignee_id' => $this->accountant->id]);

        $this->artisan('buhtasks:hand-over-orphans', ['--tenant' => $this->head->tenant_id])->assertSuccessful();
        $this->assertSame('paused', $log->fresh()->status);

        $this->artisan('buhtasks:hand-over-orphans', ['--tenant' => $this->head->tenant_id, '--apply' => true])->assertSuccessful();
        $this->assertSame(BuhTaskLog::STATUS_HANDED, $log->fresh()->status);
    }

    /** Обычный путь: главбух меняет исполнителя в смете и сохраняет. */
    public function test_saving_estimate_with_new_assignee_hands_over(): void
    {
        $log = $this->currentLog($this->head, 'running', 0);

        $admin = Employee::create([
            'full_name' => 'Тест admin', 'position' => 'admin',
            'email' => 'admin_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => Role::firstOrCreate(['name' => Role::ADMIN], ['display_name' => 'Админ'])->id,
            'status' => Employee::STATUS_ACTIVE,
        ]);

        $this->actingAs($admin, 'employee')
            ->postJson(route('clients.estimate.save', $this->client), [
                'tariff_bps' => [[
                    'service_id'  => $this->item->service_id,
                    'enabled'     => true,
                    'quantity'    => 1,
                    'assignee_id' => $this->accountant->id,
                ]],
                'extras' => [],
            ])
            ->assertOk();

        $this->assertSame($this->accountant->id, (int) $this->item->fresh()->assignee_id);
        $this->assertSame(BuhTaskLog::STATUS_HANDED, $log->fresh()->status);
    }
}
