<?php

namespace Tests\Feature;

use App\Models\BuhAdhocTask;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Module;
use App\Models\Role;
use App\Models\Service;
use App\Models\TaskReminder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Смена ответственного у клиента забирает с собой незакрытую работу.
 *
 * Раньше менялось только поле в карточке: смета оставалась на прежнем сотруднике
 * (задачи идут по `estimate_items.assignee_id`), и после увольнения работа зависала
 * на том, кто уже не работает.
 *
 * Идёт по боевому mysql-соединению в транзакции с откатом — как остальные тесты
 * этого проекта (pdo_sqlite в среде нет).
 */
class ClientResponsibleTransferTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $admin;
    private Employee $oldHead;
    private Employee $newHead;
    private Employee $accountant;
    private Client $client;
    private Estimate $estimate;
    private Service $service;

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

        $adminRole = Role::firstOrCreate(['name' => Role::ADMIN], ['display_name' => 'Администратор']);
        $headRole  = Role::firstOrCreate(['name' => Role::HEAD_ACCOUNTANT], ['display_name' => 'Главный бухгалтер']);
        $accRole   = Role::firstOrCreate(['name' => Role::ACCOUNTANT], ['display_name' => 'Бухгалтер']);

        $this->admin      = $this->employee('Админ', $adminRole->id);
        $this->oldHead    = $this->employee('Прежний главбух', $headRole->id);
        $this->newHead    = $this->employee('Новый главбух', $headRole->id);
        $this->accountant = $this->employee('Бухгалтер Аня', $accRole->id);

        $this->admin->modules()->attach(
            Module::firstOrCreate(['name' => 'clients'], ['display_name' => 'Клиенты', 'is_active' => true])->id
        );

        $this->service = Service::create([
            'name' => 'Отчёт ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_day' => [20], 'is_active' => true,
        ]);

        $this->client = Client::create([
            'name' => 'ОсОО Крепость ' . uniqid(),
            'inn'  => (string) random_int(100000000000, 999999999999),
            'responsible_employee_id' => $this->oldHead->id,
        ]);

        $this->estimate = Estimate::create(['client_id' => $this->client->id, 'total' => 0]);
    }

    private function employee(string $name, int $roleId): Employee
    {
        return Employee::create([
            'full_name' => $name, 'position' => $name,
            'email' => uniqid('resp_') . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $roleId, 'status' => Employee::STATUS_ACTIVE,
        ]);
    }

    private function item(?int $assigneeId, ?int $parentId = null): EstimateItem
    {
        return EstimateItem::create([
            'estimate_id' => $this->estimate->id,
            'parent_id'   => $parentId,
            'service_id'  => $this->service->id,
            'assignee_id' => $assigneeId,
            'type'        => 'recurring',
            'name'        => $this->service->name,
            'periodicity' => 'Ежемесячно',
            'cost'        => 100,
            'quantity'    => 1,
            'total'       => 100,
            'sort_order'  => 0,
        ]);
    }

    private function reminder(int $employeeId, string $status, string $dueDate): TaskReminder
    {
        return TaskReminder::create([
            'employee_id' => $employeeId,
            'client_id'   => $this->client->id,
            'service_id'  => $this->service->id,
            'name'        => $this->service->name,
            'periodicity' => 'Ежемесячно',
            'due_date'    => $dueDate,
            'status'      => $status,
        ]);
    }

    private function adhoc(int $employeeId, string $status): BuhAdhocTask
    {
        return BuhAdhocTask::create([
            'employee_id' => $employeeId,
            'client_id'   => $this->client->id,
            'name'        => 'Внеплановая ' . uniqid(),
            'year'        => 2026, 'month' => 8, 'due_day' => 20,
            'status'      => $status,
            'assign_seen_at' => now(),
            'paused_seconds' => 0,
        ]);
    }

    /** Смена ответственного из карточки клиента переносит на нового позиции сметы. */
    public function test_estimate_items_of_previous_responsible_move_to_the_new_one(): void
    {
        $mine    = $this->item($this->oldHead->id);
        $hers    = $this->item($this->accountant->id);
        $nobody  = $this->item(null);

        $this->changeResponsible($this->newHead->id);

        $this->assertSame($this->newHead->id, $mine->fresh()->assignee_id, 'Позиция прежнего ответственного должна перейти');
        $this->assertSame($this->newHead->id, $nobody->fresh()->assignee_id, 'Позиция без исполнителя должна перейти');
        $this->assertSame($this->accountant->id, $hers->fresh()->assignee_id, 'Позицию бухгалтера трогать нельзя');
    }

    /** Подпункты позиции исполнителя не имеют — их не трогаем. */
    public function test_child_items_are_left_alone(): void
    {
        $parent = $this->item($this->oldHead->id);
        $child  = $this->item(null, $parent->id);

        $this->changeResponsible($this->newHead->id);

        $this->assertNull($child->fresh()->assignee_id);
    }

    /** Незакрытые напоминания переходят, выполненные остаются историей прежнего. */
    public function test_pending_reminders_move_and_done_stay(): void
    {
        $pending = $this->reminder($this->oldHead->id, TaskReminder::STATUS_PENDING, '2026-09-20');
        $done    = $this->reminder($this->oldHead->id, TaskReminder::STATUS_DONE, '2026-08-20');

        $this->changeResponsible($this->newHead->id);

        $this->assertSame($this->newHead->id, $pending->fresh()->employee_id);
        $this->assertSame($this->oldHead->id, $done->fresh()->employee_id);
    }

    /**
     * Напоминание, которое у нового исполнителя уже есть, не дублируется:
     * ключ (сотрудник, клиент, БП, срок) уникален.
     */
    public function test_duplicate_reminder_is_removed_instead_of_moved(): void
    {
        $old = $this->reminder($this->oldHead->id, TaskReminder::STATUS_PENDING, '2026-09-20');
        $new = $this->reminder($this->newHead->id, TaskReminder::STATUS_PENDING, '2026-09-20');

        $this->changeResponsible($this->newHead->id);

        $this->assertNull($old->fresh(), 'Дубликат должен уйти');
        $this->assertNotNull($new->fresh(), 'Напоминание нового исполнителя остаётся');
    }

    /** Незакрытые внеплановые задачи переходят и подсвечиваются как новые. */
    public function test_open_adhoc_tasks_move(): void
    {
        $open      = $this->adhoc($this->oldHead->id, 'running');
        $review    = $this->adhoc($this->oldHead->id, 'review');
        $completed = $this->adhoc($this->oldHead->id, 'completed');

        $this->changeResponsible($this->newHead->id);

        $this->assertSame($this->newHead->id, $open->fresh()->employee_id);
        $this->assertNull($open->fresh()->assign_seen_at, 'Для нового исполнителя задача не просмотрена');
        $this->assertSame($this->oldHead->id, $review->fresh()->employee_id, 'Сданное на проверку не переносим');
        $this->assertSame($this->oldHead->id, $completed->fresh()->employee_id, 'Историю не переписываем');
    }

    /** История выполнения остаётся за тем, кто работал. */
    public function test_task_logs_are_not_rewritten(): void
    {
        $item = $this->item($this->oldHead->id);
        $log  = BuhTaskLog::create([
            'employee_id'      => $this->oldHead->id,
            'client_id'        => $this->client->id,
            'estimate_item_id' => $item->id,
            'year' => 2026, 'month' => 8,
            'status' => 'completed', 'completed_at' => now(), 'paused_seconds' => 0,
        ]);

        $this->changeResponsible($this->newHead->id);

        $this->assertSame($this->oldHead->id, $log->fresh()->employee_id);
    }

    /** Ответственного сняли: переносить некому, всё остаётся как было. */
    public function test_clearing_the_responsible_moves_nothing(): void
    {
        $item     = $this->item($this->oldHead->id);
        $reminder = $this->reminder($this->oldHead->id, TaskReminder::STATUS_PENDING, '2026-09-20');

        $this->changeResponsible(null);

        $this->assertNull($this->client->fresh()->responsible_employee_id);
        $this->assertSame($this->oldHead->id, $item->fresh()->assignee_id);
        $this->assertSame($this->oldHead->id, $reminder->fresh()->employee_id);
    }

    /** Правка других полей карточки ничего не переносит. */
    public function test_saving_without_changing_the_responsible_moves_nothing(): void
    {
        $hers = $this->item($this->accountant->id);

        $this->actingAs($this->admin, 'employee')
            ->putJson('/clients/' . $this->client->id, [
                'name' => 'ОсОО Крепость переименована',
                'inn'  => $this->client->inn,
                'responsible_employee_id' => $this->oldHead->id,
                'is_active' => 1,
            ])
            ->assertOk();

        $this->assertSame($this->accountant->id, $hers->fresh()->assignee_id);
    }

    /** Смена ответственного в карточке (секция «Договор») работает так же. */
    public function test_section_update_transfers_too(): void
    {
        $item = $this->item($this->oldHead->id);

        $this->actingAs($this->admin, 'employee')
            ->patchJson('/clients/' . $this->client->id, [
                'section' => 'contract',
                'responsible_employee_id' => $this->newHead->id,
            ])
            ->assertOk();

        $this->assertSame($this->newHead->id, $item->fresh()->assignee_id);
    }

    /** Окно подтверждения считает те же цифры, что потом переедут. */
    public function test_preview_counts_what_will_move(): void
    {
        $this->item($this->oldHead->id);
        $this->item(null);
        $this->item($this->accountant->id);
        $this->reminder($this->oldHead->id, TaskReminder::STATUS_PENDING, '2026-09-20');
        $this->reminder($this->oldHead->id, TaskReminder::STATUS_DONE, '2026-08-20');
        $this->adhoc($this->oldHead->id, 'running');

        $data = $this->actingAs($this->admin, 'employee')
            ->getJson('/clients/' . $this->client->id . '/responsible-preview?employee_id=' . $this->newHead->id)
            ->assertOk()
            ->json();

        $this->assertTrue($data['changed']);
        $this->assertSame($this->oldHead->full_name, $data['from']['name']);
        $this->assertSame($this->newHead->full_name, $data['to']['name']);
        $this->assertSame(2, $data['items'], 'Позиция прежнего и позиция без исполнителя');
        $this->assertSame(1, $data['reminders'], 'Только незакрытое');
        $this->assertSame(1, $data['adhoc']);
        $this->assertSame([['name' => $this->accountant->full_name, 'count' => 1]], $data['stays']);
        $this->assertTrue($data['to_can_assign'], 'Главбух сможет назначать исполнителей');
    }

    /** Цифры предпросмотра сходятся с тем, что делает сам перенос. */
    public function test_preview_matches_the_transfer(): void
    {
        $this->item($this->oldHead->id);
        $this->item(null);
        $this->reminder($this->oldHead->id, TaskReminder::STATUS_PENDING, '2026-09-20');

        $preview = $this->actingAs($this->admin, 'employee')
            ->getJson('/clients/' . $this->client->id . '/responsible-preview?employee_id=' . $this->newHead->id)
            ->assertOk()
            ->json();

        $this->changeResponsible($this->newHead->id);

        $moved = EstimateItem::whereIn('estimate_id', [$this->estimate->id])
            ->whereNull('parent_id')
            ->where('assignee_id', $this->newHead->id)
            ->count();

        $this->assertSame($preview['items'], $moved);
        $this->assertSame(
            $preview['reminders'],
            TaskReminder::where('client_id', $this->client->id)->where('employee_id', $this->newHead->id)->count(),
        );
    }

    /** Новый ответственный не главбух — раздавать БП в смете он не сможет, окно предупредит. */
    public function test_preview_warns_when_the_new_one_cannot_assign(): void
    {
        $data = $this->actingAs($this->admin, 'employee')
            ->getJson('/clients/' . $this->client->id . '/responsible-preview?employee_id=' . $this->accountant->id)
            ->assertOk()
            ->json();

        $this->assertFalse($data['to_can_assign']);
    }

    /** Тот же сотрудник — окну нечего показывать. */
    public function test_preview_reports_no_change(): void
    {
        $data = $this->actingAs($this->admin, 'employee')
            ->getJson('/clients/' . $this->client->id . '/responsible-preview?employee_id=' . $this->oldHead->id)
            ->assertOk()
            ->json();

        $this->assertFalse($data['changed']);
        $this->assertSame(0, $data['items']);
    }

    /** Предпросмотр чужого клиента недоступен, как и сама карточка. */
    public function test_preview_is_closed_for_a_foreign_client(): void
    {
        $this->actingAs($this->accountant, 'employee')
            ->getJson('/clients/' . $this->client->id . '/responsible-preview?employee_id=' . $this->newHead->id)
            ->assertForbidden();
    }

    private function changeResponsible(?int $employeeId): void
    {
        $this->actingAs($this->admin, 'employee')
            ->putJson('/clients/' . $this->client->id, [
                'name' => $this->client->name,
                'inn'  => $this->client->inn,
                'responsible_employee_id' => $employeeId,
                'is_active' => 1,
            ])
            ->assertOk();
    }
}
