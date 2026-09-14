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
 *   3. ставим в пару ведомость и отчёты за один и тот же период;
 *   4. отчёты филиалов за период складываем и сравниваем с ведомостью до копейки.
 *
 * Каждая строка относится к одной из проверок, статусов три:
 *   - совпало или не совпало, когда пару удалось сравнить. Пары за период нет или отчёта
 *     одного из филиалов не хватает: такую строку не пишем, чтобы не засорять страницу;
 *   - «не тот документ»: задача закрыта с файлами, но ни один не читается как нужная форма.
 *     Стоит под каждой проверкой клиента, которая берёт числа из этого документа.
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

        $osvDocuments = $this->documents($client, $osvService);
        $taxDocuments = $this->documents($client, $taxService);

        if ($osvDocuments->isEmpty() && $taxDocuments->isEmpty()) {
            return [];
        }

        // Не тот документ ломает каждую проверку, которая берёт из него число, поэтому и
        // стоит под каждой. Второй стороны может не быть вовсе: ошибка в файле от этого
        // не пропадает.
        $wrong = array_merge(
            $this->wrongDocuments($client, 'osv', $osvDocuments),
            $this->wrongDocuments($client, 'tax', $taxDocuments),
        );

        $taxItems = $this->estimateItems($client, $taxService);
        $rows     = [];

        foreach (self::RULES as $number => $rule) {
            if ($rule['cash_only'] && $client->accounting_method !== Client::ACCOUNTING_CASH) {
                continue;
            }

            foreach ($wrong as $row) {
                $rows[] = array_merge($row, ['rule' => (string) $number]);
            }

            array_push($rows, ...$this->checkRule($client, $number, $rule, $osvDocuments, $taxDocuments, $taxItems));
        }

        return $rows;
    }

    /**
     * Задачи, где файлы приложены, но нужной формы среди них нет.
     *
     * Смотрим задачу целиком, а не каждый файл: рядом с отчётом часто лежит квитанция об
     * оплате, и сама по себе она не ошибка. Ошибка, когда ни один файл задачи не прочитался
     * как нужная форма.
     *
     * Отчётный период из такого файла не прочитать, а на странице строки выбираются по
     * периоду. Берём месяц перед месяцем задачи: отчёт за июль сдают в августовской задаче.
     */
    private function wrongDocuments(Client $client, string $side, Collection $documents): array
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

            // С первого числа, иначе «31 августа минус месяц» перельётся мимо июля.
            $month  = CarbonImmutable::create($log->year, $log->month, 1)->subMonth();
            $period = DocumentPeriod::of($month->year, $month->month);

            $rows[] = [
                'client_id'   => $client->id,
                'rule'        => null,
                'period_from' => $period->from->toDateString(),
                'period_to'   => $period->to->toDateString(),
                'outcome'     => AutoAuditResult::WRONG_DOCUMENT,
                'left_value'  => null,
                'right_value' => null,
                'difference'  => null,
                'reason'      => $side === 'osv'
                    ? 'Среди файлов задачи нет оборотно-сальдовой ведомости'
                    : 'Среди файлов задачи нет отчёта по единому налогу',
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

                // Не тот документ или не открылся: в пару его не поставить.
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
            $row = $this->compare($client, $number, $rule, $entry['period'], $entry['osv'] ?? [], $entry['tax'] ?? [], $taxItems);

            if ($row) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    /** Строка результата, или null, если сравнивать за этот период не с чем. */
    private function compare(
        Client $client,
        int $number,
        array $rule,
        DocumentPeriod $period,
        array $osv,
        array $tax,
        Collection $taxItems,
    ): ?array {
        // Документ за период только с одной стороны: пары нет.
        if (!$osv || !$tax) {
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

        // Ведомость за период одна. Приложили несколько, берём последнюю загруженную.
        $sheet = $osv[0];

        if (count($osv) > 1) {
            $notes[] = sprintf('Ведомостей за период %d, взята последняя', count($osv));
        }

        // В ОСВ 1С не печатает счета без оборотов и сальдо: нет строки, значит ноль.
        if ($sheet['status'] === DocumentValue::NOT_FOUND) {
            $notes[] = "В ведомости нет счёта {$rule['account']}, оборот считаем нулевым";
        }

        $left       = round((float) ($sheet['value'] ?? 0), 2);
        $right      = round((float) array_sum(array_column($tax, 'value')), 2);
        $difference = round($left - $right, 2);

        return [
            'client_id'   => $client->id,
            'rule'        => (string) $number,
            'period_from' => $period->from->toDateString(),
            'period_to'   => $period->to->toDateString(),
            'outcome'     => abs($difference) < self::EPSILON ? AutoAuditResult::MATCHED : AutoAuditResult::MISMATCH,
            'left_value'  => $left,
            'right_value' => $right,
            'difference'  => $difference,
            'reason'      => $notes ? implode('. ', $notes) : null,
            'sources'     => array_merge($osv, $tax),
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
