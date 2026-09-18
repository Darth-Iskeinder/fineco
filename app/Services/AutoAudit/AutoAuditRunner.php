<?php

namespace App\Services\AutoAudit;

use App\Models\AutoAuditResult;
use App\Models\BuhTaskDocument;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Service;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Автоаудит: ОСВ против отчёта по единому налогу и против формы 161.
 *
 * Правила зашиты здесь же, списком. Это сознательно топорно: цель увидеть на боевых
 * документах, совпадают числа или нет. Каталог правил, допуски и переключатели появятся
 * потом, когда станет понятно, как выглядит настоящее расхождение.
 *
 * Как идёт сверка у одного клиента:
 *   1. берём документы закрытых задач эталонных БП: ведомость и второй документ проверки;
 *   2. читаем каждый и узнаём период из самого документа, а не из месяца задачи;
 *   3. ИНН в шапке документа сверяем с карточкой клиента: чужая организация, чужой файл;
 *   4. ставим в пару ведомость и документы за один и тот же период. Ведомость всегда
 *      помесячная, а отчёт бывает квартальным: тогда складываем ведомости трёх месяцев;
 *   5. документы филиалов за период складываем и сравниваем с ведомостью до копейки.
 *
 * Какие строки пишем:
 *   - совпало или не совпало, когда пару удалось сравнить. Пары нет вовсе или документа
 *     одного из филиалов не хватает: такую строку не пишем, чтобы не засорять страницу;
 *   - нет документа: задача закрыта без файла, или у квартального отчёта нет ведомости за
 *     какой-то месяц. Одна строка на клиента и период, в ней проверки, которые это ломает;
 *   - беда с документом: не тот документ, скан или файл не открылся. Стоит под каждой
 *     проверкой клиента, которая берёт числа из этого документа.
 *
 * Принудительно закрытые задачи ни документа, ни пропуска не дают: человек записал причину,
 * почему документа не будет («ежеквартально», «нет движений», «только один район»).
 *
 * Каждый прогон стирает прошлые результаты фирмы и пишет заново.
 */
class AutoAuditRunner
{
    public const REF_TAX_REPORT    = 3;
    public const REF_FORM_161      = 7;
    public const REF_BALANCE_SHEET = 11;

    /**
     * Документы, из которых берутся числа. Ключ: сторона сверки.
     *
     * ref:      эталонный номер БП, к задачам которого документ прикладывают;
     * label:    как подписать файл на странице;
     * genitive: «нет …» в причине;
     * probe:    чем проверить, что файл вообще нужная форма. Форму и период читалка
     *           проверяет до того, как искать число, поэтому годится любое поле.
     */
    private const SIDES = [
        'osv'  => ['ref' => self::REF_BALANCE_SHEET, 'label' => 'ОСВ',         'genitive' => 'оборотно-сальдовой ведомости', 'probe' => '3210'],
        'tax'  => ['ref' => self::REF_TAX_REPORT,    'label' => 'Отчёт по ЕН', 'genitive' => 'отчёта по единому налогу',     'probe' => 'base'],
        'f161' => ['ref' => self::REF_FORM_161,      'label' => 'Форма 161',   'genitive' => 'формы 161',                    'probe' => 'income'],
    ];

