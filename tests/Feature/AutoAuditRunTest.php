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

    /** Чтобы у клиентов одного теста ИНН были разные. */
    private static int $innCounter = 0;

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

        $this->app->instance(\App\Services\AutoAudit\Form161Reader::class, new class($test) extends \App\Services\AutoAudit\Form161Reader {
            public function __construct(private AutoAuditRunTest $test) {}

            public function read(string $path, string $field): DocumentValue
            {
                return $this->test->fakeForm(basename($path), $field);
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

        if (!array_key_exists($account, $sheet['accounts'])) {
            return DocumentValue::notFound("В ведомости нет счёта {$account}", [], $period);
        }

        // null в тесте: строка счёта есть, а число из ячейки прочитать не удалось.
        if ($sheet['accounts'][$account] === null) {
            return DocumentValue::uncertain("Не разобрали оборот по счёту {$account}", [], $period);
        }

        return DocumentValue::found($sheet['accounts'][$account], [], $period);
    }

    public function fakeReport(string $file, string $field): DocumentValue
    {
        $report = $this->reports[$file] ?? null;

        if (!$report) {
            return DocumentValue::wrongDocument('Это не отчёт по единому налогу');
        }

        $period = $report['period'] ?? DocumentPeriod::of(...$report['month']);

        // null в тесте: ИНН из шапки прочитать не удалось.
        return DocumentValue::found($report[$field], [], $period, $report['inn'] ?? null);
    }

    /** Квартальный отчёт сравниваем с суммой трёх помесячных ведомостей. */
    public function test_quarterly_report_is_compared_with_three_monthly_sheets(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв-апрель.xls', ['3210' => 100.00, '3410' => 4.00], month: 4);
        $this->attachSheet($client, 'осв-май.xls', ['3210' => 200.00, '3410' => 8.00], month: 5);
        $this->attachSheet($client, 'осв-июнь.xls', ['3210' => 300.00, '3410' => 12.00], month: 6);
        $this->attachQuarterReport($client, 'отчёт-2кв.pdf', base: 600.00, tax: 25.00);

        $results = $this->runAudit();

        $this->assertCount(2, $results);

        $base = $results->firstWhere('rule', '1');
        $this->assertSame(AutoAuditResult::MATCHED, $base->outcome);
        $this->assertSame('600.00', $base->left_value);
        $this->assertSame('2 квартал 2026', $base->periodLabel());
        $this->assertCount(4, $base->sources);

        $tax = $results->firstWhere('rule', '3');
        $this->assertSame(AutoAuditResult::MISMATCH, $tax->outcome);
        $this->assertSame('-1.00', $tax->difference);
    }

    /** Нет ведомости за месяц квартала: оборот не сложить, но видно, какого месяца не хватает. */
    public function test_quarter_without_one_monthly_sheet_says_which_month_is_missing(): void
    {
        $client = $this->client(['accounting_method' => Client::ACCOUNTING_ACCRUAL]);
        $this->attachSheet($client, 'осв-апрель.xls', ['3210' => 1.00, '3410' => 4.00], month: 4);
        $this->attachSheet($client, 'осв-июнь.xls', ['3210' => 1.00, '3410' => 12.00], month: 6);
        $this->attachQuarterReport($client, 'отчёт-2кв.pdf', base: 3.00, tax: 24.00);

        $results = $this->runAudit();

        $this->assertCount(1, $results);

        $row = $results->first();
        $this->assertSame(AutoAuditResult::MISSING_DOCUMENT, $row->outcome);
        $this->assertSame('3', $row->rule);
        $this->assertSame('Нет ведомости за май 2026', $row->reason);
        $this->assertNull($row->left_value);
        $this->assertNull($row->right_value);
        $this->assertSame('2 квартал 2026', $row->periodLabel());
        $this->assertFalse($row->periodFromTask());
        $this->assertCount(3, $row->sources);

        $this->asVendor()->get(route('auto-audit.index'))
            ->assertSeeInOrder([$client->name, '2 квартал 2026', 'отчёт-2кв.pdf', 'Нет документа', 'Нет ведомости за май 2026']);
    }

    /**
     * Задача по ОСВ закрыта без файла: одна строка на клиента сразу для обеих проверок, с
     * исполнителем. Отчёт за тот же период виден рядом.
     */
    public function test_task_closed_without_file_is_one_row_for_all_checks(): void
    {
        $client = $this->client(['name' => 'ООО Без ОСВ ' . uniqid()]);
        $this->closeWithoutFile($client, $this->osvService);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);

        $results = $this->runAudit();

        $this->assertCount(1, $results);

        $row = $results->first();
        $this->assertSame(AutoAuditResult::MISSING_DOCUMENT, $row->outcome);
        $this->assertSame([1, 3], $row->ruleNumbers());
        $this->assertSame('июль 2026', $row->periodLabel());
        $this->assertTrue($row->periodFromTask());
        $this->assertStringContainsString('за 08.2026: исполнитель Админ автоаудита, закрыта 05.08.2026 без файла', $row->reason);
        $this->assertSame(['отчёт.pdf'], array_column($row->sources, 'name'));
        $this->assertNull($row->sources[0]['value']);

        $this->asVendor()->get(route('auto-audit.index'))
            ->assertSeeInOrder([
                '№1 Налоговая база сходится с учётом',
                '№3 Начисленный единый налог сходится с учётом',
                $client->name,
                'по месяцу задачи',
                'отчёт.pdf',
                'Нет документа',
            ]);
    }

    /** Обе задачи закрыты без файлов: всё равно одна строка, в причине обе задачи. */
    public function test_both_tasks_without_files_make_one_row(): void
    {
        $client = $this->client();
        $this->closeWithoutFile($client, $this->osvService);
        $this->closeWithoutFile($client, $this->taxService);

        $results = $this->runAudit();

        $this->assertCount(1, $results);
        $this->assertStringContainsString($this->osvService->name, $results->first()->reason);
        $this->assertStringContainsString($this->taxService->name, $results->first()->reason);
    }

    /** Незакрытая задача без файла это обычная работа, её показывает БухЗадачник. */
    public function test_unfinished_task_without_file_is_not_shown(): void
    {
        $client = $this->client();
        $this->closeWithoutFile($client, $this->osvService, status: 'running');

        $this->assertCount(0, $this->runAudit());
    }

    /** Клиенту не подходит ни одна проверка: и строки «нет документа» у него нет. */
    public function test_task_without_file_is_ignored_where_no_check_applies(): void
    {
        $client = $this->client(['serves_accounting' => false, 'serves_payroll' => false]);
        $this->closeWithoutFile($client, $this->osvService);

        $this->assertCount(0, $this->runAudit());
    }

    /** Задача за август закрыта, а файла в ней нет вовсе. */
    private function closeWithoutFile(Client $client, Service $service, string $status = 'completed'): BuhTaskLog
    {
        return BuhTaskLog::create([
            'employee_id' => $this->admin->id, 'client_id' => $client->id,
            'estimate_item_id' => $this->item($client, $service)->id,
            'year' => 2026, 'month' => 8, 'status' => $status,
            'completed_at' => $status === 'completed' ? '2026-08-05 10:00:00' : null,
        ]);
    }

    /** Ни одной ведомости за квартал: пары нет вовсе, как и у помесячного отчёта. */
    public function test_quarter_without_any_sheet_writes_nothing(): void
    {
        $client = $this->client();
        $this->attachQuarterReport($client, 'отчёт-2кв.pdf', base: 3.00, tax: 24.00);

        $this->assertCount(0, $this->runAudit());
    }

    /** Имя файла => ['month' => [год, месяц], 'income' => ..., 'inn' => ...]. */
    private array $forms = [];

    public function fakeForm(string $file, string $field = 'income'): DocumentValue
    {
        $form = $this->forms[$file] ?? null;

        if (!$form) {
            return DocumentValue::wrongDocument('Это не форма 161');
        }

        $period = DocumentPeriod::of(...$form['month']);

        // null в тесте: клетку в итоговой строке не нашли.
        if (array_key_exists($field, $form) && $form[$field] === null) {
            return DocumentValue::uncertain("В форме 161 не нашли колонку «{$field}»", [], $period, $form['inn']);
        }

        return DocumentValue::found($form[$field] ?? 0.0, [], $period, $form['inn']);
    }

    /** Проверки №5-№7: налог к уплате, взносы и НПФ из той же формы 161 против своих счетов. */
    public function test_payroll_taxes_are_compared_with_form_161(): void
    {
        $f161   = $this->service('Форма 161 и зарплатные налоги', AutoAuditRunner::REF_FORM_161);
        $client = $this->client(['accounting_method' => Client::ACCOUNTING_ACCRUAL]);
        $this->attachSheet($client, 'осв.xls', ['3520' => 214400.00, '3420' => 19907.20, '3531' => 21976.00, '3534' => 4000.00]);

        $this->forms['форма-161.pdf'] = [
            'month' => [2026, 7], 'inn' => $client->inn,
            'income' => 214400.00, 'income_tax' => 19907.20, 'contributions' => 21976.00, 'pension' => 4288.00,
        ];
        $this->attachLog($client, $this->item($client, $f161), 'форма-161.pdf');

        $results = $this->runAudit();

        // Отчёта по налогу у клиента нет, поэтому строки только по форме 161.
        $this->assertSame(['4', '5', '6', '7'], $results->pluck('rule')->sort()->values()->all());

        $results = $results->keyBy('rule');
        $this->assertSame(AutoAuditResult::MATCHED, $results['4']->outcome);
        $this->assertSame(AutoAuditResult::MATCHED, $results['5']->outcome);
        $this->assertSame('19907.20', $results['5']->right_value);
        $this->assertSame(AutoAuditResult::MATCHED, $results['6']->outcome);
        $this->assertSame(AutoAuditResult::MISMATCH, $results['7']->outcome);
        $this->assertSame('-288.00', $results['7']->difference);
        $this->assertSame('Взносы в НПФ сходятся с учётом', $results['7']->ruleName());
    }

    /** Нет формы 161: «нет документа» ломает сразу все четыре проверки по ней. */
    public function test_missing_form_161_breaks_checks_4_to_7(): void
    {
        $f161   = $this->service('Форма 161 и зарплатные налоги', AutoAuditRunner::REF_FORM_161);
        $client = $this->client(['accounting_method' => Client::ACCOUNTING_ACCRUAL]);
        $this->attachSheet($client, 'осв.xls', ['3520' => 1000.00]);
        $this->closeWithoutFile($client, $f161);

        $missing = $this->runAudit()->firstWhere('outcome', AutoAuditResult::MISSING_DOCUMENT);

        $this->assertSame([4, 5, 6, 7], $missing->ruleNumbers());
    }

    /** Проверка №4: оборот Кт 3520 сверяем с доходом из формы 161. */
    public function test_payroll_check_compares_3520_with_form_161(): void
    {
        $f161   = $this->service('Форма 161 и зарплатные налоги', AutoAuditRunner::REF_FORM_161, splitsByBranch: true);
        $client = $this->client(['accounting_method' => Client::ACCOUNTING_ACCRUAL, 'inn' => '00907202510583']);
        $this->attachSheet($client, 'осв.xls', ['3520' => 25000.00]);
        $this->attachForm($client, $f161, 'форма-161.pdf', income: 25000.00, inn: '00907202510583');

        $results = $this->runAudit();

        // По форме 161 сразу четыре проверки: доход, налог к уплате, взносы и НПФ.
        $this->assertSame(['4', '5', '6', '7'], $results->pluck('rule')->sort()->values()->all());

        $row = $results->firstWhere('rule', '4');
        $this->assertSame(AutoAuditResult::MATCHED, $row->outcome);
        $this->assertSame('25000.00', $row->left_value);
        $this->assertSame(['ОСВ', 'Форма 161'], array_column($row->sources, 'label'));

        // Исполнитель у каждого документа свой, поэтому и показан у документа, а не у строки.
        $this->assertSame(['Админ А.', 'Админ А.'], array_column($row->sources, 'employee'));

        $this->asVendor()->get(route('auto-audit.index'))
            ->assertSeeInOrder([
                '№4 Начисленный доход сходится с учётом',
                $client->name,
                'ОСВ, задача за 08.2026',
                'Админ А.',
                'Форма 161, задача за 08.2026',
                'Админ А.',
                'Совпало',
            ]);
    }

    /** Чужая форма 161: ИНН не тот, что в карточке. Это «не тот документ», а не расхождение в цифрах. */
    public function test_form_161_of_another_company_is_a_wrong_document(): void
    {
        $f161   = $this->service('Форма 161 и зарплатные налоги', AutoAuditRunner::REF_FORM_161);
        $client = $this->client(['accounting_method' => Client::ACCOUNTING_ACCRUAL, 'inn' => '00907202510583']);
        $this->attachSheet($client, 'осв.xls', ['3520' => 25000.00]);
        $this->attachForm($client, $f161, 'форма-чужая.pdf', income: 16000.00, inn: '02101202510267');

        $results = $this->runAudit();

        // Чужая форма ломает все четыре проверки по ней.
        $this->assertSame(['4', '5', '6', '7'], $results->pluck('rule')->sort()->values()->all());
        $this->assertSame([AutoAuditResult::WRONG_DOCUMENT], $results->pluck('outcome')->unique()->values()->all());

        // Раньше в строке писалось «Среди файлов задачи нет формы 161», и человек шёл искать
        // файл, который лежит на месте. Настоящая причина была спрятана внутри источника.
        $row = $results->firstWhere('rule', '4');
        $expected = 'ИНН не совпадает: в документе 02101202510267, в карточке клиента 00907202510583. Документ чужой или ошибка в карточке';
        $this->assertSame($expected, $row->reason);
        $this->assertSame($expected, $row->sources[0]['reason']);
    }

    /**
     * В карточке нет настоящего ИНН: сверить документ с клиентом не с чем.
     *
     * Раньше сверка тут молча выключалась, и чужой документ проходил как свой. Заглушка
     * «00000000000003» из импорта тоже не ИНН: клиентов заводили, пока ИНН не знали.
     */
    public function test_client_card_without_a_real_inn_is_not_verified(): void
    {
        $f161 = $this->service('Форма 161 и зарплатные налоги', AutoAuditRunner::REF_FORM_161);

        $empty = $this->client(['accounting_method' => Client::ACCOUNTING_ACCRUAL, 'inn' => '']);
        $this->attachSheet($empty, 'осв-пустой.xls', ['3520' => 25000.00]);
        $this->attachForm($empty, $f161, 'форма-пустой.pdf', income: 25000.00, inn: '02101202510267');

        $stub = $this->client(['accounting_method' => Client::ACCOUNTING_ACCRUAL, 'inn' => '00000000000003']);
        $this->attachSheet($stub, 'осв-заглушка.xls', ['3520' => 25000.00]);
        $this->attachForm($stub, $f161, 'форма-заглушка.pdf', income: 25000.00, inn: '21402198800720');

        $results = $this->runAudit();

        // Числа сошлись бы, но проверить, что документ этого клиента, нечем.
        $this->assertSame(
            [AutoAuditResult::UNVERIFIED, AutoAuditResult::UNVERIFIED],
            $results->where('rule', '4')->pluck('outcome')->values()->all(),
        );
        $this->assertStringContainsString(
            'В карточке клиента нет ИНН из 14 цифр',
            $results->firstWhere('rule', '4')->reason,
        );
        // Чужим документ при этом не называем: мы не знаем, чей он.
        $this->assertCount(0, $results->where('outcome', AutoAuditResult::WRONG_DOCUMENT));
    }

    /**
     * ИНН в документе не прочитался: защиты от чужого файла в этот раз не было.
     *
     * Раньше сверка тут молча выключалась, и документ шёл в дело как свой. Форма при этом
     * опознана, период прочитан, число на месте, а вот чей это документ, мы не знаем.
     */
    public function test_unread_inn_in_the_document_is_not_verified(): void
    {
        $f161   = $this->service('Форма 161 и зарплатные налоги', AutoAuditRunner::REF_FORM_161);
        $client = $this->client(['accounting_method' => Client::ACCOUNTING_ACCRUAL]);
        $this->attachSheet($client, 'осв.xls', ['3520' => 25000.00]);

        // Шапку прочитали, а ИНН из неё вытащить не смогли.
        $this->forms['форма.pdf'] = ['month' => [2026, 7], 'inn' => null, 'income' => 25000.00];
        $this->attachLog($client, $this->item($client, $f161), 'форма.pdf');

        $row = $this->runAudit()->firstWhere('rule', '4');

        $this->assertSame(AutoAuditResult::UNVERIFIED, $row->outcome);
        $this->assertStringContainsString('ИНН в документе не прочитан', $row->reason);
        $this->assertNull($row->left_value);
    }

    /** В карточке ИНН короче четырнадцати цифр: это опечатка, а не ИНН. */
    public function test_short_inn_in_the_card_is_not_verified(): void
    {
        $f161 = $this->service('Форма 161 и зарплатные налоги', AutoAuditRunner::REF_FORM_161);

        // Ровно случай ИП Ермакова с боевого сервера: в карточке потеряна последняя цифра,
        // и сверка ИНН у него молча не работала.
        $client = $this->client(['accounting_method' => Client::ACCOUNTING_ACCRUAL, 'inn' => '2011019870120']);
        $this->attachSheet($client, 'осв.xls', ['3520' => 25000.00]);
        $this->attachForm($client, $f161, 'форма.pdf', income: 25000.00, inn: '20110198701209');

        $row = $this->runAudit()->firstWhere('rule', '4');

        $this->assertSame(AutoAuditResult::UNVERIFIED, $row->outcome);
        $this->assertStringContainsString('2011019870120', $row->reason);
    }

    /** Нет формы 161: ломаются проверки №4-№7, а №1 и №3 сверяются как обычно. */
    public function test_missing_form_161_breaks_only_its_check(): void
    {
        $f161   = $this->service('Форма 161 и зарплатные налоги', AutoAuditRunner::REF_FORM_161);
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 100.00, '3410' => 4.00, '3520' => 1000.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);
        $this->closeWithoutFile($client, $f161);

        $results = $this->runAudit();

        $this->assertSame(['1', '3'], $results->where('outcome', AutoAuditResult::MATCHED)->pluck('rule')->sort()->values()->all());

        $missing = $results->firstWhere('outcome', AutoAuditResult::MISSING_DOCUMENT);
        $this->assertSame([4, 5, 6, 7], $missing->ruleNumbers());
        $this->assertStringContainsString('Форма 161 и зарплатные налоги', $missing->reason);
        $this->assertSame(['осв.xls'], array_column($missing->sources, 'name'));
    }

    /** Нет ведомости: ломаются все проверки клиента, в том числе проверки по форме 161. */
    public function test_missing_sheet_breaks_every_check(): void
    {
        $f161   = $this->service('Форма 161 и зарплатные налоги', AutoAuditRunner::REF_FORM_161);
        $client = $this->client();
        $this->closeWithoutFile($client, $this->osvService);
        $this->attachForm($client, $f161, 'форма-161.pdf', income: 1000.00);

        $missing = $this->runAudit()->firstWhere('outcome', AutoAuditResult::MISSING_DOCUMENT);

        $this->assertSame([1, 3, 4, 5, 6, 7], $missing->ruleNumbers());
    }

    /** Форма 161 на августовской задаче, по умолчанию за июль. */
    private function attachForm(Client $client, Service $service, string $file, float $income, ?string $inn = null, int $month = 7): void
    {
        $this->forms[$file] = ['month' => [2026, $month], 'income' => $income, 'inn' => $inn ?? $client->inn];
        $this->attachLog($client, $this->item($client, $service), $file);
    }

    /**
     * Отчёт за 2 квартал 2026. Месяц задачи задаётся: квартал сдают не день в день, и
     * раньше этот фикстур жёстко ставил август, из-за чего все квартальные тесты шли мимо
     * настоящего подсчёта филиалов, хотя в комментарии обещалась июльская задача.
     */
    private function attachQuarterReport(
        Client $client,
        string $file,
        float $base,
        float $tax,
        int $month = 7,
        ?EstimateItem $item = null,
    ): void {
        $this->reports[$file] = [
            'period' => new DocumentPeriod(DocumentPeriod::of(2026, 4)->from, DocumentPeriod::of(2026, 6)->to),
            'base'   => $base,
            'tax'    => $tax,
            'inn'    => $client->inn,
        ];

        $this->attachLog($client, $item ?? $this->item($client, $this->taxService), $file, month: $month);
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

    /**
     * Отчёт сдал не каждый филиал: сумма заведомо меньше, сравнивать нечего.
     *
     * Раньше в этом случае не писалось ничего, и клиент пропадал со страницы: отличить его
     * от клиента, у которого всё сошлось, было нечем. Теперь это видимая строка.
     */
    public function test_missing_branch_report_is_not_verified(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 100.00, '3410' => 4.00]);

        $first  = $this->item($client, $this->taxService, 'Бишкек');
        $second = $this->item($client, $this->taxService, 'Ош');
        $this->attachReport($client, 'отчёт-бишкек.pdf', base: 60.00, tax: 2.40, item: $first);

        // У филиала Ош задача за август есть, но ещё не закрыта: его отчёт ждём.
        BuhTaskLog::create([
            'employee_id' => $this->admin->id, 'client_id' => $client->id,
            'estimate_item_id' => $second->id, 'year' => 2026, 'month' => 8, 'status' => 'running',
        ]);

        $base = $this->runAudit()->firstWhere('rule', '1');

        $this->assertSame(AutoAuditResult::UNVERIFIED, $base->outcome);
        $this->assertStringContainsString('отчёт сдали не все филиалы, 1 из 2', $base->reason);
        $this->assertNull($base->right_value);
        // Документ сдавшего филиала виден рядом: с него человек и начнёт разбираться.
        $this->assertContains('отчёт-бишкек.pdf', array_column($base->sources, 'name'));
    }

    /**
     * Один филиал приложил свой отчёт дважды, второй не сдал ничего.
     *
     * Самое опасное из того, что тут было: файлов два и филиалов два, значит старая проверка
     * пропускала, суммы складывались и выходило зелёное «Совпало» по одному филиалу вместо
     * двух. Ни пометки, ни следа.
     */
    public function test_one_branch_filing_twice_does_not_cover_for_another(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 120.00, '3410' => 4.80]);

        $first  = $this->item($client, $this->taxService, 'Бишкек');
        $second = $this->item($client, $this->taxService, 'Ош');
        $this->attachReport($client, 'отчёт-бишкек.pdf', base: 60.00, tax: 2.40, item: $first);
        $this->attachReport($client, 'отчёт-бишкек-копия.pdf', base: 60.00, tax: 2.40, item: $first);

        BuhTaskLog::create([
            'employee_id' => $this->admin->id, 'client_id' => $client->id,
            'estimate_item_id' => $second->id, 'year' => 2026, 'month' => 8, 'status' => 'running',
        ]);

        $base = $this->runAudit()->firstWhere('rule', '1');

        $this->assertSame(AutoAuditResult::UNVERIFIED, $base->outcome);
        $this->assertStringContainsString('отчёт сдали не все филиалы, 1 из 2', $base->reason);
        $this->assertStringContainsString('несколько документов за период', $base->reason);
    }

    /** Два документа одного филиала за период не складываем: какой из них настоящий, неясно. */
    public function test_two_documents_from_one_branch_are_not_summed(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 120.00, '3410' => 4.80]);

        $item = $this->item($client, $this->taxService, 'Бишкек');
        $this->attachReport($client, 'отчёт.pdf', base: 60.00, tax: 2.40, item: $item);
        $this->attachReport($client, 'отчёт-исправленный.pdf', base: 60.00, tax: 2.40, item: $item);

        $base = $this->runAudit()->firstWhere('rule', '1');

        $this->assertSame(AutoAuditResult::UNVERIFIED, $base->outcome);
        $this->assertStringContainsString('отчёт.pdf', $base->reason);
        $this->assertStringContainsString('отчёт-исправленный.pdf', $base->reason);
    }

    /**
     * Квартальный отчёт сдали с задержкой, и один филиал не сдал вовсе.
     *
     * Здесь старый счёт филиалов ломался тише всего. Он искал задачи, у которых «месяц
     * задачи минус один» попадает внутрь квартала; для августовской задачи не находил ни
     * одной и откатывался на «ждём один документ». Недостающий филиал переставал
     * замечаться, и выходило зелёное «Совпало» по одному филиалу из двух.
     */
    public function test_quarter_filed_late_still_counts_branches(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв-апрель.xls', ['3210' => 100.00, '3410' => 4.00], month: 4);
        $this->attachSheet($client, 'осв-май.xls', ['3210' => 100.00, '3410' => 4.00], month: 5);
        $this->attachSheet($client, 'осв-июнь.xls', ['3210' => 100.00, '3410' => 4.00], month: 6);

        $first  = $this->item($client, $this->taxService, 'Бишкек');
        $second = $this->item($client, $this->taxService, 'Ош');
        $this->attachQuarterReport($client, 'отчёт-2кв.pdf', base: 300.00, tax: 12.00, month: 8, item: $first);

        BuhTaskLog::create([
            'employee_id' => $this->admin->id, 'client_id' => $client->id,
            'estimate_item_id' => $second->id, 'year' => 2026, 'month' => 8, 'status' => 'running',
        ]);

        $base = $this->runAudit()->firstWhere('rule', '1');

        $this->assertSame(AutoAuditResult::UNVERIFIED, $base->outcome);
        $this->assertStringContainsString('отчёт сдали не все филиалы, 1 из 2', $base->reason);
    }

    /** Квартал сдан с задержкой обоими филиалами: окно в три месяца это допускает. */
    public function test_quarter_filed_late_by_both_branches_is_compared(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв-апрель.xls', ['3210' => 100.00, '3410' => 4.00], month: 4);
        $this->attachSheet($client, 'осв-май.xls', ['3210' => 100.00, '3410' => 4.00], month: 5);
        $this->attachSheet($client, 'осв-июнь.xls', ['3210' => 100.00, '3410' => 4.00], month: 6);

        $first  = $this->item($client, $this->taxService, 'Бишкек');
        $second = $this->item($client, $this->taxService, 'Ош');
        $this->attachQuarterReport($client, 'отчёт-бишкек.pdf', base: 180.00, tax: 7.20, month: 8, item: $first);
        $this->attachQuarterReport($client, 'отчёт-ош.pdf', base: 120.00, tax: 4.80, month: 8, item: $second);

        $base = $this->runAudit()->firstWhere('rule', '1');

        $this->assertSame(AutoAuditResult::MATCHED, $base->outcome);
        $this->assertSame('300.00', $base->right_value);
    }

    /**
     * Задачи за этот период нет вовсе: от скольких филиалов ждать документы, неизвестно.
     *
     * Раньше тут молча считалось, что филиал один, и вердикт выносился по единственному
     * найденному документу, каким бы он ни был.
     */
    public function test_report_without_a_task_in_the_window_is_not_verified(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 100.00, '3410' => 4.00], month: 12);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00, month: 12);

        $base = $this->runAudit()->firstWhere('rule', '1');

        $this->assertSame(AutoAuditResult::UNVERIFIED, $base->outcome);
        $this->assertStringContainsString('не нашли задачу за этот период', $base->reason);
    }

    /** Задача филиала закрыта принудительно («только один район»): его отчёта не ждём. */
    public function test_force_closed_branch_task_is_not_expected(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 60.00, '3410' => 2.40]);

        $first  = $this->item($client, $this->taxService, 'Бишкек');
        $second = $this->item($client, $this->taxService, 'Ош');
        $this->attachReport($client, 'отчёт-бишкек.pdf', base: 60.00, tax: 2.40, item: $first);

        BuhTaskLog::create([
            'employee_id' => $this->admin->id, 'client_id' => $client->id,
            'estimate_item_id' => $second->id, 'year' => 2026, 'month' => 8, 'status' => 'completed',
            'force_closed' => true, 'force_close_comment' => 'Только один район',
        ]);

        $results = $this->runAudit();

        $this->assertSame([AutoAuditResult::MATCHED], $results->pluck('outcome')->unique()->values()->all());
        $this->assertSame('60.00', $results->firstWhere('rule', '1')->right_value);
    }

    /** Принудительно закрытая задача без файла: человек записал причину, «нет документа» не пишем. */
    public function test_force_closed_task_without_file_is_not_missing(): void
    {
        $client = $this->client();

        BuhTaskLog::create([
            'employee_id' => $this->admin->id, 'client_id' => $client->id,
            'estimate_item_id' => $this->item($client, $this->taxService)->id,
            'year' => 2026, 'month' => 8, 'status' => 'completed',
            'force_closed' => true, 'force_close_comment' => 'Отчёт сдаётся ежеквартально',
        ]);

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

    /** Фото вместо PDF: документ может быть и тем, поэтому не «не тот документ», а скан. */
    public function test_scan_is_not_called_a_wrong_document(): void
    {
        $client = $this->client(['name' => 'ООО Фото ' . uniqid()]);
        $this->attachSheet($client, 'осв.xls', ['3210' => 1.00, '3410' => 1.00]);
        $this->attachLog($client, $this->item($client, $this->taxService), 'отчёт-фото.jpg');

        $results = $this->runAudit();

        $this->assertSame(['1', '3'], $results->pluck('rule')->sort()->values()->all());
        $this->assertSame([AutoAuditResult::SCAN], $results->pluck('outcome')->unique()->values()->all());
        $this->assertSame(DocumentValue::SCAN, $results->first()->sources[0]['status']);

        $this->asVendor()->get(route('auto-audit.index'))
            ->assertSeeInOrder([$client->name, 'по месяцу задачи', 'отчёт-фото.jpg', 'Скан, не прочитать']);
    }

    /**
     * Скан и чужая форма в одной задаче: какой из файлов нужный, не знаем. Уверенно сказать
     * «не тот документ» нельзя, поэтому скан.
     */
    public function test_scan_wins_over_wrong_form_in_one_task(): void
    {
        $client = $this->client();
        $this->attachLog($client, $this->item($client, $this->taxService), 'форма-161.pdf');

        $log  = BuhTaskLog::orderByDesc('id')->first();
        $path = "buh_task_documents/{$log->id}/отчёт-фото.jpg";
        Storage::disk('local')->put($path, 'x');
        $log->documents()->create(['path' => $path, 'name' => 'отчёт-фото.jpg']);

        $results = $this->runAudit();

        $this->assertSame([AutoAuditResult::SCAN], $results->pluck('outcome')->unique()->values()->all());
        $this->assertCount(2, $results->first()->sources);
    }

    /** Файла нет на диске: это не скан и не чужая форма, а поломка хранения. */
    public function test_missing_file_is_unreadable(): void
    {
        $client = $this->client();
        $this->attachLog($client, $this->item($client, $this->osvService), 'осв.xls');
        Storage::disk('local')->deleteDirectory('buh_task_documents');

        $issue = $this->runAudit()->first();

        $this->assertSame(AutoAuditResult::UNREADABLE, $issue->outcome);
        $this->assertSame('Файл не открылся', $issue->reason);
        $this->assertSame('Файла нет на диске', $issue->sources[0]['reason']);
    }

    /**
     * Оборота в ведомости нет, и в документе тоже ноль.
     *
     * Раньше это было «Совпало»: 1С не печатает счёт без оборотов, и ноль слева считался
     * честным. Но справа ноль мог быть и непрочитанным числом, а зелёная плашка говорила,
     * что всё сверено. Так собиралось ложное «Совпало» из двух чисел, которых никто не
     * печатал, и ради этого случая исход «Не удалось проверить» и появился.
     */
    public function test_two_zeros_do_not_make_a_match(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 100.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 0.00);

        $results = $this->runAudit();

        // Проверка №1 сверяет настоящие числа с обеих сторон и проходит как раньше.
        $this->assertSame(AutoAuditResult::MATCHED, $results->firstWhere('rule', '1')->outcome);

        $tax = $results->firstWhere('rule', '3');
        $this->assertSame(AutoAuditResult::UNVERIFIED, $tax->outcome);
        $this->assertNull($tax->left_value);
        $this->assertNull($tax->right_value);
        $this->assertNull($tax->difference);
        $this->assertStringContainsString('нет оборота по счёту 3410', $tax->reason);
        // Документы видны рядом: человек откроет их и проверит сам.
        $this->assertSame(['осв.xls', 'отчёт.pdf'], array_column($tax->sources, 'name'));
    }

    /**
     * Оборота в ведомости нет, а в документе число есть: это по-прежнему расхождение.
     *
     * Ненапечатанный оборот почти наверняка нулевой, и красное по ненулевому документу это
     * настоящая находка. Терять её из-за осторожности нельзя.
     */
    public function test_missing_turnover_against_a_nonzero_report_is_still_a_mismatch(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 100.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);

        $tax = $this->runAudit()->firstWhere('rule', '3');

        $this->assertSame(AutoAuditResult::MISMATCH, $tax->outcome);
        $this->assertSame('0.00', $tax->left_value);
        $this->assertSame('4.00', $tax->right_value);
        $this->assertStringContainsString('нет оборота по счёту 3410, считаем его нулевым', $tax->reason);
    }

    /** Число в ведомости не разобралось: сколько там на самом деле, мы не знаем. */
    public function test_unreadable_turnover_is_not_verified(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => null, '3410' => 4.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);

        $results = $this->runAudit();

        $base = $results->firstWhere('rule', '1');
        $this->assertSame(AutoAuditResult::UNVERIFIED, $base->outcome);
        $this->assertStringContainsString('Не удалось проверить', $base->reason);
        $this->assertStringContainsString('Не разобрали оборот по счёту 3210', $base->reason);

        // Соседняя проверка по той же ведомости сверяется как обычно.
        $this->assertSame(AutoAuditResult::MATCHED, $results->firstWhere('rule', '3')->outcome);
    }

    /** То же с правой стороны: колонку в форме 161 не нашли, вердикта нет. */
    public function test_unreadable_number_in_the_form_is_not_verified(): void
    {
        $f161   = $this->service('Форма 161 и зарплатные налоги', AutoAuditRunner::REF_FORM_161);
        $client = $this->client(['accounting_method' => Client::ACCOUNTING_ACCRUAL]);
        $this->attachSheet($client, 'осв.xls', ['3520' => 214400.00, '3420' => 19907.20]);

        $this->forms['форма-161.pdf'] = [
            'month'      => [2026, 7],
            'inn'        => $client->inn,
            'income'     => 214400.00,
            'income_tax' => null,
        ];
        $this->attachLog($client, $this->item($client, $f161), 'форма-161.pdf');

        $results = $this->runAudit();

        // Доход прочитан и сошёлся: непрочитанный налог ломает только свою проверку.
        $this->assertSame(AutoAuditResult::MATCHED, $results->firstWhere('rule', '4')->outcome);

        $tax = $results->firstWhere('rule', '5');
        $this->assertSame(AutoAuditResult::UNVERIFIED, $tax->outcome);
        $this->assertNull($tax->left_value);
        $this->assertStringContainsString('income_tax', $tax->reason);
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

    /** Месяц и квартал подписываются словами, всё остальное датами, и первый день не съезжает. */
    public function test_period_labels(): void
    {
        $label = fn (string $from, string $to) => (new AutoAuditResult(['period_from' => $from, 'period_to' => $to]))->periodLabel();

        $this->assertSame('июль 2026', $label('2026-07-01', '2026-07-31'));
        $this->assertSame('2 квартал 2026', $label('2026-04-01', '2026-06-30'));
        $this->assertSame('4 квартал 2026', $label('2026-10-01', '2026-12-31'));
        $this->assertSame('01.05.2026 – 31.07.2026', $label('2026-05-01', '2026-07-31'));
        $this->assertSame('30.04.2026 – 30.06.2026', $label('2026-04-30', '2026-06-30'));
    }

    /** Фильтр по проверке: общая строка «нет документа» видна под каждой своей проверкой. */
    public function test_filters_by_check(): void
    {
        $f161 = $this->service('Форма 161 и зарплатные налоги', AutoAuditRunner::REF_FORM_161);

        $taxOnly = $this->client(['name' => 'ООО Налог ' . uniqid(), 'accounting_method' => Client::ACCOUNTING_ACCRUAL]);
        $this->attachSheet($taxOnly, 'осв-налог.xls', ['3410' => 4.00]);
        $this->attachReport($taxOnly, 'отчёт-налог.pdf', base: 100.00, tax: 4.00);

        $payroll = $this->client(['name' => 'ООО Зарплата ' . uniqid(), 'accounting_method' => Client::ACCOUNTING_ACCRUAL]);
        $this->attachSheet($payroll, 'осв-зарплата.xls', ['3520' => 1000.00]);
        $this->attachForm($payroll, $f161, 'форма-зарплата.pdf', income: 1000.00);

        $noSheet = $this->client(['name' => 'ООО Без ведомости ' . uniqid()]);
        $this->closeWithoutFile($noSheet, $this->osvService);

        $this->runAudit();

        $this->asVendor()->get(route('auto-audit.index', ['rule' => '4']))
            ->assertOk()
            ->assertSee($payroll->name)
            ->assertSee($noSheet->name)
            ->assertDontSee($taxOnly->name);

        $this->asVendor()->get(route('auto-audit.index', ['rule' => '3']))
            ->assertSee($taxOnly->name)
            ->assertSee($noSheet->name)
            ->assertDontSee($payroll->name);

        // Мусор в адресе не ломает страницу: показываем все проверки.
        $this->asVendor()->get(route('auto-audit.index', ['rule' => 'x']))
            ->assertOk()
            ->assertSee($taxOnly->name)
            ->assertSee($payroll->name);
    }

    /** Фильтр по статусу работает вместе с периодом и проверкой, а счётчики его не учитывают. */
    public function test_filters_by_status_together_with_other_filters(): void
    {
        $f161 = $this->service('Форма 161 и зарплатные налоги', AutoAuditRunner::REF_FORM_161);

        $matched = $this->client(['name' => 'ООО Сошлось ' . uniqid(), 'accounting_method' => Client::ACCOUNTING_ACCRUAL]);
        $this->attachSheet($matched, 'осв-сошлось.xls', ['3410' => 4.00, '3520' => 1000.00]);
        $this->attachReport($matched, 'отчёт-сошлось.pdf', base: 100.00, tax: 4.00);
        $this->attachForm($matched, $f161, 'форма-сошлось.pdf', income: 1000.00);

        $mismatch = $this->client(['name' => 'ООО Расхождение ' . uniqid(), 'accounting_method' => Client::ACCOUNTING_ACCRUAL]);
        $this->attachSheet($mismatch, 'осв-расхождение.xls', ['3410' => 4.00, '3520' => 1500.00]);
        $this->attachReport($mismatch, 'отчёт-расхождение.pdf', base: 100.00, tax: 4.00);
        $this->attachForm($mismatch, $f161, 'форма-расхождение.pdf', income: 1000.00);

        $this->runAudit();

        // Только расхождения по №4: остаётся одна строка.
        $this->asVendor()->get(route('auto-audit.index', ['rule' => '4', 'status' => AutoAuditResult::MISMATCH]))
            ->assertOk()
            ->assertSee($mismatch->name)
            ->assertDontSee($matched->name)
            ->assertSee('Совпало: 1;')
            ->assertSee('Не совпало: 1;');

        // Совпавшие по №3: у обоих клиентов налог сошёлся.
        $this->asVendor()->get(route('auto-audit.index', ['rule' => '3', 'status' => AutoAuditResult::MATCHED]))
            ->assertSee($mismatch->name)
            ->assertSee($matched->name);

        // Мусор в адресе не ломает страницу: показываем все статусы.
        $this->asVendor()->get(route('auto-audit.index', ['status' => 'x']))
            ->assertOk()
            ->assertSee($mismatch->name)
            ->assertSee($matched->name);
    }

    /** Новый исход виден на странице: своя подпись, свой счётчик и свой фильтр. */
    public function test_unverified_is_shown_and_filtered_on_the_page(): void
    {
        $unclear = $this->client(['name' => 'ООО Непонятно ' . uniqid()]);
        $this->attachSheet($unclear, 'осв-непонятно.xls', ['3210' => 100.00]);
        $this->attachReport($unclear, 'отчёт-непонятно.pdf', base: 100.00, tax: 0.00);

        $matched = $this->client(['name' => 'ООО Сошлось ' . uniqid()]);
        $this->attachSheet($matched, 'осв-сошлось.xls', ['3210' => 100.00, '3410' => 4.00]);
        $this->attachReport($matched, 'отчёт-сошлось.pdf', base: 100.00, tax: 4.00);

        $this->runAudit();

        $this->asVendor()->get(route('auto-audit.index'))
            ->assertOk()
            ->assertSee('Не удалось проверить: 1;');

        // Фильтр по новому статусу оставляет только его строку.
        $this->asVendor()->get(route('auto-audit.index', ['status' => AutoAuditResult::UNVERIFIED]))
            ->assertOk()
            ->assertSee($unclear->name)
            ->assertDontSee($matched->name);
    }

    /** Кнопка не ждёт прогона: он идёт после ответа и записывает итог и длительность. */
    public function test_run_goes_after_the_response_and_records_its_state(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 1.00, '3410' => 1.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 1.00, tax: 1.00);

        $this->asVendor()->post(route('auto-audit.run'))
            ->assertRedirect(route('auto-audit.index'))
            ->assertSessionHas('success', 'Проверка запущена и идёт в фоне. Страница обновится сама, когда она закончится.');

        $state = \Illuminate\Support\Facades\Cache::get(\App\Jobs\RunAutoAuditJob::stateKey($this->tenant->id));

        $this->assertSame(\App\Jobs\RunAutoAuditJob::DONE, $state['status']);
        $this->assertSame(2, $state['counts'][AutoAuditResult::MATCHED]);
        $this->assertCount(2, AutoAuditResult::all());

        $this->asVendor()->get(route('auto-audit.index'))
            ->assertSee('заняла')
            ->assertDontSee('Идёт проверка');
    }

    /** Пока прогон идёт, второй не запускается, а страница показывает это и обновляется сама. */
    public function test_second_run_does_not_start_while_one_is_running(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 1.00, '3410' => 1.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 1.00, tax: 1.00);

        \App\Jobs\RunAutoAuditJob::markRunning($this->tenant->id);

        $this->asVendor()->post(route('auto-audit.run'))
            ->assertSessionHas('success', 'Проверка уже идёт. Страница обновится сама, когда она закончится.');

        $this->assertCount(0, AutoAuditResult::all());

        $this->asVendor()->get(route('auto-audit.index'))
            ->assertSee('Идёт проверка с')
            ->assertSee('window.location.reload', false);
    }

    /** «Идёт» дольше 15 минут значит, что процесс умер: запуск снова разрешён. */
    public function test_stale_running_state_does_not_block_the_button(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 1.00, '3410' => 1.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 1.00, tax: 1.00);

        \App\Jobs\RunAutoAuditJob::markRunning($this->tenant->id);
        $this->travel(16)->minutes();

        $this->asVendor()->post(route('auto-audit.run'))
            ->assertSessionHas('success', 'Проверка запущена и идёт в фоне. Страница обновится сама, когда она закончится.');

        $this->assertCount(2, AutoAuditResult::all());
    }

    /** Упавший прогон виден на странице, а не молча оставляет старые результаты. */
    public function test_failed_run_is_shown_on_the_page(): void
    {
        \Illuminate\Support\Facades\Cache::put(\App\Jobs\RunAutoAuditJob::stateKey($this->tenant->id), [
            'status' => \App\Jobs\RunAutoAuditJob::FAILED, 'error' => 'Не хватило памяти',
        ]);

        $this->asVendor()->get(route('auto-audit.index'))
            ->assertOk()
            ->assertSee('Последняя проверка упала и не записала результаты: Не хватило памяти');
    }

    /**
     * Разметка эталонных БП потерялась: прогон останавливается и не трогает прошлые строки.
     *
     * Раньше он шёл дальше, стирал все результаты фирмы и записывал ноль. Прогон при этом
     * считался успешным, и страница писала «Проверки ещё не было»: потерю результатов было
     * не отличить от фирмы, где аудит ни разу не запускали.
     */
    public function test_run_without_reference_services_keeps_previous_results(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 100.00, '3410' => 4.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);

        $this->runAudit();
        $before = AutoAuditResult::count();
        $this->assertGreaterThan(0, $before);

        // Кто-то снял пометки: ни одна проверка больше не работает.
        Service::query()->update(['reference_id' => null]);

        $stopped = false;

        try {
            app(AutoAuditRunner::class)->run();
        } catch (\RuntimeException $e) {
            $stopped = true;
            $this->assertStringContainsString('не размечены эталонные БП', $e->getMessage());
        }

        $this->assertTrue($stopped, 'прогон должен был остановиться, а не стереть результаты');
        $this->assertSame($before, AutoAuditResult::count());
    }

    /**
     * Упавший прогон: состояние «упала», текст ошибки и прежние строки на месте.
     *
     * Сам блок обработки ошибки в задании до сих пор не выполнялся ни в одном тесте:
     * соседний тест кладёт готовое состояние в кеш и проверяет только вёрстку.
     */
    public function test_failed_run_records_the_state_and_keeps_results(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 100.00, '3410' => 4.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);

        $this->runAudit();
        $before = AutoAuditResult::count();

        $runner = new class extends AutoAuditRunner {
            public function __construct() {}

            public function run(): array
            {
                throw new \RuntimeException('разметка потерялась');
            }
        };

        (new \App\Jobs\RunAutoAuditJob($this->tenant->id))->handle($runner);

        $state = \Illuminate\Support\Facades\Cache::get(\App\Jobs\RunAutoAuditJob::stateKey($this->tenant->id));

        $this->assertSame(\App\Jobs\RunAutoAuditJob::FAILED, $state['status']);
        $this->assertSame('разметка потерялась', $state['error']);
        $this->assertSame($before, AutoAuditResult::count());
    }

    /** Имя файла открывает окно просмотра прямо на странице, а не скачивание. */
    public function test_document_names_open_the_viewer(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 1.00, '3410' => 1.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 1.00, tax: 1.00);
        $this->runAudit();

        $document = \App\Models\BuhTaskDocument::where('name', 'отчёт.pdf')->firstOrFail();

        $this->asVendor()->get(route('auto-audit.index'))
            ->assertOk()
            ->assertSee('x-data="autoAuditDocs()"', false)
            ->assertSee('openDocFromLink($event', false)
            ->assertSee('href="' . route('documents.task', $document) . '"', false)
            // Разбор таблиц для Excel подключён: без него окно не нарисует ведомость.
            ->assertSee('function sheetPreview()', false);
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
            // Настоящий по виду ИНН из 14 цифр. С прежней заглушкой из 12 знаков сверка ИНН
            // молча выключалась, и весь набор шёл мимо этой ветки.
            'inn' => '0210120251' . str_pad((string) (++self::$innCounter), 4, '0', STR_PAD_LEFT),
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
        ?string $inn = null,
    ): void {
        // По умолчанию документ свой: ИНН тот же, что в карточке. Чужой передают явно.
        $this->reports[$file] = [
            'month' => [2026, $month],
            'base'  => $base,
            'tax'   => $tax,
            'inn'   => $inn ?? $client->inn,
        ];
        $this->attachLog($client, $item ?? $this->item($client, $this->taxService), $file, $status);
    }

    /** Задача за август (отчитываются в следующем месяце) с приложенным файлом. */
    private function attachLog(
        Client $client,
        EstimateItem $item,
        string $file,
        string $status = 'completed',
        int $month = 8,
    ): void {
        $log = BuhTaskLog::create([
            'employee_id' => $this->admin->id, 'client_id' => $client->id,
            'estimate_item_id' => $item->id, 'year' => 2026, 'month' => $month, 'status' => $status,
        ]);

        $path = "buh_task_documents/{$log->id}/{$file}";
        Storage::disk('local')->put($path, 'x');
        $log->documents()->create(['path' => $path, 'name' => $file]);
    }
}
