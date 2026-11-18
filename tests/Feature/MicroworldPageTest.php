<?php

namespace Tests\Feature;

use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Periodicity;
use App\Models\Role;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\Microworld\MyResults;
use App\Services\Microworld\OnTimeRule;
use App\Support\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «Микромир», экран бухгалтера: кто видит страницу и как считается «в срок».
 *
 * Сегодня 10.11.2026. Ежемесячный БП со сроком 5-го, задачи с июля (отсечка).
 */
class MicroworldPageTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Employee $admin;
    private Employee $accountant;
    private Service $bank;

    private static int $inn = 0;

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

        Carbon::setTestNow('2026-11-10 12:00:00');

        Periodicity::firstOrCreate(['name' => 'Ежемесячно'], ['kind' => 'monthly']);

        $this->tenant = Tenant::create([
            'name'   => 'Фирма микромира ' . uniqid(),
            'slug'   => 'microworld-' . uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);
        TenantContext::set($this->tenant);

        $this->admin      = $this->employee(Role::ADMIN);
        $this->accountant = $this->employee(Role::ACCOUNTANT);
        $this->bank       = Service::create([
            'name' => 'Банковские выписки ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_month' => [], 'start_day' => [5], 'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── Доступ ────────────────────────────────────────────────────────────

    public function test_hidden_until_the_firm_flag_is_on(): void
    {
        // Пункт меню смотрим под админом: у него открыты все разделы, у бухгалтера в
        // тесте нет модуля «Клиенты». Админу пункт виден, только если он ведёт клиента.
        $this->client();
        $this->actingAs($this->accountant, 'employee')->get(route('microworld.index'))->assertNotFound();
        $this->actingAs($this->admin, 'employee')->get(route('clients.index'))->assertOk()->assertDontSee('Микромир');

        $this->tenant->setMicroworldEnabled(true);

        $this->actingAs($this->accountant->fresh(), 'employee')->get(route('microworld.index'))
            ->assertOk()
            ->assertSee($this->accountant->full_name);
        $this->actingAs($this->admin->fresh(), 'employee')->get(route('clients.index'))->assertSee('Микромир');
    }

    public function test_accountant_head_and_lawyer_see_it_always(): void
    {
        $this->tenant->setMicroworldEnabled(true);

        foreach ([Role::ACCOUNTANT, Role::HEAD_ACCOUNTANT, Role::LEGAL_COUNSEL] as $role) {
            $this->actingAs($this->employee($role), 'employee')->get(route('microworld.index'))->assertOk();
        }
    }

    public function test_manager_and_admin_only_with_clients_or_tasks(): void
    {
        $this->tenant->setMicroworldEnabled(true);

        $manager = $this->employee(Role::MANAGER);
        $this->actingAs($manager, 'employee')->get(route('microworld.index'))->assertNotFound();
        $this->actingAs($this->admin, 'employee')->get(route('microworld.index'))->assertNotFound();

        // Руководитель ведёт клиента: видит
        $this->client($manager);
        $this->actingAs($manager, 'employee')->get(route('microworld.index'))->assertOk();

        // Админ без клиентов, но с назначенной позицией сметы: видит
        $this->item($this->client($this->accountant), $this->admin);
        $this->actingAs($this->admin, 'employee')->get(route('microworld.index'))->assertOk();
    }

    public function test_auditor_never_sees_it(): void
    {
        $this->tenant->setMicroworldEnabled(true);
        $auditor = $this->employee(Role::AUDITOR);
        $this->client($auditor);

        $this->actingAs($auditor, 'employee')->get(route('microworld.index'))->assertNotFound();
    }

    public function test_flag_of_another_firm_does_not_open_it(): void
    {
        $other = Tenant::create(['name' => 'Чужая ' . uniqid(), 'slug' => 'other-' . uniqid(), 'status' => Tenant::STATUS_ACTIVE]);
        $other->setMicroworldEnabled(true);

        $this->actingAs($this->accountant, 'employee')->get(route('microworld.index'))->assertNotFound();
    }

    public function test_vendor_sees_it_and_picks_whose_eyes(): void
    {
        $this->actingAs($this->admin, 'employee')->withSession([
            'vendor.tenant_id'   => $this->tenant->id,
            'vendor.tenant_name' => $this->tenant->name,
            'vendor.last_seen'   => now()->timestamp,
        ])->get(route('microworld.index', ['employee' => $this->accountant->id]))
            ->assertOk()
            ->assertSee($this->accountant->full_name . ' · Ноябрь 2026');
    }

    public function test_employee_param_is_ignored_for_staff(): void
    {
        $this->tenant->setMicroworldEnabled(true);

        $this->actingAs($this->accountant, 'employee')
            ->get(route('microworld.index', ['employee' => $this->admin->id]))
            ->assertOk()
            ->assertSee($this->accountant->full_name . ' · Ноябрь 2026');
    }

    // ── Счёт ──────────────────────────────────────────────────────────────

    public function test_submission_date_decides_not_acceptance(): void
    {
        $item = $this->item($this->client(), $this->accountant);

        // Сдала 4 октября, главбух принял 8-го: всё равно в срок
        $this->log($item, 10, 'completed', ['review_started_at' => '2026-10-04 10:00', 'completed_at' => '2026-10-08 10:00', 'reviewed_at' => '2026-10-08 10:00']);
        // Сдала 7 ноября при сроке 5-го, ещё на проверке: с опозданием
        $this->log($item, 11, 'review', ['review_started_at' => '2026-11-07 10:00']);

        $oct = $this->results(10)['month'];
        $this->assertSame(
            ['total' => 1, 'on_time' => 1, 'late' => 0, 'overdue' => 0, 'open' => 0, 'pct' => 100],
            array_intersect_key($oct, array_flip(['total', 'on_time', 'late', 'overdue', 'open', 'pct'])),
        );

        // Сданная поздно всё равно в плюсе, опоздание видно отдельным числом
        $nov = $this->results(11)['month'];
        $this->assertSame(1, $nov['late']);
        $this->assertSame(1, $nov['done']);
        $this->assertSame(100, $nov['pct']);
    }

    public function test_unsubmitted_after_due_is_overdue_and_listed(): void
    {
        $client = $this->client();
        $this->item($client, $this->accountant);

        $r = $this->results(9);

        $this->assertSame(1, $r['month']['overdue']);
        // Июль, август, сентябрь, октябрь, ноябрь: срок 5-го прошёл у всех пяти
        $this->assertCount(5, $r['overdue']);
        $this->assertSame($client->name, $r['overdue'][0]['client']);
        $this->assertSame('2026-07-05', $r['overdue'][0]['due']->toDateString(), 'самая старая сверху');
    }

    public function test_due_ahead_does_not_spoil_the_percent(): void
    {
        $service = Service::create([
            'name' => 'Отчёт ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_month' => [], 'start_day' => [25], 'is_active' => true,
        ]);
        $this->item($this->client(), $this->accountant, $service);

        $nov = $this->results(11)['month'];

        $this->assertSame(1, $nov['open']);
        $this->assertSame(0, $nov['overdue']);
    }

    public function test_task_submitted_by_someone_else_is_theirs(): void
    {
        $item = $this->item($this->client(), $this->accountant);
        $this->log($item, 10, 'completed', ['employee_id' => $this->admin->id, 'completed_at' => '2026-10-03 10:00']);

        $this->assertSame(0, $this->results(10)['month']['total']);

        $admin = (new MyResults(CarbonImmutable::now()->startOfDay()))->build($this->admin, CarbonImmutable::create(2026, 10, 1));
        $this->assertSame(1, $admin['month']['on_time']);
    }

    public function test_others_tasks_are_not_mine(): void
    {
        $this->item($this->client(), $this->admin);

        $r = $this->results(11);

        $this->assertSame(0, $r['month']['total']);
        $this->assertSame([], $r['overdue']);
    }

    public function test_history_starts_at_the_cutoff(): void
    {
        $this->item($this->client(), $this->accountant);

        $months = array_map(fn ($h) => $h['month'], $this->results(11)['history']);

        // Шесть месяцев по ноябрь, но задач до июля в системе нет
        $this->assertSame([7, 8, 9, 10, 11], $months);
    }

    public function test_rule_without_submission_date_trusts_the_employee(): void
    {
        $log = new BuhTaskLog(['status' => 'review']);
        $due = CarbonImmutable::parse('2026-10-05');

        $this->assertSame(OnTimeRule::ON_TIME, OnTimeRule::classify('review', $due, $log, CarbonImmutable::parse('2026-11-10')));
        $this->assertSame(OnTimeRule::OVERDUE, OnTimeRule::classify('rework', $due, null, CarbonImmutable::parse('2026-11-10')));
        $this->assertSame(OnTimeRule::OPEN, OnTimeRule::classify('pending', $due, null, CarbonImmutable::parse('2026-10-05')));
    }

    // ── Команда главбуха ──────────────────────────────────────────────────

    public function test_lead_sees_the_whole_team_on_own_clients(): void
    {
        $lead   = $this->employee(Role::HEAD_ACCOUNTANT);
        $client = $this->client($lead);

        $mineItem = $this->item($client, $lead);
        $theirs   = $this->item($client, $this->accountant);

        $this->log($mineItem, 10, 'completed', ['employee_id' => $lead->id, 'completed_at' => '2026-10-03 10:00']);
        // Бухгалтер октябрь не сдал: просрочка и недобор на нём

        $team = (new MyResults(CarbonImmutable::now()->startOfDay()))->build($lead, CarbonImmutable::create(2026, 10, 1))['team'];

        $this->assertSame(2, $team['month']['total']);
        $this->assertSame(50, $team['month']['pct']);

        $rows = collect($team['members'])->keyBy('id');
        $this->assertSame(1, $rows[$this->accountant->id]['overdue']);
        $this->assertSame(50.0, $rows[$this->accountant->id]['loss']);
        $this->assertSame(0.0, $rows[$lead->id]['loss']);
        $this->assertTrue($rows[$lead->id]['is_me']);
        $this->assertSame($this->accountant->id, $team['members'][0]['id'], 'кто тянет вниз, тот сверху');

        // Просрочки бухгалтера на клиентах главбуха за все месяцы: июль..ноябрь
        $this->assertCount(5, $rows[$this->accountant->id]['overdue_now']);
    }

    public function test_accountant_has_no_team(): void
    {
        $this->item($this->client(), $this->accountant);

        $this->assertNull($this->results(11)['team']);
    }

    public function test_lead_page_opens_on_team_and_switches_to_own_tasks(): void
    {
        $this->tenant->setMicroworldEnabled(true);
        $lead = $this->employee(Role::HEAD_ACCOUNTANT);
        $this->item($this->client($lead), $this->accountant);

        $this->actingAs($lead, 'employee')->get(route('microworld.index'))
            ->assertOk()
            ->assertSee('Команда на моих клиентах')
            ->assertSee('Вклад в недобор')
            ->assertSee($this->accountant->full_name);

        $this->actingAs($lead, 'employee')->get(route('microworld.index', ['view' => 'me']))
            ->assertOk()
            ->assertSee('Только задачи из сметы, которые делаете вы')
            ->assertDontSee('Вклад в недобор');
    }

    // ── Помощники ─────────────────────────────────────────────────────────

    private function results(int $month): array
    {
        return (new MyResults(CarbonImmutable::now()->startOfDay()))
            ->build($this->accountant, CarbonImmutable::create(2026, $month, 1));
    }

    private function employee(string $role): Employee
    {
        $employee = Employee::create([
            'full_name' => 'Сотрудник ' . $role . ' ' . uniqid(), 'position' => $role,
            'email' => 'microworld.' . $role . '.' . uniqid() . '@example.com', 'password' => 'secret123',
            'role_id' => Role::where('name', $role)->value('id'),
            'status' => Employee::STATUS_ACTIVE,
        ]);
        $employee->forceFill(['created_at' => '2026-01-01 00:00:00'])->saveQuietly();

        return $employee;
    }

    private function client(?Employee $lead = null): Client
    {
        return Client::create([
            'name' => 'ООО Клиент ' . uniqid(),
            'inn'  => '0210120254' . str_pad((string) (++self::$inn), 4, '0', STR_PAD_LEFT),
            'responsible_employee_id' => ($lead ?? $this->admin)->id,
            'accounting_method' => Client::ACCOUNTING_CASH,
            'serves_accounting' => true, 'serves_tax' => true, 'serves_payroll' => true,
        ]);
    }

    private function item(Client $client, Employee $assignee, ?Service $service = null): EstimateItem
    {
        $service ??= $this->bank;
        $estimate = Estimate::firstOrCreate(['client_id' => $client->id], ['total' => 0]);

        // Месяц создания сметы холостой: смета «заведена» весной, задачи идут всё окно.
        if ($estimate->wasRecentlyCreated) {
            $estimate->forceFill(['created_at' => '2026-03-01 00:00:00'])->saveQuietly();
        }

        return $estimate->items()->create([
            'service_id' => $service->id, 'type' => 'recurring', 'name' => $service->name,
            'periodicity' => $service->periodicity, 'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 0,
            'assignee_id' => $assignee->id,
        ]);
    }

    private function log(EstimateItem $item, int $month, string $status, array $extra = []): BuhTaskLog
    {
        return BuhTaskLog::create(array_merge([
            'employee_id' => $this->accountant->id, 'client_id' => $item->estimate->client_id, 'estimate_item_id' => $item->id,
            'year' => 2026, 'month' => $month, 'status' => $status,
        ], $extra));
    }
}