    /**
     * Проверки.
     *
     * Ключ: номер проверки, он же номер строки в таблице правил (колонка A). Номер не
     * меняем и не переиспользуем: по нему связаны результаты и фильтр на странице.
     *
     * account:   счёт в ОСВ, берём оборот за период по кредиту;
     * document:  сторона второго документа, см. SIDES;
     * field:     число из него: 'base' и 'tax' у отчёта по налогу; 'income', 'income_tax',
     *            'contributions' и 'pension' у формы 161;
     * cash_only: только для кассового метода. Полное обслуживание нужно всем.
     *
     * Все проверки берут обороты, поэтому ведомости за квартал складываются. У будущих
     * проверок по сальдо так нельзя: там нужен последний месяц.
     *
     * У №4 в таблице правил стоит счёт 3510, но в ведомостях фирм его нет вовсе: зарплата
     * на 3520 «Начисленная заработная плата». На боевых парах за июль 2026 оборот 3520
     * сошёлся с доходом формы 161 у всех, у кого форма своя.
     */
    public const RULES = [
        1 => [
            'name'      => 'Налоговая база сходится с учётом',
            'formula'   => 'ОСВ, оборот Кт 3210 = налоговая база из отчёта по единому налогу',
            'condition' => 'Кассовый метод, полное обслуживание',
            'account'   => '3210',
            'document'  => 'tax',
            'field'     => 'base',
            'cash_only' => true,
        ],
        3 => [
            'name'      => 'Начисленный единый налог сходится с учётом',
            'formula'   => 'ОСВ, оборот Кт 3410 = сумма единого налога из отчёта',
            'condition' => 'Полное обслуживание, любой метод учёта',
            'account'   => '3410',
            'document'  => 'tax',
            'field'     => 'tax',
            'cash_only' => false,
        ],
        4 => [
            'name'      => 'Начисленный доход сходится с учётом',
            'formula'   => 'ОСВ, оборот Кт 3520 = общая сумма начисленного дохода из формы 161',
            'condition' => 'Полное обслуживание, любой метод учёта',
            'account'   => '3520',
            'document'  => 'f161',
            'field'     => 'income',
            'cash_only' => false,
        ],
        5 => [
            'name'      => 'Подоходный налог к уплате сходится с учётом',
            'formula'   => 'ОСВ, оборот Кт 3420 = подоходный налог к уплате из формы 161',
            'condition' => 'Полное обслуживание, любой метод учёта',
            'account'   => '3420',
            'document'  => 'f161',
            'field'     => 'income_tax',
            'cash_only' => false,
        ],
        6 => [
            'name'      => 'Страховые взносы сходятся с учётом',
            'formula'   => 'ОСВ, оборот Кт 3531 = начисленные страховые взносы из формы 161',
            'condition' => 'Полное обслуживание, любой метод учёта',
            'account'   => '3531',
            'document'  => 'f161',
            'field'     => 'contributions',
            'cash_only' => false,
        ],
        7 => [
            'name'      => 'Взносы в НПФ сходятся с учётом',
            'formula'   => 'ОСВ, оборот Кт 3534 = начисленные взносы в НПФ из формы 161',
            'condition' => 'Полное обслуживание, любой метод учёта',
            'account'   => '3534',
            'document'  => 'f161',
            'field'     => 'pension',
            'cash_only' => false,
        ],
    ];

    /** Картинки вместо PDF или Excel: скан или фото, без распознавания прочитать нечем. */
    private const IMAGE_EXTENSIONS = ['jpg', 'jpeg', 'png', 'gif', 'bmp', 'tif', 'tiff', 'webp', 'heic'];

    /** Задачи, документам которых верим: работа закрыта или сдана на проверку. */
    private const DONE_STATUSES = ['completed', 'review'];

    /** Меньше копейки: числа приходят из разбора текста, и float может дать хвост. */
    private const EPSILON = 0.005;

    /** Прочитанное за прогон: один файл разбираем один раз на каждое число. */
    private array $cache = [];

    public function __construct(
        private readonly BalanceSheetReader $balanceSheet,
        private readonly SingleTaxReportReader $taxReport,
        private readonly Form161Reader $form161,
    ) {}

    /**
     * Прогнать проверку по текущей фирме и записать результат.
     *
     * @return array<string, int> сколько строк с каким исходом
     */
    public function run(): array
    {
        // Без фирмы в контексте удаление ниже снесло бы результаты всех фирм разом.
        if (!TenantContext::has()) {
            throw new RuntimeException('Автоаудит запускается только внутри фирмы');
        }

        $this->cache = [];

        $byRef    = Service::whereIn('reference_id', array_column(self::SIDES, 'ref'))->get()->keyBy('reference_id');
        $services = [];   // сторона => БП этой фирмы

        foreach (self::SIDES as $side => $definition) {
            if ($byRef->has($definition['ref'])) {
                $services[$side] = $byRef[$definition['ref']];
            }
        }

        // Проверка работает, только если в фирме размечены оба её БП.
        $rules = array_filter(self::RULES, fn (array $rule) => isset($services['osv'], $services[$rule['document']]));

        $rows = [];

        if ($rules) {
            foreach (Client::orderBy('name')->get() as $client) {
                array_push($rows, ...$this->checkClient($client, $services, $rules));
            }
        }

        DB::transaction(function () use ($rows) {
            AutoAuditResult::query()->delete();

            foreach ($rows as $row) {
                AutoAuditResult::create($row);
            }
        });

        return collect($rows)->countBy('outcome')->all();
    }

