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
        $employeeRole = Role::firstOrCreate(['name' => Role::ACCOUNTANT], ['display_name' => 'Бухгалтер']);

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

    /**
     * Разрез «по сотрудникам»: главбух отвечает за весь объём своих компаний,
     * включая розданное, а бухгалтер виден отдельной строкой со своими задачами.
     * Внеплановые в этот разрез не идут вовсе.
     */
    public function test_lead_row_covers_delegated_tasks(): void
    {
        $headRole = Role::firstOrCreate(
            ['name' => Role::HEAD_ACCOUNTANT],
            ['display_name' => 'Главбух'],
        );
        $head = Employee::create([
            'full_name' => 'Тест Главбух', 'position' => 'Главбух',
            'email' => 'head_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $headRole->id, 'status' => Employee::STATUS_ACTIVE,
        ]);

        $service = Service::create([
            'name' => 'Ведение учёта ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_day' => [25], 'is_active' => true,
        ]);
        $client = Client::create([
            'name' => 'ТОО Команда Тест', 'inn' => 'DASH0000000T',
            'responsible_employee_id' => $head->id,
        ]);
        $estimate = Estimate::create(['client_id' => $client->id, 'total' => 20000]);
        // Месяц создания сметы холостой — сдвигаем её в прошлое, чтобы задачи были
        $estimate->forceFill(['created_at' => now()->subMonths(3)])->save();

        $mine = $estimate->items()->create([
            'service_id' => $service->id, 'type' => 'recurring', 'name' => 'Своя позиция',
            'periodicity' => 'Ежемесячно', 'cost' => 10000, 'quantity' => 1,
            'total' => 10000, 'sort_order' => 0, 'assignee_id' => $head->id,
        ]);
        $estimate->items()->create([
            'service_id' => $service->id, 'type' => 'recurring', 'name' => 'Розданная позиция',
            'periodicity' => 'Ежемесячно', 'cost' => 10000, 'quantity' => 1,
            'total' => 10000, 'sort_order' => 1, 'assignee_id' => $this->accountant->id,
        ]);

        // Закрыта только позиция главбуха: у команды выходит половина
        \App\Models\BuhTaskLog::create([
            'employee_id' => $head->id, 'client_id' => $client->id,
            'estimate_item_id' => $mine->id, 'year' => now()->year, 'month' => now()->month,
            'status' => 'completed', 'completed_at' => now(),
        ]);

        // Внеплановая бухгалтера: в этот разрез она не попадает
        BuhAdhocTask::create([
            'employee_id' => $this->accountant->id, 'client_id' => $client->id,
            'name' => 'Сверка с поставщиком', 'year' => now()->year, 'month' => now()->month,
            'status' => 'completed', 'completed_at' => now(),
        ]);

        $response = $this->actingAs($this->manager, 'employee')
            ->get(route('dashboard.index'))
            ->assertOk()
            ->assertSee('По сотрудникам')
            ->assertSee('Главные бухгалтеры');

        $lead = collect($response->viewData('leads'))->firstWhere('id', $head->id);
        $this->assertNotNull($lead);
        $this->assertSame('Тест Главбух', $lead['name']);
        $this->assertSame(2, $lead['total']);      // своя плюс розданная
        $this->assertSame(1, $lead['completed']);
        $this->assertSame(50, $lead['pct']);

        $members = collect($lead['members']);
        $this->assertSame($head->full_name, $members->first()['name']); // сам всегда первый
        $this->assertTrue($members->first()['self']);
        $this->assertSame(100, $members->first()['pct']);

        $helper = $members->firstWhere('name', $this->accountant->full_name);
        $this->assertNotNull($helper);
        $this->assertSame(1, $helper['total']);
        $this->assertSame(0, $helper['completed']);

        // Компании раскрытия — та же половина
        $company = collect($lead['companies'])->firstWhere('id', $client->id);
        $this->assertNotNull($company);
        $this->assertSame(2, $company['total']);
        $this->assertSame(50, $company['pct']);
    }

    /**
     * Закрытую задачу засчитываем тому, кто её закрыл, а не тому, за кем она числится.
     * Позиции сметы переезжают при смене ответственного, логи — нет, и по назначению
     * закрытый месяц переписывался бы после каждого перевода.
     */
    public function test_completed_task_counts_for_the_one_who_closed_it(): void
    {
        $headRole = Role::firstOrCreate(['name' => Role::HEAD_ACCOUNTANT], ['display_name' => 'Главбух']);
        $head = Employee::create([
            'full_name' => 'Тест Главбух Три', 'position' => 'Главбух',
            'email' => 'head3_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $headRole->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
        $closer = Employee::create([
            'full_name' => 'Тест Закрывший', 'position' => 'Бухгалтер',
            'email' => 'closer_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $this->accountant->role_id, 'status' => Employee::STATUS_ACTIVE,
        ]);

        $service = Service::create([
            'name' => 'Учёт ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_day' => [25], 'is_active' => true,
        ]);
        $client = Client::create([
            'name' => 'ТОО Кто Закрыл', 'inn' => 'DASH0000000C',
            'responsible_employee_id' => $head->id,
        ]);
        $estimate = Estimate::create(['client_id' => $client->id, 'total' => 10000]);
        $estimate->forceFill(['created_at' => now()->subMonths(3)])->save();

        // Позиция числится за одним бухгалтером, а закрыл её другой
        $item = $estimate->items()->create([
            'service_id' => $service->id, 'type' => 'recurring', 'name' => 'Позиция',
            'periodicity' => 'Ежемесячно', 'cost' => 10000, 'quantity' => 1,
            'total' => 10000, 'sort_order' => 0, 'assignee_id' => $this->accountant->id,
        ]);
        \App\Models\BuhTaskLog::create([
            'employee_id' => $closer->id, 'client_id' => $client->id,
            'estimate_item_id' => $item->id, 'year' => now()->year, 'month' => now()->month,
            'status' => 'completed', 'completed_at' => now(),
        ]);

        $response = $this->actingAs($this->manager, 'employee')
            ->get(route('dashboard.index'))->assertOk();

        $lead = collect($response->viewData('leads'))->firstWhere('id', $head->id);
        $this->assertNotNull($lead);
        $this->assertSame(1, $lead['total']);
        $this->assertSame(1, $lead['completed']);

        $members = collect($lead['members']);
        $this->assertSame([$closer->full_name], $members->pluck('name')->all());
        $this->assertSame(1, $members->first()['completed']);

        // Тот, за кем позиция числится, эту закрытую задачу себе не забирает
        $this->assertNull(
            collect($response->viewData('accountants'))->firstWhere('id', $this->accountant->id)
        );
    }

    /**
     * Закрытая задача без слота в этом месяце всё равно в разрезе: у позиции без
     * периодичности слот создаётся только на текущий месяц, и работа за прошлый
     * иначе исчезала бы из отчёта целиком.
     */
    public function test_completed_task_without_slot_is_still_counted(): void
    {
        $headRole = Role::firstOrCreate(['name' => Role::HEAD_ACCOUNTANT], ['display_name' => 'Главбух']);
        $head = Employee::create([
            'full_name' => 'Тест Главбух Четыре', 'position' => 'Главбух',
            'email' => 'head4_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $headRole->id, 'status' => Employee::STATUS_ACTIVE,
        ]);

        // Услуга без периодичности: расписания у неё нет вовсе
        $service = Service::create([
            'name' => 'Разовая ' . uniqid(), 'periodicity' => null, 'is_active' => true,
        ]);
        $client = Client::create([
            'name' => 'ТОО Без Расписания', 'inn' => 'DASH0000000S',
            'responsible_employee_id' => $head->id,
        ]);
        $estimate = Estimate::create(['client_id' => $client->id, 'total' => 5000]);
        $estimate->forceFill(['created_at' => now()->subMonths(3)])->save();
        $item = $estimate->items()->create([
            'service_id' => $service->id, 'type' => 'recurring', 'name' => 'Разовая позиция',
            'cost' => 5000, 'quantity' => 1, 'total' => 5000, 'sort_order' => 0,
            'assignee_id' => $this->accountant->id,
        ]);

        $prev = now()->subMonth();
        \App\Models\BuhTaskLog::create([
            'employee_id' => $this->accountant->id, 'client_id' => $client->id,
            'estimate_item_id' => $item->id, 'year' => $prev->year, 'month' => $prev->month,
            'status' => 'completed', 'completed_at' => $prev,
        ]);

        $response = $this->actingAs($this->manager, 'employee')
            ->get(route('dashboard.index', ['year' => $prev->year, 'month' => $prev->month]))
            ->assertOk();

        $lead = collect($response->viewData('leads'))->firstWhere('id', $head->id);
        $this->assertNotNull($lead, 'Закрытая задача без слота должна попасть в разрез');
        $this->assertSame(1, $lead['total']);
        $this->assertSame(1, $lead['completed']);
        $this->assertSame(100, $lead['pct']);
    }

    /**
     * Уволенный виден с меткой и датой. Метка про сегодняшний день, а не про месяц:
     * в августе человек мог ещё работать, и его закрытые задачи за тот месяц законны.
     */
    public function test_fired_employee_is_marked_with_date(): void
    {
        $fired = Employee::create([
            'full_name' => 'Тест Уволенный', 'position' => 'Бухгалтер',
            'email' => 'fired_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $this->accountant->role_id, 'status' => Employee::STATUS_ACTIVE,
            'employment_status' => Employee::EMPLOYMENT_FIRED, 'fired_at' => '2026-08-26',
        ]);

        $this->seedTaskFor($fired, 'ТОО Уволенный Тест', 'DASH0000000F');

        $rows = $this->actingAs($this->manager, 'employee')
            ->get(route('dashboard.index'))->assertOk()
            ->viewData('accountants');

        $row = collect($rows)->firstWhere('id', $fired->id);
        $this->assertNotNull($row);
        $this->assertSame('уволен', $row['note']['label']);
        $this->assertSame('Уволен 26.08.2026', $row['note']['title']);
    }

    /** У удалённой карточки имя сохраняется: иначе задачи сливались в общее «Не назначено». */
    public function test_deleted_employee_keeps_name(): void
    {
        $gone = Employee::create([
            'full_name' => 'Тест Удалённый', 'position' => 'Бухгалтер',
            'email' => 'gone_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $this->accountant->role_id, 'status' => Employee::STATUS_ACTIVE,
        ]);

        $this->seedTaskFor($gone, 'ТОО Удалённый Тест', 'DASH0000000G');
        $gone->delete();

        $rows = $this->actingAs($this->manager, 'employee')
            ->get(route('dashboard.index'))->assertOk()
            ->viewData('accountants');

        $row = collect($rows)->firstWhere('id', $gone->id);
        $this->assertNotNull($row, 'Строка удалённого сотрудника должна остаться');
        $this->assertSame('Тест Удалённый', $row['name']);
        $this->assertSame('удалён', $row['note']['label']);
    }

    /** Клиент со сметой и одной помесячной позицией на этом сотруднике. */
    private function seedTaskFor(Employee $doer, string $clientName, string $inn): void
    {
        $headRole = Role::firstOrCreate(['name' => Role::HEAD_ACCOUNTANT], ['display_name' => 'Главбух']);
        $head = Employee::create([
            'full_name' => 'Главбух для ' . $doer->full_name, 'position' => 'Главбух',
            'email' => 'lead_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $headRole->id, 'status' => Employee::STATUS_ACTIVE,
        ]);

        $service = Service::create([
            'name' => 'Услуга ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_day' => [25], 'is_active' => true,
        ]);
        $client = Client::create([
            'name' => $clientName, 'inn' => $inn, 'responsible_employee_id' => $head->id,
        ]);
        $estimate = Estimate::create(['client_id' => $client->id, 'total' => 5000]);
        $estimate->forceFill(['created_at' => now()->subMonths(3)])->save();
        $estimate->items()->create([
            'service_id' => $service->id, 'type' => 'recurring', 'name' => 'Позиция',
            'periodicity' => 'Ежемесячно', 'cost' => 5000, 'quantity' => 1,
            'total' => 5000, 'sort_order' => 0, 'assignee_id' => $doer->id,
        ]);
    }

    /** Бухгалтер в своём списке один раз и только со сметными задачами. */
    public function test_accountant_row_counts_own_estimate_tasks_only(): void
    {
        $headRole = Role::firstOrCreate(
            ['name' => Role::HEAD_ACCOUNTANT],
            ['display_name' => 'Главбух'],
        );
        $head = Employee::create([
            'full_name' => 'Тест Главбух Два', 'position' => 'Главбух',
            'email' => 'head2_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $headRole->id, 'status' => Employee::STATUS_ACTIVE,
        ]);

        $service = Service::create([
            'name' => 'Отчётность ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_day' => [25], 'is_active' => true,
        ]);
        $client = Client::create([
            'name' => 'ТОО Помощник Тест', 'inn' => 'DASH0000000H',
            'responsible_employee_id' => $head->id,
        ]);
        $estimate = Estimate::create(['client_id' => $client->id, 'total' => 10000]);
        $estimate->forceFill(['created_at' => now()->subMonths(3)])->save();
        $estimate->items()->create([
            'service_id' => $service->id, 'type' => 'recurring', 'name' => 'Розданная позиция',
            'periodicity' => 'Ежемесячно', 'cost' => 10000, 'quantity' => 1,
            'total' => 10000, 'sort_order' => 0, 'assignee_id' => $this->accountant->id,
        ]);

        // Внеплановая того же бухгалтера: объём строки она не меняет
        BuhAdhocTask::create([
            'employee_id' => $this->accountant->id, 'client_id' => $client->id,
            'name' => 'Разовое поручение', 'year' => now()->year, 'month' => now()->month,
            'status' => 'completed', 'completed_at' => now(),
        ]);

        $response = $this->actingAs($this->manager, 'employee')
            ->get(route('dashboard.index'))
            ->assertOk()
            ->assertSee('Бухгалтеры');

        $rows = collect($response->viewData('accountants'))
            ->where('id', $this->accountant->id);
        $this->assertCount(1, $rows, 'Бухгалтер должен быть в списке ровно один раз');

        $row = $rows->first();
        $this->assertSame(1, $row['total']);
        $this->assertContains($head->full_name, $row['leads']);

        // Главбух в список бухгалтеров не попадает: он показан выше со своей командой
        $this->assertNull(collect($response->viewData('accountants'))->firstWhere('id', $head->id));
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
        // Смета «из прошлого»: месяц её создания холостой (Estimate::tasksStartFrom),
        // а тут нужен клиент, по которому задачи текущего месяца уже генерятся.
        $estimate->forceFill(['created_at' => now()->subMonths(3)])->save();
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
