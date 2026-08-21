<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\Module;
use App\Models\Periodicity;
use App\Models\Role;
use App\Models\Service;
use App\Models\TaskReminder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * БП, добавленный в смету действующего клиента, начинает работать со следующего месяца.
 *
 * Раньше точкой отсчёта была дата создания всей сметы: у клиента, которого ведут с мая,
 * включённый в середине августа БП тут же выдавал задачу за 20 августа (уже просроченную)
 * и подтягивал июльскую. Теперь своя граница есть у каждой позиции сметы.
 *
 * По боевому mysql в транзакции (как TaskGenerationStartTest): страница /buhtasks
 * поднимает слишком много связей для sqlite-памяти.
 */
class NewEstimateItemStartsNextMonthTest extends TestCase
{
    use DatabaseTransactions;

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
        DB::purge('mysql');

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Periodicity::firstOrCreate(['name' => 'Ежемесячно'], ['kind' => 'monthly']);
        $role   = Role::firstOrCreate(['name' => Role::ACCOUNTANT], ['display_name' => 'Бухгалтер']);
        // Нужны оба модуля: смета живёт в клиентах, задачи — в задачнике.
        $modules = collect(['buhtasks' => 'БухЗадачник', 'clients' => 'Клиенты'])
            ->map(fn ($label, $name) => Module::firstOrCreate(
                ['name' => $name],
                ['display_name' => $label, 'is_active' => true],
            )->id);

