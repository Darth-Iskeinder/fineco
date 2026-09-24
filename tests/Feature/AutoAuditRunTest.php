<?php

namespace Tests\Feature;

use App\Models\AutoAuditFinding;
use App\Models\AutoAuditFindingMessage;
use App\Models\AutoAuditResult;
use App\Models\BuhTaskDocument;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Periodicity;
use App\Models\Role;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\AutoAudit\AutoAuditQuestions;
use App\Services\AutoAudit\AutoAuditRunner;
use App\Services\AutoAudit\BalanceSheetReader;
use App\Services\AutoAudit\DocumentPeriod;
use App\Services\AutoAudit\DocumentValue;
use App\Services\AutoAudit\SingleTaxReportReader;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use App\Jobs\RunAutoAuditJob;
use Illuminate\Support\Facades\Cache;
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

        // Отдельная фирма: прогон сверяет строки фирмы целиком, чужие трогать нельзя.
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

        // Не тот документ со своей причиной, отличной от стандартной.
        if (isset($report['wrong'])) {
            return DocumentValue::wrongDocument($report['wrong']);
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
        // Налог в отчёте больше суммы ведомостей на 6: это больше допуска, значит расхождение.
        $this->attachQuarterReport($client, 'отчёт-2кв.pdf', base: 600.00, tax: 30.00);

        $results = $this->runAudit();

        $this->assertCount(2, $results);

        $base = $results->firstWhere('rule', '1');
        $this->assertSame(AutoAuditResult::MATCHED, $base->outcome);
        $this->assertSame('600.00', $base->left_value);
        $this->assertSame('2 квартал 2026', $base->periodLabel());
        $this->assertCount(4, $base->sources);

        $tax = $results->firstWhere('rule', '3');
        $this->assertSame(AutoAuditResult::MISMATCH, $tax->outcome);
        $this->assertSame('-6.00', $tax->difference);
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
        // Первой идёт сама задача без файла: по ней видно, чья она. Файла у неё нет.
        $this->assertSame([null, 'отчёт.pdf'], array_column($row->sources, 'name'));
        $this->assertSame(AutoAuditRunner::SOURCE_MISSING, $row->sources[0]['status']);
        $this->assertSame('osv', $row->sources[0]['side']);
        $this->assertSame(BuhTaskLog::whereDoesntHave('documents')->sole()->id, $row->sources[0]['log_id']);
        $this->assertNull($row->sources[0]['document_id']);
        $this->assertNull($row->sources[1]['value']);

        $this->asVendor()->get(route('auto-audit.index'))
            ->assertSeeInOrder([
                '№1 Налоговая база сходится с учётом',
                '№3 Начисленный единый налог сходится с учётом',
                $client->name,
                'по месяцу задачи',
                'файл не приложен',
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
                'Задача за 08.2026',
                'ОСВ',
                'Админ А.',
                'Задача за 08.2026',
                'Форма 161',
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

        // Чужая форма ломает все четыре проверки по ней. Строка одна, в ней номера всех четырёх.
        $this->assertCount(1, $results);
        $row = $results->first();
        $this->assertSame('4,5,6,7', $row->rule);
        $this->assertSame(AutoAuditResult::WRONG_DOCUMENT, $row->outcome);

        // Раньше в строке писалось «Среди файлов задачи нет формы 161», и человек шёл искать
        // файл, который лежит на месте. Настоящая причина была спрятана внутри источника.
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
        $this->assertSame([null, 'осв.xls'], array_column($missing->sources, 'name'));
        $this->assertSame('f161', $missing->sources[0]['side']);
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

        // Кассовый метод и полное обслуживание: файл ломает обе проверки. Строка одна на
        // беду, в ней номера обеих, а не копия под каждой.
        $issues = $results->where('outcome', AutoAuditResult::WRONG_DOCUMENT);
        $this->assertCount(1, $issues);

        $issue = $issues->first();
        $this->assertSame($broken->id, $issue->client_id);
        $this->assertSame('1,3', $issue->rule);
        // Причина из самого файла, а не «Среди файлов задачи нет отчёта»: файл-то лежит.
        $this->assertSame('Это не отчёт по единому налогу', $issue->reason);
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
        $this->assertSame('Это не оборотно-сальдовая ведомость', $issue->reason);
    }

    /** Фото вместо PDF: документ может быть и тем, поэтому не «не тот документ», а скан. */
    public function test_scan_is_not_called_a_wrong_document(): void
    {
        $client = $this->client(['name' => 'ООО Фото ' . uniqid()]);
        $this->attachSheet($client, 'осв.xls', ['3210' => 1.00, '3410' => 1.00]);
        $this->attachLog($client, $this->item($client, $this->taxService), 'отчёт-фото.jpg');

        $results = $this->runAudit();

        $this->assertCount(1, $results);
        $this->assertSame('1,3', $results->first()->rule);
        $this->assertSame(AutoAuditResult::SCAN, $results->first()->outcome);
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
     * Файл есть, но прав на его папку нет: прогон падает целиком и не трогает прошлые строки.
     *
     * На бою 23.09.2026 команду запустили не от www-data. Корень диска читался, а папки задач
     * нет, и прогон записал «Файл не открылся» в 199 строк вместо настоящих результатов.
     */
    public function test_no_access_to_document_folder_keeps_previous_results(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('У root права на папку не отнять');
        }

        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 1.00, '3410' => 1.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 1.00, tax: 1.00);
        $this->runAudit();
        $before = AutoAuditResult::orderBy('id')->pluck('outcome', 'id')->all();

        $folder = Storage::disk('local')->path('buh_task_documents');
        chmod($folder, 0000);

        try {
            $this->artisan('autoaudit:run', ['--tenant' => $this->tenant->id])
                ->expectsOutputToContain('Нет прав на чтение файлов документов')
                ->assertFailed();
        } finally {
            chmod($folder, 0755);
        }

        $this->assertSame($before, AutoAuditResult::orderBy('id')->pluck('outcome', 'id')->all());
        $this->assertSame(
            RunAutoAuditJob::FAILED,
            Cache::get(RunAutoAuditJob::stateKey($this->tenant->id))['status'],
        );
    }

    /** Файл закрыт правами сам по себе, а папка открыта: тоже права, а не пропажа. */
    public function test_unreadable_file_in_an_open_folder_is_not_called_missing(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('У root права на файл не отнять');
        }

        $client = $this->client();
        $this->attachLog($client, $this->item($client, $this->osvService), 'осв.xls');
        $document = \App\Models\BuhTaskDocument::where('name', 'осв.xls')->firstOrFail();
        $file = Storage::disk('local')->path($document->path);
        chmod($file, 0000);

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Нет прав на чтение файлов документов');
            $this->runAudit();
        } finally {
            chmod($file, 0644);
        }
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

    /**
     * Повторный прогон без изменений ничего не дописывает. Суммы из базы приходят строкой
     * «150.00», из прогона числом: сравнение не должно счесть их разными.
     */
    public function test_rerun_without_changes_adds_no_history(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 150.00, '3410' => 4.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);

        $first = $this->runAudit();

        $runner = app(AutoAuditRunner::class);
        $runner->run();

        $this->assertSame(['kept' => 2, 'changed' => 0, 'added' => 0, 'gone' => 0], $runner->changes());
        $this->assertSame(2, AutoAuditResult::count());
        $this->assertSame($first->pluck('id')->all(), AutoAuditResult::current()->orderBy('id')->pluck('id')->all());
    }

    /** Итог поменялся: прежняя строка уходит в историю, новая встаёт рядом. */
    public function test_changed_verdict_keeps_the_old_row_as_history(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 150.00, '3410' => 4.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);

        $first = $this->runAudit();
        $mismatch = $first->firstWhere('rule', '1');
        $this->assertSame(AutoAuditResult::MISMATCH, $mismatch->outcome);

        // Бухгалтер исправил ведомость.
        $this->sheets['осв.xls']['accounts']['3210'] = 100.00;

        $runner = app(AutoAuditRunner::class);
        $runner->run();

        $this->assertSame(['kept' => 1, 'changed' => 1, 'added' => 0, 'gone' => 0], $runner->changes());

        $current = AutoAuditResult::current()->where('rule', '1')->get();
        $this->assertCount(1, $current);
        $this->assertSame(AutoAuditResult::MATCHED, $current->first()->outcome);

        $old = $mismatch->fresh();
        $this->assertNotNull($old->superseded_at);
        $this->assertSame(AutoAuditResult::MISMATCH, $old->outcome);
        $this->assertSame('50.00', $old->difference);

        // Проверка №3 не менялась: та же строка, без истории.
        $this->assertNull($first->firstWhere('rule', '3')->fresh()->superseded_at);
        $this->assertSame(3, AutoAuditResult::count());
    }

    /**
     * Файл заменили на такой же по числам: итог тот же, история не нужна. Но ссылка на файл
     * обновляется, иначе вела бы на удалённый.
     */
    public function test_same_numbers_in_a_new_file_update_the_row_in_place(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 100.00, '3410' => 4.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);

        $first = $this->runAudit();

        $this->sheets['осв-новая.xls'] = $this->sheets['осв.xls'];
        $document = BuhTaskDocument::where('name', 'осв.xls')->firstOrFail();
        $path     = dirname($document->path) . '/осв-новая.xls';
        Storage::disk('local')->put($path, 'x');
        $document->update(['path' => $path, 'name' => 'осв-новая.xls']);

        $second = $this->runAudit();

        $this->assertSame($first->pluck('id')->all(), $second->pluck('id')->all());
        $this->assertSame(2, AutoAuditResult::count());
        $this->assertContains('осв-новая.xls', array_column($second->first()->sources, 'name'));
    }

    /** Клиента удалили: его строки уходят в историю, а не стираются. */
    public function test_rows_of_a_deleted_client_become_history(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 100.00, '3410' => 4.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);

        $this->runAudit();
        $client->delete();

        $runner = app(AutoAuditRunner::class);
        $runner->run();

        $this->assertSame(['kept' => 0, 'changed' => 0, 'added' => 0, 'gone' => 2], $runner->changes());
        $this->assertSame(0, AutoAuditResult::current()->count());
        $this->assertSame(2, AutoAuditResult::whereNotNull('superseded_at')->count());
    }

    /** У двух филиалов в одном месяце не те файлы: две строки, ключи не сливаются. */
    public function test_wrong_documents_of_two_branches_are_two_rows(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 100.00, '3410' => 4.00]);
        $this->attachLog($client, $this->item($client, $this->taxService, 'Первомайский'), 'чужое-1.pdf');
        $this->attachLog($client, $this->item($client, $this->taxService, 'Октябрьский'), 'чужое-2.pdf');

        $this->runAudit();
        $results = $this->runAudit();

        $this->assertCount(2, $results->where('outcome', AutoAuditResult::WRONG_DOCUMENT));
        $this->assertSame(0, AutoAuditResult::whereNotNull('superseded_at')->count());
    }

    /** Прогон одной фирмы не закрывает строки другой. */
    public function test_run_does_not_touch_results_of_another_firm(): void
    {
        $other = Tenant::create([
            'name'   => 'Соседняя фирма ' . uniqid(),
            'slug'   => 'neighbour-' . uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $foreign = TenantContext::for($other, function () {
            $client = Client::create(['name' => 'ООО Соседа ' . uniqid(), 'inn' => '02101202519999']);

            return AutoAuditResult::create([
                'client_id' => $client->id, 'rule' => '1', 'outcome' => AutoAuditResult::MATCHED,
                'period_from' => '2026-07-01', 'period_to' => '2026-07-31',
            ]);
        });

        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 100.00, '3410' => 4.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);
        $this->runAudit();

        $this->assertNull(AutoAuditResult::withoutGlobalScopes()->find($foreign->id)->superseded_at);
    }

    /** На странице только действующие строки: история туда не попадает. */
    public function test_page_shows_only_current_rows(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 150.00, '3410' => 4.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);
        $this->runAudit();

        $this->sheets['осв.xls']['accounts']['3210'] = 100.00;
        $this->runAudit();

        $this->asVendor()->get(route('auto-audit.index'))
            ->assertOk()
            ->assertSee('Совпало')
            ->assertDontSee('50,00');
    }

    public function test_firm_staff_cannot_see_or_run_the_audit(): void
    {
        $this->actingAs($this->admin, 'employee')->get(route('auto-audit.index'))->assertNotFound();
        $this->actingAs($this->admin, 'employee')->post('/auto-audit/run')->assertNotFound();

        $this->actingAs($this->admin, 'employee')
            ->get(route('employees.index'))
            ->assertOk()
            ->assertDontSee(route('auto-audit.index'));
    }

    public function test_vendor_inside_the_firm_sees_the_result_of_the_command(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 150.00, '3410' => 4.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);

        $this->asVendor()->get(route('employees.index'))->assertSee(route('auto-audit.index'));

        $this->artisan('autoaudit:run', ['--tenant' => $this->tenant->id])->assertSuccessful();

        $this->asVendor()
            ->get(route('auto-audit.index'))
            ->assertOk()
            ->assertSee('Период')
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
            ->assertSee('data-status="matched" data-count="1"', false)
            ->assertSee('data-status="mismatch" data-count="1"', false);

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
            ->assertSee('data-status="unverified" data-count="1"', false);

        // Фильтр по новому статусу оставляет только его строку.
        $this->asVendor()->get(route('auto-audit.index', ['status' => AutoAuditResult::UNVERIFIED]))
            ->assertOk()
            ->assertSee($unclear->name)
            ->assertDontSee($matched->name);
    }

    /** Команда записывает итог и длительность, и страница их показывает. */
    public function test_command_records_its_state_for_the_page(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 1.00, '3410' => 1.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 1.00, tax: 1.00);

        $this->artisan('autoaudit:run', ['--tenant' => $this->tenant->id])->assertSuccessful();

        $state = \Illuminate\Support\Facades\Cache::get(\App\Jobs\RunAutoAuditJob::stateKey($this->tenant->id));

        $this->assertSame(\App\Jobs\RunAutoAuditJob::DONE, $state['status']);
        $this->assertSame(2, $state['counts'][AutoAuditResult::MATCHED]);
        $this->assertCount(2, AutoAuditResult::all());

        // Дата данных видна, а служебная длительность прогона на странице больше не пишется.
        $this->asVendor()->get(route('auto-audit.index'))
            ->assertSee('Данные на')
            ->assertDontSee('заняла')
            ->assertDontSee('Идёт проверка');
    }

    /**
     * Пока прогон идёт, страница показывает это и обновляется сама. Что второй прогон не
     * начнётся, проверяют тесты замка ниже.
     */
    public function test_page_shows_a_running_audit_and_reloads(): void
    {
        \App\Jobs\RunAutoAuditJob::markRunning($this->tenant->id);

        $this->asVendor()->get(route('auto-audit.index'))
            ->assertSee('Идёт проверка с')
            ->assertSee('window.location.reload', false);
    }

    /** «Идёт» дольше 15 минут значит, что процесс умер: плашка больше не висит. */
    public function test_stale_running_state_is_not_shown(): void
    {
        \App\Jobs\RunAutoAuditJob::markRunning($this->tenant->id);
        $this->travel(16)->minutes();

        $this->asVendor()->get(route('auto-audit.index'))
            ->assertOk()
            ->assertDontSee('Идёт проверка')
            ->assertDontSee('window.location.reload', false);
    }

    /** Кнопки нет: со страницы проверку не запустить даже вендору, только командой. */
    public function test_page_has_no_run_button_and_no_run_route(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 1.00, '3410' => 1.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 1.00, tax: 1.00);

        $this->asVendor()->get(route('auto-audit.index'))
            ->assertOk()
            ->assertDontSee('Проверить сейчас')
            ->assertDontSee('/auto-audit/run', false);

        $this->asVendor()->post('/auto-audit/run')->assertNotFound();
        $this->asVendor()->get('/auto-audit/run')->assertNotFound();

        $this->assertCount(0, AutoAuditResult::all());
    }

    /**
     * Команда без доступа к папке документов не начинает прогон.
     *
     * На бою так бывает, если запустить не от www-data: папка закрыта правами 0700. Прогон
     * тогда не падает, а записывает всем «Файл не открылся» и стирает настоящие результаты.
     */
    public function test_command_refuses_without_access_to_documents(): void
    {
        if (function_exists('posix_getuid') && posix_getuid() === 0) {
            $this->markTestSkipped('У root права на папку не отнять');
        }

        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 1.00, '3410' => 1.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 1.00, tax: 1.00);
        $this->runAudit();
        $before = AutoAuditResult::orderBy('id')->pluck('id')->all();

        $root = \Illuminate\Support\Facades\Storage::disk('local')->path('');
        chmod($root, 0000);

        try {
            $this->artisan('autoaudit:run', ['--tenant' => $this->tenant->id])
                ->expectsOutputToContain('Нет доступа к папке документов')
                ->assertFailed();
        } finally {
            chmod($root, 0755);
        }

        $this->assertSame($before, AutoAuditResult::orderBy('id')->pluck('id')->all());
    }

    /** Упавший прогон виден на странице, а не молча оставляет старые результаты. */
    public function test_failed_run_is_shown_on_the_page(): void
    {
        \Illuminate\Support\Facades\Cache::put(\App\Jobs\RunAutoAuditJob::stateKey($this->tenant->id), [
            'status' => \App\Jobs\RunAutoAuditJob::FAILED, 'error' => 'Не хватило памяти',
        ]);

        $this->asVendor()->get(route('auto-audit.index'))
            ->assertOk()
            ->assertSee('Последняя проверка не прошла, ниже результаты предыдущей.')
            ->assertSee('Причина: Не хватило памяти');

        // Руководителю техническая причина ни к чему: он её не починит.
        $this->tenant->setAutoAuditEnabled(true);
        $this->flushSession();

        $this->actingAs($this->employee(Role::MANAGER), 'employee')->get(route('auto-audit.index'))
            ->assertOk()
            ->assertSee('Последняя проверка не прошла, ниже результаты предыдущей.')
            ->assertDontSee('Не хватило памяти');
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

        $this->performQuietly($runner);

        $state = \Illuminate\Support\Facades\Cache::get(\App\Jobs\RunAutoAuditJob::stateKey($this->tenant->id));

        $this->assertSame(\App\Jobs\RunAutoAuditJob::FAILED, $state['status']);
        $this->assertSame('разметка потерялась', $state['error']);
        $this->assertSame($before, AutoAuditResult::count());
    }

    /**
     * Замок занят: второй прогон по этой же фирме не начинается.
     *
     * Раньше защита была только в контроллере, между «посмотрел состояние» и «отметил, что
     * идёт». Двойной клик, две вкладки или команда из терминала проскакивали этот зазор, и
     * по одной фирме шли два прогона сразу.
     */
    public function test_second_run_does_not_start_while_the_lock_is_held(): void
    {
        $lock = Cache::lock(RunAutoAuditJob::lockKey($this->tenant->id), 60);
        $this->assertTrue($lock->get());

        $runner = new class extends AutoAuditRunner {
            public int $calls = 0;

            public function __construct() {}

            public function run(): array
            {
                $this->calls++;

                return [];
            }
        };

        $this->performQuietly($runner);

        $this->assertSame(0, $runner->calls, 'прогон пошёл поверх занятого замка');

        $lock->release();
    }

    /** Команда берёт тот же замок и поверх идущего прогона не запускается. */
    public function test_command_does_not_run_while_the_lock_is_held(): void
    {
        $lock = Cache::lock(RunAutoAuditJob::lockKey($this->tenant->id), 60);
        $this->assertTrue($lock->get());

        $this->artisan('autoaudit:run', ['--tenant' => $this->tenant->id])
            ->expectsOutputToContain('уже идёт проверка')
            ->assertFailed();

        $lock->release();
    }

    /** Упавший прогон освобождает замок: следующий запуск не должен ждать протухания. */
    public function test_failed_run_releases_the_lock(): void
    {
        $runner = new class extends AutoAuditRunner {
            public function __construct() {}

            public function run(): array
            {
                throw new \RuntimeException('упал');
            }
        };

        $this->performQuietly($runner);

        $lock = Cache::lock(RunAutoAuditJob::lockKey($this->tenant->id), 60);

        $this->assertTrue($lock->get(), 'замок остался занятым после падения');

        $lock->release();
    }

    /** Пока прогон идёт, состояние так и говорит: это видит и страница, и второй запуск. */
    public function test_state_says_running_while_the_audit_works(): void
    {
        $key = RunAutoAuditJob::stateKey($this->tenant->id);
        Cache::forget($key);

        $runner = new class($key) extends AutoAuditRunner {
            public ?string $seen = null;

            public function __construct(private string $key) {}

            public function run(): array
            {
                $this->seen = Cache::get($this->key)['status'] ?? null;

                return [];
            }
        };

        $this->performQuietly($runner);

        $this->assertSame(RunAutoAuditJob::RUNNING, $runner->seen);
        $this->assertSame(RunAutoAuditJob::DONE, Cache::get($key)['status']);
    }

    /** Замок на фирму: прогон одной фирмы не мешает прогону другой. */
    public function test_lock_of_one_firm_does_not_block_another(): void
    {
        $other = Tenant::create([
            'name'   => 'Соседняя фирма ' . uniqid(),
            'slug'   => 'neighbour-' . uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $lock = Cache::lock(RunAutoAuditJob::lockKey($other->id), 60);
        $this->assertTrue($lock->get());

        $runner = new class extends AutoAuditRunner {
            public int $calls = 0;

            public function __construct() {}

            public function run(): array
            {
                $this->calls++;

                return [];
            }
        };

        $this->performQuietly($runner);

        $this->assertSame(1, $runner->calls, 'чужой замок заблокировал прогон');

        $lock->release();
    }

    /**
     * Допуск 1 сом: копейки округления дают «Совпало», но разница видна.
     *
     * На бою 0,01 и 0,02 давали красные строки: 1С и налоговая форма округляют по-разному.
     */
    public function test_difference_within_one_som_is_a_match_with_a_note(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 100.02, '3410' => 5.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);

        $results = $this->runAudit();

        $base = $results->firstWhere('rule', '1');
        $this->assertSame(AutoAuditResult::MATCHED, $base->outcome);
        $this->assertSame('0.02', $base->difference);
        $this->assertSame('Разница 0,02 на округлении', $base->reason);

        // Ровно сом ещё в допуске.
        $tax = $results->firstWhere('rule', '3');
        $this->assertSame(AutoAuditResult::MATCHED, $tax->outcome);
        $this->assertSame('1.00', $tax->difference);
    }

    /** Больше сома, даже на копейку, это уже расхождение. */
    public function test_difference_over_one_som_is_a_mismatch(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 100.00, '3410' => 5.01]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);

        $tax = $this->runAudit()->firstWhere('rule', '3');

        $this->assertSame(AutoAuditResult::MISMATCH, $tax->outcome);
        $this->assertSame('1.01', $tax->difference);
        $this->assertNull($tax->reason);
    }

    /** Два файла в задаче, и беды у них разные: одной причины нет, пишем общими словами. */
    public function test_different_problems_of_two_files_fall_back_to_a_general_reason(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 1.00, '3410' => 1.00]);
        $this->attachLog($client, $this->item($client, $this->taxService), 'форма-161.pdf');

        $log  = BuhTaskLog::orderByDesc('id')->first();
        $path = "buh_task_documents/{$log->id}/ещё-одна.pdf";
        Storage::disk('local')->put($path, 'x');
        $log->documents()->create(['path' => $path, 'name' => 'ещё-одна.pdf']);
        // Подменённая читалка отвечает по имени файла: второй файл не отчёт по другой причине.
        $this->reports['ещё-одна.pdf'] = ['wrong' => 'Это не отчёт: не нашли строку отчётного периода'];

        $issue = $this->runAudit()->firstWhere('outcome', AutoAuditResult::WRONG_DOCUMENT);

        $this->assertSame('Среди файлов задачи нет отчёта по единому налогу', $issue->reason);
        $this->assertCount(2, $issue->sources);
    }

    /** Общая строка беды видна при фильтре по любой из своих проверок. */
    public function test_one_problem_row_is_found_by_each_of_its_checks(): void
    {
        $client = $this->client(['name' => 'ООО Не тот файл ' . uniqid()]);
        $this->attachSheet($client, 'осв.xls', ['3210' => 1.00, '3410' => 1.00]);
        $this->attachLog($client, $this->item($client, $this->taxService), 'форма-161.pdf');
        $this->runAudit();

        foreach (['1', '3'] as $rule) {
            $this->asVendor()->get(route('auto-audit.index', ['rule' => $rule]))
                ->assertOk()
                ->assertSee($client->name)
                ->assertSee('data-status="wrong_document" data-count="1"', false);
        }
    }

    /** Руководитель фирмы, которой страницу не открыли: ни пункта меню, ни страницы. */
    public function test_manager_does_not_see_the_page_while_the_firm_flag_is_off(): void
    {
        $manager = $this->employee(Role::MANAGER);

        $this->actingAs($manager, 'employee')->get(route('auto-audit.index'))->assertNotFound();
        $this->actingAs($manager, 'employee')
            ->get(route('employees.index'))
            ->assertOk()
            ->assertDontSee(route('auto-audit.index'));
    }

    /** Флаг включён: руководитель видит пункт меню, результаты, исполнителей и открывает файлы. */
    public function test_manager_sees_the_page_when_the_firm_flag_is_on(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 150.00, '3410' => 4.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);
        $results = $this->runAudit();

        $this->tenant->setAutoAuditEnabled(true);
        $manager = $this->employee(Role::MANAGER);

        $this->actingAs($manager, 'employee')->get(route('employees.index'))->assertSee(route('auto-audit.index'));

        $employee = $results->first()->sources[0]['employee'];
        $this->assertNotEmpty($employee);

        $this->actingAs($manager, 'employee')->get(route('auto-audit.index'))
            ->assertOk()
            ->assertSee($client->name)
            ->assertSee('Не совпало')
            ->assertSee($employee)
            // Подсказка про видимость только для вендора.
            ->assertDontSee('Руководитель фирмы')
            ->assertSee(route('docs.section', 'auto-audit'), false);

        $document = \App\Models\BuhTaskDocument::where('name', 'отчёт.pdf')->firstOrFail();
        $this->actingAs($manager, 'employee')->get(route('documents.task', $document))->assertOk();
    }

    /** Флаг открывает страницу только руководителю: остальным ролям фирмы по-прежнему 404. */
    public function test_other_roles_do_not_see_the_page_even_with_the_flag_on(): void
    {
        $this->tenant->setAutoAuditEnabled(true);

        foreach ([Role::ADMIN, Role::HEAD_ACCOUNTANT, Role::ACCOUNTANT, Role::AUDITOR] as $role) {
            $employee = $this->employee($role);

            $this->actingAs($employee, 'employee')->get(route('auto-audit.index'))->assertNotFound();
            $this->actingAs($employee, 'employee')
                ->get(route('employees.index'))
                ->assertDontSee(route('auto-audit.index'));
        }
    }

    /** Флаг одной фирмы не открывает страницу руководителю другой. */
    public function test_flag_of_one_firm_does_not_open_the_page_in_another(): void
    {
        $this->tenant->setAutoAuditEnabled(true);

        $other = Tenant::create([
            'name'   => 'Соседняя фирма ' . uniqid(),
            'slug'   => 'neighbour-' . uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);
        $foreignManager = TenantContext::for($other, fn () => $this->employee(Role::MANAGER));

        $this->assertSame($other->id, $foreignManager->tenant_id);
        $this->actingAs($foreignManager, 'employee')->get(route('auto-audit.index'))->assertNotFound();
    }

    /**
     * Руководитель видит только свою фирму, даже если страница открыта обеим.
     *
     * Результаты отсекает по фирме сама модель. Тест держит это на случай, если страницу
     * когда-нибудь начнут собирать мимо модели.
     */
    public function test_manager_sees_only_results_of_own_firm(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 1.00, '3410' => 1.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 1.00, tax: 1.00);
        $this->runAudit();
        $this->tenant->setAutoAuditEnabled(true);

        $other = Tenant::create([
            'name'   => 'Соседняя фирма ' . uniqid(),
            'slug'   => 'neighbour-' . uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);
        $other->setAutoAuditEnabled(true);
        $foreignManager = TenantContext::for($other, fn () => $this->employee(Role::MANAGER));

        $this->actingAs($foreignManager, 'employee')->get(route('auto-audit.index'))
            ->assertOk()
            ->assertDontSee($client->name);
    }

    /** Вендор видит, открыта ли страница руководителю, чтобы не гадать. */
    public function test_vendor_sees_whether_the_manager_sees_the_page(): void
    {
        $this->asVendor()->get(route('auto-audit.index'))
            ->assertSee('Руководитель фирмы эту страницу пока не видит.');

        $this->tenant->setAutoAuditEnabled(true);

        $this->asVendor()->get(route('auto-audit.index'))
            ->assertSee('Руководитель фирмы видит эту страницу.');
    }

    /**
     * Строки с неразобранным периодом находятся своим пунктом в списке периодов.
     *
     * Страница берёт из базы только выбранный период, и пустые даты ищутся отдельно: простое
     * сравнение с пустой строкой их бы не нашло.
     */
    public function test_rows_without_a_period_are_reachable(): void
    {
        $client = $this->client(['name' => 'ООО Без периода ' . uniqid()]);

        AutoAuditResult::create([
            'client_id' => $client->id, 'rule' => '1', 'outcome' => AutoAuditResult::UNREADABLE,
            'reason' => 'Файл не открылся', 'sources' => [],
        ]);

        $this->asVendor()->get(route('auto-audit.index'))
            ->assertOk()
            ->assertSee('период не разобран')
            ->assertSee($client->name);

        $this->asVendor()->get(route('auto-audit.index', ['period' => '..']))
            ->assertOk()
            ->assertSee($client->name);
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

    /**
     * Прогон под замком, как его делает команда. Ошибку прогона глотаем: тесты смотрят на
     * состояние и замок, которые perform записал до того, как пробросить её наружу.
     */
    // ─── Находки ─────────────────────────────────────────────────────────────────

    /** «Не совпало» открывает находку. Висит она с того дня, когда появилась строка. */
    public function test_mismatch_opens_a_finding_dated_by_its_row(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 150.00, '3410' => 4.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);

        $runner = app(AutoAuditRunner::class);
        $runner->run();

        $mismatch = AutoAuditResult::current()->where('rule', '1')->firstOrFail();
        $finding  = AutoAuditFinding::sole();

        $this->assertSame(['opened' => 1, 'closed' => 0, 'open' => 1], $runner->findingChanges());
        $this->assertSame($mismatch->key(), $finding->key);
        $this->assertSame($client->id, $finding->client_id);
        $this->assertNull($finding->closed_at);
        $this->assertTrue($mismatch->created_at->equalTo($finding->opened_at));
    }

    /** Строки, что уже висели до выкатки находок, получают дату своей строки, а не дату прогона. */
    public function test_finding_for_an_old_row_counts_days_from_the_row(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 150.00, '3410' => 4.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);

        $this->travelTo(now()->subDays(5));
        $this->runAudit();
        // Находок тогда ещё не было.
        AutoAuditFinding::query()->delete();
        $this->travelBack();

        $this->runAudit();

        $this->assertSame(5, AutoAuditFinding::sole()->daysOpen());
    }

    public function test_rerun_keeps_the_same_finding(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 150.00, '3410' => 4.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);
        $this->runAudit();
        $finding = AutoAuditFinding::sole();

        $runner = app(AutoAuditRunner::class);
        $runner->run();

        $this->assertSame(['opened' => 0, 'closed' => 0, 'open' => 1], $runner->findingChanges());
        $this->assertSame($finding->id, AutoAuditFinding::sole()->id);
    }

    /** Итог сменился с одного проблемного на другой: находка и переписка те же. */
    public function test_finding_survives_a_change_of_numbers(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 150.00, '3410' => 4.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);
        $this->runAudit();
        $finding = AutoAuditFinding::sole();

        $this->sheets['осв.xls']['accounts']['3210'] = 170.00;
        $this->runAudit();

        $this->assertSame(1, AutoAuditFinding::count());
        $this->assertNull($finding->fresh()->closed_at);
    }

    /** Исправили: по ключу стало «Совпало», находка закрывается сама, переписка остаётся. */
    public function test_finding_closes_when_the_row_matches(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 150.00, '3410' => 4.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);
        $this->runAudit();
        $this->asVendor()->post(route('auto-audit.findings.reject', AutoAuditFinding::sole()), [
            'result_id' => AutoAuditResult::current()->where('rule', '1')->value('id'),
            'body'      => 'Проверьте оборот',
        ]);

        $this->sheets['осв.xls']['accounts']['3210'] = 100.00;
        $runner = app(AutoAuditRunner::class);
        $runner->run();

        $finding = AutoAuditFinding::sole();
        $this->assertSame(['opened' => 0, 'closed' => 1, 'open' => 0], $runner->findingChanges());
        $this->assertNotNull($finding->closed_at);
        $this->assertSame(AutoAuditResult::MATCHED, $finding->closed_outcome);
        $this->assertCount(1, $finding->messages);
    }

    /** Строки не стало (клиента удалили): находка закрывается без итога. */
    public function test_finding_closes_when_the_row_is_gone(): void
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 150.00, '3410' => 4.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);
        $this->runAudit();

        $client->delete();
        $this->runAudit();

        $finding = AutoAuditFinding::sole();
        $this->assertNotNull($finding->closed_at);
        $this->assertNull($finding->closed_outcome);
    }

    /** Скан и «Не удалось проверить» с бухгалтера не спрашиваем: находки нет. */
    public function test_scan_and_unverified_open_no_finding(): void
    {
        $scanned = $this->client();
        $this->attachSheet($scanned, 'осв-1.xls', ['3210' => 100.00, '3410' => 4.00]);
        $this->attachLog($scanned, $this->item($scanned, $this->taxService), 'отчёт-фото.jpg');

        $unsure = $this->client();
        $this->attachSheet($unsure, 'осв-2.xls', ['3210' => null, '3410' => 4.00]);
        $this->attachReport($unsure, 'отчёт-2.pdf', base: 100.00, tax: 4.00);

        $results = $this->runAudit();

        $this->assertContains(AutoAuditResult::SCAN, $results->pluck('outcome'));
        $this->assertContains(AutoAuditResult::UNVERIFIED, $results->pluck('outcome'));
        $this->assertSame(0, AutoAuditFinding::count());
    }

    /** Все четыре проблемных итога открывают находку. */
    public function test_missing_and_wrong_documents_open_findings(): void
    {
        $missing = $this->client();
        $this->attachSheet($missing, 'осв-1.xls', ['3210' => 100.00, '3410' => 4.00]);
        // Задача закрыта, а файла к ней нет.
        BuhTaskLog::create([
            'employee_id' => $this->admin->id, 'client_id' => $missing->id,
            'estimate_item_id' => $this->item($missing, $this->taxService)->id, 'year' => 2026, 'month' => 8, 'status' => 'completed',
        ]);

        $wrong = $this->client();
        $this->attachSheet($wrong, 'осв-2.xls', ['3210' => 100.00, '3410' => 4.00]);
        $this->attachLog($wrong, $this->item($wrong, $this->taxService), 'чужое.pdf');

        $results = $this->runAudit();

        $this->assertContains(AutoAuditResult::MISSING_DOCUMENT, $results->pluck('outcome'));
        $this->assertContains(AutoAuditResult::WRONG_DOCUMENT, $results->pluck('outcome'));
        $this->assertSame(2, AutoAuditFinding::count());
    }

    /** Вендор принимает: строка серая «Объяснено», и так остаётся, пока итог тот же. */
    public function test_vendor_accepts_a_finding(): void
    {
        [$client, $mismatch] = $this->mismatchRow();

        $this->asVendor()
            ->post(route('auto-audit.findings.accept', AutoAuditFinding::sole()), ['result_id' => $mismatch->id])
            ->assertRedirect();

        $message = AutoAuditFindingMessage::sole();
        $this->assertSame(AutoAuditFindingMessage::ACCEPTED, $message->kind);
        $this->assertSame($mismatch->id, $message->result_id);
        $this->assertTrue($message->by_vendor);

        $this->runAudit();

        $this->asVendor()->get(route('auto-audit.index'))
            ->assertOk()
            ->assertSee('Объяснено')
            ->assertSee('Без ответа: 0')
            ->assertDontSee(route('auto-audit.findings.accept', AutoAuditFinding::sole()));
    }

    /** Принятое не переносится на новый итог: цифры сменились, строка снова ждёт ответа. */
    public function test_acceptance_does_not_carry_over_to_a_new_verdict(): void
    {
        [$client, $mismatch] = $this->mismatchRow();
        $this->asVendor()->post(route('auto-audit.findings.accept', AutoAuditFinding::sole()), ['result_id' => $mismatch->id]);

        $this->sheets['осв.xls']['accounts']['3210'] = 170.00;
        $this->runAudit();

        $current = AutoAuditResult::current()->where('rule', '1')->firstOrFail();
        $finding = AutoAuditFinding::with('messages')->sole();
        $this->assertNotSame($mismatch->id, $current->id);
        $this->assertSame(AutoAuditFinding::WAITING, $finding->state($current));

        $this->asVendor()->get(route('auto-audit.index'))
            ->assertSee('Без ответа: 1')
            ->assertSee('к прежнему итогу')
            ->assertDontSee('Объяснено');
    }

    /** «Не принято» без комментария не пишется. С комментарием строка снова ждёт бухгалтера. */
    public function test_reject_needs_a_comment(): void
    {
        [, $mismatch] = $this->mismatchRow();
        $finding = AutoAuditFinding::sole();

        $this->asVendor()
            ->post(route('auto-audit.findings.reject', $finding), ['result_id' => $mismatch->id, 'body' => '  '])
            ->assertSessionHasErrors('body');
        $this->assertSame(0, AutoAuditFindingMessage::count());

        $this->asVendor()
            ->post(route('auto-audit.findings.reject', $finding), ['result_id' => $mismatch->id, 'body' => 'Где пояснение?'])
            ->assertSessionHasNoErrors();

        $finding = AutoAuditFinding::with('messages')->sole();
        $this->assertSame(AutoAuditFinding::REJECTED, $finding->state($mismatch));

        $this->asVendor()->get(route('auto-audit.index', ['answer' => 'none']))
            ->assertSee('Где пояснение?')
            ->assertSee('Не принято')
            ->assertSee('Без ответа: 1');
    }

    /** Между загрузкой страницы и кликом прошёл прогон: решение по старой строке не пишем. */
    public function test_decision_on_a_changed_row_is_refused(): void
    {
        [, $mismatch] = $this->mismatchRow();

        $this->sheets['осв.xls']['accounts']['3210'] = 170.00;
        $this->runAudit();

        $this->asVendor()
            ->post(route('auto-audit.findings.accept', AutoAuditFinding::sole()), ['result_id' => $mismatch->id])
            ->assertSessionHas('error');
        $this->assertSame(0, AutoAuditFindingMessage::count());
    }

    /** Закрытую находку принять нельзя. */
    public function test_closed_finding_takes_no_decision(): void
    {
        [, $mismatch] = $this->mismatchRow();
        $finding = AutoAuditFinding::sole();

        $this->sheets['осв.xls']['accounts']['3210'] = 100.00;
        $this->runAudit();

        $this->asVendor()
            ->post(route('auto-audit.findings.accept', $finding), ['result_id' => $mismatch->id])
            ->assertSessionHas('error');
        $this->assertSame(0, AutoAuditFindingMessage::count());
    }

    /** Фильтр «Без ответа» оставляет только строки, которые ждут бухгалтера. */
    public function test_unanswered_filter(): void
    {
        [$client, $mismatch] = $this->mismatchRow();
        $other = $this->client();
        $this->attachSheet($other, 'осв-2.xls', ['3210' => 300.00, '3410' => 4.00]);
        $this->attachReport($other, 'отчёт-2.pdf', base: 100.00, tax: 4.00);
        $this->runAudit();

        $this->asVendor()->post(
            route('auto-audit.findings.accept', AutoAuditFinding::get()->firstWhere('client_id', $client->id)),
            ['result_id' => $mismatch->id],
        );

        $this->asVendor()->get(route('auto-audit.index', ['answer' => 'none']))
            ->assertOk()
            ->assertSee($other->name)
            ->assertDontSee($client->name)
            ->assertSee('Без ответа: 1');
    }

    /** Флаг находок выключен: руководитель не видит колонку и не может решать. */
    public function test_manager_without_the_findings_flag_sees_no_answers(): void
    {
        [, $mismatch] = $this->mismatchRow();
        $this->tenant->setAutoAuditEnabled(true);
        $manager = $this->employee(Role::MANAGER);

        $this->actingAs($manager, 'employee')->get(route('auto-audit.index', ['answer' => 'none']))
            ->assertOk()
            ->assertDontSee('Без ответа')
            ->assertDontSee('Ответа пока нет')
            ->assertDontSee(route('auto-audit.findings.accept', AutoAuditFinding::sole()));

        $this->actingAs($manager, 'employee')
            ->post(route('auto-audit.findings.accept', AutoAuditFinding::sole()), ['result_id' => $mismatch->id])
            ->assertNotFound();
        $this->assertSame(0, AutoAuditFindingMessage::count());
    }

    /** Флаг включён: руководитель видит ответы и принимает от своего имени. */
    public function test_manager_with_the_findings_flag_accepts(): void
    {
        [, $mismatch] = $this->mismatchRow();
        $this->tenant->setAutoAuditEnabled(true);
        $this->tenant->setAutoAuditFindingsEnabled(true);
        $manager = $this->employee(Role::MANAGER);

        $this->actingAs($manager, 'employee')->get(route('auto-audit.index'))
            ->assertOk()
            ->assertSee('Ответа пока нет')
            ->assertSee('Висит с сегодня')
            ->assertSee(route('auto-audit.findings.accept', AutoAuditFinding::sole()));

        $this->actingAs($manager, 'employee')
            ->post(route('auto-audit.findings.accept', AutoAuditFinding::sole()), ['result_id' => $mismatch->id])
            ->assertSessionHas('success');

        $message = AutoAuditFindingMessage::sole();
        $this->assertSame($manager->id, $message->employee_id);
        $this->assertFalse($message->by_vendor);
        $this->assertSame($manager->full_name, $message->authorName());
    }

    /** Флаг находок без открытой страницы ничего не открывает, и другим ролям тоже. */
    public function test_only_manager_and_vendor_decide(): void
    {
        [, $mismatch] = $this->mismatchRow();
        $this->tenant->setAutoAuditFindingsEnabled(true);

        // Страница руководителю закрыта: флаг находок её не открывает.
        $this->actingAs($this->employee(Role::MANAGER), 'employee')
            ->post(route('auto-audit.findings.accept', AutoAuditFinding::sole()), ['result_id' => $mismatch->id])
            ->assertNotFound();

        $this->tenant->setAutoAuditEnabled(true);

        foreach ([Role::ADMIN, Role::HEAD_ACCOUNTANT, Role::ACCOUNTANT, Role::AUDITOR] as $role) {
            $this->actingAs($this->employee($role), 'employee')
                ->post(route('auto-audit.findings.reject', AutoAuditFinding::sole()), ['result_id' => $mismatch->id, 'body' => 'нет'])
                ->assertNotFound();
        }

        $this->assertSame(0, AutoAuditFindingMessage::count());
    }

    /** Находку чужой фирмы не открыть даже по прямому адресу. */
    public function test_finding_of_another_firm_is_not_reachable(): void
    {
        [, $mismatch] = $this->mismatchRow();
        $finding = AutoAuditFinding::sole();

        $other = Tenant::create([
            'name'   => 'Соседняя фирма ' . uniqid(),
            'slug'   => 'neighbour-' . uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);
        $other->setAutoAuditEnabled(true);
        $other->setAutoAuditFindingsEnabled(true);
        $foreignManager = TenantContext::for($other, fn () => $this->employee(Role::MANAGER));

        $this->actingAs($foreignManager, 'employee')
            ->post(route('auto-audit.findings.accept', $finding), ['result_id' => $mismatch->id])
            ->assertNotFound();
        $this->assertSame(0, AutoAuditFindingMessage::count());
    }

    /** Прогон одной фирмы не трогает находки другой. */
    public function test_run_does_not_touch_findings_of_another_firm(): void
    {
        $other = Tenant::create([
            'name'   => 'Соседняя фирма ' . uniqid(),
            'slug'   => 'neighbour-' . uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);
        $foreign = TenantContext::for($other, fn () => AutoAuditFinding::create([
            'client_id' => $this->client()->id, 'key' => 'check:1:1:2026-07-01..2026-07-31', 'opened_at' => now(),
        ]));

        $this->runAudit();

        $this->assertNull(AutoAuditFinding::acrossTenants()->find($foreign->id)->closed_at);
    }

    /** Строка «Не совпало» по №1 у нового клиента, с открытой находкой. */
    private function mismatchRow(): array
    {
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 150.00, '3410' => 4.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);

        $mismatch = $this->runAudit()->firstWhere('rule', '1');
        $this->assertSame(AutoAuditResult::MISMATCH, $mismatch->outcome);

        return [$client, $mismatch];
    }

    // ─── Вопросы бухгалтеру (этап 3) ─────────────────────────────────────────────

    /** «Не совпало»: вопрос обоим исполнителям, и каждый может заменить только свой файл. */
    public function test_mismatch_question_goes_to_both_executors(): void
    {
        $this->tenant->setAutoAuditFindingsEnabled(true);
        [$osvDoer, $taxDoer] = [$this->accountant(), $this->accountant()];
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 150.00, '3410' => 4.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);
        $this->ownedBy('осв.xls', $osvDoer);
        $this->ownedBy('отчёт.pdf', $taxDoer);
        $this->runAudit();

        $questions = app(AutoAuditQuestions::class);

        $osvQuestions = $questions->forEmployee($osvDoer);
        $this->assertCount(1, $osvQuestions);
        $this->assertSame([$this->logOf('осв.xls')->id], $osvQuestions->first()['fixable']->pluck('id')->all());

        $taxQuestions = $questions->forEmployee($taxDoer);
        $this->assertCount(1, $taxQuestions);
        $this->assertSame([$this->logOf('отчёт.pdf')->id], $taxQuestions->first()['fixable']->pluck('id')->all());

        // Ответственный за клиента (здесь админ) вопроса не получает: исполнители на месте.
        $this->assertCount(0, $questions->forEmployee($this->admin));

        $page = $this->actingAs($osvDoer, 'employee')->get(route('buhtasks.index'))->assertOk()->getContent();
        $onPage = $this->auditQuestionsOnPage($page);
        $this->assertCount(1, $onPage);
        $this->assertSame('№1 Налоговая база сходится с учётом', $onPage[0]['rules'][0]);
        $this->assertSame($client->name, $onPage[0]['client_name']);
        $this->assertSame('replace', $onPage[0]['fix'][0]['action']);
    }

    /** «Нет документа»: вопрос тому, кто закрыл задачу без файла, и файл можно приложить. */
    public function test_missing_document_question_goes_to_who_closed_the_task(): void
    {
        $this->tenant->setAutoAuditFindingsEnabled(true);
        $doer   = $this->accountant();
        $client = $this->client();
        $log    = $this->closeWithoutFile($client, $this->osvService);
        $log->update(['employee_id' => $doer->id]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);
        $this->runAudit();

        $question = app(AutoAuditQuestions::class)->forEmployee($doer)->sole();
        $front    = app(AutoAuditQuestions::class)->toFront($question);

        $this->assertSame([['log_id' => $log->id, 'label' => 'ОСВ за 08.2026', 'action' => 'attach', 'files' => []]], $front['fix']);
        $this->assertCount(0, app(AutoAuditQuestions::class)->forEmployee($this->admin));
    }

    /** Исполнитель уволен: вопрос уходит ответственному за клиента, но чужой файл он не трогает. */
    public function test_question_of_a_fired_executor_goes_to_the_responsible(): void
    {
        $this->tenant->setAutoAuditFindingsEnabled(true);
        $fired  = $this->accountant();
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 150.00, '3410' => 4.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);
        $this->ownedBy('осв.xls', $fired);
        $this->ownedBy('отчёт.pdf', $fired);
        $this->runAudit();
        $fired->delete();

        $question = app(AutoAuditQuestions::class)->forEmployee($this->admin)->sole();
        $this->assertCount(0, $question['fixable']);

        $this->actingAs($this->admin, 'employee')
            ->postJson(route('buhtasks.audit-questions.fix', $question['finding']), [
                'result_id' => $question['result']->id,
                'log_id'    => $this->logOf('осв.xls')->id,
                'file'      => UploadedFile::fake()->create('осв-новая.xls', 5),
            ])
            ->assertForbidden();
        $this->assertSame(0, AutoAuditFindingMessage::count());
    }

    /** Объяснение уходит руководителю, вопрос пропадает у бухгалтера. «Не принято» его возвращает. */
    public function test_explanation_goes_to_the_manager_and_rejection_brings_it_back(): void
    {
        $this->tenant->setAutoAuditFindingsEnabled(true);
        $doer = $this->accountant();
        [, $mismatch] = $this->mismatchRow();
        $this->ownedBy('осв.xls', $doer);
        $this->ownedBy('отчёт.pdf', $doer);
        $finding = AutoAuditFinding::sole();

        $this->actingAs($doer, 'employee')
            ->postJson(route('buhtasks.audit-questions.explain', $finding), ['result_id' => $mismatch->id, 'body' => ''])
            ->assertUnprocessable();

        $this->actingAs($doer, 'employee')
            ->postJson(route('buhtasks.audit-questions.explain', $finding), ['result_id' => $mismatch->id, 'body' => 'Возврат покупателю'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $message = AutoAuditFindingMessage::sole();
        $this->assertSame(AutoAuditFindingMessage::EXPLAINED, $message->kind);
        $this->assertSame($doer->id, $message->employee_id);
        $this->assertCount(0, app(AutoAuditQuestions::class)->forEmployee($doer));

        $this->asVendor()->get(route('auto-audit.index'))
            ->assertSee('Возврат покупателю')
            ->assertSee('Объяснил')
            ->assertSee('Без ответа: 0');

        $this->asVendor()->post(route('auto-audit.findings.reject', $finding), ['result_id' => $mismatch->id, 'body' => 'Приложите акт возврата']);

        $front = app(AutoAuditQuestions::class)->toFront(app(AutoAuditQuestions::class)->forEmployee($doer)->sole());
        $this->assertSame('Приложите акт возврата', $front['rejection']['body']);
    }

    /**
     * Замена файла: задача остаётся закрытой с той же датой, файл строки заменён, прочие
     * файлы задачи на месте.
     */
    public function test_replacing_a_file_keeps_the_task_closed(): void
    {
        $this->tenant->setAutoAuditFindingsEnabled(true);
        $doer = $this->accountant();
        [, $mismatch] = $this->mismatchRow();
        $this->ownedBy('осв.xls', $doer);
        $this->ownedBy('отчёт.pdf', $doer);

        $log = $this->logOf('осв.xls');
        $log->update(['completed_at' => '2026-08-05 10:00:00']);
        $extra = $log->documents()->create(['path' => "buh_task_documents/{$log->id}/пояснение.pdf", 'name' => 'пояснение.pdf']);
        $old   = $log->documents()->where('name', 'осв.xls')->sole();

        $this->actingAs($doer, 'employee')
            ->post(route('buhtasks.audit-questions.fix', AutoAuditFinding::sole()), [
                'result_id' => $mismatch->id,
                'log_id'    => $log->id,
                'file'      => UploadedFile::fake()->create('осв-исправленная.xls', 5),
            ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['success' => true]);

        $log->refresh();
        $this->assertSame('completed', $log->status);
        $this->assertSame('2026-08-05 10:00:00', $log->completed_at->format('Y-m-d H:i:s'));
        $this->assertNull(BuhTaskDocument::find($old->id));
        Storage::disk('local')->assertMissing($old->path);
        $this->assertNotNull(BuhTaskDocument::find($extra->id));
        $this->assertSame(['пояснение.pdf', 'осв-исправленная.xls'], $log->documents()->orderBy('id')->pluck('name')->all());

        $message = AutoAuditFindingMessage::sole();
        $this->assertSame(AutoAuditFindingMessage::FIXED, $message->kind);
        $this->assertSame('Заменил «осв.xls» на «осв-исправленная.xls»', $message->body);
        $this->assertCount(0, app(AutoAuditQuestions::class)->forEmployee($doer));

        $this->asVendor()->get(route('auto-audit.index'))->assertSee('Файл заменён, ждёт следующей проверки');
    }

    /** Заменил, а прогон показал то же: вопрос возвращается с пометкой. Помогло: закрывается. */
    public function test_fix_that_did_not_help_brings_the_question_back(): void
    {
        $this->tenant->setAutoAuditFindingsEnabled(true);
        $doer = $this->accountant();
        [, $mismatch] = $this->mismatchRow();
        $this->ownedBy('осв.xls', $doer);
        $this->ownedBy('отчёт.pdf', $doer);

        $this->actingAs($doer, 'employee')->post(route('buhtasks.audit-questions.fix', AutoAuditFinding::sole()), [
            'result_id' => $mismatch->id,
            'log_id'    => $this->logOf('осв.xls')->id,
            'file'      => UploadedFile::fake()->create('осв-2.xls', 5),
        ], ['Accept' => 'application/json'])->assertOk();

        // Новый файл с теми же числами.
        $stored = basename($this->logOf('осв-2.xls')->documents()->sole()->path);
        $this->sheets[$stored] = $this->sheets['осв.xls'];
        $this->travel(1)->minutes();
        $this->runAudit();

        $front = app(AutoAuditQuestions::class)->toFront(app(AutoAuditQuestions::class)->forEmployee($doer)->sole());
        $this->assertTrue($front['fix_failed']);
        $this->asVendor()->get(route('auto-audit.index'))->assertSee('всё ещё не сходится');

        // Теперь числа сошлись: вопрос закрывается сам.
        $this->sheets[$stored]['accounts']['3210'] = 100.00;
        $this->runAudit();
        $this->assertNotNull(AutoAuditFinding::sole()->closed_at);
        $this->assertCount(0, app(AutoAuditQuestions::class)->forEmployee($doer));
    }

    /** К эталонному БП принимаем только PDF и Excel, как при обычной загрузке. */
    public function test_fix_accepts_only_readable_formats(): void
    {
        $this->tenant->setAutoAuditFindingsEnabled(true);
        $doer = $this->accountant();
        [, $mismatch] = $this->mismatchRow();
        $this->ownedBy('осв.xls', $doer);

        $this->actingAs($doer, 'employee')->post(route('buhtasks.audit-questions.fix', AutoAuditFinding::sole()), [
            'result_id' => $mismatch->id,
            'log_id'    => $this->logOf('осв.xls')->id,
            'file'      => UploadedFile::fake()->create('скан.jpg', 5),
        ], ['Accept' => 'application/json'])->assertUnprocessable();

        $this->assertSame(0, AutoAuditFindingMessage::count());
        $this->assertSame(['осв.xls'], $this->logOf('осв.xls')->documents()->pluck('name')->all());
    }

    /** Между загрузкой страницы и ответом прошёл прогон и итог сменился: ответ не пишем. */
    public function test_answer_to_a_changed_question_is_refused(): void
    {
        $this->tenant->setAutoAuditFindingsEnabled(true);
        $doer = $this->accountant();
        [, $mismatch] = $this->mismatchRow();
        $this->ownedBy('осв.xls', $doer);

        $this->sheets['осв.xls']['accounts']['3210'] = 170.00;
        $this->runAudit();

        $this->actingAs($doer, 'employee')
            ->postJson(route('buhtasks.audit-questions.explain', AutoAuditFinding::sole()), ['result_id' => $mismatch->id, 'body' => 'так надо'])
            ->assertStatus(409)
            ->assertJson(['stale' => true]);
        $this->assertSame(0, AutoAuditFindingMessage::count());
    }

    /** Ответить может только тот, кому задан вопрос. */
    public function test_stranger_cannot_answer(): void
    {
        $this->tenant->setAutoAuditFindingsEnabled(true);
        $doer = $this->accountant();
        [, $mismatch] = $this->mismatchRow();
        $this->ownedBy('осв.xls', $doer);
        $this->ownedBy('отчёт.pdf', $doer);

        $this->actingAs($this->accountant(), 'employee')
            ->postJson(route('buhtasks.audit-questions.explain', AutoAuditFinding::sole()), ['result_id' => $mismatch->id, 'body' => 'чужое'])
            ->assertForbidden();
        $this->assertSame(0, AutoAuditFindingMessage::count());
    }

    /** Флаг выключен: бухгалтер вопросов не видит и ответить не может. */
    public function test_questions_are_hidden_while_the_flag_is_off(): void
    {
        $doer = $this->accountant();
        [, $mismatch] = $this->mismatchRow();
        $this->ownedBy('осв.xls', $doer);

        $page = $this->actingAs($doer, 'employee')->get(route('buhtasks.index'))->assertOk()->getContent();
        $this->assertSame([], $this->auditQuestionsOnPage($page));

        $this->actingAs($doer, 'employee')
            ->postJson(route('buhtasks.audit-questions.explain', AutoAuditFinding::sole()), ['result_id' => $mismatch->id, 'body' => 'так'])
            ->assertNotFound();
    }

    /** Вопрос чужой фирмы не открыть даже по прямому адресу. */
    public function test_question_of_another_firm_is_not_reachable(): void
    {
        [, $mismatch] = $this->mismatchRow();
        $finding = AutoAuditFinding::sole();

        $other = Tenant::create([
            'name'   => 'Соседняя фирма ' . uniqid(),
            'slug'   => 'neighbour-' . uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);
        $other->setAutoAuditFindingsEnabled(true);
        $stranger = TenantContext::for($other, fn () => $this->accountant());

        $this->actingAs($stranger, 'employee')
            ->postJson(route('buhtasks.audit-questions.explain', $finding), ['result_id' => $mismatch->id, 'body' => 'x'])
            ->assertNotFound();
        $this->assertSame(0, AutoAuditFindingMessage::count());
    }

    /**
     * №1 и №3 не сошлись по одним и тем же файлам. Файл заменили в одном вопросе: второй
     * тоже ждёт проверки и уходит из списка, иначе в нём висела бы ссылка на удалённый файл.
     */
    public function test_replacing_a_shared_file_answers_every_question_on_it(): void
    {
        $this->tenant->setAutoAuditFindingsEnabled(true);
        $doer   = $this->accountant();
        $client = $this->client();
        $this->attachSheet($client, 'осв.xls', ['3210' => 150.00, '3410' => 9.00]);
        $this->attachReport($client, 'отчёт.pdf', base: 100.00, tax: 4.00);
        $this->ownedBy('осв.xls', $doer);
        $this->ownedBy('отчёт.pdf', $doer);
        $this->runAudit();

        $this->assertSame(2, AutoAuditFinding::count());
        [$first, $second] = AutoAuditFinding::orderBy('id')->get()->all();
        $result = AutoAuditResult::current()->get()->first(fn ($r) => $r->key() === $first->key);

        $this->actingAs($doer, 'employee')->post(route('buhtasks.audit-questions.fix', $first), [
            'result_id' => $result->id,
            'log_id'    => $this->logOf('осв.xls')->id,
            'file'      => UploadedFile::fake()->create('осв-новая.xls', 5),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['success' => true, 'also_fixed' => [$second->id]]);

        $this->assertSame([AutoAuditFindingMessage::FIXED], $second->messages()->pluck('kind')->all());
        $this->assertStringContainsString('в другом вопросе', $second->messages()->value('body'));
        $this->assertCount(0, app(AutoAuditQuestions::class)->forEmployee($doer));
        // Файл один, а не два: второй вопрос его не добавляет.
        $this->assertSame(['осв-новая.xls'], $this->logOf('осв-новая.xls')->documents()->pluck('name')->all());
    }

    /** Объяснили, а потом цифры сменились: объяснение было про другое, вопрос снова ждёт. */
    public function test_explanation_is_outdated_when_numbers_change(): void
    {
        $this->tenant->setAutoAuditFindingsEnabled(true);
        $doer = $this->accountant();
        [, $mismatch] = $this->mismatchRow();
        $this->ownedBy('осв.xls', $doer);
        $this->ownedBy('отчёт.pdf', $doer);

        $this->actingAs($doer, 'employee')
            ->postJson(route('buhtasks.audit-questions.explain', AutoAuditFinding::sole()), ['result_id' => $mismatch->id, 'body' => 'Возврат 50'])
            ->assertOk();
        $this->assertCount(0, app(AutoAuditQuestions::class)->forEmployee($doer));

        $this->sheets['осв.xls']['accounts']['3210'] = 170.00;
        $this->runAudit();

        $front = app(AutoAuditQuestions::class)->toFront(app(AutoAuditQuestions::class)->forEmployee($doer)->sole());
        $this->assertTrue($front['outdated']);

        $this->asVendor()->get(route('auto-audit.index'))
            ->assertSee('После объяснения цифры изменились')
            ->assertSee('Без ответа: 1');
    }

    /** Сбой в данных автоаудита не роняет БухЗадачник: страница открывается без вопросов. */
    public function test_broken_questions_do_not_break_the_task_page(): void
    {
        $this->tenant->setAutoAuditFindingsEnabled(true);
        $doer = $this->accountant();

        $this->app->instance(AutoAuditQuestions::class, new class extends AutoAuditQuestions {
            public function forEmployee(Employee $employee): \Illuminate\Support\Collection
            {
                throw new \RuntimeException('битые данные автоаудита');
            }
        });

        $page = $this->actingAs($doer, 'employee')->get(route('buhtasks.index'))->assertOk()->getContent();
        $this->assertSame([], $this->auditQuestionsOnPage($page));
    }

    // ─── Уведомления (этап 4) ────────────────────────────────────────────────────

    /** Флаг выключен: в карточке уведомлений автоаудита нет. */
    public function test_alert_is_silent_while_the_flag_is_off(): void
    {
        $doer = $this->accountant();
        $this->mismatchRow();
        $this->ownedBy('осв.xls', $doer);

        $this->actingAs($doer, 'employee')->getJson(route('task-alerts.index'))
            ->assertOk()
            ->assertJsonMissing(['kind' => 'audit']);
    }

    /** Новые вопросы одной строкой-сводкой с разбивкой, «Понятно» её гасит. */
    public function test_new_questions_come_as_one_summary_until_seen(): void
    {
        $this->tenant->setAutoAuditFindingsEnabled(true);
        $doer = $this->accountant();
        $this->mismatchRow();
        $this->ownedBy('осв.xls', $doer);
        $this->ownedBy('отчёт.pdf', $doer);

        $missing = $this->client();
        $log     = $this->closeWithoutFile($missing, $this->osvService);
        $log->update(['employee_id' => $doer->id]);
        $this->attachReport($missing, 'отчёт-2.pdf', base: 1.00, tax: 1.00);
        $this->runAudit();

        $items = $this->actingAs($doer, 'employee')->getJson(route('task-alerts.index'))->assertOk()->json('items');
        $audit = collect($items)->where('kind', 'audit')->values();

        $this->assertCount(1, $audit);
        $this->assertSame('audit:summary', $audit[0]['key']);
        $this->assertSame('2 вопроса по вашим задачам', $audit[0]['name']);
        $this->assertSame('1 не совпало, 1 нет документа', $audit[0]['client_name']);

        $this->actingAs($doer, 'employee')->postJson(route('task-alerts.seen'), ['keys' => ['audit:summary']])->assertOk();
        $this->assertNotNull($doer->fresh()->audit_alert_seen_at);

        $this->actingAs($doer, 'employee')->getJson(route('task-alerts.index'))
            ->assertJsonMissing(['kind' => 'audit']);

        // Вопросы при этом никуда не делись: они в задачнике.
        $this->assertCount(2, app(AutoAuditQuestions::class)->forEmployee($doer));
    }

    /** После «Понятно» всплывает только новое: новый вопрос приходит сводкой из одного. */
    public function test_only_new_questions_come_back_after_seen(): void
    {
        $this->tenant->setAutoAuditFindingsEnabled(true);
        $doer = $this->accountant();
        $this->mismatchRow();
        $this->ownedBy('осв.xls', $doer);
        $this->ownedBy('отчёт.pdf', $doer);

        $this->actingAs($doer, 'employee')->postJson(route('task-alerts.seen'), ['keys' => ['audit:summary']]);

        $this->travel(1)->minutes();
        $other = $this->client();
        $this->attachSheet($other, 'осв-2.xls', ['3210' => 300.00, '3410' => 4.00]);
        $this->attachReport($other, 'отчёт-2.pdf', base: 100.00, tax: 4.00);
        $this->ownedBy('осв-2.xls', $doer);
        $this->runAudit();

        $audit = collect($this->actingAs($doer, 'employee')->getJson(route('task-alerts.index'))->json('items'))->where('kind', 'audit')->values();
        $this->assertCount(1, $audit);
        $this->assertSame('1 вопрос по вашим задачам', $audit[0]['name']);
    }

    /** «Не принято» отдельной строкой с комментарием и тем, кто не принял. */
    public function test_rejection_comes_as_its_own_line(): void
    {
        $this->tenant->setAutoAuditFindingsEnabled(true);
        $doer = $this->accountant();
        [$client, $mismatch] = $this->mismatchRow();
        $this->ownedBy('осв.xls', $doer);
        $this->ownedBy('отчёт.pdf', $doer);
        $finding = AutoAuditFinding::sole();

        $this->actingAs($doer, 'employee')->postJson(route('buhtasks.audit-questions.explain', $finding), ['result_id' => $mismatch->id, 'body' => 'Возврат']);
        $this->actingAs($doer, 'employee')->postJson(route('task-alerts.seen'), ['keys' => ['audit:summary']]);

        $this->travel(1)->minutes();
        $this->asVendor()->post(route('auto-audit.findings.reject', $finding), ['result_id' => $mismatch->id, 'body' => 'Нужен акт']);

        $items = collect($this->actingAs($doer, 'employee')->getJson(route('task-alerts.index'))->json('items'));
        $line  = $items->firstWhere('kind', 'audit_rejected');

        $this->assertNotNull($line);
        $this->assertSame('audit:finding:' . $finding->id, $line['key']);
        $this->assertSame('Нужен акт', $line['comment']);
        $this->assertSame('Kubik', $line['from_name']);
        $this->assertStringContainsString($client->name, $line['client_name']);
        $this->assertNull($items->firstWhere('kind', 'audit'));
    }

    /** Вопрос другого бухгалтера в мою карточку не попадает. */
    public function test_alert_shows_only_own_questions(): void
    {
        $this->tenant->setAutoAuditFindingsEnabled(true);
        $doer = $this->accountant();
        $this->mismatchRow();
        $this->ownedBy('осв.xls', $doer);
        $this->ownedBy('отчёт.pdf', $doer);

        $this->actingAs($this->accountant(), 'employee')->getJson(route('task-alerts.index'))
            ->assertOk()
            ->assertJsonMissing(['kind' => 'audit']);
    }

    /** Мой «Понятно» гасит уведомление только у меня: у второго исполнителя оно остаётся. */
    public function test_seen_affects_only_the_employee_who_pressed_it(): void
    {
        $this->tenant->setAutoAuditFindingsEnabled(true);
        [$osvDoer, $taxDoer] = [$this->accountant(), $this->accountant()];
        $this->mismatchRow();
        $this->ownedBy('осв.xls', $osvDoer);
        $this->ownedBy('отчёт.pdf', $taxDoer);

        $finding = AutoAuditFinding::sole();
        $this->actingAs($osvDoer, 'employee')
            ->postJson(route('task-alerts.seen'), ['keys' => ['audit:summary', 'audit:finding:' . $finding->id, 'audit:finding:999999']])
            ->assertOk();

        $this->assertNull($taxDoer->fresh()->audit_alert_seen_at);
        $this->actingAs($taxDoer, 'employee')->getJson(route('task-alerts.index'))->assertJsonFragment(['kind' => 'audit']);
        // Подставленные ключи ничего, кроме своей отметки, не меняют.
        $this->assertSame(0, AutoAuditFindingMessage::count());
    }

    /** Карточка другой фирмы вопросов этой не показывает, даже при включённом флаге там. */
    public function test_alert_does_not_leak_to_another_firm(): void
    {
        $this->tenant->setAutoAuditFindingsEnabled(true);
        $this->mismatchRow();

        $other = Tenant::create([
            'name'   => 'Соседняя фирма ' . uniqid(),
            'slug'   => 'neighbour-' . uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);
        $other->setAutoAuditFindingsEnabled(true);
        $stranger = TenantContext::for($other, fn () => $this->accountant());
        // Даже если бы чужой задачей числился он сам.
        BuhTaskLog::query()->update(['employee_id' => $stranger->id]);

        $this->actingAs($stranger, 'employee')->getJson(route('task-alerts.index'))
            ->assertOk()
            ->assertJsonMissing(['kind' => 'audit']);
    }

    /** Без доступа к задачнику уведомлений нет вовсе: звать туда незачем. */
    public function test_no_alert_without_the_task_module(): void
    {
        $this->tenant->setAutoAuditFindingsEnabled(true);
        $doer = $this->employee(Role::ACCOUNTANT);
        $this->mismatchRow();
        $this->ownedBy('осв.xls', $doer);

        $this->actingAs($doer, 'employee')->getJson(route('task-alerts.index'))->assertExactJson(['items' => []]);
    }

    /** Два десятка возвратов не вытесняют автоаудит: его строки в карточке первые. */
    public function test_audit_line_is_not_pushed_out_by_many_reworks(): void
    {
        $this->tenant->setAutoAuditFindingsEnabled(true);
        $doer = $this->accountant();
        $this->mismatchRow();
        $this->ownedBy('осв.xls', $doer);

        $client = $this->client();
        for ($i = 0; $i < 25; $i++) {
            $log = $this->closeWithoutFile($client, $this->taxService, 'rework');
            $log->update(['employee_id' => $doer->id, 'month' => 1 + $i % 12, 'year' => 2020 + intdiv($i, 12)]);
        }

        $items = $this->actingAs($doer, 'employee')->getJson(route('task-alerts.index'))->json('items');

        $this->assertCount(20, $items);
        $this->assertSame('audit', $items[0]['kind']);
    }

    /** Сбой в вопросах не лишает карточку возвратов на доработку. */
    public function test_broken_questions_keep_the_rest_of_the_alerts(): void
    {
        $this->tenant->setAutoAuditFindingsEnabled(true);
        $doer   = $this->accountant();
        $client = $this->client();
        $log    = $this->closeWithoutFile($client, $this->osvService, 'rework');
        $log->update(['employee_id' => $doer->id, 'review_comment' => 'Переделать']);

        $this->app->instance(AutoAuditQuestions::class, new class extends AutoAuditQuestions {
            public function alerts(Employee $employee): array
            {
                throw new \RuntimeException('битые данные автоаудита');
            }
        });

        $this->actingAs($doer, 'employee')->getJson(route('task-alerts.index'))
            ->assertOk()
            ->assertJsonFragment(['kind' => 'rework', 'comment' => 'Переделать']);
    }

    /** Вопросы, которые БухЗадачник отдал странице. */
    private function auditQuestionsOnPage(string $html): array
    {
        $this->assertSame(1, preg_match('~<script type="application/json" id="audit-questions-data">(.*?)</script>~s', $html, $m));

        return json_decode($m[1], true);
    }

    /** Бухгалтер с доступом к БухЗадачнику. */
    private function accountant(): Employee
    {
        $employee = $this->employee(Role::ACCOUNTANT);
        $module   = \App\Models\Module::firstOrCreate(['name' => 'buhtasks'], ['display_name' => 'БухЗадачник', 'is_active' => true]);
        $employee->modules()->syncWithoutDetaching([$module->id]);

        return $employee;
    }

    /** Задача, к которой приложен файл с таким именем. */
    private function logOf(string $file): BuhTaskLog
    {
        return BuhTaskLog::whereHas('documents', fn ($q) => $q->where('name', $file))->sole();
    }

    /** Сделать исполнителем задачи с этим файлом другого сотрудника. */
    private function ownedBy(string $file, Employee $employee): void
    {
        $this->logOf($file)->update(['employee_id' => $employee->id]);
    }

    private function performQuietly(AutoAuditRunner $runner): void
    {
        try {
            RunAutoAuditJob::perform($this->tenant->id, $runner);
        } catch (\Throwable) {
        }
    }

    private function employee(string $role): Employee
    {
        return Employee::create([
            'full_name' => 'Сотрудник ' . $role, 'position' => $role,
            'email' => 'autoaudit.' . $role . '.' . uniqid() . '@example.com', 'password' => 'secret123',
            'role_id' => Role::where('name', $role)->value('id'),
            'status' => Employee::STATUS_ACTIVE,
        ]);
    }

    private function asVendor(): static
    {
        return $this->actingAs($this->admin, 'employee')->withSession([
            'vendor.tenant_id'   => $this->tenant->id,
            'vendor.tenant_name' => $this->tenant->name,
            'vendor.last_seen'   => now()->timestamp,
        ]);
    }

    /** Прогон и действующие строки после него. История в ответ не входит. */
    private function runAudit(): Collection
    {
        app(AutoAuditRunner::class)->run();

        return AutoAuditResult::current()->orderBy('id')->get();
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
