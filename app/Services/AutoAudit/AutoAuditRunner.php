<?php

namespace App\Services\AutoAudit;

use App\Models\AutoAuditResult;
use App\Models\BuhTaskDocument;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\EstimateItem;
use App\Models\Service;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Первый, самый простой прогон автоаудита: ОСВ против отчёта по единому налогу.
 *
 * Правила зашиты здесь же, списком. Это сознательно топорно: цель первого варианта
 * увидеть на боевых документах, совпадают числа или нет. Каталог правил, допуски и
 * переключатели появятся потом, когда станет понятно, как выглядит настоящее расхождение.
 *
 * Как идёт сверка у одного клиента:
 *   1. берём документы закрытых задач двух эталонных БП;
 *   2. читаем каждый и узнаём период из самого документа, а не из месяца задачи;
 *   3. ставим в пару ведомость и отчёты за один и тот же период. Ведомость всегда
 *      помесячная, а отчёт бывает квартальным: тогда складываем ведомости трёх месяцев;
 *   4. отчёты филиалов за период складываем и сравниваем с ведомостью до копейки.
 *
 * Какие строки пишем:
 *   - совпало или не совпало, когда пару удалось сравнить. Пары нет вовсе или отчёта
 *     одного из филиалов не хватает: такую строку не пишем, чтобы не засорять страницу;
 *   - нет документа: задача закрыта без файла, или у квартального отчёта нет ведомости за
 *     какой-то месяц. Одна строка на клиента и период сразу для всех его проверок;
 *   - беда с документом: не тот документ, скан или файл не открылся. Стоит под каждой
 *     проверкой клиента, которая берёт числа из этого документа.
 *
 * Каждый прогон стирает прошлые результаты фирмы и пишет заново.
 */
class AutoAuditRunner
{
    public const REF_TAX_REPORT    = 3;
    public const REF_BALANCE_SHEET = 11;

    /**
     * Проверки первого варианта.
     *
     * Ключ: номер проверки, он же номер строки в таблице правил (колонка A). Номер не
     * меняем и не переиспользуем: по нему связаны результаты и фильтр на странице.
     *
     * account:   счёт в ОСВ, берём оборот за период по кредиту;
     * field:     число из отчёта, 'base' (налоговая база) или 'tax' (сумма налога);
     * cash_only: только для кассового метода. Полное обслуживание нужно обеим.
     *
     * Обе проверки берут обороты, поэтому ведомости за квартал складываются. У будущих
     * проверок по сальдо так нельзя: там нужен последний месяц.
     */
    public const RULES = [
        1 => [
            'name'      => 'Налоговая база сходится с учётом',
            'formula'   => 'ОСВ, оборот Кт 3210 = налоговая база из отчёта по единому налогу',
            'condition' => 'Кассовый метод, полное обслуживание',
            'account'   => '3210',
            'field'     => 'base',
            'cash_only' => true,
        ],
        3 => [
            'name'      => 'Начисленный единый налог сходится с учётом',
            'formula'   => 'ОСВ, оборот Кт 3410 = сумма единого налога из отчёта',
            'condition' => 'Полное обслуживание, любой метод учёта',
            'account'   => '3410',
            'field'     => 'tax',
            'cash_only' => false,
        ],
    ];

    /**
     * Чем проверяем, что файл вообще нужная форма. Форму и период читалка проверяет до
     * того, как искать число, поэтому годится любое. Берём то, что читает проверка №1:
     * разбор из кеша пригодится ей же.
     */
    private const PROBE = ['osv' => '3210', 'tax' => 'base'];

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

        $osvService = Service::where('reference_id', self::REF_BALANCE_SHEET)->first();
        $taxService = Service::where('reference_id', self::REF_TAX_REPORT)->first();

        $rows = [];