        $this->accountant = Employee::create([
            'full_name' => 'Тест Бухгалтер', 'position' => 'Бухгалтер',
            'email' => 'nextmonth_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
        $this->accountant->modules()->attach($modules->values()->all());
    }

    /** Клиент, которого ведут давно: смета из прошлого, холостой месяц позади. */
    private function longRunningClient(): Client
    {
        $client = Client::create([
            'name' => 'ОсОО Давний ' . uniqid(),
            'inn'  => strtoupper(substr(md5(uniqid()), 0, 12)),
            'responsible_employee_id' => $this->accountant->id,
            'service_start_date' => CarbonImmutable::now()->subMonths(10)->toDateString(),
        ]);

        $estimate = Estimate::create(['client_id' => $client->id, 'total' => 0]);
        $estimate->forceFill(['created_at' => CarbonImmutable::now()->subMonths(4)->startOfMonth()])->save();

        return $client;
    }

    /** Ежемесячный БП со сроком, который в текущем месяце уже прошёл. */
    private function monthlyService(): Service
    {
        return Service::create([
            'name' => 'Декларация ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_day' => [1], 'is_active' => true, 'cost' => 0,
        ]);
    }

    /** Позиция сметы «как раньше»: добавлена давно, границы у неё нет. */
    private function oldItem(Client $client, Service $service): void
    {
        $client->estimates()->first()->items()->create([
            'service_id' => $service->id, 'type' => 'recurring',
            'name' => $service->name, 'periodicity' => 'Ежемесячно',
            'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 0,
            'assignee_id' => $this->accountant->id,
        ]);
    }

    /** Месяцы задач по конкретной позиции сметы: ['2026-8', ...] */
    private function taskMonths(Client $client, int $itemId): array
    {
        $tasks = $this->actingAs($this->accountant, 'employee')
            ->get(route('buhtasks.index'))
            ->assertOk()
            ->viewData('tasks');

        return collect($tasks)
            ->where('client_id', $client->id)
            ->where('item_id', $itemId)
            ->map(fn ($t) => $t['year'] . '-' . $t['month'])
            ->unique()->values()->all();
    }

    private function itemFor(Client $client, Service $service)
    {
        return $client->estimates()->first()->items()->where('service_id', $service->id)->first();
    }

    private function saveEstimate(Client $client, array $payload)
    {
        return $this->actingAs($this->accountant, 'employee')
            ->postJson(route('clients.estimate.save', $client), $payload)
            ->assertOk();
    }

    public function test_added_bp_gets_next_month_as_its_start(): void
    {
        $client  = $this->longRunningClient();
        $service = $this->monthlyService();

        $this->saveEstimate($client, [
            'tariff_bps' => [['service_id' => $service->id, 'enabled' => true]],
        ]);

        $item = $this->itemFor($client, $service);

        $this->assertSame(
            CarbonImmutable::now()->addMonth()->startOfMonth()->toDateString(),
            $item->tasks_start_from->toDateString(),
        );
    }

    /** Главное: в текущем месяце задачи по новому БП нет, есть только в следующем. */
    public function test_no_task_in_the_current_month_for_a_freshly_added_bp(): void
    {
        $client  = $this->longRunningClient();
        $service = $this->monthlyService();
        $now     = CarbonImmutable::now();

        $this->saveEstimate($client, [
            'tariff_bps' => [['service_id' => $service->id, 'enabled' => true]],
        ]);

        $months = $this->taskMonths($client, $this->itemFor($client, $service)->id);

        // Задачник показывает текущий месяц и просрочку, будущие месяцы скрывает.
        // Значит у только что добавленного БП в списке не должно быть ничего:
        // ни задачи за этот месяц, ни подтянутой за прошлый.
        $this->assertSame([], $months, 'По новому БП уже появились задачи: ' . implode(', ', $months));
        $this->assertNotContains($now->year . '-' . $now->month, $months);
    }

    /** Позиции, которые уже вели, правило не трогает: их задачи остаются на месте. */
    public function test_existing_items_keep_their_tasks(): void
    {
        $client = $this->longRunningClient();
        $old    = $this->monthlyService();
        $this->oldItem($client, $old);

        $new = $this->monthlyService();
        $now = CarbonImmutable::now();

        // Сохраняем смету целиком: старый БП остаётся включённым, новый добавляется.
        $this->saveEstimate($client, [
            'tariff_bps' => [
                ['service_id' => $old->id, 'enabled' => true],
                ['service_id' => $new->id, 'enabled' => true],
            ],
        ]);

        $oldItem = $this->itemFor($client, $old);

        $this->assertNull($oldItem->tasks_start_from, 'Старой позиции проставили границу');
        $this->assertContains(
            $now->year . '-' . $now->month,
            $this->taskMonths($client, $oldItem->id),
            'Задача по старому БП пропала из текущего месяца',
        );
    }

    /** Разовую доп. услугу добавляют, чтобы сделать сейчас, — её не откладываем. */
    public function test_one_time_extra_is_not_postponed(): void
    {
        $client = $this->longRunningClient();

        $this->saveEstimate($client, [
            'extras' => [['name' => 'Справка в банк', 'cost' => 500, 'type' => 'one_time']],
        ]);

        $item = $client->estimates()->first()->items()->where('name', 'Справка в банк')->first();

        $this->assertNull($item->tasks_start_from);
    }

    /** А постоянная доп. услуга — такой же повторяющийся БП, её откладываем. */
    public function test_recurring_extra_is_postponed(): void
    {
        $client = $this->longRunningClient();

        $this->saveEstimate($client, [
            'extras' => [[
                'name' => 'Ведение учёта ГСМ', 'cost' => 1000,
                'type' => 'recurring', 'periodicity' => 'Ежемесячно',
            ]],
        ]);

        $item = $client->estimates()->first()->items()->where('name', 'Ведение учёта ГСМ')->first();

        $this->assertSame(
            CarbonImmutable::now()->addMonth()->startOfMonth()->toDateString(),
            $item->tasks_start_from->toDateString(),
        );
    }

    /** Повторное сохранение сметы границу не сдвигает — иначе БП уезжал бы вечно. */
    public function test_second_save_does_not_move_the_border(): void
    {
        $client  = $this->longRunningClient();
        $service = $this->monthlyService();
        $payload = ['tariff_bps' => [['service_id' => $service->id, 'enabled' => true]]];

        $this->saveEstimate($client, $payload);
        $first = $this->itemFor($client, $service);

        $this->saveEstimate($client, $payload);
        $second = $this->itemFor($client, $service);

        $this->assertSame($first->id, $second->id, 'Позицию пересоздали, а не обновили');
        $this->assertSame(
            $first->tasks_start_from->toDateString(),
            $second->tasks_start_from->toDateString(),
        );
    }

    /** Страница сметы отдаёт предупреждение и дату, с которой пойдут задачи. */
    public function test_estimate_page_carries_the_warning(): void
    {
        $client = $this->longRunningClient();

        $page = $this->actingAs($this->accountant, 'employee')
            ->get(route('clients.estimate.edit', $client))
            ->assertOk();

        $page->assertSee('Задачи по новым БП начнутся', false);
        $page->assertSee('В текущем месяце задач по ним не будет.', false);

        $months = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
                   'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
        $start  = CarbonImmutable::now()->addMonth()->startOfMonth();

        $page->assertSee('1 ' . $months[$start->month - 1] . ' ' . $start->year, false);
    }

    /** Воркер напоминаний уважает ту же границу. */
    public function test_reminder_worker_respects_the_border(): void
    {
        $client  = $this->longRunningClient();
        $service = $this->monthlyService();

        $this->saveEstimate($client, [
            'tariff_bps' => [['service_id' => $service->id, 'enabled' => true]],
        ]);

        $this->artisan('tasks:generate', ['--tenant' => $client->tenant_id])->assertSuccessful();

        $earliest = TaskReminder::where('client_id', $client->id)
            ->where('service_id', $service->id)
            ->min('due_date');

        // Срок БП — 1 число, значит первое напоминание ровно на 1 число следующего
        // месяца: за прошедшие сроки этого и прошлых месяцев ничего не заводится.
        $this->assertNotNull($earliest, 'Напоминаний не создалось вообще');
        $this->assertSame(
            CarbonImmutable::now()->addMonth()->startOfMonth()->toDateString(),
            CarbonImmutable::parse($earliest)->toDateString(),
        );
    }
}
