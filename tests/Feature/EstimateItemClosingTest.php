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
use App\Models\TaskReminder;
use App\Models\TaxSystem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Клиент сменил режим налогообложения: старые БП заканчиваются, новые начинаются.
 *
 * Раньше выключение БП в смете физически удаляло позицию, а вместе с ней каскадом
 * уходили все отметки о выполнении. Теперь позиция с историей закрывается датой:
 * новых задач по ней нет, история и незакрытые хвосты прошлых месяцев остаются.
 *
 * По боевому mysql в транзакции: страница сметы поднимает слишком много связей для sqlite.
 */
class EstimateItemClosingTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $admin;

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

        $role   = Role::firstOrCreate(['name' => Role::ADMIN], ['display_name' => 'Администратор']);
        $module = Module::firstOrCreate(['name' => 'clients'], ['display_name' => 'Клиенты', 'is_active' => true]);

        $this->admin = Employee::create([
            'full_name' => 'Тест Админ', 'position' => 'Админ',
            'email' => 'closing_admin_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
        $this->admin->modules()->attach($module->id);
    }

    private function client(): Client
    {
        return Client::create([
            'name' => 'ОсОО Переход ' . uniqid(),
            'inn' => strtoupper(substr(md5(uniqid()), 0, 12)),
            'responsible_employee_id' => $this->admin->id,
            'service_start_date' => CarbonImmutable::now()->subYear()->toDateString(),
        ]);
    }

    /** БП, который сам клиенту не подтягивается: в смету попадает только явным включением. */
    private function service(): Service
    {
        return Service::create([
            'name' => 'БП режима ' . uniqid(),
            'periodicity' => 'Ежемесячно',
            'start_day' => [15],
            'due_day' => 15,
            'is_active' => true,
        ]);
    }

    /** Позиция сметы, как её создаёт сохранение: с датой начала задач в прошлом. */
    private function item(Client $client, Service $service): EstimateItem
    {
        $estimate = Estimate::firstOrCreate(['client_id' => $client->id], ['total' => 0]);
        // Месяц создания сметы холостой: без этого задач в текущем месяце не будет вовсе.
        $estimate->forceFill(['created_at' => CarbonImmutable::now()->subYear()])->saveQuietly();

        return $estimate->items()->create([
            'service_id'       => $service->id,
            'assignee_id'      => $this->admin->id,
            'type'             => 'recurring',
            'name'             => $service->name,
            'periodicity'      => $service->periodicity,
            'due_day'          => $service->due_day,
            'cost'             => 1000,
            'quantity'         => 1,
            'total'            => 1000,
            'sort_order'       => 0,
            'tasks_start_from' => CarbonImmutable::now()->subMonths(6)->startOfMonth()->toDateString(),
        ]);
    }

    private function log(Client $client, EstimateItem $item, CarbonImmutable $month): BuhTaskLog
    {
        return BuhTaskLog::create([
            'employee_id'      => $this->admin->id,
            'client_id'        => $client->id,
            'estimate_item_id' => $item->id,
            'year'             => $month->year,
            'month'            => $month->month,
            'status'           => 'completed',
            'completed_at'     => $month->day(15),
        ]);
    }

    private function saveEstimate(Client $client, array $payload = [])
    {
        return $this->actingAs($this->admin, 'employee')
            ->postJson(route('clients.estimate.save', $client), array_merge([
                'tariff_bps' => [],
                'extras'     => [],
            ], $payload));
    }

    /** Выключили БП, по которому есть история: позиция остаётся, но закрывается датой. */
    public function test_item_with_history_is_closed_not_deleted(): void
    {
        $client  = $this->client();
        $service = $this->service();
        $item    = $this->item($client, $service);
        $log     = $this->log($client, $item, CarbonImmutable::now()->subMonth());

        $this->saveEstimate($client)->assertOk();

        $fresh = EstimateItem::find($item->id);

        $this->assertNotNull($fresh, 'позиция с историей не должна удаляться');
        $this->assertSame(
            CarbonImmutable::now()->endOfMonth()->toDateString(),
            $fresh->tasks_end_at->toDateString(),
            'по умолчанию закрываем концом текущего месяца',
        );
        $this->assertNotNull(BuhTaskLog::find($log->id), 'история выполнения должна остаться');
    }

    /** Выключили БП, по которому истории нет: позиция удаляется, как и раньше. */
    public function test_item_without_history_is_deleted(): void
    {
        $client  = $this->client();
        $service = $this->service();
        $item    = $this->item($client, $service);

        $this->saveEstimate($client)->assertOk();

        $this->assertNull(EstimateItem::find($item->id), 'терять нечего — позицию удаляем');
    }

    /** Закрытая позиция уходит из тарифного блока в «Завершённые», тумблер к ней не липнет. */
    public function test_closed_item_shows_in_its_own_block(): void
    {
        $client  = $this->client();
        $service = $this->service();
        $item    = $this->item($client, $service);
        $this->log($client, $item, CarbonImmutable::now()->subMonth());
        $this->saveEstimate($client)->assertOk();

        $response = $this->actingAs($this->admin, 'employee')
            ->get(route('clients.estimate.edit', $client))
            ->assertOk();

        $closed = $response->viewData('closed');
        $extras = $response->viewData('extras');

        $this->assertCount(1, $closed);
        $this->assertSame($item->id, $closed[0]['id']);
        $this->assertSame([], collect($extras)->where('service_id', $service->id)->values()->all());
    }

    /** Дату закрытия можно отодвинуть: у квартальных последняя декларация сдаётся позже. */
    public function test_closing_date_can_be_moved(): void
    {
        $client  = $this->client();
        $service = $this->service();
        $item    = $this->item($client, $service);
        $this->log($client, $item, CarbonImmutable::now()->subMonth());
        $this->saveEstimate($client)->assertOk();

        $later = CarbonImmutable::now()->addMonths(2)->endOfMonth()->toDateString();

        $this->saveEstimate($client, [
            'closed' => [['id' => $item->id, 'tasks_end_at' => $later]],
        ])->assertOk();

        $this->assertSame($later, EstimateItem::find($item->id)->tasks_end_at->toDateString());
    }

    /** После даты закрытия новых сроков нет, а те, что до неё, остаются. */
    public function test_closed_item_stops_producing_tasks_after_the_date(): void
    {
        $client  = $this->client();
        $service = $this->service();
        $item    = $this->item($client, $service);

        $dueDates = fn () => collect(
            $this->actingAs($this->admin, 'employee')
                ->get(route('buhtasks.index'))
                ->assertOk()
                ->viewData('tasks')
            )
            ->where('name', $service->name)
            ->pluck('due_date')
            ->sort()->values()->all();

        $prevMonth = CarbonImmutable::now()->subMonth();
        $thisMonth = CarbonImmutable::now();
        $prevDue   = $prevMonth->day(15)->toDateString();
        $thisDue   = $thisMonth->day(15)->toDateString();

        $this->assertSame([$prevDue, $thisDue], $dueDates(), 'пока позиция открыта, идут оба срока');

        // Закрываем прошлым месяцем: срок текущего месяца отпадает, прошлый остаётся.
        $item->update(['tasks_end_at' => $prevMonth->endOfMonth()->toDateString()]);

        $this->assertSame([$prevDue], $dueDates(), 'после даты закрытия новых сроков нет');
    }

    /** Незакрытая задача прошлого месяца остаётся видна и после закрытия позиции. */
    public function test_open_task_before_closing_date_survives(): void
    {
        $client  = $this->client();
        $service = $this->service();
        $item    = $this->item($client, $service);

        // Закрываем концом текущего месяца — как это делает выключение тумблера.
        $item->update(['tasks_end_at' => CarbonImmutable::now()->endOfMonth()->toDateString()]);

        $names = array_column(
            $this->actingAs($this->admin, 'employee')
                ->get(route('buhtasks.index'))
                ->assertOk()
                ->viewData('tasks'),
            'name',
        );

        $this->assertContains($service->name, $names, 'сроки до даты закрытия остаются в работе');
    }

    /** После смены режима смета показывает напоминание с обоими режимами. */
    public function test_notice_shows_after_tax_system_change(): void
    {
        $old = TaxSystem::firstOrCreate(['name' => 'УСН 6%']);
        $new = TaxSystem::firstOrCreate(['name' => 'ОСНО']);

        $client = $this->client();
        $client->update(['tax_system_id' => $old->id]);
        $client->update(['tax_system_id' => $new->id]);

        $notice = $this->actingAs($this->admin, 'employee')
            ->get(route('clients.estimate.edit', $client))
            ->assertOk()
            ->viewData('taxSystemChange');

        $this->assertNotNull($notice);
        $this->assertSame('УСН 6%', $notice['from']);
        $this->assertSame('ОСНО', $notice['to']);
    }

    /** Первое заполнение режима сменой не считается. */
    public function test_first_tax_system_is_not_a_change(): void
    {
        $taxSystem = TaxSystem::firstOrCreate(['name' => 'ОСНО']);

        $client = $this->client();
        $client->update(['tax_system_id' => $taxSystem->id]);

        $this->assertNull($client->fresh()->tax_system_changed_at);

        $notice = $this->actingAs($this->admin, 'employee')
            ->get(route('clients.estimate.edit', $client))
            ->assertOk()
            ->viewData('taxSystemChange');

        $this->assertNull($notice);
    }

    /** Напоминание живёт не меньше двух недель и не пропадает раньше нового месяца. */
    public function test_notice_window_covers_two_weeks_and_the_month_end(): void
    {
        $client = $this->client();

        // Смена в начале месяца: две недели истекают внутри месяца, держим до 1 числа.
        $client->forceFill(['tax_system_changed_at' => '2026-08-03'])->save();
        $this->assertSame('2026-09-01', $client->taxSystemNoticeUntil()->toDateString());

        // Смена в конце месяца: две недели заезжают в следующий месяц, держим их.
        $client->forceFill(['tax_system_changed_at' => '2026-08-30'])->save();
        $this->assertSame('2026-09-13', $client->taxSystemNoticeUntil()->toDateString());
    }

    /** Старая смена напоминание уже не показывает. */
    public function test_notice_disappears_after_the_window(): void
    {
        $old = TaxSystem::firstOrCreate(['name' => 'УСН 6%']);
        $new = TaxSystem::firstOrCreate(['name' => 'ОСНО']);

        $client = $this->client();
        $client->update(['tax_system_id' => $old->id]);
        $client->update(['tax_system_id' => $new->id]);

        // Отматываем смену на три месяца назад: окно давно закрылось.
        $client->forceFill([
            'tax_system_changed_at' => CarbonImmutable::now()->subMonths(3)->toDateString(),
        ])->save();

        $notice = $this->actingAs($this->admin, 'employee')
            ->get(route('clients.estimate.edit', $client))
            ->assertOk()
            ->viewData('taxSystemChange');

        $this->assertNull($notice);
    }

    /** Смена режима сама по себе не трогает ни задач, ни позиций сметы. */
    public function test_tax_system_change_alone_touches_nothing(): void
    {
        $old = TaxSystem::firstOrCreate(['name' => 'УСН 6%']);
        $new = TaxSystem::firstOrCreate(['name' => 'ОСНО']);

        $client  = $this->client();
        $client->update(['tax_system_id' => $old->id]);
        $service = $this->service();
        $item    = $this->item($client, $service);

        $before = collect(
            $this->actingAs($this->admin, 'employee')
                ->get(route('buhtasks.index'))->assertOk()->viewData('tasks')
            )->where('name', $service->name)->pluck('due_date')->sort()->values()->all();

        $client->update(['tax_system_id' => $new->id]);

        $after = collect(
            $this->actingAs($this->admin, 'employee')
                ->get(route('buhtasks.index'))->assertOk()->viewData('tasks')
            )->where('name', $service->name)->pluck('due_date')->sort()->values()->all();

        $this->assertSame($before, $after, 'смена режима не должна ничего останавливать');
        $this->assertNotNull(EstimateItem::find($item->id));
        $this->assertNull(EstimateItem::find($item->id)->tasks_end_at);
    }

    /** Воркер напоминаний за дату закрытия не заходит. */
    public function test_reminders_are_not_created_after_the_closing_date(): void
    {
        $client  = $this->client();
        $service = $this->service();
        $item    = $this->item($client, $service);

        $closesAt = CarbonImmutable::now()->endOfMonth();
        $item->update(['tasks_end_at' => $closesAt->toDateString()]);

        $this->artisan('tasks:generate', [
            '--date' => CarbonImmutable::now()->toDateString(),
            '--horizon' => 120,
            '--lookback' => 0,
        ])->assertSuccessful();

        $after = TaskReminder::where('client_id', $client->id)
            ->where('service_id', $service->id)
            ->where('due_date', '>', $closesAt->toDateString())
            ->count();

        $this->assertSame(0, $after, 'после даты закрытия напоминаний быть не должно');
    }

    /** Дашборд закрытую позицию за её датой тоже не считает. */
    public function test_dashboard_does_not_count_tasks_after_the_closing_date(): void
    {
        // Дашборд открыт только руководителю, админ туда не ходит.
        $managerRole = Role::firstOrCreate(['name' => Role::MANAGER], ['display_name' => 'Руководитель']);
        $manager = Employee::create([
            'full_name' => 'Тест Руководитель', 'position' => 'Руководитель',
            'email' => 'closing_manager_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $managerRole->id, 'status' => Employee::STATUS_ACTIVE,
        ]);

        $client  = $this->client();
        $service = $this->service();
        $item    = $this->item($client, $service);

        $withOpenItem = $this->actingAs($manager, 'employee')
            ->get(route('dashboard.index'))
            ->assertOk()
            ->viewData('stats')['total'];

        // Закрываем прошлым месяцем: срок текущего месяца из счёта уходит.
        $item->update(['tasks_end_at' => CarbonImmutable::now()->subMonth()->endOfMonth()->toDateString()]);

        $withClosedItem = $this->actingAs($manager, 'employee')
            ->get(route('dashboard.index'))
            ->assertOk()
            ->viewData('stats')['total'];

        $this->assertSame($withOpenItem - 1, $withClosedItem, 'задача текущего месяца должна уйти из счёта');
    }

    /** Включили завершённый БП обратно: дата снимается, задачи идут со следующего месяца. */
    public function test_closed_item_reopens_with_next_month_start(): void
    {
        $client  = $this->client();
        $service = $this->service();
        $item    = $this->item($client, $service);
        $this->log($client, $item, CarbonImmutable::now()->subMonth());
        $this->saveEstimate($client)->assertOk();

        $this->saveEstimate($client, [
            'tariff_bps' => [[
                'service_id' => $service->id,
                'enabled'    => true,
                'quantity'   => 1,
            ]],
        ])->assertOk();

        $fresh = EstimateItem::find($item->id);

        $this->assertNull($fresh->tasks_end_at, 'вернули в работу — дата закрытия снимается');
        $this->assertSame(
            EstimateItem::tasksStartForNew()->toDateString(),
            $fresh->tasks_start_from->toDateString(),
            'задачи идут со следующего месяца, а не задним числом',
        );
    }
}