        // Фирма без обоих эталонных БП не размечена, сверять в ней нечего.
        if ($osvService && $taxService) {
            foreach (Client::orderBy('name')->get() as $client) {
                array_push($rows, ...$this->checkClient($client, $osvService, $taxService));
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

    private function checkClient(Client $client, Service $osvService, Service $taxService): array
    {
        // Ведём не всё: ведомость или отчёт может делать кто-то другой, сверка ни о чём.
        if (!$client->servesEverything()) {
            return [];
        }

        $numbers = array_keys(array_filter(
            self::RULES,
            fn (array $rule) => !$rule['cash_only'] || $client->accounting_method === Client::ACCOUNTING_CASH,
        ));

        $osvDocuments = $this->documents($client, $osvService);
        $taxDocuments = $this->documents($client, $taxService);

        // «Нет документа» одинаково для всех проверок клиента: одна строка, в ней все номера.
        $rows = array_map(
            fn (array $row) => array_merge($row, ['rule' => implode(',', $numbers)]),
            $this->missingDocuments($client, $osvService, $taxService, $osvDocuments, $taxDocuments),
        );

        if ($osvDocuments->isEmpty() && $taxDocuments->isEmpty()) {
            return $rows;
        }

        // Беда с документом ломает каждую проверку, которая берёт из него число, поэтому и
        // стоит под каждой. Второй стороны может не быть вовсе: беда от этого не пропадает.
        $problems = array_merge(
            $this->documentProblems($client, 'osv', $osvDocuments),
            $this->documentProblems($client, 'tax', $taxDocuments),
        );

        $taxItems = $this->estimateItems($client, $taxService);

        foreach ($numbers as $number) {
            foreach ($problems as $row) {
                $rows[] = array_merge($row, ['rule' => (string) $number]);
            }

            array_push($rows, ...$this->checkRule($client, $number, self::RULES[$number], $osvDocuments, $taxDocuments, $taxItems));
        }

        return $rows;
    }

    /**
     * Нет документа: одна строка на период, сразу для всех проверок клиента.
     *
     * Два случая:
     *   - задача по ОСВ или отчёту закрыта (или сдана на проверку), а файла в ней нет. Работу
     *     отметили сделанной, а подтверждения нет. Незакрытые задачи не трогаем: это обычная
     *     работа или просрочка, её уже показывает БухЗадачник. Период берём месяцем перед
     *     задачей, как у беды с документом;
     *   - отчёт квартальный, а ведомости за какой-то месяц квартала нет. Нет ни одной
     *     ведомости за квартал: пары нет вовсе, строку не пишем. Период берём из отчёта.
     *
     * Числа в такой строке не показываем: строка общая для проверок, а числа у них разные.
     */
    private function missingDocuments(
        Client $client,
        Service $osvService,
        Service $taxService,
        Collection $osvDocuments,
        Collection $taxDocuments,
    ): array {
        // Опознанные документы по периодам: сторона => подпись периода => период и источники.
        $recognized = ['osv' => [], 'tax' => []];

        foreach (['osv' => $osvDocuments, 'tax' => $taxDocuments] as $side => $documents) {
            foreach ($documents as [$log, $document]) {
                $value = $this->read($side, self::PROBE[$side], $document);

                if (!$value->period) {
                    continue;
                }

                $label = $value->period->label();
                $recognized[$side][$label]['period']    = $value->period;
                $recognized[$side][$label]['sources'][] = array_merge($this->source($side, $log, $document, $value), ['value' => null]);
            }
        }

        $rows = [];   // подпись периода => ['period' => DocumentPeriod, 'notes' => [...], 'sources' => [...]]

        foreach (['osv' => $osvService, 'tax' => $taxService] as $service) {
            foreach ($this->closedWithoutFiles($client, $service) as $log) {
                // С первого числа, иначе «31 августа минус месяц» перельётся мимо июля.
                $month  = CarbonImmutable::create($log->year, $log->month, 1)->subMonth();
                $period = DocumentPeriod::of($month->year, $month->month);
                $label  = $period->label();

                // Документы второй стороны за тот же период видны рядом: понятно, что уже есть.
                $rows[$label] ??= [
                    'period'  => $period,
                    'notes'   => [],
                    'sources' => array_merge(
                        $recognized['osv'][$label]['sources'] ?? [],
                        $recognized['tax'][$label]['sources'] ?? [],
                    ),
                ];

                $rows[$label]['notes'][] = $this->closedWithoutFileNote($log, $service);
            }
        }

        foreach ($recognized['tax'] as $label => $report) {
            // Отчёт месячный, или ведомость ровно за его период есть: сверка идёт обычным путём.
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

            $rows[$label] ??= ['period' => $report['period'], 'notes' => [], 'sources' => array_merge($present, $report['sources'])];
            $rows[$label]['notes'][] = 'Нет ведомости за ' . implode(', ', $missing);
        }

        return array_map(fn (array $row) => [
            'client_id'   => $client->id,
            'rule'        => null,
            'period_from' => $row['period']->from->toDateString(),
            'period_to'   => $row['period']->to->toDateString(),
            'outcome'     => AutoAuditResult::MISSING_DOCUMENT,
            'left_value'  => null,
            'right_value' => null,
            'difference'  => null,
            'reason'      => implode('. ', $row['notes']),
            'sources'     => $row['sources'],
        ], array_values($rows));
    }

    /** Закрытые или сданные на проверку задачи клиента по БП, где нет ни одного файла. */
    private function closedWithoutFiles(Client $client, Service $service): Collection
    {
        return BuhTaskLog::where('client_id', $client->id)
            ->whereIn('status', self::DONE_STATUSES)
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

        if ($log->force_closed) {
            $parts[] = 'закрыта принудительно' . ($log->force_close_comment ? ' («' . $log->force_close_comment . '»)' : '');
        }

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
     *   - все файлы открылись и прочитались, но это другие формы: вот тогда «не тот документ».
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
                $value = $this->read($side, self::PROBE[$side], $document);

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
                    $side === 'osv'
                        ? 'Среди файлов задачи нет оборотно-сальдовой ведомости'
                        : 'Среди файлов задачи нет отчёта по единому налогу',
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
        Collection $osvDocuments,
        Collection $taxDocuments,
        Collection $taxItems,
    ): array {
        $periods = [];   // подпись периода => ['period' => DocumentPeriod, 'osv' => [...], 'tax' => [...]]

        foreach (['osv' => $osvDocuments, 'tax' => $taxDocuments] as $side => $documents) {
            foreach ($documents as [$log, $document]) {
                $value = $this->read($side, $side === 'osv' ? $rule['account'] : $rule['field'], $document);

                // Не тот документ, скан или не открылся: в пару его не поставить.
                if (!$value->period) {
                    continue;
                }

                $label = $value->period->label();
                $periods[$label]['period'] = $value->period;
                $periods[$label][$side][]  = $this->source($side, $log, $document, $value);
            }
        }

        $rows = [];

        foreach ($periods as $entry) {
            $osv = $entry['osv'] ?? [];
            $tax = $entry['tax'] ?? [];

            // Отчёт за несколько месяцев, а ведомости за тот же период нет: ведомости у нас
            // помесячные, собираем их по месяцам отчёта.
            $sheets = !$osv && $tax && count($entry['period']->months()) > 1
                ? $this->sheetsByMonth($entry['period'], $periods)
                : [$entry['period']->title() => $osv];

            $row = $this->compare($client, $number, $rule, $entry['period'], $sheets, $tax, $taxItems);

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
        array $tax,
        Collection $taxItems,
    ): ?array {
        // Документы только с одной стороны: пары нет.
        if (!$tax || !array_filter($sheets)) {
            return null;
        }

        // Квартал без ведомости за какой-то месяц: сумма двух месяцев дала бы ложное красное.
        // Строку «нет документа» об этом пишет missingDocuments().
        if (count(array_filter($sheets)) < count($sheets)) {
            return null;
        }

        $expected = $this->expectedReports($taxItems, $period);

        // Без отчёта одного из филиалов сумма заведомо меньше, и красное было бы ложным.
        if (count($tax) < $expected) {
            return null;
        }

        $notes = [];

        if (count($tax) > $expected) {
            $notes[] = sprintf('Отчётов по налогу %d, а филиалов %d: возможно, один приложен дважды', count($tax), $expected);
        }

        $left    = 0.0;
        $sources = [];

        foreach ($sheets as $month => $osv) {
            // Ведомость за месяц одна. Приложили несколько, берём последнюю загруженную.
            if (count($osv) > 1) {
                $notes[] = sprintf('Ведомостей за %s: %d, взята последняя', $month, count($osv));
            }

            // В ОСВ 1С не печатает счета без оборотов и сальдо: нет строки, значит ноль.
            if ($osv[0]['status'] === DocumentValue::NOT_FOUND) {
                $notes[] = "В ведомости за {$month} нет счёта {$rule['account']}, оборот считаем нулевым";
            }

            $left += (float) ($osv[0]['value'] ?? 0);
            array_push($sources, ...$osv);
        }

        $left  = round($left, 2);
        $right = round((float) array_sum(array_column($tax, 'value')), 2);

        return [
            'client_id'   => $client->id,
            'rule'        => (string) $number,
            'period_from' => $period->from->toDateString(),
            'period_to'   => $period->to->toDateString(),
            'outcome'     => abs($left - $right) < self::EPSILON ? AutoAuditResult::MATCHED : AutoAuditResult::MISMATCH,
            'left_value'  => $left,
            'right_value' => $right,
            'difference'  => round($left - $right, 2),
            'reason'      => $notes ? implode('. ', $notes) : null,
            'sources'     => array_merge($sources, $tax),
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
            ->with('documents')
            ->get()
            ->flatMap(fn (BuhTaskLog $log) => $log->documents->map(fn (BuhTaskDocument $document) => [$log, $document]))
            ->sortByDesc(fn (array $pair) => $pair[1]->id)
            ->values();
    }

    /** Строки сметы клиента по БП. У филиального БП строка на каждый налоговый орган. */
    private function estimateItems(Client $client, Service $service): Collection
    {
        return EstimateItem::where('service_id', $service->id)
            ->whereNull('parent_id')
            ->whereHas('estimate', fn ($q) => $q->where('client_id', $client->id))
            ->get();
    }

    /**
     * Сколько отчётов по налогу ждём за период: столько, сколько строк сметы тогда работало.
     *
     * Строку, закрытую раньше начала периода, не считаем: филиал закрыли, отчёта по нему
     * больше не будет.
     */
    private function expectedReports(Collection $items, DocumentPeriod $period): int
    {
        $working = $items->filter(
            fn (EstimateItem $item) => !$item->isClosed() || $item->tasksEndAt()->greaterThanOrEqualTo($period->from),
        );

        return max(1, $working->count());
    }

    /**
     * Одно число из документа.
     *
     * @param string $field для ОСВ номер счёта, для отчёта 'base' или 'tax'
     */
    private function read(string $side, string $field, BuhTaskDocument $document): DocumentValue
    {
        return $this->cache["{$side}:{$field}:{$document->id}"] ??= $this->readFile($side, $field, $document);
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
            if ($side === 'osv') {
                return $this->balanceSheet->turnover($path, $field, 'credit');
            }

            return $field === 'tax'
                ? $this->taxReport->totalTax($path)
                : $this->taxReport->taxableBase($path);
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
            'log_id'      => $log->id,
            'task_month'  => sprintf('%02d.%d', $log->month, $log->year),
            'document_id' => $document->id,
            'name'        => $document->name,
            'status'      => $value->status,
            'value'       => $value->value,
            'reason'      => $value->reason,
        ];
    }
}
