<?php

namespace Tests\Feature;

use App\Models\BuhAdhocTask;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Module;
use App\Models\Periodicity;
use App\Models\Role;
use App\Models\Service;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Один таймер на сотрудника. Раньше «Старт» можно было нажать на нескольких задачах,
 * и время шло по всем сразу. Теперь запуск новой задачи ставит прежнюю на паузу.
 */
class SingleRunningTimerTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $accountant;
    private Employee $colleague;
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
            'full_name' => 'Тест ' . $prefix, 'position' => 'Бухгалтер',
            'email' => $prefix . '_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
        $this->accountant = $make('acc');
        $this->colleague  = $make('colleague');
        $this->accountant->modules()->attach($module->id);
        $this->colleague->modules()->attach($module->id);

        $this->client = Client::create([
            'name' => 'ТОО Таймер Тест', 'inn' => 'TIMER0000000',
            'responsible_employee_id' => $this->accountant->id,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function item(?EstimateItem $parent = null): EstimateItem
    {
        $service = Service::create([
            'name' => 'Тест услуга ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_day' => [5], 'is_active' => true,
        ]);
        $estimate = Estimate::firstOrCreate(['client_id' => $this->client->id], ['total' => 0]);

        return $estimate->items()->create([
            'service_id' => $service->id, 'type' => 'recurring', 'parent_id' => $parent?->id,
            'name' => $service->name, 'periodicity' => 'Ежемесячно',
            'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 0,
        ]);
    }

    private function log(?EstimateItem $item = null, ?Employee $doer = null): BuhTaskLog
    {
        return BuhTaskLog::create([
            'employee_id' => ($doer ?? $this->accountant)->id,
            'client_id' => $this->client->id,
            'estimate_item_id' => ($item ?? $this->item())->id,
            'year' => now()->year, 'month' => now()->month,
            'status' => 'pending', 'paused_seconds' => 0,
        ]);
    }

    private function adhoc(): BuhAdhocTask
    {
        return BuhAdhocTask::create([
            'employee_id' => $this->accountant->id, 'created_by' => $this->accountant->id,
            'name' => 'Внеплановая ' . uniqid(), 'cost' => 0,
            'year' => now()->year, 'month' => now()->month,
            'status' => 'pending', 'paused_seconds' => 0,
        ]);
    }

    public function test_starting_second_task_pauses_first_and_keeps_its_time(): void
    {
        $first  = $this->log();
        $second = $this->log();

        Carbon::setTestNow('2026-10-05 10:00:00');
        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.logs.start', $first))->assertOk();

        Carbon::setTestNow('2026-10-05 10:07:00');
        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.logs.start', $second))
            ->assertOk()
            ->assertJsonPath('log.status', 'running')
            ->assertJsonPath('paused.0.type', 'planned')
            ->assertJsonPath('paused.0.log.id', $first->id)
            ->assertJsonPath('paused.0.log.status', 'paused');

        $first->refresh();
        $this->assertSame('paused', $first->status);
        $this->assertSame(420, $first->paused_seconds); // 7 минут работы не потерялись
        $this->assertSame('running', $second->fresh()->status);
    }

    /** Плановые и внеплановые считаются вместе: таймер один на всё. */
    public function test_planned_and_adhoc_share_one_timer(): void
    {
        $planned = $this->log();
        $adhoc   = $this->adhoc();

        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.logs.start', $planned))->assertOk();
        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.adhoc.start', $adhoc))
            ->assertOk()
            ->assertJsonPath('paused.0.type', 'planned');

        $this->assertSame('paused', $planned->fresh()->status);

        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.logs.start', $planned))
            ->assertOk()
            ->assertJsonPath('paused.0.type', 'adhoc');

        $this->assertSame('paused', $adhoc->fresh()->status);
        $this->assertSame('running', $planned->fresh()->status);
    }

    /** Галочка подпункта шлёт старт на миг. Задачу, над которой работают, она не останавливает. */
    public function test_subtask_checkbox_does_not_pause_running_task(): void
    {
        $parentItem = $this->item();
        $parent = $this->log($parentItem);
        $child  = $this->log($this->item($parentItem));

        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.logs.start', $parent))->assertOk();
        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.logs.start', $child))
            ->assertOk()
            ->assertJsonCount(0, 'paused');

        $this->assertSame('running', $parent->fresh()->status);
    }

    /** Повторный старт той же задачи (двойной клик) ничего не ломает. */
    public function test_double_start_of_same_task_keeps_it_running(): void
    {
        $log = $this->log();

        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.logs.start', $log))->assertOk();
        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.logs.start', $log))
            ->assertOk()
            ->assertJsonPath('log.status', 'running')
            ->assertJsonCount(0, 'paused');
    }

    /** Чужие таймеры не трогаем: правило действует внутри одного сотрудника. */
    public function test_other_employee_timer_is_untouched(): void
    {
        $mine   = $this->log();
        $theirs = $this->log(doer: $this->colleague);

        $this->actingAs($this->colleague, 'employee')
            ->postJson(route('buhtasks.logs.start', $theirs))->assertOk();
        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.logs.start', $mine))
            ->assertOk()
            ->assertJsonCount(0, 'paused');

        $this->assertSame('running', $theirs->fresh()->status);
    }
}
