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
 * Контроль поручений: вкладка «Я поручил» и адресат проверки.
 *
 * Раньше автор внеплановой задачи нигде не сохранялся — поручив задачу другому,
 * сотрудник терял её из виду, а принимал работу главбух клиента, даже если задачу
 * заводил кто-то другой. Теперь поручение принимает тот, кто поручил.
 */
class AssignedTasksTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $author;
    private Employee $doer;
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

        Periodicity::firstOrCreate(['name' => 'Ежемесячно'], ['kind' => 'monthly']);
        $module = Module::firstOrCreate(
            ['name' => 'buhtasks'],
            ['display_name' => 'БухЗадачник', 'is_active' => true],
        );
        $role = Role::firstOrCreate(['name' => Role::ACCOUNTANT], ['display_name' => 'Бухгалтер']);

        $make = fn (string $prefix) => Employee::create([
            'full_name' => 'Тест ' . $prefix, 'position' => $prefix,
            'email' => $prefix . '_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);

        $this->author = $make('author');
        $this->doer   = $make('doer');
        $this->head   = $make('head');

        foreach ([$this->author, $this->doer, $this->head] as $e) {
            $e->modules()->attach($module->id);
        }
    }

    private function client(?int $responsibleId): Client
    {
        $name = 'ТОО ' . uniqid();

        return Client::create([
            'name' => $name, 'inn' => strtoupper(substr(md5($name), 0, 12)),
            'responsible_employee_id' => $responsibleId, 'is_active' => true,
        ]);
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
        ], $attributes));
    }

    /** @return array<int, string> названия строк вкладки «Я поручил» */
    private function assignedNames(Employee $employee): array
    {
        $response = $this->actingAs($employee, 'employee')
            ->get(route('buhtasks.index'))
            ->assertOk();

        return array_column($response->viewData('assignedTasks'), 'name');
    }

    // === Вкладка «Я поручил» ===

    public function test_author_sees_task_assigned_to_someone_else(): void
    {
        $task = $this->adhoc();

        $this->assertContains($task->name, $this->assignedNames($this->author));
    }

    public function test_task_created_for_self_is_not_an_assignment(): void
    {
        $task = $this->adhoc(['employee_id' => $this->author->id]);

        $this->assertNotContains($task->name, $this->assignedNames($this->author));
    }

    public function test_other_employees_do_not_see_someone_elses_assignments(): void
    {
        $task = $this->adhoc();

        $this->assertNotContains($task->name, $this->assignedNames($this->head));
    }

    public function test_completed_assignment_stays_for_30_days_then_disappears(): void
    {
        $fresh = $this->adhoc(['status' => 'completed', 'completed_at' => now()->subDays(29)]);
        $old   = $this->adhoc(['status' => 'completed', 'completed_at' => now()->subDays(31)]);

        $names = $this->assignedNames($this->author);

        $this->assertContains($fresh->name, $names);
        $this->assertNotContains($old->name, $names);
    }

    public function test_alert_count_covers_overdue_and_not_started(): void
    {
        $this->adhoc(['status' => 'pending']);                        // не начата
        $this->adhoc(['status' => 'running', 'started_at' => now()]); // в работе — не в счёт

        $response = $this->actingAs($this->author, 'employee')
            ->get(route('buhtasks.index'))
            ->assertOk();

        $this->assertSame(1, $response->viewData('assignedAlertCount'));
    }

    public function test_created_assignment_is_returned_for_the_tab_right_away(): void
    {
        $response = $this->actingAs($this->author, 'employee')
            ->postJson(route('buhtasks.adhoc.store'), [
                'employee_id' => $this->doer->id,
                'name'        => 'Справка в банк',
                'due_date'    => now()->addDays(3)->toDateString(),
            ])
            ->assertOk();

        $response->assertJsonPath('mine', false);
        $response->assertJsonPath('assigned.name', 'Справка в банк');
        $response->assertJsonPath('assigned.doer_name', $this->doer->full_name);

        $this->assertSame($this->author->id, BuhAdhocTask::latest('id')->first()->created_by);
    }

    // === Проверка уходит поручителю ===

    public function test_review_goes_to_the_author_not_the_client_head(): void
    {
        // Клиент ведёт главбух, но задачу поручил другой сотрудник — принимать ему.
        $client = $this->client($this->head->id);
        $task   = $this->adhoc(['client_id' => $client->id, 'requires_review' => true]);

        $this->actingAs($this->doer, 'employee')
            ->post(route('buhtasks.adhoc.complete', $task->id))
            ->assertOk();

        $this->assertSame('review', $task->fresh()->status);

        // Главбух клиента к чужому поручению не допущен
        $this->actingAs($this->head, 'employee')
            ->post(route('buhtasks.adhoc.review-approve', $task->id))
            ->assertForbidden();

        // А автор — принимает
        $this->actingAs($this->author, 'employee')
            ->post(route('buhtasks.adhoc.review-approve', $task->id))
            ->assertOk();

        $this->assertSame('completed', $task->fresh()->status);
    }

    public function test_assignment_goes_to_the_author_even_without_the_review_flag(): void
    {
        // Тумблера «на проверку» у поручения нет: выполнил — значит сдал, закрывает автор.
        $task = $this->adhoc(['requires_review' => false]);

        $this->actingAs($this->doer, 'employee')
            ->post(route('buhtasks.adhoc.complete', $task->id))
            ->assertOk();

        $this->assertSame('review', $task->fresh()->status);

        $this->actingAs($this->author, 'employee')
            ->post(route('buhtasks.adhoc.review-approve', $task->id))
            ->assertOk();

        $this->assertSame('completed', $task->fresh()->status);
    }

    public function test_new_assignment_is_created_with_review_required(): void
    {
        $this->actingAs($this->author, 'employee')
            ->post(route('buhtasks.adhoc.store'), [
                'employee_id' => $this->doer->id,
                'name'        => 'Сверка по банку',
                'due_date'    => now()->toDateString(),
            ])
            ->assertOk();

        $this->assertTrue((bool) BuhAdhocTask::latest('id')->first()->requires_review);
    }

    public function test_task_for_self_still_closes_without_the_review_flag(): void
    {
        // Задачу себе приёмка не касается: тумблер выключен — закрывается сразу.
        $task = $this->adhoc(['employee_id' => $this->author->id, 'requires_review' => false]);

        $this->actingAs($this->author, 'employee')
            ->post(route('buhtasks.adhoc.complete', $task->id))
            ->assertOk();

        $this->assertSame('completed', $task->fresh()->status);
    }

    public function test_author_sees_his_assignment_awaiting_review_in_the_task_list(): void
    {
        $task = $this->adhoc(['requires_review' => true, 'status' => 'review', 'review_started_at' => now()]);

        $response = $this->actingAs($this->author, 'employee')
            ->get(route('buhtasks.index'))
            ->assertOk();

        $uids = array_column($response->viewData('tasks'), 'uid');
        $this->assertContains('review_adhoc_' . $task->id, $uids);
    }

    public function test_author_can_send_assignment_back_for_rework(): void
    {
        $task = $this->adhoc(['requires_review' => true, 'status' => 'review', 'review_started_at' => now()]);

        $this->actingAs($this->author, 'employee')
            ->post(route('buhtasks.adhoc.review-reject', $task->id), ['comment' => 'не та печать'])
            ->assertOk();

        $task->refresh();
        $this->assertSame('rework', $task->status);
        $this->assertSame('не та печать', $task->review_comment);
    }

    public function test_review_falls_back_to_client_head_when_author_is_unknown(): void
    {
        // Задача до появления created_by: адресат прежний — главбух клиента.
        $client = $this->client($this->head->id);
        $task   = $this->adhoc(['created_by' => null, 'client_id' => $client->id, 'requires_review' => true]);

        $this->actingAs($this->doer, 'employee')
            ->post(route('buhtasks.adhoc.complete', $task->id))
            ->assertOk();

        $this->assertSame('review', $task->fresh()->status);

        $this->actingAs($this->head, 'employee')
            ->post(route('buhtasks.adhoc.review-approve', $task->id))
            ->assertOk();
    }

    public function test_task_closes_immediately_when_there_is_nobody_to_review(): void
    {
        // Ни автора, ни клиента — принимать некому, иначе задача зависла бы в review.
        $task = $this->adhoc(['created_by' => null, 'client_id' => null, 'requires_review' => true]);

        $this->actingAs($this->doer, 'employee')
            ->post(route('buhtasks.adhoc.complete', $task->id))
            ->assertOk();

        $this->assertSame('completed', $task->fresh()->status);
    }

    // === Отзыв поручения ===

    public function test_author_can_revoke_assignment_before_it_is_started(): void
    {
        $task = $this->adhoc();

        $this->actingAs($this->author, 'employee')
            ->post(route('buhtasks.adhoc.destroy', $task->id))
            ->assertOk();

        $this->assertNull(BuhAdhocTask::find($task->id));
    }

    public function test_author_cannot_revoke_assignment_already_in_progress(): void
    {
        $task = $this->adhoc(['status' => 'running', 'started_at' => now(), 'resumed_at' => now()]);

        $this->actingAs($this->author, 'employee')
            ->post(route('buhtasks.adhoc.destroy', $task->id))
            ->assertStatus(422);

        $this->assertNotNull(BuhAdhocTask::find($task->id));
    }

    public function test_outsider_cannot_revoke_assignment(): void
    {
        $task = $this->adhoc();

        $this->actingAs($this->head, 'employee')
            ->post(route('buhtasks.adhoc.destroy', $task->id))
            ->assertForbidden();

        $this->assertNotNull(BuhAdhocTask::find($task->id));
    }

    // === Исполнитель видит, кто поручил ===

    public function test_doer_sees_who_assigned_the_task(): void
    {
        $task = $this->adhoc();

        $response = $this->actingAs($this->doer, 'employee')
            ->get(route('buhtasks.index'))
            ->assertOk();

        $row = collect($response->viewData('tasks'))->firstWhere('uid', 'adhoc_' . $task->id);

        $this->assertNotNull($row);
        $this->assertSame($this->author->full_name, $row['assigned_by_name']);
    }
}
