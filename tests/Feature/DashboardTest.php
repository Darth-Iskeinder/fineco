<?php

namespace Tests\Feature;

use App\Models\BuhAdhocTask;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\Periodicity;
use App\Models\Role;
use App\Models\Service;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Страница руководителя (/dashboard). Как и GenerateTaskRemindersTest, идёт по
 * боевому mysql-соединению в транзакции с откатом (pdo_sqlite в среде нет).
 */
class DashboardTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $manager;
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

        $managerRole = Role::firstOrCreate(
            ['name' => Role::MANAGER],
            ['display_name' => 'Руководитель'],
        );
        $employeeRole = Role::firstOrCreate(['name' => 'employee'], ['display_name' => 'Сотрудник']);

        $this->manager = Employee::create([
            'full_name' => 'Тест Руководитель', 'position' => 'Директор',
            'email' => 'boss_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $managerRole->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
        $this->accountant = Employee::create([
            'full_name' => 'Тест Бухгалтер', 'position' => 'Бухгалтер',
            'email' => 'acc_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $employeeRole->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
    }

    public function test_manager_sees_dashboard(): void
    {
        $this->actingAs($this->manager, 'employee')
            ->get(route('dashboard.index'))
            ->assertOk()
            ->assertSee('Просрочено сейчас')
            ->assertSee('Задач в месяце');
    }

    public function test_manager_has_full_module_access(): void
    {
        $this->actingAs($this->manager, 'employee')
            ->get(route('employees.index'))
            ->assertOk();
    }

    public function test_future_month_clamped_to_current(): void
    {
        $next = now()->addMonth();

        $response = $this->actingAs($this->manager, 'employee')
            ->get(route('dashboard.index', ['year' => $next->year, 'month' => $next->month]))
            ->assertOk();

        $this->assertSame(now()->year, $response->viewData('year'));
        $this->assertSame((int) now()->month, $response->viewData('month'));
        $this->assertTrue($response->viewData('isCurrent'));
    }

    public function test_non_manager_gets_403(): void
    {
        $this->actingAs($this->accountant, 'employee')
            ->get(route('dashboard.index'))
            ->assertForbidden();
    }

    public function test_guest_redirected_to_login(): void
    {
        $this->get(route('dashboard.index'))->assertRedirect(route('login'));
    }

    public function test_root_redirects_manager_to_dashboard(): void
    {
        $this->actingAs($this->manager, 'employee')
            ->get('/')
            ->assertRedirect(route('dashboard.index'));
    }

    public function test_counts_month_tasks_and_overdue(): void
    {
        // Плановая задача: ежемесячный БП со сроком 5-го числа, срок уже прошёл → просрочка
        $service = Service::create([
            'name' => 'Начисление зарплаты', 'periodicity' => 'Ежемесячно',
            'start_day' => [5], 'is_active' => true,
        ]);
        $client = Client::create([
            'name' => 'ТОО Дашборд Тест', 'inn' => 'DASH0000000A',
            'responsible_employee_id' => $this->accountant->id,
        ]);
        $estimate = Estimate::create(['client_id' => $client->id, 'total' => 0]);
        $estimate->items()->create([
            'service_id' => $service->id, 'type' => 'recurring',
            'name' => 'Начисление зарплаты', 'periodicity' => 'Ежемесячно',
            'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 0,
        ]);

        // Внеплановая выполненная задача этого месяца
        BuhAdhocTask::create([
            'employee_id' => $this->accountant->id, 'client_id' => $client->id,
            'name' => 'Внеплановая консультация', 'year' => now()->year, 'month' => now()->month,
            'status' => 'completed', 'started_at' => now()->subHour(),
            'paused_seconds' => 1800, 'completed_at' => now(),
        ]);

        $response = $this->actingAs($this->manager, 'employee')
            ->get(route('dashboard.index'))
            ->assertOk();

        $stats   = $response->viewData('stats');
        $overdue = $response->viewData('overdue');

        // Задачи месяца: минимум плановая + внеплановая (в базе могут быть и другие данные)
        $this->assertGreaterThanOrEqual(2, $stats['total']);
        $this->assertGreaterThanOrEqual(1, $stats['adhoc']);
        $this->assertGreaterThanOrEqual(1, $stats['completed']);

        // Плановая со сроком 5-го числа не начата → в просрочке (сегодня позже 5-го)
        if (now()->day > 5) {
            $names = array_column($overdue, 'name');
            $this->assertContains('Начисление зарплаты', $names);
        }
    }

    public function test_by_employee_breakdown_with_rework_and_overdue(): void
    {
        $client = Client::create([
            'name' => 'ТОО Сотрудники Тест', 'inn' => 'DASH0000000B',
            'responsible_employee_id' => $this->accountant->id,
        ]);

        // Выполненная внеплановая с двумя возвратами с проверки
        BuhAdhocTask::create([
            'employee_id' => $this->accountant->id, 'client_id' => $client->id,
            'name' => 'Сверка с поставщиком', 'year' => now()->year, 'month' => now()->month,
            'status' => 'completed', 'started_at' => now()->subHours(2),
            'paused_seconds' => 3600, 'completed_at' => now(), 'rework_count' => 2,
        ]);

        // Просроченная внеплановая: срок 1-го числа текущего месяца, не начата
        // (прошлые месяцы до отсечки backlog 2026-07-01 в просрочку не попадают)
        BuhAdhocTask::create([
            'employee_id' => $this->accountant->id, 'client_id' => $client->id,
            'name' => 'Старый долг', 'year' => now()->year, 'month' => now()->month,
            'due_day' => 1, 'status' => 'pending',
        ]);

        $response = $this->actingAs($this->manager, 'employee')
            ->get(route('dashboard.index'))
            ->assertOk()
            ->assertSee('По сотрудникам');

        $byEmployee = $response->viewData('byEmployee');
        $this->assertArrayHasKey($this->accountant->id, $byEmployee);

        $row = $byEmployee[$this->accountant->id];
        $this->assertSame('Тест Бухгалтер', $row['name']);
        $this->assertGreaterThanOrEqual(1, $row['total']);
        $this->assertGreaterThanOrEqual(1, $row['adhoc']);
        $this->assertGreaterThanOrEqual(1, $row['completed']);
        $this->assertGreaterThanOrEqual(2, $row['rework']);
        if (now()->day > 1) {
            $this->assertGreaterThanOrEqual(1, $row['overdue']);
        }
        $names = array_column($row['tasks'], 'name');
        $this->assertContains('Сверка с поставщиком', $names);
        // Просроченная задача текущего месяца — в списке, помечена как поздняя
        $late = collect($row['tasks'])->firstWhere('name', 'Старый долг');
        $this->assertNotNull($late);
        if (now()->day > 1) {
            $this->assertTrue($late['late']);
        }
    }

    public function test_by_company_breakdown_with_estimate_and_rate(): void
    {
        // Клиент со сметой 60 000 сом и выполненной задачей с 2 часами таймера
        $service = Service::create([
            'name' => 'Ведение учёта', 'periodicity' => 'Ежемесячно',
            'start_day' => [25], 'is_active' => true,
        ]);
        $client = Client::create([
            'name' => 'ТОО Компании Тест', 'inn' => 'DASH0000000D',
            'responsible_employee_id' => $this->accountant->id,
        ]);
        $estimate = Estimate::create(['client_id' => $client->id, 'total' => 60000]);
        $item = $estimate->items()->create([
            'service_id' => $service->id, 'type' => 'recurring',
            'name' => 'Ведение учёта', 'periodicity' => 'Ежемесячно',
            'cost' => 60000, 'quantity' => 1, 'total' => 60000, 'sort_order' => 0,
        ]);
        \App\Models\BuhTaskLog::create([
            'employee_id' => $this->accountant->id, 'client_id' => $client->id,
            'estimate_item_id' => $item->id, 'year' => now()->year, 'month' => now()->month,
            'status' => 'completed',
            'started_at' => now()->subHours(3), 'paused_seconds' => 7200, 'completed_at' => now(),
        ]);

        // Внеплановая без клиента → строка «Без компании», смета и сом/час = «—»
        BuhAdhocTask::create([
            'employee_id' => $this->accountant->id, 'client_id' => null,
            'name' => 'Разовая консультация', 'year' => now()->year, 'month' => now()->month,
            'status' => 'completed', 'started_at' => now()->subHour(),
            'paused_seconds' => 1800, 'completed_at' => now(),
        ]);

        $response = $this->actingAs($this->manager, 'employee')
            ->get(route('dashboard.index'))
            ->assertOk()
            ->assertSee('По компаниям');

        $byCompany = $response->viewData('byCompany');
        $this->assertArrayHasKey($client->id, $byCompany);

        $row = $byCompany[$client->id];
        $this->assertSame('ТОО Компании Тест', $row['name']);
        $this->assertGreaterThanOrEqual(1, $row['total']);
        $this->assertGreaterThanOrEqual(1, $row['completed']);
        $this->assertSame(60000.0, $row['estimate']);
        // 60 000 сом / 2 часа = 30 000 сом/час
        $this->assertSame(30000, $row['rate']);

        $this->assertArrayHasKey(0, $byCompany);
        $noClient = $byCompany[0];
        $this->assertSame('Без компании', $noClient['name']);
        $this->assertNull($noClient['estimate']);
        $this->assertNull($noClient['rate']);
        $this->assertGreaterThanOrEqual(1, $noClient['adhoc']);

        // «Куда уходит время»: клиент с 2 часами таймера присутствует, порядок по убыванию
        $timeTop = $response->viewData('timeTop');
        $this->assertNotEmpty($timeTop);
        $this->assertContains('ТОО Компании Тест', array_column($timeTop, 'name'));
        $elapsed = array_column($timeTop, 'elapsed');
        $sorted = $elapsed;
        rsort($sorted);
        $this->assertSame($sorted, $elapsed);
        $this->assertSame($response->viewData('timeMax'), $elapsed[0]);
    }

    public function test_discipline_chart_counts_judged_tasks(): void
    {
        $client = Client::create([
            'name' => 'ТОО Дисциплина Тест', 'inn' => 'DASH0000000E',
            'responsible_employee_id' => $this->accountant->id,
        ]);

        // Выполнена вовремя (срок — конец месяца) и просроченная открытая (срок 1-го числа)
        BuhAdhocTask::create([
            'employee_id' => $this->accountant->id, 'client_id' => $client->id,
            'name' => 'Закрытая вовремя', 'year' => now()->year, 'month' => now()->month,
            'due_day' => 28, 'status' => 'completed',
            'started_at' => now()->subHour(), 'completed_at' => now(),
        ]);
        BuhAdhocTask::create([
            'employee_id' => $this->accountant->id, 'client_id' => $client->id,
            'name' => 'Висящая просрочка', 'year' => now()->year, 'month' => now()->month,
            'due_day' => 1, 'status' => 'pending',
        ]);

        $response = $this->actingAs($this->manager, 'employee')
            ->get(route('dashboard.index'))
            ->assertOk()
            ->assertSee('Дисциплина по месяцам');

        $discipline = $response->viewData('discipline');
        $current    = end($discipline); // последний месяц окна — текущий

        $this->assertGreaterThanOrEqual(1, $current['on_time']);
        if (now()->day > 1) {
            $this->assertGreaterThanOrEqual(1, $current['overdue']);
        }
        $this->assertGreaterThanOrEqual($current['on_time'] + $current['late'] + $current['overdue'], $response->viewData('disciplineMax'));

        // Состав месяца: счётчики «в работе»/«не начато» присутствуют и согласованы с total
        $stats = $response->viewData('stats');
        $this->assertSame(
            $stats['total'],
            $stats['completed'] + $stats['review'] + $stats['in_progress'] + $stats['pending'],
        );
    }

    public function test_reject_review_increments_rework_count(): void
    {
        $client = Client::create([
            'name' => 'ТОО Возврат Тест', 'inn' => 'DASH0000000C',
            'responsible_employee_id' => $this->accountant->id,
        ]);
        $task = BuhAdhocTask::create([
            'employee_id' => $this->accountant->id, 'client_id' => $client->id,
            'name' => 'Задача на проверке', 'year' => now()->year, 'month' => now()->month,
            'status' => 'review', 'requires_review' => true,
            'started_at' => now()->subHour(), 'paused_seconds' => 600,
        ]);

        // Роуты buhtasks закрыты модулем — выдаём его бухгалтеру
        $module = \App\Models\Module::firstOrCreate(
            ['name' => 'buhtasks'],
            ['display_name' => 'Задачи', 'sort_order' => 0, 'is_active' => true],
        );
        $this->accountant->modules()->syncWithoutDetaching([$module->id]);

        // Главбух (ответственный клиента) возвращает на доработку
        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.adhoc.review-reject', $task), ['comment' => 'Поправить суммы'])
            ->assertOk();

        $task->refresh();
        $this->assertSame('rework', $task->status);
        $this->assertSame(1, $task->rework_count);
    }
}
