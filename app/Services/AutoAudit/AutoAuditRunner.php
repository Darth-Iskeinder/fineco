<?php

namespace App\Services\AutoAudit;

use App\Models\AutoAuditResult;
use App\Models\BuhTaskDocument;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\EstimateItem;
use App\Models\Service;
use App\Support\TenantContext;
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
 * Каждый прогон стирает прошлые результаты фирмы и пишет заново.
 */
class AutoAuditRunner
{
    public const REF_TAX_REPORT    = 3;
    public const REF_BALANCE_SHEET = 11;

    /**
     * Проверки первого варианта.
     *
     * account: счёт в ОСВ, берём оборот за период по кредиту;
     * field:   число из отчёта, 'base' (налоговая база) или 'tax' (сумма налога);
     * cash_only: только для кассового метода. Полное обслуживание нужно обеим.
     */
    public const RULES = [
        'osv_3210_tax_base' => [
            'name'      => 'Оборот Кт 3210 = налоговая база',
            'account'   => '3210',
            'field'     => 'base',
            'cash_only' => true,
        ],
        'osv_3410_tax_total' => [
            'name'      => 'Оборот Кт 3410 = сумма единого налога',
            'account'   => '3410',
            'field'     => 'tax',
            'cash_only' => false,
        ],
    ];

    /** Задачи, документам которых верим: работа закрыта или сдана на проверку. */
    private const DONE_STATUSES = ['completed', 'review'];

    /** Меньше копейки: числа приходят из разбора текста, и float может дать хвост. */
    private const EPSILON = 0.005;

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

        // Ни одного документа: период узнать не из чего, строку не пишем.
        if ($osvDocuments->isEmpty() && $taxDocuments->isEmpty()) {
            return [];
        }

        $taxItems = $this->estimateItems($client, $taxService);
        $rows     = [];

        foreach (self::RULES as $key => $rule) {
            if ($rule['cash_only'] && $client->accounting_method !== Client::ACCOUNTING_CASH) {
                continue;
            }

            array_push($rows, ...$this->checkRule($client, $key, $rule, $osvDocuments, $taxDocuments, $taxItems));
        }

        return $rows;
    }

    private function checkRule(
        Client $client,
        string $key,
        array $rule,
        Collection $osvDocuments,
        Collection $taxDocuments,
        Collection $taxItems,
    ): array {
        $rows    = [];
        $periods = [];   // подпись периода => ['period' => DocumentPeriod, 'osv' => [...], 'tax' => [...]]

        foreach (['osv' => $osvDocuments, 'tax' => $taxDocuments] as $side => $documents) {
            foreach ($documents as [$log, $document]) {
                $value  = $this->read($side, $rule, $document);
                $source = $this->source($side, $log, $document, $value);

                // Не тот документ или не открылся: в пару его не поставить, но показать надо.
                if (!$value->period) {
                    $rows[] = $this->row($client, $key, null, AutoAuditResult::NO_DOCUMENTS,
                        reason: ($side === 'osv' ? 'Не прочитали ведомость: ' : 'Не прочитали отчёт по налогу: ') . $value->reason,
                        sources: [$source],
                    );

                    continue;
                }

                $label = $value->period->label();
                $periods[$label]['period'] = $value->period;
                $periods[$label][$side][]  = $source;
            }
        }

        foreach ($periods as $entry) {
            $rows[] = $this->compare($client, $key, $rule, $entry['period'], $entry['osv'] ?? [], $entry['tax'] ?? [], $taxItems);
        }

        return $rows;
    }

    private function compare(
        Client $client,
        string $key,
        array $rule,
        DocumentPeriod $period,
        array $osv,
        array $tax,
        Collection $taxItems,
    ): array {
        if (!$osv) {
            return $this->row($client, $key, $period, AutoAuditResult::NO_DOCUMENTS,
                reason: 'Нет ведомости за этот период', sources: $tax);
        }

        if (!$tax) {
            return $this->row($client, $key, $period, AutoAuditResult::NO_DOCUMENTS,
                reason: 'Нет отчёта по налогу за этот период', sources: $osv);
        }

        $notes    = [];
        $expected = $this->expectedReports($taxItems, $period);

        // Без отчёта одного филиала сумма заведомо меньше, и красное было бы ложным.
        if (count($tax) < $expected) {
            return $this->row($client, $key, $period, AutoAuditResult::NO_DOCUMENTS,
                reason: sprintf('Отчётов по налогу %d, а филиалов %d: сумма неполная', count($tax), $expected),
                sources: array_merge($osv, $tax),
            );
        }

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

        return $this->row($client, $key, $period,
            abs($difference) < self::EPSILON ? AutoAuditResult::MATCHED : AutoAuditResult::MISMATCH,
            left: $left,
            right: $right,
            difference: $difference,
            reason: $notes ? implode('. ', $notes) : null,
            sources: array_merge($osv, $tax),
        );
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

    private function read(string $side, array $rule, BuhTaskDocument $document): DocumentValue
    {
        $path = Storage::disk('local')->path($document->path);

        if (!is_readable($path)) {
            return DocumentValue::unreadable('файла нет на диске');
        }

        try {
            if ($side === 'osv') {
                return $this->balanceSheet->turnover($path, $rule['account'], 'credit');
            }

            return $rule['field'] === 'tax'
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

    private function row(
        Client $client,
        string $rule,
        ?DocumentPeriod $period,
        string $outcome,
        ?float $left = null,
        ?float $right = null,
        ?float $difference = null,
        ?string $reason = null,
        array $sources = [],
    ): array {
        return [
            'client_id'   => $client->id,
            'rule'        => $rule,
            'period_from' => $period?->from->toDateString(),
            'period_to'   => $period?->to->toDateString(),
            'outcome'     => $outcome,
            'left_value'  => $left,
            'right_value' => $right,
            'difference'  => $difference,
            'reason'      => $reason,
            'sources'     => $sources,
        ];
    }
}