    /**
     * @param array<string, Service> $services сторона => БП
     * @param array<int, array>      $rules    проверки, для которых в фирме есть оба БП
     */
    private function checkClient(Client $client, array $services, array $rules): array
    {
        // Ведём не всё: ведомость или отчёт может делать кто-то другой, сверка ни о чём.
        if (!$client->servesEverything()) {
            return [];
        }

        $rules = array_filter(
            $rules,
            fn (array $rule) => !$rule['cash_only'] || $client->accounting_method === Client::ACCOUNTING_CASH,
        );

        // Ведомость нужна всем проверкам, вторые документы только своим.
        $documents = [];

        foreach (array_unique(['osv', ...array_column($rules, 'document')]) as $side) {
            $documents[$side] = $this->documents($client, $services[$side]);
        }

        $rows = $this->missingDocuments($client, $services, $documents, $rules);

        if (collect($documents)->every(fn (Collection $found) => $found->isEmpty())) {
            return $rows;
        }

        // Беда с документом ломает каждую проверку, которая берёт из него число, поэтому и
        // стоит под каждой. Второй стороны может не быть вовсе: беда от этого не пропадает.
        $problems = [];

        foreach ($documents as $side => $found) {
            $problems[$side] = $this->documentProblems($client, $side, $found);
        }

        $logs = [];

        foreach ($rules as $number => $rule) {
            $side = $rule['document'];

            foreach (array_merge($problems['osv'], $problems[$side]) as $row) {
                $rows[] = array_merge($row, ['rule' => (string) $number]);
            }

            $logs[$side] ??= $this->taskLogs($client, $services[$side]);

            array_push($rows, ...$this->checkRule($client, $number, $rule, $documents['osv'], $documents[$side], $logs[$side]));
        }

        return $rows;
    }

