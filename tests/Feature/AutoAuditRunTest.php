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

        $this->assertSame(['1', '3'], $results->pluck('rule')->sort()->values()->all());
        $this->assertSame([AutoAuditResult::MATCHED], $results->pluck('outcome')->unique()->values()->all());

        $base = $results->firstWhere('rule', '1');
        $this->assertSame('87513.60', $base->left_value);
        $this->assertSame('87513.60', $base->right_value);
        $this->assertSame('0.00', $base->difference);
        $this->assertSame('июль 2026', $base->periodLabel());
        $this->assertSame('Налоговая база сходится с учётом', $base->ruleName());
        $this->assertCount(2, $base->sources);
    }

    public function test_different_numbers_are_a_mismatch_with_the_difference(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 150.00, '3410' => 6.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 6.00);

        $results = $this->runAudit();

        $base = $results->firstWhere('rule', '1');
        $this->assertSame(AutoAuditResult::MISMATCH, $base->outcome);
        $this->assertSame('50.00', $base->difference);

        $this->assertSame(AutoAuditResult::MATCHED, $results->firstWhere('rule', '3')->outcome);
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

        $base = $this->runAudit()->firstWhere('rule', '1');

        $this->assertSame(AutoAuditResult::MATCHED, $base->outcome);
        $this->assertSame('100.00', $base->right_value);
    }

    /** Без отчёта одного филиала сумма заведомо меньше: сравнивать нечего, строку не пишем. */
    public function test_missing_branch_report_writes_nothing(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 100.00, '3410' => 4.00]);

        $first = $this->item($client, $this->taxService, 'Бишкек');
        $this->item($client, $this->taxService, 'Ош');
        $this->attachReport($client, 'отчёт-бишкек.pdf', base: 60.00, tax: 2.40, item: $first);

        $this->assertCount(0, $this->runAudit());
    }

    /** Ведомость за июнь и отчёт за июль в пару не встают. */
    public function test_documents_for_different_periods_do_not_pair(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв-июнь.xls', ['3210' => 100.00, '3410' => 4.00], month: 6);
        $this->attachReport($client, 'отчёт-июль.pdf', base: 100.00, tax: 4.00, month: 7);

        $this->assertCount(0, $this->runAudit());
    }

    /**
     * Форма 161 вместо отчёта по налогу: в сверку файл не идёт, зато под проверками
     * клиента стоит «не тот документ». Остальных клиентов это не задевает.
     */
    public function test_wrong_document_is_listed_and_does_not_block_others(): void
    {
        $broken = $this->client();
        $this->attachSheet($broken, 'осв-сломанный.xls', ['3210' => 100.00, '3410' => 4.00]);
        $this->attachLog($broken, $this->item($broken, $this->taxService), 'форма-161.pdf');

        $fine = $this->client();
        $this->attachSheet($fine, 'осв.xls', ['3210' => 100.00, '3410' => 4.00]);
        $this->attachReport($fine, 'отчёт.pdf', base: 100.00, tax: 4.00);

        $results = $this->runAudit();

        $checks = $results->where('outcome', '!==', AutoAuditResult::WRONG_DOCUMENT);
        $this->assertCount(2, $checks);
        $this->assertSame([$fine->id], $checks->pluck('client_id')->unique()->values()->all());

        // Кассовый метод и полное обслуживание: файл ломает обе проверки, строка под каждой.
        $issues = $results->where('outcome', AutoAuditResult::WRONG_DOCUMENT);
        $this->assertSame(['1', '3'], $issues->pluck('rule')->sort()->values()->all());
        $this->assertSame([$broken->id], $issues->pluck('client_id')->unique()->values()->all());

        $issue = $issues->first();
        $this->assertSame('Среди файлов задачи нет отчёта по единому налогу', $issue->reason);
        $this->assertSame('форма-161.pdf', $issue->sources[0]['name']);
        $this->assertSame('Это не отчёт по единому налогу', $issue->sources[0]['reason']);
        $this->assertSame('08.2026', $issue->sources[0]['task_month']);

        // Из файла период не прочитать: берём месяц перед августовской задачей.
        $this->assertSame('июль 2026', $issue->periodLabel());
    }

    /** Квитанция рядом с настоящим отчётом не ошибка: нужная форма в задаче есть. */
    public function test_extra_file_next_to_the_right_one_is_not_flagged(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 100.00, '3410' => 4.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);

        $log  = BuhTaskLog::orderByDesc('id')->first();
        $path = "buh_task_documents/{$log->id}/квитанция.pdf";
        Storage::disk('local')->put($path, 'x');
        $log->documents()->create(['path' => $path, 'name' => 'квитанция.pdf']);

        $results = $this->runAudit();

        $this->assertCount(0, $results->where('outcome', AutoAuditResult::WRONG_DOCUMENT));
        $this->assertSame([AutoAuditResult::MATCHED], $results->pluck('outcome')->unique()->values()->all());
    }

    /**
     * Не тот документ стоит только под проверками, что подходят клиенту. Второй стороны
     * у клиента нет вовсе, а строка всё равно есть: ошибка в файле от этого не пропадает.
     */
    public function test_wrong_document_follows_check_applicability(): void
    {
        $accrual = $this->client(['accounting_method' => Client::ACCOUNTING_ACCRUAL]);
        $this->attachLog($accrual, $this->item($accrual, $this->osvService), 'скриншот.pdf');

        $narrowed = $this->client(['serves_accounting' => false, 'serves_payroll' => false]);
        $this->attachLog($narrowed, $this->item($narrowed, $this->osvService), 'скриншот-2.pdf');

        $results = $this->runAudit();

        $this->assertCount(1, $results);

        $issue = $results->first();
        $this->assertSame($accrual->id, $issue->client_id);
        $this->assertSame('3', $issue->rule);
        $this->assertSame(AutoAuditResult::WRONG_DOCUMENT, $issue->outcome);
        $this->assertSame('Среди файлов задачи нет оборотно-сальдовой ведомости', $issue->reason);
    }

    /** 1С не печатает счёт без оборотов: нет строки в ведомости, значит оборот нулевой. */
    public function test_account_missing_from_the_sheet_counts_as_zero(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 100.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 0.00);

        $tax = $this->runAudit()->firstWhere('rule', '3');

        $this->assertSame(AutoAuditResult::MATCHED, $tax->outcome);
        $this->assertStringContainsString('нет счёта 3410', $tax->reason);
    }

    /** Налоговая база и оборот 3210 сходятся только при кассовом методе. */
    public function test_accrual_client_gets_only_rule_3(): void
    {
        $client = $this->client(['accounting_method' => Client::ACCOUNTING_ACCRUAL]);
        $this->attachSheet($client, 'осв.xls', ['3210' => 100.00, '3410' => 4.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);

        $this->assertSame(['3'], $this->runAudit()->pluck('rule')->all());
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

        $this->asVendor()->get(route('employees.index'))->assertSee(route('auto-audit.index'));

        $this->asVendor()
            ->post(route('auto-audit.run'))
            ->assertRedirect(route('auto-audit.index'))
            ->assertSessionHas('success');

        $this->asVendor()
            ->get(route('auto-audit.index'))
            ->assertOk()
            ->assertSee('Отчётный период')
            ->assertSee('№1 Налоговая база сходится с учётом')
            ->assertSee('№3 Начисленный единый налог сходится с учётом')
            ->assertSee($client->name)
            ->assertSee('50,00')
            ->assertSee('Не совпало')
            ->assertSee('осв.xls');
    }

    /** По умолчанию виден самый свежий отчётный период. */
    public function test_latest_period_is_shown_by_default(): void
    {
        $june = $this->client(['name' => 'ООО Июньский ' . uniqid()]);
        $this->attachSheet($june, 'осв-июнь.xls', ['3210' => 1.00, '3410' => 1.00], month: 6);
        $this->attachReport($june, 'отчёт-июнь.pdf', base: 1.00, tax: 1.00, month: 6);

        $july = $this->client(['name' => 'ООО Июльский ' . uniqid()]);
        $this->attachSheet($july, 'осв-июль.xls', ['3210' => 1.00, '3410' => 1.00]);
        $this->attachReport($july, 'отчёт-июль.pdf', base: 1.00, tax: 1.00);

        $this->runAudit();

        $this->asVendor()->get(route('auto-audit.index'))
            ->assertSee($july->name)
            ->assertDontSee($june->name);

        $this->asVendor()->get(route('auto-audit.index', ['period' => '2026-06-01..2026-06-30']))
            ->assertSee($june->name)
            ->assertDontSee($july->name);

        // Мусор в адресе не ломает страницу: показываем самый свежий период.
        $this->asVendor()->get(route('auto-audit.index', ['period' => 'z']))
            ->assertOk()
            ->assertSee($july->name);
    }

    /** Не тот документ стоит в общем списке своего периода, со статусом в конце строки. */
    public function test_wrong_document_shows_in_the_list_of_its_period(): void
    {
        $fine = $this->client(['name' => 'ООО Сошлось ' . uniqid()]);
        $this->attachSheet($fine, 'осв.xls', ['3210' => 1.00, '3410' => 1.00]);
        $this->attachReport($fine, 'отчёт.pdf', base: 1.00, tax: 1.00);

        $broken = $this->client(['name' => 'ООО Форма 161 ' . uniqid()]);
        $this->attachLog($broken, $this->item($broken, $this->taxService), 'форма-161.pdf');

        $this->runAudit();

        $this->asVendor()->get(route('auto-audit.index'))
            ->assertOk()
            ->assertSee($fine->name)
            ->assertSee($broken->name)
            ->assertDontSee('Документ:')
            // Период у такой строки не из файла, и это подписано.
            ->assertSeeInOrder([$broken->name, 'июль 2026', 'по месяцу задачи', 'форма-161.pdf'])
            ->assertSee('Не тот документ')
            ->assertSee('форма-161.pdf')
            ->assertSee('Это не отчёт по единому налогу');
    }

    private function asVendor(): static
    {
        return $this->actingAs($this->admin, 'employee')->withSession([
            'vendor.tenant_id'   => $this->tenant->id,
            'vendor.tenant_name' => $this->tenant->name,
            'vendor.last_seen'   => now()->timestamp,
        ]);
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
