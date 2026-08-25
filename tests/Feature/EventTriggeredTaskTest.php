<?php

namespace Tests\Feature;

use App\Models\BuhAdhocTask;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\Module;
use App\Models\Periodicity;
use App\Models\Role;
use App\Models\Service;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «По событию»: выполнили задачу по БП-родителю — родилась задача по дочернему.
 *
 * Тому же клиенту и тому же сотруднику, который выполнил. Родителем бывает и
 * плановая задача, и разовая из каталога. Идёт по боевому mysql-соединению
 * в транзакции с откатом, как ForceCompleteTaskTest.
 */
class EventTriggeredTaskTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $head;
    private Employee $accountant;
    private Client $client;

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

        Periodicity::firstOrCreate(['name' => 'Ежемесячно'], ['kind' => 'monthly']);
        Periodicity::firstOrCreate(['name' => 'По запросу'], ['kind' => Service::KIND_ON_REQUEST]);

        $role   = Role::firstOrCreate(['name' => Role::ACCOUNTANT], ['display_name' => 'Бухгалтер']);
        $module = Module::firstOrCreate(
            ['name' => 'buhtasks'],
            ['display_name' => 'БухЗадачник', 'is_active' => true],
        );

        $this->head = Employee::create([
            'full_name' => 'Тест Главбух', 'position' => 'Главбух',
            'email' => 'evhead_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
        $this->accountant = Employee::create([
            'full_name' => 'Тест Бухгалтер', 'position' => 'Бухгалтер',
            'email' => 'evacc_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
        $this->head->modules()->attach($module->id);
        $this->accountant->modules()->attach($module->id);

        $this->client = Client::create([
            'name' => 'ТОО Событие Тест', 'inn' => 'EVENT00000A',
            'responsible_employee_id' => $this->head->id,
        ]);
    }

    /** Дочерний БП: «По запросу» со сроком в днях — из него считается дата задачи. */
    private function childService(int $days = 3, array $extra = []): Service
    {
        return Service::create(array_merge([
            'name'          => 'Приём дел ' . uniqid(),
            'periodicity'   => 'По запросу',
            'deadline_days' => $days,
            'is_active'     => true,
            'cost'          => 0,
        ], $extra));
    }

    /** БП-родитель с включённым тумблером «По событию». */
    private function parentService(Service $child, array $extra = []): Service
    {
        return Service::create(array_merge([
            'name'                   => 'Внутренняя передача ' . uniqid(),
            'periodicity'            => 'По запросу',
            'deadline_days'          => 1,
            'is_active'              => true,
            'cost'                   => 0,
            'triggers_on_event'      => true,
            'event_child_service_id' => $child->id,
        ], $extra));
    }

    /** Разовая задача из каталога, назначенная себе. */
    private function adhoc(Service $service, array $extra = []): BuhAdhocTask
    {
        return BuhAdhocTask::create(array_merge([
            'employee_id' => $this->accountant->id,
            'created_by'  => $this->accountant->id,
            'client_id'   => $this->client->id,
            'service_id'  => $service->id,
            'name'        => $service->name,
            'cost'        => 0,
            'year'        => now()->year, 'month' => now()->month, 'due_day' => now()->day,
            'status'      => 'pending',
        ], $extra));
    }

    /** Плановая задача по БП: строка сметы плюс лог. */
    private function plannedLog(Service $service): BuhTaskLog
    {
        $estimate = Estimate::firstOrCreate(['client_id' => $this->client->id], ['total' => 0]);
        $item     = $estimate->items()->create([
            'service_id' => $service->id, 'type' => 'recurring',
            'name' => $service->name, 'periodicity' => $service->periodicity,
            'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 0,
        ]);

        return BuhTaskLog::create([
            'employee_id'      => $this->accountant->id,
            'client_id'        => $this->client->id,
            'estimate_item_id' => $item->id,
            'year'             => now()->year, 'month' => now()->month,
            'status'           => 'pending',
        ]);
    }

    private function spawnedFor(BuhAdhocTask|BuhTaskLog $source): ?BuhAdhocTask
    {
        return BuhAdhocTask::where('trigger_source_type', $source::class)
            ->where('trigger_source_id', $source->id)
            ->first();
    }

    /** Выполнили разовую задачу по родителю — дочерняя ушла тому же сотруднику и клиенту. */
    public function test_completing_adhoc_parent_spawns_child_task(): void
    {
        $child = $this->childService(days: 3);
        $task  = $this->adhoc($this->parentService($child));

        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.adhoc.complete', $task))
            ->assertOk()
            ->assertJsonPath('log.status', 'completed')
            ->assertJsonPath('spawned_name', $child->name);

        $spawned = $this->spawnedFor($task);

        $this->assertNotNull($spawned, 'Дочерняя задача должна создаться');
        $this->assertSame($child->id, $spawned->service_id);
        $this->assertSame($this->accountant->id, $spawned->employee_id);
        $this->assertSame($this->client->id, $spawned->client_id);
        $this->assertSame('pending', $spawned->status);
        $this->assertSame(0.0, (float) $spawned->cost);
        // Срок: день выполнения плюс срок в днях дочернего БП, дни календарные
        $this->assertSame(now()->addDays(3)->day, $spawned->due_day);
    }

    /** Плановая задача (повторяющийся БП) работает так же, как разовая. */
    public function test_completing_planned_parent_spawns_child_task(): void
    {
        $child = $this->childService();
        $log   = $this->plannedLog($this->parentService($child, [
            'periodicity' => 'Ежемесячно', 'start_day' => [5], 'deadline_days' => null,
        ]));

        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.logs.complete', $log))
            ->assertOk()
            ->assertJsonPath('log.status', 'completed');

        $this->assertNotNull($this->spawnedFor($log));
    }

    /** Задача с проверкой: на «выполнено» ничего не рождается, только на приёмке главбухом. */
    public function test_child_appears_only_after_review_is_approved(): void
    {
        $child = $this->childService();
        $task  = $this->adhoc($this->parentService($child), ['requires_review' => true]);

        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.adhoc.complete', $task))
            ->assertOk()
            ->assertJsonPath('log.status', 'review');

        $this->assertNull($this->spawnedFor($task), 'На проверке дочерней быть не должно');

        $this->actingAs($this->head, 'employee')
            ->postJson(route('buhtasks.adhoc.review-approve', $task))
            ->assertOk();

        $spawned = $this->spawnedFor($task);
        $this->assertNotNull($spawned);
        // Дочернюю получает исполнитель, а не принявший главбух
        $this->assertSame($this->accountant->id, $spawned->employee_id);
    }

    /** Сброс и повторное закрытие второй копии не дают. */
    public function test_reset_and_complete_again_does_not_duplicate(): void
    {
        $task = $this->adhoc($this->parentService($this->childService()));

        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.adhoc.complete', $task))->assertOk();
        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.adhoc.reset', $task))->assertOk();
        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.adhoc.complete', $task))->assertOk();

        $this->assertSame(1, BuhAdhocTask::where('trigger_source_type', $task::class)
            ->where('trigger_source_id', $task->id)->count());
    }

    /** Одна ступень: рождённая задача сама триггер не запускает, кольцо безопасно. */
    public function test_spawned_task_does_not_fire_its_own_trigger(): void
    {
        // Кольцо: А рождает Б, Б настроен рождать А
        $b = $this->childService();
        $a = $this->parentService($b);
        $b->update(['triggers_on_event' => true, 'event_child_service_id' => $a->id]);

        $task = $this->adhoc($a);
        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.adhoc.complete', $task))->assertOk();

        $spawned = $this->spawnedFor($task);
        $this->assertNotNull($spawned);

        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.adhoc.complete', $spawned))->assertOk();

        $this->assertNull($this->spawnedFor($spawned), 'Цепочка должна остановиться на первой ступени');
    }

    /** Дочерний БП выключили — задача не создаётся, но закрытие родителя проходит. */
    public function test_broken_child_does_not_break_completion(): void
    {
        $child = $this->childService();
        $task  = $this->adhoc($this->parentService($child));
        $child->update(['is_active' => false]);

        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.adhoc.complete', $task))
            ->assertOk()
            ->assertJsonPath('log.status', 'completed');

        $this->assertNull($this->spawnedFor($task));
        $this->assertSame('completed', $task->fresh()->status);
    }

    /** Тумблер выключен — ничего не рождается, как было до этой правки. */
    public function test_service_without_toggle_spawns_nothing(): void
    {
        $child   = $this->childService();
        $service = $this->parentService($child, ['triggers_on_event' => false]);
        $task    = $this->adhoc($service);

        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.adhoc.complete', $task))
            ->assertOk()
            ->assertJsonMissingPath('spawned_name');

        $this->assertNull($this->spawnedFor($task));
    }

    /** Задача помнит, от чего родилась: задачник отдаёт это на бейдж «по событию». */
    public function test_spawned_task_carries_its_origin(): void
    {
        $child = $this->childService();
        $task  = $this->adhoc($this->parentService($child), ['name' => 'Внутренняя передача клиента']);

        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.adhoc.complete', $task))->assertOk();

        $spawned = $this->spawnedFor($task);
        $this->assertSame('Внутренняя передача клиента', $spawned->trigger_source_name);

        $response = $this->actingAs($this->accountant, 'employee')->get('/buhtasks')->assertOk();
        $row = collect($response->viewData('tasks'))->firstWhere('uid', 'adhoc_' . $spawned->id);

        $this->assertNotNull($row, 'Дочерняя задача должна быть в списке исполнителя');
        $this->assertTrue($row['from_event']);
        $this->assertSame('Внутренняя передача клиента', $row['from_event_name']);
    }

    /** У родителя-плановой задачи в снимок идёт название строки сметы, а не БП. */
    public function test_origin_of_planned_parent_is_the_estimate_line(): void
    {
        $child   = $this->childService();
        $service = $this->parentService($child, [
            'periodicity' => 'Ежемесячно', 'start_day' => [5], 'deadline_days' => null,
        ]);
        $log = $this->plannedLog($service);

        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.logs.complete', $log))->assertOk();

        $this->assertSame($service->name, $this->spawnedFor($log)->trigger_source_name);
    }

    /** Обычная задача бейджа не получает. */
    public function test_ordinary_task_has_no_origin(): void
    {
        $task = $this->adhoc($this->childService());

        $response = $this->actingAs($this->accountant, 'employee')->get('/buhtasks')->assertOk();
        $row = collect($response->viewData('tasks'))->firstWhere('uid', 'adhoc_' . $task->id);

        $this->assertFalse($row['from_event']);
        $this->assertNull($row['from_event_name']);
    }

    /** Подпункты дочернего БП становятся чеклистом задачи, как при добавлении из каталога. */
    public function test_child_children_become_checklist(): void
    {
        $child = $this->childService();
        $child->children()->create(['name' => 'Собрать документы', 'cost' => 0, 'is_active' => true, 'sort_order' => 0]);
        $child->children()->create(['name' => 'Передать базу', 'cost' => 0, 'is_active' => true, 'sort_order' => 1]);

        $task = $this->adhoc($this->parentService($child));
        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.adhoc.complete', $task))->assertOk();

        $spawned = $this->spawnedFor($task);
        $this->assertCount(2, $spawned->checklist);
        $this->assertSame('Собрать документы', $spawned->checklist[0]['name']);
        $this->assertFalse($spawned->checklist[0]['done']);
    }
}