    /**
     * Нет документа: одна строка на период, в ней проверки, которые это ломает.
     *
     * Два случая:
     *   - задача закрыта (или сдана на проверку), а файла в ней нет. Работу отметили
     *     сделанной, а подтверждения нет. Незакрытые задачи не трогаем: это обычная работа
     *     или просрочка, её уже показывает БухЗадачник. Период берём месяцем перед задачей;
     *   - отчёт квартальный, а ведомости за какой-то месяц квартала нет. Нет ни одной
     *     ведомости за квартал: пары нет вовсе, строку не пишем. Период берём из отчёта.
     *
     * Нет ведомости, значит сломаны все проверки клиента. Нет второго документа, значит
     * только те, что берут из него число.
     *
     * Числа в такой строке не показываем: строка общая для проверок, а числа у них разные.
     */
    private function missingDocuments(Client $client, array $services, array $documents, array $rules): array
    {
        $affects = ['osv' => array_keys($rules)];

        foreach ($rules as $number => $rule) {
            $affects[$rule['document']][] = $number;
        }

        // Опознанные документы: сторона => подпись периода => период и источники.
        $recognized = [];

        foreach ($documents as $side => $found) {
            foreach ($found as [$log, $document]) {
                $value = $this->read($client, $side, self::SIDES[$side]['probe'], $document);

                if (!$value->period) {
                    continue;
                }

                $label = $value->period->label();
                $recognized[$side][$label]['period']    = $value->period;
                $recognized[$side][$label]['sources'][] = array_merge($this->source($side, $log, $document, $value), ['value' => null]);
            }
        }

        // Подпись периода => период, номера проверок, причины и файлы, найденные сверх опознанных.
        $rows = [];

        foreach (array_keys($documents) as $side) {
            foreach ($this->closedWithoutFiles($client, $services[$side]) as $log) {
                // С первого числа, иначе «31 августа минус месяц» перельётся мимо июля.
                $month  = CarbonImmutable::create($log->year, $log->month, 1)->subMonth();
                $period = DocumentPeriod::of($month->year, $month->month);
                $label  = $period->label();

                $rows[$label] ??= ['period' => $period, 'numbers' => [], 'notes' => [], 'extra' => []];
                array_push($rows[$label]['numbers'], ...$affects[$side]);
                $rows[$label]['notes'][] = $this->closedWithoutFileNote($log, $services[$side]);
            }
        }

        foreach ($recognized as $side => $periods) {
            if ($side === 'osv') {
                continue;
            }

            foreach ($periods as $label => $report) {
                // Документ месячный, или ведомость ровно за его период есть: сверка идёт обычным путём.
                if (count($report['period']->months()) === 1 || isset($recognized['osv'][$label])) {
                    continue;
                }

                $present = [];
                $missing = [];

                foreach ($report['period']->months() as [$year, $month]) {
                    $monthPeriod = DocumentPeriod::of($year, $month);

                    if (isset($recognized['osv'][$monthPeriod->label()])) {
                        array_push($present, ...$recognized['osv'][$monthPeriod->label()]['sources']);
                    } else {
                        $missing[] = $monthPeriod->title();
                    }
                }

                if (!$missing || !$present) {
                    continue;
                }

                $rows[$label] ??= ['period' => $report['period'], 'numbers' => [], 'notes' => [], 'extra' => []];
                array_push($rows[$label]['numbers'], ...$affects[$side]);
                array_push($rows[$label]['extra'], ...$present);
                $rows[$label]['notes'][] = 'Нет ведомости за ' . implode(', ', $missing);
            }
        }

        $result = [];

        foreach ($rows as $label => $row) {
            $numbers = array_values(array_unique($row['numbers']));
            sort($numbers);

            // Рядом видны уже приложенные документы этих проверок за тот же период.
            $sources = $row['extra'];

            foreach (array_unique(['osv', ...array_map(fn (int $number) => $rules[$number]['document'], $numbers)]) as $side) {
                array_push($sources, ...($recognized[$side][$label]['sources'] ?? []));
            }

            $result[] = [
                'client_id'   => $client->id,
                'rule'        => implode(',', $numbers),
                'period_from' => $row['period']->from->toDateString(),
                'period_to'   => $row['period']->to->toDateString(),
                'outcome'     => AutoAuditResult::MISSING_DOCUMENT,
                'left_value'  => null,
                'right_value' => null,
                'difference'  => null,
                'reason'      => implode('. ', $row['notes']),
                'sources'     => collect($sources)->unique('document_id')->values()->all(),
            ];
        }

        return $result;
    }

    /**
     * Закрытые или сданные на проверку задачи клиента по БП, где нет ни одного файла.
     *
     * Принудительно закрытые не берём: человек записал причину, почему файла не будет.
     */
    private function closedWithoutFiles(Client $client, Service $service): Collection
    {
        return BuhTaskLog::where('client_id', $client->id)
            ->whereIn('status', self::DONE_STATUSES)
            ->where(fn ($q) => $q->where('force_closed', false)->orWhereNull('force_closed'))
            ->whereHas('estimateItem', fn ($q) => $q->where('service_id', $service->id))
            ->whereDoesntHave('documents')
            ->with('employee:id,full_name')
            ->orderBy('year')->orderBy('month')
            ->get();
    }

    /** «Задача «Закрытие месяца и ОСВ» за 08.2026: исполнитель Иванова А., закрыта 05.08.2026 без файла». */
    private function closedWithoutFileNote(BuhTaskLog $log, Service $service): string
    {
        $parts = [];

        if ($log->employee) {
            $parts[] = 'исполнитель ' . $log->employee->full_name;
        }

        $parts[] = $log->status === 'review'
            ? 'сдана на проверку без файла'
            : trim('закрыта ' . ($log->completed_at?->format('d.m.Y') ?? '')) . ' без файла';

        return sprintf('Задача «%s» за %02d.%d: %s', $service->name, $log->month, $log->year, implode(', ', $parts));
    }

