<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\AuditTaskReview;
use App\Models\AutoAuditResult;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\ClientResponsibleChange;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Periodicity;
use App\Models\Role;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\AutoAudit\AutoAuditRunner;
use App\Services\MonthClosing\MonthClosingBoard;
use App\Support\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «Закрытие месяца», первый шаг: кто видит страницу, когда месяц клиента закрыт, какой у
 * него срок, что держит его открытым и что число запросов не растёт с числом клиентов.
 *
 * Сегодня 10.11.2026. Работа за сентябрь в задачах октября, срок задач 5-го числа.
 */
class MonthClosingPageTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Employee $admin;
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
            'name'   => 'Фирма закрытия ' . uniqid(),
            'slug'   => 'month-closing-' . uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);
        TenantContext::set($this->tenant);

        $this->admin = $this->employee(Role::ADMIN);
        $this->bank  = $this->service('Банковские выписки');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── Доступ ────────────────────────────────────────────────────────────

    public function test_vendor_sees_the_page_always(): void
    {
        $this->asVendor()->get(route('month-closing.index'))
            ->assertOk()
            ->assertSee('Клиенты × месяцы')
            ->assertSee('Руководитель фирмы эту страницу пока не видит.');
    }

    public function test_manager_needs_the_flag(): void
    {
        $manager = fn () => $this->employee(Role::MANAGER, 'manager.' . $this->tenant->id)->fresh();

        $this->actingAs($manager(), 'employee')->get(route('month-closing.index'))->assertNotFound();
        $this->actingAs($manager(), 'employee')->get(route('clients.index'))->assertDontSee(route('month-closing.index'));

        $this->tenant->setMonthClosingEnabled(true);

        $this->actingAs($manager(), 'employee')->get(route('month-closing.index'))->assertOk();
        $this->actingAs($manager(), 'employee')->get(route('clients.index'))->assertSee(route('month-closing.index'));
    }

    public function test_accountant_and_admin_without_vendor_do_not_see_it(): void
    {
        $this->tenant->setMonthClosingEnabled(true);

        $this->actingAs($this->employee(Role::ACCOUNTANT), 'employee')->get(route('month-closing.index'))->assertNotFound();
        $this->actingAs($this->employee(Role::HEAD_ACCOUNTANT), 'employee')->get(route('month-closing.index'))->assertNotFound();
        $this->actingAs($this->admin, 'employee')->get(route('month-closing.index'))->assertNotFound();
    }

    public function test_card_of_another_firms_client_is_not_found(): void
    {
        $other   = Tenant::create(['name' => 'Чужая ' . uniqid(), 'slug' => 'other-' . uniqid(), 'status' => Tenant::STATUS_ACTIVE]);
        $foreign = TenantContext::for($other, fn () => Client::create(['name' => 'Чужой клиент', 'inn' => '02101202539999']));

        $this->asVendor()->get(route('month-closing.card', $foreign->id))->assertNotFound();
    }

    public function test_flag_command(): void
    {
        $this->artisan('month-closing:access', ['--tenant' => $this->tenant->id])
            ->expectsOutputToContain('«Закрытие месяца» руководителю закрыто')
            ->assertSuccessful();

        $this->artisan('month-closing:access', ['--tenant' => $this->tenant->id, '--on' => true])
            ->expectsOutputToContain('«Закрытие месяца» руководителю открыто')
            ->assertSuccessful();
        $this->assertTrue($this->tenant->fresh()->monthClosingEnabled());

        $this->artisan('month-closing:access', ['--tenant' => $this->tenant->id, '--on' => true, '--off' => true])->assertFailed();

        $this->artisan('month-closing:access', ['--tenant' => $this->tenant->id, '--off' => true])->assertSuccessful();
        $this->assertFalse($this->tenant->fresh()->monthClosingEnabled());
    }

    // ── Статусы ───────────────────────────────────────────────────────────

    /** Задача октября это работа за сентябрь: приняли её, и закрыт сентябрь, а не октябрь. */
    public function test_accepted_october_task_closes_september(): void
    {
        $client = $this->client();
        $item   = $this->item($client, $this->bank);
        $this->task($client, $item, 10, 'completed');

        $this->assertSame(MonthClosingBoard::CLOSED, $this->statusOf($client, '2026-09'));
        // Октябрь закрывают в ноябре, срок 05.11 прошёл.
        $this->assertSame(MonthClosingBoard::OVERDUE, $this->statusOf($client, '2026-10'));
    }

    public function test_task_on_review_does_not_close_the_month(): void
    {
        $client = $this->client();
        $this->task($client, $this->item($client, $this->bank), 10, 'review');

        $cell = $this->cell($client, '2026-09');

        $this->assertSame(MonthClosingBoard::OVERDUE, $cell['status']);
        $this->assertSame(['1 задача не закрыта'], $cell['reasons']);
    }

    public function test_month_before_its_deadline_is_open(): void
    {
        Carbon::setTestNow('2026-10-03 12:00:00');

        $client = $this->client();
        $this->item($client, $this->bank);

        $this->assertSame(MonthClosingBoard::OPEN, $this->statusOf($client, '2026-09'));
    }

    /** Срок закрытия = самый поздний срок задач месяца, а не срок первой задачи. */
    public function test_deadline_is_the_latest_task_due_date(): void
    {
        Carbon::setTestNow('2026-10-20 12:00:00');

        $client = $this->client();
        $this->item($client, $this->bank);
        $this->item($client, $this->service('Выгрузка базы 1С', 28));

        $cell = $this->cell($client, '2026-09');

        $this->assertSame('2026-10-28', $cell['deadline']->toDateString());
        // Банк просрочен с 05.10, но месяц ещё можно закрыть до 28.10.
        $this->assertSame(MonthClosingBoard::OPEN, $cell['status']);
        $this->assertSame(1, $cell['late']);
    }

    /** Ручной аудит в разработке и к закрытию не подключён: замечание месяц не держит. */
    public function test_manual_audit_remark_does_not_affect_closing_yet(): void
    {
        $client = $this->client();
        $log    = $this->task($client, $this->item($client, $this->bank), 10, 'completed');
        $this->remark($client, $log);

        $this->assertSame(MonthClosingBoard::CLOSED, $this->statusOf($client, '2026-09'));
    }

    /** Вопрос автоаудита держит месяц, только если страница автоаудита открыта руководителю. */
    public function test_auto_audit_question_blocks_only_when_auto_audit_is_open(): void
    {
        $osv    = $this->service('ОСВ', 5, AutoAuditRunner::REF_BALANCE_SHEET);
        $client = $this->client();
        $this->task($client, $this->item($client, $osv), 10, 'completed');
        AutoAuditResult::create([
            'client_id' => $client->id, 'rule' => '1', 'period_from' => '2026-09-01', 'period_to' => '2026-09-30',
            'outcome' => AutoAuditResult::MISMATCH, 'left_value' => 1000, 'right_value' => 500, 'difference' => 500, 'sources' => [],
        ]);

        $this->assertSame(MonthClosingBoard::CLOSED, $this->statusOf($client, '2026-09'));

        $this->tenant->setAutoAuditEnabled(true);

        $cell = $this->cell($client, '2026-09');
        $this->assertSame(MonthClosingBoard::OVERDUE, $cell['status']);
        $this->assertSame(['вопросы автоаудита'], $cell['reasons']);

        // Таблица считает вопросы облегчённо, без вкладки целиком: ответ тот же, что в карточке.
        $this->assertSame(MonthClosingBoard::OVERDUE, $this->tableStatusOf($client, '2026-09'));

        // Клиента без полного обслуживания автоаудит не проверяет: его строка не в счёт.
        $client->update(['serves_payroll' => false]);
        $this->assertSame(MonthClosingBoard::CLOSED, $this->statusOf($client, '2026-09'));
        $this->assertSame(MonthClosingBoard::CLOSED, $this->tableStatusOf($client, '2026-09'));
    }

    public function test_handed_log_does_not_count(): void
    {
        $client = $this->client();
        $item   = $this->item($client, $this->bank);
        // Передали другому: эта запись не в счёт, задача ещё открыта.
        $this->task($client, $item, 10, BuhTaskLog::STATUS_HANDED);

        $this->assertSame(MonthClosingBoard::OVERDUE, $this->statusOf($client, '2026-09'));
    }

    /** Журнал без даты в расписании (расписание поменяли) всё равно работа месяца. */
    public function test_log_outside_the_schedule_still_counts(): void
    {
        Periodicity::firstOrCreate(['name' => 'Ежеквартально'], ['kind' => 'quarterly']);

        $client    = $this->client();
        $this->task($client, $this->item($client, $this->bank), 10, 'completed');
        $quarterly = Service::create([
            'name' => 'Квартальный ' . uniqid(), 'periodicity' => 'Ежеквартально',
            'start_month' => [3, 6, 9, 12], 'start_day' => [5], 'is_active' => true,
        ]);
        $this->task($client, $this->item($client, $quarterly), 10, 'running');

        $cell = $this->cell($client, '2026-09');

        $this->assertSame(MonthClosingBoard::OVERDUE, $cell['status']);
        $this->assertCount(2, $cell['tasks']);
    }

    // ── Чей месяц ─────────────────────────────────────────────────────────

    public function test_responsible_change_is_recorded(): void
    {
        $first  = $this->employee(Role::HEAD_ACCOUNTANT);
        $second = $this->employee(Role::HEAD_ACCOUNTANT);

        $client = $this->client(['responsible_employee_id' => $first->id]);
        $client->update(['name' => 'Другое имя ' . uniqid()]);
        $client->update(['responsible_employee_id' => $second->id]);

        $changes = ClientResponsibleChange::where('client_id', $client->id)->orderBy('id')->get();

        $this->assertCount(1, $changes, 'Только смена: создание и правка имени не в счёт');
        $this->assertSame([$first->id, $second->id], [(int) $changes[0]->previous_employee_id, (int) $changes[0]->employee_id]);
        $this->assertSame($this->tenant->id, (int) $changes[0]->tenant_id);
    }

    /** Месяц засчитывается тому, кто вёл клиента в срок закрытия, а не нынешнему. */
    public function test_month_belongs_to_whoever_led_the_client_at_the_deadline(): void
    {
        $before = $this->employee(Role::HEAD_ACCOUNTANT);
        $after  = $this->employee(Role::HEAD_ACCOUNTANT);

        Carbon::setTestNow('2026-09-01 12:00:00');
        $client = $this->client(['responsible_employee_id' => $before->id]);
        $this->task($client, $this->item($client, $this->bank), 10, 'completed');

        // Передали 20 октября: срок сентября (05.10) уже прошёл, срок октября (05.11) впереди.
        Carbon::setTestNow('2026-10-20 12:00:00');
        $client->update(['responsible_employee_id' => $after->id]);

        Carbon::setTestNow('2026-11-10 12:00:00');

        $this->assertSame($before->id, $this->cell($client, '2026-09')['chief']);
        $this->assertSame($after->id, $this->cell($client, '2026-10')['chief']);

        $this->asVendor()->get(route('month-closing.index', ['month' => '2026-09']))
            ->assertOk()
            ->assertViewHas('chiefs', fn ($chiefs) => $chiefs->pluck('id')->all() === [$before->id]);
    }

    /** До истории передач: новичку не засчитывается месяц, срок которого прошёл до его прихода. */
    public function test_newcomer_does_not_get_months_before_they_joined(): void
    {
        $newcomer = $this->employee(Role::HEAD_ACCOUNTANT, null, '2026-11-02 09:00:00');
        $client   = $this->client(['responsible_employee_id' => $newcomer->id]);
        // Клиента передали до того, как стали записывать историю: записей нет.
        $this->task($client, $this->item($client, $this->bank), 10, 'completed');

        $september = $this->cell($client, '2026-09');
        $this->assertNull($september['chief']);
        $this->assertTrue($september['chiefUnknown']);

        // Октябрь закрывают до 05.11, а она пришла 02.11: её месяц.
        $this->assertSame($newcomer->id, $this->cell($client, '2026-10')['chief']);

        $this->asVendor()->get(route('month-closing.index', ['month' => '2026-09']))
            ->assertOk()
            ->assertSee('Не определён')
            ->assertViewHas('chiefs', fn ($chiefs) => $chiefs->pluck('key')->all() === ['unknown']);
    }

    // ── Страница и карточка ───────────────────────────────────────────────

    public function test_tiles_count_closed_clients_by_chief(): void
    {
        $chief = $this->employee(Role::HEAD_ACCOUNTANT);
        $chief->update(['full_name' => 'Динара Главбухова']);

        $closed = $this->client(['responsible_employee_id' => $chief->id]);
        $this->task($closed, $this->item($closed, $this->bank), 10, 'completed');
        $open = $this->client(['responsible_employee_id' => $chief->id]);
        $this->item($open, $this->bank);

        $this->asVendor()->get(route('month-closing.index', ['month' => '2026-09']))
            ->assertOk()
            ->assertSee('50%')
            ->assertSee('1 из 2 закрыто')
            ->assertSee($closed->name)
            ->assertSee($open->name);

        $this->asVendor()->get(route('month-closing.index', ['month' => '2026-09', 'chief' => $chief->id]))
            ->assertOk()->assertSee($open->name);
    }

    /** Главбух, у которого в выбранном месяце нет клиентов с задачами, чипом не показывается. */
    public function test_chief_without_clients_in_the_month_is_hidden(): void
    {
        $active = $this->employee(Role::HEAD_ACCOUNTANT);
        $fired = $this->employee(Role::HEAD_ACCOUNTANT);

        $this->item($this->client(['responsible_employee_id' => $active->id]), $this->bank);
        // Обслуживание остановлено в конце июля: задачи были только за июнь.
        $stopped = $this->client(['responsible_employee_id' => $fired->id, 'service_end_date' => '2026-07-31']);
        $this->item($stopped, $this->bank);

        $chiefIds = fn (string $month) => $this->asVendor()->get(route('month-closing.index', ['month' => $month]))
            ->assertOk()->viewData('chiefs')->pluck('id')->all();

        $this->assertSame([$active->id], $chiefIds('2026-08'));
        $this->assertEqualsCanonicalizing([$active->id, $fired->id], $chiefIds('2026-06'));

        // Выбранный чип не пропадает, даже если клиентов в месяце нет: иначе его не снять.
        $this->assertContains($fired->id, $this->asVendor()
            ->get(route('month-closing.index', ['month' => '2026-08', 'chief' => $fired->id]))
            ->viewData('chiefs')->pluck('id')->all());
    }

    public function test_card_lists_open_tasks_and_marks_what_is_not_connected(): void
    {
        $client = $this->client();
        $this->item($client, $this->bank);
        $this->task($client, $this->item($client, $this->service('Зарплата')), 10, 'completed');

        $this->asVendor()->get(route('month-closing.card', [$client->id, 'month' => '2026-09']))
            ->assertOk()
            ->assertSee('Открыт, срок прошёл')
            ->assertSee('срок закрытия 05.10')
            ->assertSee('Не все сметные задачи закрыты')
            ->assertSee($this->bank->name)
            ->assertSee('Принято 1 из 2')
            ->assertDontSee('Принятые задачи')
            ->assertSee('Пока не подключён к закрытию месяца')
            ->assertSee('Для фирмы не включён, на закрытие месяца не влияет');

        $this->tenant->setAutoAuditEnabled(true);

        $this->asVendor()->get(route('month-closing.card', [$client->id, 'month' => '2026-09']))
            ->assertOk()
            ->assertSee('Вопросов автоаудита нет')
            ->assertDontSee('Для фирмы не включён');
    }

    public function test_query_count_does_not_grow_with_clients(): void
    {
        $count = function (int $clients): int {
            for ($i = 0; $i < $clients; $i++) {
                $client = $this->client();
                $this->task($client, $this->item($client, $this->bank), 10, $i % 2 ? 'completed' : 'running');
            }

            DB::flushQueryLog();
            DB::enableQueryLog();
            (new MonthClosingBoard(CarbonImmutable::now()->startOfDay()))->build(MonthClosingBoard::window(CarbonImmutable::parse('2026-09-01'), CarbonImmutable::now()));
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $n;
        };

        $five  = $count(5);
        $fifty = $count(45);

        $this->assertSame($five, $fifty);
    }

    // ── Помощники ─────────────────────────────────────────────────────────

    private function cell(Client $client, string $month): array
    {
        $today = CarbonImmutable::now()->startOfDay();
        $data  = (new MonthClosingBoard($today))->build(MonthClosingBoard::window(CarbonImmutable::parse($month . '-01'), $today), $client->id);
        $row   = $data['rows'][0] ?? null;
        $this->assertNotNull($row, 'Клиента нет в таблице');

        return $row['cells'][$month];
    }

    /** Статус из расчёта всей таблицы, а не карточки одного клиента. */
    private function tableStatusOf(Client $client, string $month): string
    {
        $today = CarbonImmutable::now()->startOfDay();
        $rows  = (new MonthClosingBoard($today))->build(MonthClosingBoard::window(CarbonImmutable::parse($month . '-01'), $today))['rows'];
        $row   = collect($rows)->firstWhere('client.id', $client->id);
        $this->assertNotNull($row, 'Клиента нет в таблице');

        return $row['cells'][$month]['status'];
    }

    private function statusOf(Client $client, string $month): string
    {
        return $this->cell($client, $month)['status'];
    }

    /** Сотрудник «работает давно»: появился в системе в начале года, раньше всех сроков. */
    private function employee(string $role, ?string $login = null, string $since = '2026-01-01 00:00:00'): Employee
    {
        $employee = Employee::firstOrCreate(
            ['email' => 'month.closing.' . ($login ?? $role . '.' . uniqid()) . '@example.com'],
            [
                'full_name' => 'Сотрудник ' . $role, 'position' => $role, 'password' => 'secret123',
                'role_id' => Role::where('name', $role)->value('id'),
                'status' => Employee::STATUS_ACTIVE,
            ],
        );

        if ($employee->wasRecentlyCreated) {
            $employee->forceFill(['created_at' => $since])->saveQuietly();
        }

        return $employee;
    }

    private function asVendor(): static
    {
        return $this->actingAs($this->admin, 'employee')->withSession([
            'vendor.tenant_id'   => $this->tenant->id,
            'vendor.tenant_name' => $this->tenant->name,
            'vendor.last_seen'   => now()->timestamp,
        ]);
    }

    private function service(string $name, int $day = 5, ?int $ref = null): Service
    {
        return Service::create([
            'name' => $name . ' ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_month' => [], 'start_day' => [$day], 'is_active' => true,
            'reference_id' => $ref,
        ]);
    }

    private function client(array $attributes = []): Client
    {
        return Client::create(array_merge([
            'name' => 'ООО Клиент ' . uniqid(),
            'inn'  => '0210120253' . str_pad((string) (++self::$inn), 4, '0', STR_PAD_LEFT),
            'responsible_employee_id' => $this->admin->id,
            'accounting_method' => Client::ACCOUNTING_CASH,
            'serves_accounting' => true, 'serves_tax' => true, 'serves_payroll' => true,
        ], $attributes));
    }

    private function item(Client $client, Service $service): EstimateItem
    {
        $estimate = Estimate::firstOrCreate(['client_id' => $client->id], ['total' => 0]);

        // Месяц создания сметы холостой: смета «заведена» весной, задачи идут всё окно.
        if ($estimate->wasRecentlyCreated) {
            $estimate->forceFill(['created_at' => '2026-03-01 00:00:00'])->saveQuietly();
        }

        return $estimate->items()->create([
            'service_id' => $service->id, 'type' => 'recurring', 'name' => $service->name,
            'periodicity' => $service->periodicity, 'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 0,
        ]);
    }

    private function task(Client $client, EstimateItem $item, int $month, string $status): BuhTaskLog
    {
        return BuhTaskLog::create([
            'employee_id' => $this->admin->id, 'client_id' => $client->id, 'estimate_item_id' => $item->id,
            'year' => 2026, 'month' => $month, 'status' => $status,
            'completed_at' => $status === 'completed' ? '2026-10-06 10:00:00' : null,
        ]);
    }

    private function remark(Client $client, BuhTaskLog $log, string $comment = 'Ошибка в проводке'): AuditTaskReview
    {
        $audit = Audit::create([
            'client_id' => $client->id, 'auditor_id' => $this->admin->id,
            'period_start' => '2026-09-01', 'period_end' => '2026-09-30', 'status' => Audit::STATUS_IN_PROGRESS,
        ]);

        return AuditTaskReview::create([
            'audit_id' => $audit->id, 'buh_task_log_id' => $log->id, 'task_name' => 'Задача',
            'verdict' => AuditTaskReview::VERDICT_FINDING, 'severity' => 'major', 'comment' => $comment,
        ]);
    }
}
