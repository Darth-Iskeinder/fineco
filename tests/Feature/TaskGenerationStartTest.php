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
use Tests\TestCase;

/**
 * Начало генерации задач по смете: месяц, в котором смету завели, — холостой
 * (посадка клиента: собирают документы, договариваются), задачи идут с 1-го числа
 * следующего месяца. Иначе смета, собранная 31 июля, сразу выдавала «просрочку»
 * за июльские сроки 5-го и 20-го.
 *
 * По боевому mysql в транзакции (как DashboardTest): страница /buhtasks поднимает
 * слишком много связей для sqlite-памяти.
 */
class TaskGenerationStartTest extends TestCase
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

        $this->accountant = Employee::create([
            'full_name' => 'Тест Бухгалтер', 'position' => 'Бухгалтер',
            'email' => 'gen_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
        $this->accountant->modules()->attach($module->id);
    }

    /**
     * Клиент с ежемесячным БП (срок 5-го числа) и сметой, заведённой в указанный день.
     * Дата начала обслуживания намеренно ранняя — проверяем именно границу по смете.
     */
    private function clientWithEstimateCreatedAt(CarbonImmutable $createdAt): Client
    {
        $client = Client::create([
            'name' => 'ТОО Посадка ' . uniqid(),
            'inn' => strtoupper(substr(md5(uniqid()), 0, 12)),
            'responsible_employee_id' => $this->accountant->id,
            'service_start_date' => $createdAt->subMonths(5)->toDateString(),
        ]);

        $service = Service::create([
            'name' => 'Тест отчёт ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_day' => [5], 'is_active' => true,
        ]);

        $estimate = Estimate::create(['client_id' => $client->id, 'total' => 0]);
        $estimate->items()->create([
            'service_id' => $service->id, 'type' => 'recurring',
            'name' => $service->name, 'periodicity' => 'Ежемесячно',
            'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 0,
            'assignee_id' => $this->accountant->id,
        ]);
        $estimate->forceFill(['created_at' => $createdAt])->save();

        return $client;
    }

    /** Задачи бухгалтера по этому клиенту: [[год, месяц], ...] */
    private function taskMonths(Client $client): array
    {
        $tasks = $this->actingAs($this->accountant, 'employee')
            ->get(route('buhtasks.index'))
            ->assertOk()
            ->viewData('tasks');

        return collect($tasks)
            ->where('client_id', $client->id)
            ->map(fn ($t) => $t['year'] . '-' . $t['month'])
            ->values()->all();
    }

    public function test_month_of_estimate_creation_is_idle(): void
    {
        $now = CarbonImmutable::now();
        // Смета заведена в этом месяце → задач нет вообще, даже за текущий месяц
        $client = $this->clientWithEstimateCreatedAt($now->startOfMonth());

        $this->assertSame([], $this->taskMonths($client));
    }

    public function test_generation_starts_next_month(): void
    {
        $now  = CarbonImmutable::now();
        $prev = $now->subMonth();
        // Смета заведена в прошлом месяце (как 31 июля) → прошлого месяца нет, текущий есть
        $client = $this->clientWithEstimateCreatedAt($prev->endOfMonth()->startOfDay());

        $months = $this->taskMonths($client);

        $this->assertNotContains($prev->year . '-' . $prev->month, $months);
        $this->assertContains($now->year . '-' . $now->month, $months);
    }

    /**
     * Смета, заведённая 31 числа, начинает работать с 1 числа следующего месяца.
     *
     * «31 августа плюс месяц» это несуществующее 31 сентября, PHP переливает его
     * на 1 октября, и клиент терял целый месяц задач. На бою так и вышло: у всех
     * смет одного бухгалтера дата 31 августа, и экран был пуст весь сентябрь.
     */
    public function test_estimate_created_on_the_31st_starts_next_month(): void
    {
        $estimate = new Estimate();
        $estimate->created_at = CarbonImmutable::parse('2026-08-31 12:00:00');

        $this->assertSame('2026-09-01', $estimate->tasksStartFrom()->toDateString());

        $estimate->created_at = CarbonImmutable::parse('2026-01-31 12:00:00');

        $this->assertSame('2026-02-01', $estimate->tasksStartFrom()->toDateString());
    }

    /**
     * Воркер напоминаний держится того же холостого месяца, что и экран.
     *
     * Раньше он знал только границу позиции сметы, поэтому заводил напоминания за
     * месяцы до старта: на экране пусто, а ночью человеку приходили сроки.
     */
    public function test_worker_respects_the_idle_month_of_the_estimate(): void
    {
        $client = $this->clientWithEstimateCreatedAt(
            CarbonImmutable::now()->subMonth()->endOfMonth()->startOfDay(),
        );

        $this->artisan('tasks:generate', ['--horizon' => 45, '--lookback' => 190])
            ->assertSuccessful();

        $start = $client->estimates()->first()->tasksStartFrom();
        $dates = TaskReminder::where('client_id', $client->id)->pluck('due_date');

        $this->assertTrue($dates->isNotEmpty(), 'Напоминаний нет вовсе — проверять нечего');
        $this->assertTrue(
            $dates->every(fn ($d) => $d->toDateString() >= $start->toDateString()),
            'Воркер завёл напоминания раньше старта сметы: ' . $dates->map->toDateString()->implode(', '),
        );
    }

    public function test_old_estimate_still_shows_past_overdue(): void
    {
        $now  = CarbonImmutable::now();
        $prev = $now->subMonth();
        // Давняя смета — прежнее поведение: просрочка прошлого месяца видна
        $client = $this->clientWithEstimateCreatedAt($now->subMonths(4)->startOfMonth());

        $months = $this->taskMonths($client);

        $this->assertContains($prev->year . '-' . $prev->month, $months);
        $this->assertContains($now->year . '-' . $now->month, $months);
    }
}