    /**
     * Задачи, где файлы приложены, но нужную форму среди них прочитать не удалось.
     *
     * Смотрим задачу целиком, а не каждый файл: рядом с отчётом часто лежит квитанция об
     * оплате, и сама по себе она не ошибка.
     *
     * Причину называем уверенно, только когда можем:
     *   - среди файлов есть скан или фото: нужный документ может быть как раз им, поэтому
     *     «скан», а не «не тот документ»;
     *   - какой-то файл не открылся: по той же причине «файл не открылся»;
     *   - все файлы открылись и прочитались, но это другие формы или другая организация:
     *     вот тогда «не тот документ».
     *
     * Отчётный период из такого файла не прочитать, а на странице строки выбираются по
     * периоду. Берём месяц перед месяцем задачи: отчёт за июль сдают в августовской задаче.
     */
    private function documentProblems(Client $client, string $side, Collection $documents): array
    {
        $rows = [];

        foreach ($documents->groupBy(fn (array $pair) => $pair[0]->id) as $pairs) {
            $sources    = [];
            $recognized = false;

            foreach ($pairs as [$log, $document]) {
                $value = $this->read($client, $side, self::SIDES[$side]['probe'], $document);

                // Период читалка отдаёт, только когда форма опознана.
                $recognized = $recognized || $value->period !== null;
                $sources[]  = $this->source($side, $log, $document, $value);
            }

            if ($recognized) {
                continue;
            }

            $statuses = array_column($sources, 'status');

            [$outcome, $reason] = match (true) {
                in_array(DocumentValue::SCAN, $statuses, true) => [
                    AutoAuditResult::SCAN,
                    'Документ отсканирован или сфотографирован, прочитать его пока нельзя',
                ],
                in_array(DocumentValue::UNREADABLE, $statuses, true) => [
                    AutoAuditResult::UNREADABLE,
                    'Файл не открылся',
                ],
                default => [
                    AutoAuditResult::WRONG_DOCUMENT,
                    'Среди файлов задачи нет ' . self::SIDES[$side]['genitive'],
                ],
            };

            // С первого числа, иначе «31 августа минус месяц» перельётся мимо июля.
            $month  = CarbonImmutable::create($log->year, $log->month, 1)->subMonth();
            $period = DocumentPeriod::of($month->year, $month->month);

            $rows[] = [
                'client_id'   => $client->id,
                'rule'        => null,
                'period_from' => $period->from->toDateString(),
                'period_to'   => $period->to->toDateString(),
                'outcome'     => $outcome,
                'left_value'  => null,
                'right_value' => null,
                'difference'  => null,
                'reason'      => $reason,
                'sources'     => $sources,
            ];
        }

        return $rows;
    }

