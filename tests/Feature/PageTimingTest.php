<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\Tenant;
use App\Support\PageTimer;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

/**
 * Счётчик скорости страниц.
 *
 * Главное здесь не точность цифр, а то, что счётчик безопасен: страница
 * открывается, даже если он сломался, адрес с номерами клиентов не пишется,
 * а выключатель действительно выключает.
 */
class PageTimingTest extends TestCase
{
    use DatabaseTransactions;

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

        @unlink(PageTimer::offFlag());
        DB::table('page_timings')->delete();

        Route::middleware('web')->get('/__timed/{client}', fn () => 'ok');
        Route::middleware('web')->get('/__timed-db', function () {
            DB::select('select 1');
            DB::select('select 2');
            DB::select('select 3');

            return 'ok';
        });
    }

    protected function tearDown(): void
    {
        @unlink(PageTimer::offFlag());

        parent::tearDown();
    }

    public function test_opening_a_page_is_counted_by_route_pattern_and_firm(): void
    {
        $tenantId = (int) Tenant::query()->value('id');
        $this->actingAs($this->employee($tenantId), 'employee');

        $this->get('/__timed/123?search=Иванов')->assertOk();
        $this->get('/__timed/456')->assertOk();

        $rows = DB::table('page_timings')->get();

        $this->assertCount(1, $rows);
        $row = $rows->first();
        $this->assertSame('__timed/{client}', $row->route);
        $this->assertSame('GET', $row->method);
        $this->assertSame($tenantId, (int) $row->tenant_id);
        $this->assertSame(2, (int) $row->hits);
        $this->assertGreaterThanOrEqual((int) $row->max_ms, (int) $row->total_ms);
        $this->assertLessThanOrEqual(2, (int) $row->under_1000);
    }

    public function test_database_queries_of_the_page_are_counted_without_the_counters_own(): void
    {
        $this->get('/__timed-db')->assertOk();
        $before = (int) DB::table('page_timings')->where('route', '__timed-db')->value('total_queries');

        $this->get('/__timed-db')->assertOk();
        $row = DB::table('page_timings')->where('route', '__timed-db')->first();

        // Сессия и прочие прослойки тоже ходят в базу, поэтому точное число не
        // проверяем. Главное: три запроса страницы видны, а второе открытие
        // добавило ровно столько же, сколько первое (своя запись не в счёт).
        $this->assertGreaterThanOrEqual(3, $before);
        $this->assertSame(2 * $before, (int) $row->total_queries);
        $this->assertLessThanOrEqual((int) $row->total_ms, (int) $row->total_db_ms);
    }

    public function test_page_without_firm_is_counted_under_zero(): void
    {
        $this->get('/login')->assertOk();

        $this->assertSame(0, (int) DB::table('page_timings')->where('route', 'login')->value('tenant_id'));
    }

    public function test_page_still_opens_when_the_counter_breaks(): void
    {
        DB::beforeExecuting(function (string $sql) {
            if (str_contains($sql, 'page_timings')) {
                throw new RuntimeException('База недоступна');
            }
        });

        $this->get('/__timed/1')->assertOk()->assertSee('ok');
    }

    public function test_switch_off_stops_counting_and_on_resumes(): void
    {
        $this->artisan('page-timing', ['--off' => true])->expectsOutput('Счётчик выключен')->assertSuccessful();
        $this->get('/__timed/1')->assertOk();
        $this->assertSame(0, DB::table('page_timings')->count());

        $this->artisan('page-timing', ['--on' => true])->expectsOutput('Счётчик включён')->assertSuccessful();
        $this->get('/__timed/1')->assertOk();
        $this->assertSame(1, (int) DB::table('page_timings')->value('hits'));
    }

    public function test_report_shows_the_slowest_pages_first(): void
    {
        PageTimer::record(0, 'GET', 'fast', 50, 4, 10);
        PageTimer::record(0, 'GET', 'slow', 3400, 150, 2500);
        PageTimer::record(0, 'GET', 'slow', 2000, 110, 1820);

        $row = DB::table('page_timings')->where('route', 'slow')->first();
        $this->assertSame(2, (int) $row->hits);
        $this->assertSame(5400, (int) $row->total_ms);
        $this->assertSame(3400, (int) $row->max_ms);
        $this->assertSame(0, (int) $row->under_1000);

        $this->artisan('page-timing')
            ->expectsOutputToContain('Счётчик включён')
            ->expectsTable(
                ['Страница', 'Открытий', 'Среднее, с', 'До 0,3 с', 'До 1 с', 'Худшее, с', 'Всего ждали, мин', 'Запросов к базе', 'База, %'],
                [
                    ['GET /slow', '2', '2,70', '0%', '0%', '3,40', '0,1', '130', '80%'],
                    ['GET /fast', '1', '0,05', '100%', '100%', '0,05', '0,0', '4', '20%'],
                ],
            )
            ->assertSuccessful();
    }

    private function employee(int $tenantId): Employee
    {
        $role = Role::firstOrCreate(['name' => Role::ACCOUNTANT], ['display_name' => 'Бухгалтер']);

        return Employee::create([
            'full_name' => 'Тест Скорость', 'position' => 'Бухгалтер',
            'email' => 'timing_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
            'tenant_id' => $tenantId,
        ]);
    }
}
