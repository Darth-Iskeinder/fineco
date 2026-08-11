<?php

namespace Tests\Feature;

use App\Models\BuhAdhocTask;
use App\Models\Employee;
use App\Models\Module;
use App\Models\Periodicity;
use App\Models\Role;
use App\Models\Service;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Внеплановая задача из каталога: описание и подпункты берутся у услуги.
 *
 * Раньше переносилось только название — описание приходилось перепечатывать, а чек-листа
 * не было вовсе, хотя у самого БП он есть. Теперь и то, и другое копируется СНИМКОМ,
 * и задача не закрывается, пока подпункты не отмечены — как у плановых.
 */
class AdhocCatalogChecklistTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $employee;

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

        $this->employee = Employee::create([
            'full_name' => 'Тест Бухгалтер', 'position' => 'Бухгалтер',
            'email' => 'adhoc_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
        $this->employee->modules()->attach($module->id);
    }

    /** Услуга каталога с описанием и подпунктами. */
    private function service(?string $description = 'Описание из каталога', array $items = ['Первый пункт', 'Второй пункт']): Service
    {
        $service = Service::create([
            'name' => 'Тест услуга ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_day' => [5], 'is_active' => true, 'description' => $description,
        ]);

        foreach ($items as $i => $name) {
            Service::create([
                'name' => $name, 'parent_id' => $service->id, 'periodicity' => 'Ежемесячно',
                'is_active' => true, 'sort_order' => $i,
            ]);
        }

        return $service->fresh();
    }

    private function createFromCatalog(Service $service, array $extra = []): BuhAdhocTask
    {
        $this->actingAs($this->employee, 'employee')
            ->postJson(route('buhtasks.adhoc.store'), array_merge([
                'employee_id' => $this->employee->id,
                'service_id'  => $service->id,
                'name'        => $service->name,
                'due_date'    => now()->addDays(3)->toDateString(),
            ], $extra))
            ->assertOk();

        return BuhAdhocTask::latest('id')->first();
    }

    // === Копирование из каталога ===

    public function test_description_and_checklist_come_from_the_service(): void
    {
        $service = $this->service();
        $task    = $this->createFromCatalog($service);

        $this->assertSame('Описание из каталога', $task->description);
        $this->assertSame(
            ['Первый пункт', 'Второй пункт'],
            array_column($task->checklist, 'name'),
        );
        $this->assertSame([false, false], array_column($task->checklist, 'done'));
    }

    public function test_description_sent_from_the_form_is_ignored_for_catalog_tasks(): void
    {
        // В форме поле закрыто для правки, но подменить запрос никто не мешает
        $service = $this->service();
        $task    = $this->createFromCatalog($service, ['description' => 'подсунутое описание']);

        $this->assertSame('Описание из каталога', $task->description);
    }

    public function test_clarification_is_saved_alongside_catalog_description(): void
    {
        $service = $this->service();
        $task    = $this->createFromCatalog($service, ['clarification' => 'за второй квартал']);

        $this->assertSame('Описание из каталога', $task->description);
        $this->assertSame('за второй квартал', $task->clarification);
    }

    public function test_editing_the_service_later_does_not_change_created_tasks(): void
    {
        $service = $this->service();
        $task    = $this->createFromCatalog($service);

        $service->update(['description' => 'переписали описание']);
        $service->children()->first()->update(['name' => 'переименовали пункт']);

        $task->refresh();
        $this->assertSame('Описание из каталога', $task->description);
        $this->assertSame('Первый пункт', $task->checklist[0]['name']);
    }

    public function test_own_task_keeps_its_manual_description_and_has_no_checklist(): void
    {
        $this->actingAs($this->employee, 'employee')
            ->postJson(route('buhtasks.adhoc.store'), [
                'employee_id' => $this->employee->id,
                'name'        => 'Своя задача',
                'description' => 'моё описание',
                'due_date'    => now()->addDays(3)->toDateString(),
            ])
            ->assertOk();

        $task = BuhAdhocTask::latest('id')->first();

        $this->assertSame('моё описание', $task->description);
        $this->assertNull($task->checklist);
    }

    public function test_service_without_children_gives_no_checklist(): void
    {
        $task = $this->createFromCatalog($this->service(items: []));

        $this->assertNull($task->checklist);
    }

    // === Отметки и блокировка закрытия ===

    public function test_task_cannot_be_completed_until_all_items_are_checked(): void
    {
        $task = $this->createFromCatalog($this->service());

        $this->actingAs($this->employee, 'employee')
            ->postJson(route('buhtasks.adhoc.complete', $task->id))
            ->assertStatus(422)
            ->assertJsonPath('requires_checklist', true);

        $this->assertSame('pending', $task->fresh()->status);
    }

    public function test_task_closes_once_every_item_is_checked(): void
    {
        $task = $this->createFromCatalog($this->service());

        foreach ([0, 1] as $index) {
            $this->actingAs($this->employee, 'employee')
                ->postJson(route('buhtasks.adhoc.checklist', $task->id), ['index' => $index, 'done' => true])
                ->assertOk();
        }

        $this->actingAs($this->employee, 'employee')
            ->postJson(route('buhtasks.adhoc.complete', $task->id))
            ->assertOk();

        $this->assertSame('completed', $task->fresh()->status);
    }

    public function test_unchecking_an_item_blocks_closing_again(): void
    {
        $task = $this->createFromCatalog($this->service());
        $task->update(['checklist' => [
            ['name' => 'Первый пункт', 'done' => true],
            ['name' => 'Второй пункт', 'done' => true],
        ]]);

        $this->actingAs($this->employee, 'employee')
            ->postJson(route('buhtasks.adhoc.checklist', $task->id), ['index' => 1, 'done' => false])
            ->assertOk();

        $this->actingAs($this->employee, 'employee')
            ->postJson(route('buhtasks.adhoc.complete', $task->id))
            ->assertStatus(422)
            ->assertJsonPath('requires_checklist', true);
    }

    public function test_checklist_is_returned_to_the_task_list(): void
    {
        $task = $this->createFromCatalog($this->service());

        $response = $this->actingAs($this->employee, 'employee')
            ->get(route('buhtasks.index'))
            ->assertOk();

        $row = collect($response->viewData('tasks'))->firstWhere('uid', 'adhoc_' . $task->id);

        $this->assertNotNull($row);
        $this->assertSame('Описание из каталога', $row['description']);
        $this->assertCount(2, $row['children']);
        $this->assertSame('pending', $row['children'][0]['status']);
    }

    public function test_someone_else_cannot_tick_my_checklist(): void
    {
        $task = $this->createFromCatalog($this->service());

        $outsider = Employee::create([
            'full_name' => 'Чужой', 'position' => 'x',
            'email' => 'out_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $this->employee->role_id, 'status' => Employee::STATUS_ACTIVE,
        ]);
        $outsider->modules()->attach(Module::where('name', 'buhtasks')->first()->id);

        $this->actingAs($outsider, 'employee')
            ->postJson(route('buhtasks.adhoc.checklist', $task->id), ['index' => 0, 'done' => true])
            ->assertForbidden();

        $this->assertFalse($task->fresh()->checklist[0]['done']);
    }
}
