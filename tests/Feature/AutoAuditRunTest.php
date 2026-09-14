<?php

namespace Tests\Feature;

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
use App\Services\AutoAudit\AutoAuditRunner;
use App\Services\AutoAudit\BalanceSheetReader;
use App\Services\AutoAudit\DocumentPeriod;
use App\Services\AutoAudit\DocumentValue;
use App\Services\AutoAudit\SingleTaxReportReader;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Первый вариант автоаудита: ОСВ против отчёта по единому налогу.
 *
 * Читалки подменены: как разбирать сами файлы, проверяют их юнит-тесты. Здесь важно
 * другое: какие документы попадают в сверку, как они встают в пары по периоду, как
 * складываются филиалы и что видно на странице. Подменённая читалка отвечает по имени
 * файла тем, что тест положил в $sheets и $reports.
 */
class AutoAuditRunTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Employee $admin;
    private Service $osvService;
    private Service $taxService;

    /** Имя файла => ['month' => [год, месяц], 'accounts' => [счёт => оборот]]. */
    private array $sheets = [];

    /** Имя файла => ['month' => [год, месяц], 'base' => ..., 'tax' => ...]. */
    private array $reports = [];

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

        Storage::fake('local');
        Periodicity::firstOrCreate(['name' => 'Ежемесячно'], ['kind' => 'monthly']);

        // Отдельная фирма: прогон стирает результаты фирмы целиком, чужие трогать нельзя.
        $this->tenant = Tenant::create([
            'name'   => 'Фирма автоаудита ' . uniqid(),
            'slug'   => 'autoaudit-' . uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);
        TenantContext::set($this->tenant);

        $this->admin = Employee::create([
            'full_name' => 'Админ автоаудита', 'position' => 'Администратор',
            'email' => 'autoaudit.' . uniqid() . '@example.com', 'password' => 'secret123',
            'role_id' => Role::where('name', Role::ADMIN)->value('id'),
            'status' => Employee::STATUS_ACTIVE,
        ]);

        $this->osvService = $this->service('Закрытие месяца и формирование ОСВ', AutoAuditRunner::REF_BALANCE_SHEET);
        $this->taxService = $this->service('Отчёт по единому налогу', AutoAuditRunner::REF_TAX_REPORT, splitsByBranch: true);

        $test = $this;

        $this->app->instance(BalanceSheetReader::class, new class($test) extends BalanceSheetReader {
            public function __construct(private AutoAuditRunTest $test) {}

            public function turnover(string $path, string $account, string $side = 'credit'): DocumentValue
            {
                return $this->test->fakeSheet(basename($path), $account);
            }
        });

        $this->app->instance(SingleTaxReportReader::class, new class($test) extends SingleTaxReportReader {
            public function __construct(private AutoAuditRunTest $test) {}

            public function taxableBase(string $path): DocumentValue
            {
                return $this->test->fakeReport(basename($path), 'base');
            }

            public function totalTax(string $path): DocumentValue
            {
                return $this->test->fakeReport(basename($path), 'tax');
            }
        });
    }

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }

    public function fakeSheet(string $file, string $account): DocumentValue
    {
        $sheet = $this->sheets[$file] ?? null;

        if (!$sheet) {
            return DocumentValue::wrongDocument('Это не оборотно-сальдовая ведомость');
        }

        $period = DocumentPeriod::of(...$sheet['month']);

        return array_key_exists($account, $sheet['accounts'])
            ? DocumentValue::found($sheet['accounts'][$account], [], $period)
            : DocumentValue::notFound("В ведомости нет счёта {$account}", [], $period);
    }

    public function fakeReport(string $file, string $field): DocumentValue
    {
        $report = $this->reports[$file] ?? null;

        if (!$report) {
            return DocumentValue::wrongDocument('Это не отчёт по единому налогу');
        }

        return DocumentValue::found($report[$field], [], DocumentPeriod::of(...$report['month']));
    }

    public function test_equal_numbers_match_for_both_rules(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 87513.60, '3410' => 3500.54]);
        $this->attachReport($client, 'отчёт.pdf', base: 87513.60, tax: 3500.54);

        $results = $this->runAudit();

        $this->assertCount(2, $results);
        $this->assertSame([AutoAuditResult::MATCHED], $results->pluck('outcome')->unique()->values()->all());

        $base = $results->firstWhere('rule', 'osv_3210_tax_base');
        $this->assertSame('87513.60', $base->left_value);
        $this->assertSame('87513.60', $base->right_value);
        $this->assertSame('0.00', $base->difference);
        $this->assertSame('июль 2026', $base->periodLabel());
        $this->assertCount(2, $base->sources);
    }

    public function test_different_numbers_are_a_mismatch_with_the_difference(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 150.00, '3410' => 6.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 6.00);

        $results = $this->runAudit();

        $base = $results->firstWhere('rule', 'osv_3210_tax_base');
        $this->assertSame(AutoAuditResult::MISMATCH, $base->outcome);
        $this->assertSame('50.00', $base->difference);

        $this->assertSame(AutoAuditResult::MATCHED, $results->firstWhere('rule', 'osv_3410_tax_total')->outcome);
    }

    /** Филиальный БП: отчёт на каждый налоговый орган, в сумме они дают ведомость. */
    public function test_branch_reports_are_summed(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 100.00, '3410' => 4.00]);

        $first  = $this->item($client, $this->taxService, 'Бишкек');
        $second = $this->item($client, $this->taxService, 'Ош');
        $this->attachReport($client, 'отчёт-бишкек.pdf', base: 60.00, tax: 2.40, item: $first);
        $this->attachReport($client, 'отчёт-ош.pdf', base: 40.00, tax: 1.60, item: $second);

        $base = $this->runAudit()->firstWhere('rule', 'osv_3210_tax_base');

        $this->assertSame(AutoAuditResult::MATCHED, $base->outcome);
        $this->assertSame('100.00', $base->right_value);
    }

    /** Без отчёта одного филиала сумма заведомо меньше: это не расхождение, а нехватка. */
    public function test_missing_branch_report_is_not_a_mismatch(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 100.00, '3410' => 4.00]);

        $first = $this->item($client, $this->taxService, 'Бишкек');
        $this->item($client, $this->taxService, 'Ош');
        $this->attachReport($client, 'отчёт-бишкек.pdf', base: 60.00, tax: 2.40, item: $first);

        $base = $this->runAudit()->firstWhere('rule', 'osv_3210_tax_base');

        $this->assertSame(AutoAuditResult::NO_DOCUMENTS, $base->outcome);
        $this->assertStringContainsString('филиалов 2', $base->reason);
    }

    /** Ведомость за июнь и отчёт за июль в пару не встают: у каждого нет своей половины. */
    public function test_documents_for_different_periods_do_not_pair(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв-июнь.xls', ['3210' => 100.00, '3410' => 4.00], month: 6);
        $this->attachReport($client, 'отчёт-июль.pdf', base: 100.00, tax: 4.00, month: 7);

        $base = $this->runAudit()->where('rule', 'osv_3210_tax_base');

        $this->assertCount(2, $base);
        $this->assertSame([AutoAuditResult::NO_DOCUMENTS], $base->pluck('outcome')->unique()->values()->all());
        $this->assertEqualsCanonicalizing(
            ['Нет отчёта по налогу за этот период', 'Нет ведомости за этот период'],
            $base->pluck('reason')->all(),
        );
    }

    /** Приложили не тот документ: показываем причину, а не выдуманное число. */
    public function test_unreadable_document_is_shown_with_its_reason(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 100.00, '3410' => 4.00]);
        $this->attachLog($client, $this->item($client, $this->taxService), 'форма-161.pdf');

        $broken = $this->runAudit()
            ->where('rule', 'osv_3210_tax_base')
            ->first(fn (AutoAuditResult $r) => $r->period_from === null);

        $this->assertSame(AutoAuditResult::NO_DOCUMENTS, $broken->outcome);
        $this->assertStringContainsString('Это не отчёт по единому налогу', $broken->reason);
        $this->assertSame('форма-161.pdf', $broken->sources[0]['name']);
    }

    /** 1С не печатает счёт без оборотов: нет строки в ведомости, значит оборот нулевой. */
    public function test_account_missing_from_the_sheet_counts_as_zero(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 100.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 0.00);

        $tax = $this->runAudit()->firstWhere('rule', 'osv_3410_tax_total');

        $this->assertSame(AutoAuditResult::MATCHED, $tax->outcome);
        $this->assertStringContainsString('нет счёта 3410', $tax->reason);
    }

    /** Налоговая база и оборот 3210 сходятся только при кассовом методе. */
    public function test_accrual_client_gets_only_the_tax_total_rule(): void
    {
        $client = $this->client(['accounting_method' => Client::ACCOUNTING_ACCRUAL]);
        $this->attachSheet($client, 'осв.xls', ['3210' => 100.00, '3410' => 4.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);

        $this->assertSame(['osv_3410_tax_total'], $this->runAudit()->pluck('rule')->all());
    }

    /** Ведём не всё: ведомость или отчёт делает кто-то другой, сверять незачем. */
    public function test_narrowed_service_is_skipped(): void
    {
        $client = $this->client(['serves_accounting' => false, 'serves_payroll' => false]);
        $this->attachSheet($client, 'осв.xls', ['3210' => 100.00, '3410' => 4.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);

        $this->assertCount(0, $this->runAudit());
    }

    /** В незакрытой задаче документ может быть черновиком. */
    public function test_documents_of_unfinished_tasks_are_ignored(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 100.00, '3410' => 4.00], status: 'running');
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00, status: 'running');

        $this->assertCount(0, $this->runAudit());
    }

    public function test_rerun_replaces_previous_results(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 100.00, '3410' => 4.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);

        $this->runAudit();

        $this->assertCount(2, $this->runAudit());
    }

    public function test_firm_staff_cannot_see_or_run_the_audit(): void
    {
        $this->actingAs($this->admin, 'employee')->get(route('auto-audit.index'))->assertNotFound();
        $this->actingAs($this->admin, 'employee')->post(route('auto-audit.run'))->assertNotFound();

        $this->actingAs($this->admin, 'employee')
            ->get(route('employees.index'))
            ->assertOk()
            ->assertDontSee(route('auto-audit.index'));
    }

    public function test_vendor_inside_the_firm_runs_the_audit_and_sees_the_result(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 150.00, '3410' => 4.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);

        $vendor = [
            'vendor.tenant_id'   => $this->tenant->id,
            'vendor.tenant_name' => $this->tenant->name,
            'vendor.last_seen'   => now()->timestamp,
        ];

        $this->actingAs($this->admin, 'employee')->withSession($vendor)
            ->get(route('employees.index'))
            ->assertSee(route('auto-audit.index'));

        $this->actingAs($this->admin, 'employee')->withSession($vendor)
            ->post(route('auto-audit.run'))
            ->assertRedirect(route('auto-audit.index'))
            ->assertSessionHas('success');

        $this->actingAs($this->admin, 'employee')->withSession($vendor)
            ->get(route('auto-audit.index'))
            ->assertOk()
            ->assertSee($client->name)
            ->assertSee('Не совпало')
            ->assertSee('50,00')
            ->assertSee('осв.xls');
    }

    private function runAudit(): Collection
    {
        app(AutoAuditRunner::class)->run();

        return AutoAuditResult::orderBy('id')->get();
    }

    private function service(string $name, int $referenceId, bool $splitsByBranch = false): Service
    {
        return Service::create([
            'name' => $name . ' ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_day' => [5], 'is_active' => true, 'requires_document' => true,
            'reference_id' => $referenceId, 'splits_by_branch' => $splitsByBranch,
        ]);
    }

    private function client(array $attributes = []): Client
    {
        return Client::create(array_merge([
            'name' => 'ООО Сверка ' . uniqid(),
            'inn' => strtoupper(substr(md5(uniqid()), 0, 12)),
            'responsible_employee_id' => $this->admin->id,
            'accounting_method' => Client::ACCOUNTING_CASH,
            'serves_accounting' => true, 'serves_tax' => true, 'serves_payroll' => true,
        ], $attributes));
    }

    /** Строка сметы по БП. У филиального БП их столько, сколько налоговых органов. */
    private function item(Client $client, Service $service, ?string $branch = null): EstimateItem
    {
        $estimate = Estimate::firstOrCreate(['client_id' => $client->id], ['total' => 0]);

        return $estimate->items()->create([
            'service_id' => $service->id, 'type' => 'recurring',
            'name' => $service->name, 'periodicity' => 'Ежемесячно', 'branch_label' => $branch,
            'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 0,
        ]);
    }

    private function attachSheet(Client $client, string $file, array $accounts, int $month = 7, string $status = 'completed'): void
    {
        $this->sheets[$file] = ['month' => [2026, $month], 'accounts' => $accounts];
        $this->attachLog($client, $this->item($client, $this->osvService), $file, $status);
    }

    private function attachReport(
        Client $client,
        string $file,
        float $base,
        float $tax,
        int $month = 7,
        string $status = 'completed',
        ?EstimateItem $item = null,
    ): void {
        $this->reports[$file] = ['month' => [2026, $month], 'base' => $base, 'tax' => $tax];
        $this->attachLog($client, $item ?? $this->item($client, $this->taxService), $file, $status);
    }

    /** Задача за август (отчитываются в следующем месяце) с приложенным файлом. */
    private function attachLog(Client $client, EstimateItem $item, string $file, string $status = 'completed'): void
    {
        $log = BuhTaskLog::create([
            'employee_id' => $this->admin->id, 'client_id' => $client->id,
            'estimate_item_id' => $item->id, 'year' => 2026, 'month' => 8, 'status' => $status,
        ]);

        $path = "buh_task_documents/{$log->id}/{$file}";
        Storage::disk('local')->put($path, 'x');
        $log->documents()->create(['path' => $path, 'name' => $file]);
    }
}
