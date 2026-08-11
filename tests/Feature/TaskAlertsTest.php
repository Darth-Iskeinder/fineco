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
 * Всплывающие уведомления: сотруднику поручили задачу или вернули работу на доработку.
 *
 * Уведомление висит, пока сотрудник не нажмёт «Понятно», и отметка о просмотре живёт
 * в задаче — с другого компьютера то же самое всплыть не должно.
 */
class TaskAlertsTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $author;
    private Employee $doer;

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

        $this->author = $make('author');
        $this->doer   = $make('doer');

        foreach ([$this->author, $this->doer] as $e) {
            $e->modules()->attach($module->id);
        }
    }

    private function adhoc(array $attributes = []): BuhAdhocTask
    {
        $now = now();

        return BuhAdhocTask::create(array_merge([
            'employee_id'    => $this->doer->id,
            'created_by'     => $this->author->id,
            'client_id'      => null,
            'name'           => 'Поручение ' . uniqid(),
            'cost'           => 0,
            'year'           => $now->year,
            'month'          => $now->month,
            'due_day'        => $now->day,
            'status'         => 'pending',
            'paused_seconds' => 0,
            'rework_seen_at' => $now,
        ], $attributes));
    }

    /** @return array<int, string> ключи уведомлений сотрудника */
    private function alertKeys(Employee $employee): array
    {
        return array_column(
            $this->actingAs($employee, 'employee')
                ->getJson(route('task-alerts.index'))
                ->assertOk()
                ->json('items'),
            'key'
        );
    }

    // === Новое поручение ===

    public function test_assigned_task_raises_an_alert_for_the_doer(): void
    {
        $task = $this->adhoc();

        $this->assertContains('assigned:adhoc:' . $task->id, $this->alertKeys($this->doer));
    }

    public function test_author_gets_no_alert_about_his_own_assignment(): void
    {
        $task = $this->adhoc();

        $this->assertNotContains('assigned:adhoc:' . $task->id, $this->alertKeys($this->author));
    }

    public function test_task_created_for_self_raises_no_alert(): void
    {
        $task = $this->adhoc([
            'employee_id'    => $this->author->id,
            'assign_seen_at' => now(),
        ]);

        $this->assertNotContains('assigned:adhoc:' . $task->id, $this->alertKeys($this->author));
    }

    public function test_completed_assignment_raises_no_alert(): void
    {
        $task = $this->adhoc(['status' => 'completed', 'completed_at' => now()]);

        $this->assertNotContains('assigned:adhoc:' . $task->id, $this->alertKeys($this->doer));
    }

    public function test_dismiss_hides_the_alert_for_good(): void
    {
        $task = $this->adhoc();

        $this->actingAs($this->doer, 'employee')
            ->postJson(route('task-alerts.seen'), ['keys' => ['assigned:adhoc:' . $task->id]])
            ->assertOk();

        $this->assertNotNull($task->fresh()->assign_seen_at);
        $this->assertNotContains('assigned:adhoc:' . $task->id, $this->alertKeys($this->doer));
    }

    public function test_dismiss_does_not_touch_someone_elses_task(): void
    {
        // Чужую задачу «прочитать» за исполнителя нельзя
        $task = $this->adhoc();

        $this->actingAs($this->author, 'employee')
            ->postJson(route('task-alerts.seen'), ['keys' => ['assigned:adhoc:' . $task->id]])
            ->assertOk();

        $this->assertNull($task->fresh()->assign_seen_at);
        $this->assertContains('assigned:adhoc:' . $task->id, $this->alertKeys($this->doer));
    }

    public function test_employee_without_the_module_gets_no_alerts(): void
    {
        $this->adhoc();
        $this->doer->modules()->detach();

        $this->assertSame([], $this->alertKeys($this->doer));
    }

    // === Возврат на доработку ===

    public function test_rejected_task_raises_a_rework_alert(): void
    {
        $client = Client::create([
            'name' => 'ТОО ' . uniqid(), 'inn' => strtoupper(substr(md5(uniqid()), 0, 12)),
            'responsible_employee_id' => $this->author->id, 'is_active' => true,
        ]);
        $task = $this->adhoc([
            'client_id' => $client->id, 'requires_review' => true,
            'status' => 'review', 'review_started_at' => now(), 'assign_seen_at' => now(),
        ]);

        $this->actingAs($this->author, 'employee')
            ->postJson(route('buhtasks.adhoc.review-reject', $task->id), ['comment' => 'не та печать'])
            ->assertOk();

        $this->assertNull($task->fresh()->rework_seen_at);

        $items = $this->actingAs($this->doer, 'employee')
            ->getJson(route('task-alerts.index'))
            ->assertOk()
            ->json('items');

        $alert = collect($items)->firstWhere('key', 'rework:adhoc:' . $task->id);

        $this->assertNotNull($alert);
        $this->assertSame('rework', $alert['kind']);
        $this->assertSame('не та печать', $alert['comment']);
        $this->assertSame($this->author->full_name, $alert['from_name']);
    }

    public function test_planned_task_returned_for_rework_also_raises_an_alert(): void
    {
        // Доработка бывает не только у внеплановых: плановая задача из сметы возвращается так же.
        $client = Client::create([
            'name' => 'ТОО ' . uniqid(), 'inn' => strtoupper(substr(md5(uniqid()), 0, 12)),
            'responsible_employee_id' => $this->author->id, 'is_active' => true,
        ]);
        $service = \App\Models\Service::create([
            'name' => 'Тест услуга ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_day' => [5], 'is_active' => true, 'requires_review' => true,
        ]);
        $estimate = \App\Models\Estimate::firstOrCreate(['client_id' => $client->id], ['total' => 0]);
        $item = $estimate->items()->create([
            'service_id' => $service->id, 'type' => 'recurring', 'name' => $service->name,
            'periodicity' => 'Ежемесячно', 'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 0,
        ]);
        $log = \App\Models\BuhTaskLog::create([
            'employee_id' => $this->doer->id, 'client_id' => $client->id,
            'estimate_item_id' => $item->id, 'year' => now()->year, 'month' => now()->month,
            'status' => 'review', 'review_started_at' => now(), 'rework_seen_at' => now(),
        ]);

        $this->actingAs($this->author, 'employee')
            ->postJson(route('buhtasks.logs.review-reject', $log->id), ['comment' => 'пересчитать НДС'])
            ->assertOk();

        $items = $this->actingAs($this->doer, 'employee')
            ->getJson(route('task-alerts.index'))
            ->assertOk()
            ->json('items');

        $alert = collect($items)->firstWhere('key', 'rework:log:' . $log->id);

        $this->assertNotNull($alert);
        $this->assertSame('пересчитать НДС', $alert['comment']);
        $this->assertSame($service->name, $alert['name']);
    }

    public function test_second_rejection_raises_the_alert_again(): void
    {
        $client = Client::create([
            'name' => 'ТОО ' . uniqid(), 'inn' => strtoupper(substr(md5(uniqid()), 0, 12)),
            'responsible_employee_id' => $this->author->id, 'is_active' => true,
        ]);
        $task = $this->adhoc([
            'client_id' => $client->id, 'requires_review' => true,
            'status' => 'rework', 'assign_seen_at' => now(), 'rework_seen_at' => now(),
        ]);

        // Первый возврат сотрудник уже видел — уведомления нет
        $this->assertNotContains('rework:adhoc:' . $task->id, $this->alertKeys($this->doer));

        // Работу сдали снова и снова вернули — уведомление должно всплыть заново
        $task->update(['status' => 'review', 'review_started_at' => now()]);
        $this->actingAs($this->author, 'employee')
            ->postJson(route('buhtasks.adhoc.review-reject', $task->id), ['comment' => 'опять не то'])
            ->assertOk();

        $this->assertContains('rework:adhoc:' . $task->id, $this->alertKeys($this->doer));
    }
}
