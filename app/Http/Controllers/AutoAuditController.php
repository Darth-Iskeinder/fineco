<?php

namespace App\Http\Controllers;

use App\Models\AutoAuditResult;
use App\Services\AutoAudit\AutoAuditRunner;
use App\Support\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Страница автоаудита: что сошлось, что нет.
 *
 * Пока её видит только владелец системы, зашедший в фирму. Сырые результаты фирме
 * показывать рано: неизвестно, как выглядит настоящее расхождение, и ложное красное
 * подорвало бы доверие к проверке с первого дня. Всем остальным страница отвечает 404,
 * как будто её нет.
 *
 * Три фильтра, все в адресе страницы: период, проверка и итог. По умолчанию показан
 * самый свежий период: смотрят обычно конкретный месяц, а не всю историю разом.
 */
class AutoAuditController extends Controller
{
    /** Сначала то, на что надо смотреть. Заодно список допустимых итогов для фильтра. */
    private const OUTCOME_ORDER = [
        AutoAuditResult::MISMATCH => 0,
        AutoAuditResult::MATCHED  => 1,
    ];

    /** Значение фильтра «все периоды». */
    public const ALL_PERIODS = 'all';

    public function index(Request $request): View
    {
        $this->vendorOnly();

        $everything = AutoAuditResult::with('client:id,name')->get();

        // «Не тот документ» живёт отдельным блоком: периода у непрочитанного файла нет,
        // и фильтры страницы к нему не относятся. Свежие задачи сверху.
        [$issues, $all] = $everything->partition(fn (AutoAuditResult $r) => $r->outcome === AutoAuditResult::WRONG_DOCUMENT);

        $issues = $issues
            ->sortBy(fn (AutoAuditResult $r) => [
                -(int) (substr($r->taskMonth(), 3) . substr($r->taskMonth(), 0, 2)),
                mb_strtolower($r->client?->name ?? ''),
            ])
            ->values();

        // Периоды от свежих к старым: первый в списке и показываем по умолчанию.
        $periods = $all
            ->sortByDesc(fn (AutoAuditResult $r) => [$r->period_to?->timestamp, $r->period_from?->timestamp])
            ->mapWithKeys(fn (AutoAuditResult $r) => [$r->periodKey() => $r->periodLabel()])
            ->all();

        $period = $this->param($request, 'period');

        if ($period !== self::ALL_PERIODS && !array_key_exists((string) $period, $periods)) {
            $period = array_key_first($periods);
        }

        $inPeriod = $period === self::ALL_PERIODS
            ? $all
            : $all->filter(fn (AutoAuditResult $r) => $r->periodKey() === $period);

        $rule = $this->param($request, 'rule');

        if ($rule !== null && !(ctype_digit($rule) && array_key_exists((int) $rule, AutoAuditRunner::RULES))) {
            $rule = null;
        }

        $inRule = $rule === null ? $inPeriod : $inPeriod->where('rule', $rule);

        $outcome = $this->param($request, 'outcome');

        if ($outcome !== null && !array_key_exists($outcome, self::OUTCOME_ORDER)) {
            $outcome = null;
        }

        $results = ($outcome === null ? $inRule : $inRule->where('outcome', $outcome))
            ->sort(fn (AutoAuditResult $a, AutoAuditResult $b) => $this->sortKey($a) <=> $this->sortKey($b))
            ->values();

        // Счётчики на карточках считаем в выбранном периоде, но без фильтра по проверке:
        // иначе у невыбранной карточки всегда были бы нули.
        $cards = collect(AutoAuditRunner::RULES)->map(fn (array $definition, int $number) => $definition + [
            'number' => (string) $number,
            'counts' => $inPeriod->where('rule', (string) $number)->countBy('outcome'),
        ]);

        return view('auto-audit.index', [
            'results'       => $results,
            'cards'         => $cards,
            'periods'       => $periods,
            'filters'       => ['period' => $period, 'rule' => $rule, 'outcome' => $outcome],
            'outcomeCounts' => $inRule->countBy('outcome'),
            'total'         => $inRule->count(),
            'issues'        => $issues,
            'checkedAt'     => $everything->max('created_at'),
        ]);
    }

    public function run(AutoAuditRunner $runner): RedirectResponse
    {
        $this->vendorOnly();

        // Разбор PDF и таблиц занимает секунды на документ, у фирмы их десятки.
        set_time_limit(300);

        $started = microtime(true);
        $counts  = $runner->run();

        return redirect()->route('auto-audit.index')->with('success', sprintf(
            'Проверка прошла за %.1f с. Совпало: %d, не совпало: %d, не тот документ: %d.',
            microtime(true) - $started,
            $counts[AutoAuditResult::MATCHED] ?? 0,
            $counts[AutoAuditResult::MISMATCH] ?? 0,
            $counts[AutoAuditResult::WRONG_DOCUMENT] ?? 0,
        ));
    }

    private function vendorOnly(): void
    {
        abort_unless(Impersonation::isActive(), 404);
    }

    /** Строковый параметр адреса. Массив вместо строки (?rule[]=1) считаем пустым. */
    private function param(Request $request, string $key): ?string
    {
        $value = $request->query($key);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function sortKey(AutoAuditResult $result): array
    {
        return [
            self::OUTCOME_ORDER[$result->outcome] ?? 9,
            mb_strtolower($result->client?->name ?? ''),
            -($result->period_from?->timestamp ?? 0),
            (int) $result->rule,
        ];
    }
}