    private function checkRule(
        Client $client,
        int $number,
        array $rule,
        Collection $sheetDocuments,
        Collection $reportDocuments,
        Collection $reportLogs,
    ): array {
        $periods = [];   // подпись периода => ['period' => DocumentPeriod, 'osv' => [...], 'report' => [...]]

        foreach (['osv' => $sheetDocuments, 'report' => $reportDocuments] as $slot => $documents) {
            $side  = $slot === 'osv' ? 'osv' : $rule['document'];
            $field = $slot === 'osv' ? $rule['account'] : $rule['field'];

            foreach ($documents as [$log, $document]) {
                $value = $this->read($client, $side, $field, $document);

                // Не тот документ, скан или не открылся: в пару его не поставить.
                if (!$value->period) {
                    continue;
                }

                $label = $value->period->label();
                $periods[$label]['period'] = $value->period;
                $periods[$label][$slot][]  = $this->source($side, $log, $document, $value);
            }
        }

        $rows = [];

        foreach ($periods as $entry) {
            $osv     = $entry['osv'] ?? [];
            $reports = $entry['report'] ?? [];

            // Документ за несколько месяцев, а ведомости за тот же период нет: ведомости у нас
            // помесячные, собираем их по месяцам документа.
            $sheets = !$osv && $reports && count($entry['period']->months()) > 1
                ? $this->sheetsByMonth($entry['period'], $periods)
                : [$entry['period']->title() => $osv];

            $row = $this->compare($client, $number, $rule, $entry['period'], $sheets, $reports, $reportLogs);

            if ($row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /**
     * Ведомости за каждый месяц периода, ключ «май 2026». Пустой список, если за месяц
     * ведомости нет.
     */
    private function sheetsByMonth(DocumentPeriod $period, array $periods): array
    {
        $sheets = [];

        foreach ($period->months() as [$year, $month]) {
            $monthPeriod = DocumentPeriod::of($year, $month);
            $sheets[$monthPeriod->title()] = $periods[$monthPeriod->label()]['osv'] ?? [];
        }

        return $sheets;
    }

    /**
     * Строка результата, или null, если сравнивать не с чем.
     *
     * @param array<string, array> $sheets ведомости по месяцам периода: «июль 2026» => источники
     */
    private function compare(
        Client $client,
        int $number,
        array $rule,
        DocumentPeriod $period,
        array $sheets,
        array $reports,
        Collection $reportLogs,
    ): ?array {
        // Документы только с одной стороны: пары нет.
        if (!$reports || !array_filter($sheets)) {
            return null;
        }

        // Квартал без ведомости за какой-то месяц: сумма двух месяцев дала бы ложное красное.
        // Строку «нет документа» об этом пишет missingDocuments().
        if (count(array_filter($sheets)) < count($sheets)) {
            return null;
        }

        $expected = $this->expectedReports($reportLogs, $period);

        // Без документа одного из филиалов сумма заведомо меньше, и красное было бы ложным.
        if (count($reports) < $expected) {
            return null;
        }

        $notes = [];

        if (count($reports) > $expected) {
            $notes[] = sprintf(
                'Документов «%s» за период %d, а филиалов %d: возможно, один приложен дважды',
                self::SIDES[$rule['document']]['label'],
                count($reports),
                $expected,
            );
        }

        $left        = 0.0;
        $sources     = [];
        $unknown     = [];   // числа, которые прочитать не удалось
        $assumedZero = [];   // обороты, которых в ведомости нет и которые взяты нулём

        foreach ($sheets as $month => $osv) {
            // Ведомость за месяц одна. Приложили несколько, берём последнюю загруженную.
            if (count($osv) > 1) {
                $notes[] = sprintf('Ведомостей за %s: %d, взята последняя', $month, count($osv));
            }

            if ($osv[0]['status'] === DocumentValue::UNCERTAIN) {
                $unknown[] = $osv[0]['reason'];
            } elseif ($osv[0]['status'] === DocumentValue::NOT_FOUND) {
                // В ОСВ 1С не печатает счета без оборотов и сальдо: нет строки, значит ноль.
                $assumedZero[] = "в ведомости за {$month} нет оборота по счёту {$rule['account']}";
            }

            $left += (float) ($osv[0]['value'] ?? 0);
            array_push($sources, ...$osv);
        }

        foreach ($reports as $report) {
            if ($report['status'] === DocumentValue::UNCERTAIN) {
                $unknown[] = $report['reason'];
            } elseif ($report['status'] === DocumentValue::NOT_FOUND) {
                $assumedZero[] = sprintf('в документе «%s» нет показателя', $report['name']);
            }
        }

        $left  = round($left, 2);
        $right = round((float) array_sum(array_column($reports, 'value')), 2);

        $row = [
            'client_id'   => $client->id,
            'rule'        => (string) $number,
            'period_from' => $period->from->toDateString(),
            'period_to'   => $period->to->toDateString(),
            'sources'     => array_merge($sources, $reports),
        ];

        // Числа нет: вердикт не выносим ни в какую сторону. Строку всё равно пишем, иначе
        // клиент пропал бы со страницы, а это выглядит как «у него всё в порядке».
        if ($unknown) {
            return $row + [
                'outcome'     => AutoAuditResult::UNVERIFIED,
                'left_value'  => null,
                'right_value' => null,
                'difference'  => null,
                'reason'      => 'Не удалось проверить: ' . implode('. ', array_unique(array_filter($unknown))),
            ];
        }

        $matched = abs($left - $right) < self::EPSILON;

        // Сошлось, но одна из сторон не прочитана, а взята нулём: «Совпало» тут собралось бы
        // из двух нулей, а не из проверенных чисел. Расхождение при этом остаётся
        // расхождением и показывается как раньше: если оборот не напечатан, он почти
        // наверняка нулевой, и красное по ненулевому документу это настоящая находка.
        if ($matched && $assumedZero) {
            return $row + [
                'outcome'     => AutoAuditResult::UNVERIFIED,
                'left_value'  => null,
                'right_value' => null,
                'difference'  => null,
                'reason'      => 'Не удалось проверить: числа сошлись только потому, что '
                    . implode(', ', array_unique($assumedZero)) . ', а второе число тоже нулевое',
            ];
        }

        foreach (array_unique($assumedZero) as $note) {
            $notes[] = mb_strtoupper(mb_substr($note, 0, 1)) . mb_substr($note, 1) . ', считаем его нулевым';
        }

        return $row + [
            'outcome'     => $matched ? AutoAuditResult::MATCHED : AutoAuditResult::MISMATCH,
            'left_value'  => $left,
            'right_value' => $right,
            'difference'  => round($left - $right, 2),
            'reason'      => $notes ? implode('. ', $notes) : null,
        ];
    }

    /**
     * Документы закрытых задач клиента по одному БП, последние загруженные первыми.
     *
     * @return Collection<int, array{0: BuhTaskLog, 1: BuhTaskDocument}>
     */
    private function documents(Client $client, Service $service): Collection
    {
        return BuhTaskLog::where('client_id', $client->id)
            ->whereIn('status', self::DONE_STATUSES)
            ->whereHas('estimateItem', fn ($q) => $q->where('service_id', $service->id))
            ->with(['documents', 'employee:id,full_name'])
            ->get()
            ->flatMap(fn (BuhTaskLog $log) => $log->documents->map(fn (BuhTaskDocument $document) => [$log, $document]))
            ->sortByDesc(fn (array $pair) => $pair[1]->id)
            ->values();
    }

    /** Все задачи клиента по БП в любом статусе: по ним считаем, от скольких филиалов ждать документ. */
    private function taskLogs(Client $client, Service $service): Collection
    {
        return BuhTaskLog::where('client_id', $client->id)
            ->whereHas('estimateItem', fn ($q) => $q->where('service_id', $service->id))
            ->get(['id', 'estimate_item_id', 'year', 'month', 'force_closed']);
    }

    /**
     * Сколько документов ждём за период: по одному от каждого филиала, у которого есть
     * задача за этот период. Задача идёт месяцем позже периода: отчёт за июль сдают в
     * августе. У филиального БП задача своя на каждый налоговый орган.
     *
     * Принудительно закрытую задачу не считаем: человек записал, почему документа не будет
     * («только один район», «ежеквартально»).
     */
    private function expectedReports(Collection $logs, DocumentPeriod $period): int
    {
        $months = array_map(fn (array $month) => sprintf('%04d-%02d', ...$month), $period->months());

        $expected = $logs
            ->reject(fn (BuhTaskLog $log) => (bool) $log->force_closed)
            ->filter(fn (BuhTaskLog $log) => in_array(
                CarbonImmutable::create($log->year, $log->month, 1)->subMonth()->format('Y-m'),
                $months,
                true,
            ))
            ->pluck('estimate_item_id')
            ->unique()
            ->count();

        return max(1, $expected);
    }

    /**
     * Одно число из документа клиента.
     *
     * @param string $field для ОСВ номер счёта, для второго документа его поле
     */
    private function read(Client $client, string $side, string $field, BuhTaskDocument $document): DocumentValue
    {
        return $this->cache["{$side}:{$field}:{$document->id}"] ??= $this->checkInn($client, $this->readFile($side, $field, $document));
    }

    /**
     * ИНН в шапке документа не тот, что в карточке клиента.
     *
     * Без этой проверки чужая форма дала бы «не совпало» по числам, и расхождение искали бы
     * в учёте, хотя перепутан файл. Так нашлась форма 161 «Нова Трек» у «Нова трек плюс».
     * Сверяем, только когда ИНН есть и в документе, и в карточке. В ОСВ ИНН нет.
     *
     * Кто ошибся, документ или карточка, мы не знаем: у ИНАМ отчёт и форма 161 показывают
     * один и тот же ИНН, а в карточке записан другой. Поэтому причину пишем без обвинения.
     *
     * В части карточек вместо ИНН стоит заглушка вроде «00000000000003»: клиентов заводили,
     * пока ИНН не знали. Настоящий ИНН не начинается с пяти нулей (у организации там ноль
     * и дата регистрации, у человека единица или двойка), поэтому такую карточку пропускаем.
     * Иначе свой документ клиента назывался бы чужим.
     */
    private function checkInn(Client $client, DocumentValue $value): DocumentValue
    {
        $clientInn = preg_replace('/\D+/', '', (string) $client->inn);

        if ($value->inn === null || strlen($clientInn) !== 14 || str_starts_with($clientInn, '00000') || $value->inn === $clientInn) {
            return $value;
        }

        return DocumentValue::wrongDocument(
            "ИНН не совпадает: в документе {$value->inn}, в карточке клиента {$clientInn}. Документ чужой или ошибка в карточке",
        );
    }

    private function readFile(string $side, string $field, BuhTaskDocument $document): DocumentValue
    {
        $path = Storage::disk('local')->path($document->path);

        if (!is_readable($path)) {
            return DocumentValue::unreadable('Файла нет на диске');
        }

        // Картинку не открыть ни как таблицу, ни как PDF. Документ на ней может быть и тем,
        // просто прочитать его без распознавания нечем.
        if (in_array(strtolower(pathinfo($document->path, PATHINFO_EXTENSION)), self::IMAGE_EXTENSIONS, true)) {
            return DocumentValue::scan('Это картинка, а не PDF или Excel');
        }

        try {
            return match ($side) {
                'osv'  => $this->balanceSheet->turnover($path, $field, 'credit'),
                'tax'  => $field === 'tax' ? $this->taxReport->totalTax($path) : $this->taxReport->taxableBase($path),
                'f161' => $this->form161->read($path, $field),
            };
        } catch (Throwable $e) {
            // Один кривой файл не должен ронять проверку всей фирмы.
            return DocumentValue::unreadable(class_basename($e) . ': ' . $e->getMessage());
        }
    }

    /** Откуда взято число: по этому человек откроет файл и проверит вывод сам. */
    private function source(string $side, BuhTaskLog $log, BuhTaskDocument $document, DocumentValue $value): array
    {
        return [
            'side'        => $side,
            'label'       => self::SIDES[$side]['label'],
            'log_id'      => $log->id,
            'task_month'  => sprintf('%02d.%d', $log->month, $log->year),
            'employee'    => $this->shortName($log->employee?->full_name),
            'document_id' => $document->id,
            'name'        => $document->name,
            'status'      => $value->status,
            'value'       => $value->value,
            'reason'      => $value->reason,
        ];
    }

    /**
     * Исполнитель задачи коротко: «Обозова Айзада Алмасбековна» становится «Обозова А. А.».
     * Полные ФИО раздувают колонку с документами.
     */
    private function shortName(?string $fullName): ?string
    {
        $parts = preg_split('/\s+/u', trim((string) $fullName), -1, PREG_SPLIT_NO_EMPTY);

        if (!$parts) {
            return null;
        }

        $initials = array_map(fn (string $part) => mb_strtoupper(mb_substr($part, 0, 1)) . '.', array_slice($parts, 1));

        return trim($parts[0] . ' ' . implode(' ', $initials));
    }
}
