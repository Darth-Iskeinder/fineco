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
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Вкладка «Выполненные» отвечает не только «когда закрыли», но и «за какой период».
 *
 * Дата закрытия этого не показывает: отчёт за июль сдают в августе, и в истории
 * несколько одинаковых по названию задач различались только временем закрытия.
 * Метку считает Service::reportingPeriodLabel от месяца срока (year/month лога).
 *
 * По боевому mysql в транзакции (как TaskGenerationStartTest): страница /buhtasks
 * поднимает слишком много связей для sqlite-памяти.
 */
class CompletedReportingPeriodTest extends TestCase
{
    use DatabaseTransactions;

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
        Periodicity::firstOrCreate(['name' => 'Ежеквартально'], ['kind' => 'quarterly']);
        Periodicity::firstOrCreate(['name' => 'Ежегодно'], ['kind' => 'yearly']);
        Periodicity::firstOrCreate(['name' => 'Еженедельно'], ['kind' => 'weekly']);

        $role   = Role::firstOrCreate(['name' => Role::EMPLOYEE], ['display_name' => 'Сотрудник']);
        $module = Module::firstOrCreate(
            ['name' => 'buhtasks'],
            ['display_name' => 'БухЗадачник', 'is_active' => true],
        );

        $this->accountant = Employee::create([
            'full_name' => 'Тест Бухгалтер', 'position' => 'Бухгалтер',
            'email' => 'crp_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
        $this->accountant->modules()->attach($module->id);

        $this->client = Client::create([
            'name' => 'ТОО Период ' . uniqid(),
            'inn' => strtoupper(substr(md5(uniqid()), 0, 12)),
            'responsible_employee_id' => $this->accountant->id,
            'service_start_date' => CarbonImmutable::now()->subYear()->toDateString(),
        ]);
    }

    /**
     * Закрытая задача с заданным месяцем срока. $dueDate заполняем только для weekly —
     * так же, как это делает боевой код: у помесячных логов due_date пустой.
     */
    private function completedLog(string $periodicity, int $year, int $month, ?string $dueDate = null): void
    {
        $service = Service::create([
            'name' => 'Тест БП ' . uniqid(), 'periodicity' => $periodicity,
            'start_day' => [10], 'is_active' => true,
        ]);

        $estimate = Estimate::firstOrCreate(['client_id' => $this->client->id], ['total' => 0]);
        $item = $estimate->items()->create([
            'service_id' => $service->id, 'type' => 'recurring',
            'name' => $service->name, 'periodicity' => $periodicity,
            'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 0,
            'assignee_id' => $this->accountant->id,
        ]);

        BuhTaskLog::create([
            'employee_id' => $this->accountant->id,
            'client_id' => $this->client->id,
            'estimate_item_id' => $item->id,
            'year' => $year, 'month' => $month, 'due_date' => $dueDate,
            'status' => 'completed', 'completed_at' => CarbonImmutable::now()->subDay(),
        ]);
    }

    /** Записи вкладки «Выполненные» по этому клиенту. */
    private function completed(): array
    {
        return collect(
            $this->actingAs($this->accountant, 'employee')
                ->get(route('buhtasks.index'))
                ->assertOk()
                ->viewData('completed')
        )->where('client_name', $this->client->name)->values()->all();
    }

    /** Помесячный БП: срок в августе → отчёт за июль. */
    public function test_monthly_shows_previous_month(): void
    {
        $now = CarbonImmutable::now();
        $this->completedLog('Ежемесячно', $now->year, $now->month);

        $row = $this->completed()[0] ?? null;

        $this->assertNotNull($row, 'Выполненная задача не попала во вкладку');
        $expected = Service::reportingPeriodLabel('monthly', $now->startOfMonth(), $now->year);
        $this->assertSame($expected, $row['reporting_period']);
        $this->assertStringStartsWith('за ', $row['reporting_period']);
    }

    /** Квартальный БП: срок в 3 квартале → отчёт за 2 квартал. */
    public function test_quarterly_shows_previous_quarter(): void
    {
        $now = CarbonImmutable::now();
        $this->completedLog('Ежеквартально', $now->year, $now->month);

        $row = $this->completed()[0] ?? null;

        $this->assertNotNull($row);
        $this->assertStringContainsString('квартал', $row['reporting_period']);
    }

    /** Годовой БП: срок в этом году → отчёт за прошлый. */
    public function test_yearly_shows_previous_year(): void
    {
        $now = CarbonImmutable::now();
        $this->completedLog('Ежегодно', $now->year, $now->month);

        $row = $this->completed()[0] ?? null;

        $this->assertNotNull($row);
        $this->assertSame('за ' . ($now->year - 1) . ' год', $row['reporting_period']);
    }

    /**
     * Еженедельный БП: периода словами нет, но есть дата срока — по ней в истории
     * и различают вхождения. Без неё пять «Кассовых операций» за месяц одинаковы.
     */
    public function test_weekly_has_no_label_but_keeps_due_date(): void
    {
        $now = CarbonImmutable::now();
        $due = $now->startOfMonth()->addDays(6)->toDateString();
        $this->completedLog('Еженедельно', $now->year, $now->month, $due);

        $row = $this->completed()[0] ?? null;

        $this->assertNotNull($row);
        $this->assertNull($row['reporting_period']);
        $this->assertSame($due, $row['due_date']);
    }

    /** Внеплановая задача: отчётного периода нет, и срок в историю не тянем — прочерк. */
    public function test_adhoc_has_no_period_and_no_due_date(): void
    {
        $now = CarbonImmutable::now();

        BuhAdhocTask::create([
            'employee_id' => $this->accountant->id,
            'client_id' => $this->client->id,
            'name' => 'Внеплановая ' . uniqid(),
            'year' => $now->year, 'month' => $now->month, 'due_day' => 15,
            'cost' => 0, 'status' => 'completed',
            'completed_at' => CarbonImmutable::now()->subDay(),
        ]);

        $row = $this->completed()[0] ?? null;

        $this->assertNotNull($row);
        $this->assertNull($row['reporting_period']);
        $this->assertNull($row['due_date']);
    }
}
