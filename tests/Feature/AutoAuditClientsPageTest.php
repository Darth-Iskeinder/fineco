<?php

namespace Tests\Feature;

use App\Models\AutoAuditDocumentRead;
use App\Models\AutoAuditFinding;
use App\Models\AutoAuditFindingMessage;
use App\Models\AutoAuditResult;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Periodicity;
use App\Models\Role;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\AutoAudit\AutoAuditClientBoard;
use App\Services\AutoAudit\AutoAuditRunner;
use App\Support\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Вкладка автоаудита «По клиентам»: кто её видит, какой статус получает клетка клиента за
 * месяц, где стоит квартальный ЕН и что число запросов не растёт с числом клиентов.
 *
 * Сегодня 08.10.2026: работа за сентябрь идёт в октябре, срок задач 5-го числа.
 */
class AutoAuditClientsPageTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Employee $admin;
    private Service $osv;
    private Service $tax;
    private Service $form161;

    /** Чтобы у клиентов одного теста ИНН были разные. */
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

        Carbon::setTestNow('2026-10-08 12:00:00');

        Periodicity::firstOrCreate(['name' => 'Ежемесячно'], ['kind' => 'monthly']);
        Periodicity::firstOrCreate(['name' => 'Ежеквартально'], ['kind' => 'quarterly']);

        $this->tenant = Tenant::create([
            'name'   => 'Фирма по клиентам ' . uniqid(),
            'slug'   => 'autoaudit-clients-' . uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);
        TenantContext::set($this->tenant);

        $this->admin = $this->employee(Role::ADMIN);

        $this->osv     = $this->service('ОСВ', AutoAuditRunner::REF_BALANCE_SHEET);
        $this->form161 = $this->service('Форма 161', AutoAuditRunner::REF_FORM_161);
        // Отчёт по ЕН сдают за квартал до 25-го числа месяца после квартала.
        $this->tax     = $this->service('Отчёт по ЕН', AutoAuditRunner::REF_TAX_REPORT, 'Ежеквартально', [1, 4, 7, 10], 25);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── Доступ ────────────────────────────────────────────────────────────

    public function test_vendor_sees_the_tab_always(): void
    {
        $this->asVendor()->get(route('auto-audit.clients'))
            ->assertOk()
            ->assertSee('По клиентам')
            ->assertSee('Все сверки');
    }

    public function test_manager_needs_both_flags(): void
    {
        // Свежая модель на каждый запрос: фирма с флагами кешируется в отношении сотрудника.
        $manager = fn () => $this->employee(Role::MANAGER, 'manager.' . $this->tenant->id)->fresh();

        $this->actingAs($manager(), 'employee')->get(route('auto-audit.clients'))->assertNotFound();

        $this->tenant->setAutoAuditEnabled(true);
        $this->actingAs($manager(), 'employee')->get(route('auto-audit.clients'))->assertNotFound();
        // Без вкладки на «Все сверки» переключателя нет.
        $this->actingAs($manager(), 'employee')->get(route('auto-audit.index'))->assertOk()->assertDontSee('По клиентам');

        $this->tenant->setAutoAuditClientsEnabled(true);
        $this->actingAs($manager(), 'employee')->get(route('auto-audit.clients'))->assertOk();
        $this->actingAs($manager(), 'employee')->get(route('auto-audit.index'))->assertOk()->assertSee('По клиентам');
    }

    public function test_accountant_and_admin_without_vendor_do_not_see_it(): void
    {
        $this->tenant->setAutoAuditEnabled(true);
        $this->tenant->setAutoAuditClientsEnabled(true);

        $this->actingAs($this->employee(Role::ACCOUNTANT), 'employee')->get(route('auto-audit.clients'))->assertNotFound();
        $this->actingAs($this->admin, 'employee')->get(route('auto-audit.clients'))->assertNotFound();
    }

    public function test_card_of_another_firms_client_is_not_found(): void
    {
        $other = Tenant::create(['name' => 'Чужая ' . uniqid(), 'slug' => 'other-' . uniqid(), 'status' => Tenant::STATUS_ACTIVE]);
        $foreign = TenantContext::for($other, fn () => Client::create(['name' => 'Чужой клиент', 'inn' => '02101202529999']));

        $this->asVendor()->get(route('auto-audit.clients.card', $foreign->id))->assertNotFound();
    }

    public function test_clients_flag_command(): void
    {
        $this->artisan('autoaudit:access', ['--tenant' => $this->tenant->id, '--clients-on' => true])
            ->expectsOutputToContain('Вкладка «По клиентам» руководителю: видна')
            ->assertSuccessful();
        $this->assertTrue($this->tenant->fresh()->autoAuditClientsEnabled());
        $this->assertFalse($this->tenant->fresh()->autoAuditEnabled());

        $this->artisan('autoaudit:access', ['--tenant' => $this->tenant->id, '--clients-on' => true, '--clients-off' => true])
            ->assertFailed();

        $this->artisan('autoaudit:access', ['--tenant' => $this->tenant->id, '--clients-off' => true])->assertSuccessful();
        $this->assertFalse($this->tenant->fresh()->autoAuditClientsEnabled());
    }

    // ── Статусы ───────────────────────────────────────────────────────────

    public function test_task_past_its_due_date_is_overdue(): void
    {
        $client = $this->client();
        $this->item($client, $this->osv);

        $this->assertSame(AutoAuditClientBoard::OVERDUE, $this->statusOf($client, '2026-09'));
    }

    public function test_task_before_its_due_date_is_in_progress(): void
    {
        Carbon::setTestNow('2026-10-03 12:00:00');

        $client = $this->client();
        $this->item($client, $this->osv);

        $this->assertSame(AutoAuditClientBoard::IN_PROGRESS, $this->statusOf($client, '2026-09'));
    }

    public function test_matched_month_is_ok_and_shows_how_many_matched(): void
    {
        $client = $this->client();
        $this->doneTask($client, $this->item($client, $this->osv));
        $this->doneTask($client, $this->item($client, $this->form161));
        $this->auditRow($client, '4', AutoAuditResult::MATCHED);
        $this->auditRow($client, '5', AutoAuditResult::MATCHED);

        $cell = $this->cell($client, '2026-09');

        $this->assertSame(AutoAuditClientBoard::OK, $cell['status']);
        $this->assertSame('2 сверки сошлись', $cell['note']);
    }

    /** Время для людей по Бишкеку: ночной прогон в 23:00 UTC это 05:00 следующего дня. */
    public function test_check_time_is_shown_in_bishkek_time(): void
    {
        $client = $this->client();
        $this->doneTask($client, $this->item($client, $this->osv));
        $row = $this->auditRow($client, '1', AutoAuditResult::MATCHED);
        $row->timestamps = false;
        $row->forceFill(['updated_at' => CarbonImmutable::parse('2026-10-07 23:00:00', 'UTC')])->save();

        $this->asVendor()->get(route('auto-audit.clients', ['all' => 1]))
            ->assertOk()->assertSee('Последняя проверка 08.10.2026 05:00');
        $this->asVendor()->get(route('auto-audit.index'))
            ->assertOk()->assertSee('Данные на 08.10.2026 05:00');
    }

    public function test_unanswered_finding_waits_for_the_accountant(): void
    {
        $client = $this->client();
        $this->doneTask($client, $this->item($client, $this->osv));
        $result = $this->auditRow($client, '4', AutoAuditResult::MISMATCH);
        $this->finding($result, CarbonImmutable::parse('2026-10-02'));

        $cell = $this->cell($client, '2026-09');

        $this->assertSame(AutoAuditClientBoard::ACCOUNTANT, $cell['status']);
        $this->assertSame(6, $cell['days']);
    }

    public function test_explained_finding_waits_for_the_chief(): void
    {
        $client = $this->client();
        $this->doneTask($client, $this->item($client, $this->osv));
        $result = $this->auditRow($client, '4', AutoAuditResult::MISMATCH);
        $this->finding($result)->messages()->create([
            'result_id' => $result->id, 'employee_id' => $this->admin->id, 'kind' => AutoAuditFindingMessage::EXPLAINED, 'body' => 'Полис УСМЗ',
        ]);

        $this->assertSame(AutoAuditClientBoard::CHIEF, $this->statusOf($client, '2026-09'));
    }

    public function test_accepted_explanation_counts_as_ok(): void
    {
        $client = $this->client();
        $this->doneTask($client, $this->item($client, $this->osv));
        $result = $this->auditRow($client, '4', AutoAuditResult::MISMATCH);
        $this->finding($result)->messages()->create([
            'result_id' => $result->id, 'employee_id' => $this->admin->id, 'kind' => AutoAuditFindingMessage::ACCEPTED,
        ]);

        $this->assertSame(AutoAuditClientBoard::OK, $this->statusOf($client, '2026-09'));
    }

    public function test_fixed_finding_waits_for_the_nightly_run(): void
    {
        $client = $this->client();
        $this->doneTask($client, $this->item($client, $this->osv));
        $result = $this->auditRow($client, '4', AutoAuditResult::MISMATCH);
        Carbon::setTestNow('2026-10-08 15:00:00');
        $this->finding($result)->messages()->create([
            'result_id' => $result->id, 'employee_id' => $this->admin->id, 'kind' => AutoAuditFindingMessage::FIXED, 'body' => 'Заменил ОСВ',
        ]);

        $this->assertSame(AutoAuditClientBoard::WAITING_RUN, $this->statusOf($client, '2026-09'));
    }

    public function test_task_closed_after_the_last_run_waits_for_it(): void
    {
        $client = $this->client();
        $this->auditRow($client, '4', AutoAuditResult::MATCHED);
        $this->doneTask($client, $this->item($client, $this->osv), completedAt: '2026-10-08 18:00:00');

        $this->assertSame(AutoAuditClientBoard::WAITING_RUN, $this->statusOf($client, '2026-09'));
    }

    public function test_scan_is_not_verified(): void
    {
        $client = $this->client();
        $this->doneTask($client, $this->item($client, $this->osv));
        $this->auditRow($client, '4,5,6,7', AutoAuditResult::SCAN);

        $this->assertSame(AutoAuditClientBoard::UNVERIFIED, $this->statusOf($client, '2026-09'));
    }

    public function test_overdue_wins_over_a_question(): void
    {
        $client = $this->client();
        $this->item($client, $this->osv);
        $this->finding($this->auditRow($client, '4', AutoAuditResult::MISMATCH));

        $this->assertSame(AutoAuditClientBoard::OVERDUE, $this->statusOf($client, '2026-09'));
    }

    public function test_client_without_full_service_is_not_connected(): void
    {
        $client = $this->client(['serves_payroll' => false]);
        $this->item($client, $this->osv);

        $data = $this->board('2026-09');

        $this->assertNull(collect($data['rows'])->firstWhere('client.id', $client->id));
        $this->assertSame(1, $data['notConnected']);
    }

    // ── Квартальный ЕН ────────────────────────────────────────────────────

    public function test_quarterly_tax_result_stands_in_the_last_month_of_the_quarter(): void
    {
        $client = $this->client();
        $this->item($client, $this->tax);
        $this->auditRow($client, '1', AutoAuditResult::MISMATCH, '2026-07-01', '2026-09-30');

        $data  = $this->board('2026-09');
        $cells = collect($data['rows'])->firstWhere('client.id', $client->id)['cells'];

        $this->assertCount(1, $cells['2026-09']['issues']);
        $this->assertSame([], $cells['2026-07']['issues']);
        $this->assertSame([], $cells['2026-08']['issues']);
        // Задача по ЕН за третий квартал в октябре, то есть в клетке сентября.
        $this->assertSame('tax', $cells['2026-09']['tasks'][0]['side']);
        $this->assertSame(AutoAuditClientBoard::NONE, $cells['2026-08']['status']);
    }

    public function test_card_in_the_middle_of_a_quarter_says_where_the_tax_check_is(): void
    {
        $client = $this->client();
        $this->doneTask($client, $this->item($client, $this->osv), month: 9);
        $this->item($client, $this->tax);

        $this->asVendor()->get(route('auto-audit.clients.card', [$client->id, 'month' => '2026-08']))
            ->assertOk()
            ->assertSee('Отчёт по ЕН квартальный, его сверка в сентябрь 2026', false);
    }

    // ── Страница ──────────────────────────────────────────────────────────

    public function test_page_lists_only_clients_that_need_action_by_default(): void
    {
        $late = $this->client(['name' => 'ОсОО Просрочка']);
        $this->item($late, $this->osv);

        $fine = $this->client(['name' => 'ОсОО Порядок']);
        $this->doneTask($fine, $this->item($fine, $this->osv));
        $this->auditRow($fine, '4', AutoAuditResult::MATCHED);

        $this->asVendor()->get(route('auto-audit.clients'))
            ->assertOk()
            ->assertSee('ОсОО Просрочка')
            ->assertDontSee('ОсОО Порядок');

        $this->asVendor()->get(route('auto-audit.clients', ['all' => 1]))
            ->assertSee('ОсОО Просрочка')
            ->assertSee('ОсОО Порядок');
    }

    public function test_card_shows_the_mismatch_and_whose_turn_it_is(): void
    {
        $client = $this->client();
        $this->doneTask($client, $this->item($client, $this->osv));
        $this->finding($this->auditRow($client, '4', AutoAuditResult::MISMATCH));

        $this->asVendor()->get(route('auto-audit.clients.card', [$client->id, 'month' => '2026-09']))
            ->assertOk()
            ->assertSee('Что не так')
            ->assertSee('№4 ' . AutoAuditRunner::RULES[4]['name'])
            ->assertSee('Ход бухгалтера');
    }

    /**
     * Документы с обеих сторон есть, а пары нет: ОСВ на деле за прошлый год. Карточка не
     * пишет «Ничего не требует внимания» и показывает, за какой период прочитан каждый файл.
     * На бою 09.10.2026 так выглядел Дипмаркет.
     */
    public function test_card_without_a_pair_says_why_and_shows_read_periods(): void
    {
        $client = $this->client();
        $osv    = $this->doneTask($client, $this->item($client, $this->osv));
        $form   = $this->doneTask($client, $this->item($client, $this->form161));
        $this->fileRead($osv, 'осв.pdf', '2025-09-01', '2025-09-30');
        $this->fileRead($form, 'форма.pdf', '2026-09-01', '2026-09-30');
        $this->checkedAfterTasks();

        $this->assertSame(AutoAuditClientBoard::UNVERIFIED, $this->statusOf($client, '2026-09'));

        $this->asVendor()->get(route('auto-audit.clients.card', [$client->id, 'month' => '2026-09']))
            ->assertOk()
            ->assertSee('Сверка не сложилась')
            ->assertSee('осв.pdf</b>, прочитан как сентябрь 2025', false)
            ->assertSee('форма.pdf</b>, прочитан как сентябрь 2026', false)
            ->assertDontSee('Ничего не требует внимания')
            ->assertDontSee('В порядке');
    }

    /**
     * Только ОСВ: сверять её не с чем, и это не повод для действия. Клиент не в «Не
     * проверено» и не в списке по умолчанию. На бою за август таких было 8 из 15 «?».
     */
    public function test_only_a_sheet_is_nothing_to_compare(): void
    {
        $client = $this->client(['name' => 'ИП Только ОСВ']);
        $this->fileRead($this->doneTask($client, $this->item($client, $this->osv)), 'осв.pdf', '2026-09-01', '2026-09-30');
        $this->checkedAfterTasks();

        $cell = $this->cell($client, '2026-09');

        $this->assertSame(AutoAuditClientBoard::NOTHING, $cell['status']);
        $this->assertSame('есть только ОСВ', $cell['note']);

        $this->asVendor()->get(route('auto-audit.clients', ['month' => '2026-09']))
            ->assertOk()
            ->assertDontSee('ИП Только ОСВ');
    }

    /** Карточка «Сверять нечего»: спокойное пояснение, без оранжевого блока, задачи в порядке. */
    public function test_card_of_nothing_to_compare_explains_it_calmly(): void
    {
        $client = $this->client();
        $this->fileRead($this->doneTask($client, $this->item($client, $this->osv)), 'осв.pdf', '2026-09-01', '2026-09-30');
        $this->checkedAfterTasks();

        $this->asVendor()->get(route('auto-audit.clients.card', [$client->id, 'month' => '2026-09']))
            ->assertOk()
            ->assertSee('Сверять нечего: есть только ОСВ')
            ->assertSee('В порядке')
            ->assertDontSee('Почему не проверено')
            ->assertDontSee('Ничего не требует внимания');
    }

    /** Только форма 161, ведомости нет: сверять тоже нечего. */
    public function test_only_a_form_is_nothing_to_compare(): void
    {
        $client = $this->client();
        $this->fileRead($this->doneTask($client, $this->item($client, $this->form161)), 'форма.pdf', '2026-09-01', '2026-09-30');
        $this->checkedAfterTasks();

        $cell = $this->cell($client, '2026-09');

        $this->assertSame(AutoAuditClientBoard::NOTHING, $cell['status']);
        $this->assertSame('есть только форма 161', $cell['note']);
    }

    /** Отчёт по ЕН закрыт без файла как «Раз в квартал»: стороной он не считается. */
    public function test_tax_closed_as_quarterly_without_a_file_is_not_a_side(): void
    {
        $client = $this->client();
        $this->fileRead($this->doneTask($client, $this->item($client, $this->osv)), 'осв.pdf', '2026-09-01', '2026-09-30');
        $this->doneTask($client, $this->item($client, $this->tax))
            ->forceFill(['force_closed' => true, 'force_close_reason' => BuhTaskLog::FORCE_QUARTERLY])->save();
        $this->checkedAfterTasks();

        $this->assertSame(AutoAuditClientBoard::NOTHING, $this->statusOf($client, '2026-09'));
    }

    /**
     * Ответ ждёт решения: карточка пишет «Ход руководителя», а не имя главбуха клиента, и
     * даёт ссылку на ту же строку на «Все сверки». Решает там руководитель (09.10.2026).
     */
    public function test_card_sends_the_answer_to_the_manager_on_all_checks(): void
    {
        $client = $this->client();
        $this->doneTask($client, $this->item($client, $this->osv));
        $result  = $this->auditRow($client, '4', AutoAuditResult::MISMATCH);
        $finding = $this->finding($result);
        $finding->messages()->create([
            'result_id' => $result->id, 'employee_id' => $this->admin->id,
            'kind' => AutoAuditFindingMessage::EXPLAINED, 'body' => 'Взнос ИП за себя',
        ]);

        $this->asVendor()->get(route('auto-audit.clients.card', [$client->id, 'month' => '2026-09']))
            ->assertOk()
            ->assertSee('Ход руководителя')
            ->assertDontSee('Ход главбуха')
            ->assertSee(route('auto-audit.index', ['period' => '2026-09-01..2026-09-30', 'rule' => 4, 'status' => AutoAuditResult::MISMATCH]));
    }

    /** Требование: таблица грузит итог константным числом запросов, сколько бы ни было клиентов. */
    public function test_query_count_does_not_grow_with_clients(): void
    {
        $this->clients(5);
        $this->asVendor()->get(route('auto-audit.clients'))->assertOk();   // прогрев кешей справочников
        $few = $this->countQueries(fn () => $this->asVendor()->get(route('auto-audit.clients'))->assertOk());

        $this->clients(45);
        $many = $this->countQueries(fn () => $this->asVendor()->get(route('auto-audit.clients'))->assertOk());

        $this->assertSame($few, $many, "5 клиентов: {$few} запросов, 50 клиентов: {$many}");
    }

    // ── Помощники ─────────────────────────────────────────────────────────

    private function clients(int $n): void
    {
        for ($i = 0; $i < $n; $i++) {
            $client = $this->client();
            $this->doneTask($client, $this->item($client, $this->osv));
            $this->item($client, $this->form161);
            $this->item($client, $this->tax);
            $this->finding($this->auditRow($client, '4', AutoAuditResult::MISMATCH));
            $this->auditRow($client, '5', AutoAuditResult::MATCHED);
        }
    }

    private function countQueries(callable $fn): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $fn();
        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $count;
    }

    private function board(string $focus): array
    {
        $today = CarbonImmutable::now()->startOfDay();

        return (new AutoAuditClientBoard($today))->build(AutoAuditClientBoard::window(CarbonImmutable::parse($focus . '-01'), $today));
    }

    private function cell(Client $client, string $month): array
    {
        $row = collect($this->board($month)['rows'])->firstWhere('client.id', $client->id);
        $this->assertNotNull($row, 'Клиента нет в таблице');

        return $row['cells'][$month];
    }

    private function statusOf(Client $client, string $month): string
    {
        return $this->cell($client, $month)['status'];
    }

    private function employee(string $role, ?string $login = null): Employee
    {
        return Employee::firstOrCreate(
            ['email' => 'autoaudit.clients.' . ($login ?? $role . '.' . uniqid()) . '@example.com'],
            [
                'full_name' => 'Сотрудник ' . $role, 'position' => $role, 'password' => 'secret123',
                'role_id' => Role::where('name', $role)->value('id'),
                'status' => Employee::STATUS_ACTIVE,
            ],
        );
    }

    private function asVendor(): static
    {
        return $this->actingAs($this->admin, 'employee')->withSession([
            'vendor.tenant_id'   => $this->tenant->id,
            'vendor.tenant_name' => $this->tenant->name,
            'vendor.last_seen'   => now()->timestamp,
        ]);
    }

    private function service(string $name, int $ref, string $periodicity = 'Ежемесячно', array $months = [], int $day = 5): Service
    {
        return Service::create([
            'name' => $name . ' ' . uniqid(), 'periodicity' => $periodicity,
            'start_month' => $months, 'start_day' => [$day], 'is_active' => true, 'requires_document' => true,
            'reference_id' => $ref,
        ]);
    }

    private function client(array $attributes = []): Client
    {
        return Client::create(array_merge([
            'name' => 'ООО Клиент ' . uniqid(),
            'inn'  => '0210120252' . str_pad((string) (++self::$inn), 4, '0', STR_PAD_LEFT),
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

    /** Закрытая задача месяца $month (по умолчанию октябрь: работа за сентябрь). */
    private function doneTask(Client $client, EstimateItem $item, int $month = 10, string $completedAt = '2026-10-06 10:00:00'): BuhTaskLog
    {
        return BuhTaskLog::create([
            'employee_id' => $this->admin->id, 'client_id' => $client->id, 'estimate_item_id' => $item->id,
            'year' => 2026, 'month' => $month, 'status' => 'completed', 'completed_at' => $completedAt,
        ]);
    }

    private function auditRow(Client $client, string $rule, string $outcome, string $from = '2026-09-01', string $to = '2026-09-30'): AutoAuditResult
    {
        $mismatch = $outcome === AutoAuditResult::MISMATCH;

        return AutoAuditResult::create([
            'client_id' => $client->id, 'rule' => $rule, 'period_from' => $from, 'period_to' => $to,
            'outcome' => $outcome,
            'left_value' => $mismatch ? 1000 : 500, 'right_value' => 500, 'difference' => $mismatch ? 500 : 0,
            'sources' => [],
        ]);
    }

    /** Файл в задаче и период, который прочитал из него прогон. */
    private function fileRead(BuhTaskLog $log, string $name, string $from, string $to): void
    {
        $document = $log->documents()->create(['path' => "buh_task_documents/{$log->id}/{$name}", 'name' => $name]);

        AutoAuditDocumentRead::create([
            'document_id' => $document->id, 'client_id' => $log->client_id, 'side' => 'osv',
            'path' => $document->path, 'period_from' => $from, 'period_to' => $to, 'status' => 'found',
        ]);
    }

    /** Прогон был после закрытия задач: иначе клетка ждала бы ночной проверки. */
    private function checkedAfterTasks(): void
    {
        $this->auditRow($this->client(), '4', AutoAuditResult::MATCHED, '2026-06-01', '2026-06-30');
    }

    private function finding(AutoAuditResult $result, ?CarbonImmutable $openedAt = null): AutoAuditFinding
    {
        return AutoAuditFinding::create([
            'client_id' => $result->client_id, 'key' => $result->key(), 'opened_at' => $openedAt ?? now(),
        ]);
    }
}
