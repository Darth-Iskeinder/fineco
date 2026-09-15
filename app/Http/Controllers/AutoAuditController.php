<?php

namespace App\Http\Controllers;

use App\Models\AutoAuditResult;
use App\Services\AutoAudit\AutoAuditRunner;
use App\Support\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Страница автоаудита: одна таблица за выбранный отчётный период.
 *
 * Пока её видит только владелец системы, зашедший в фирму. Сырые результаты фирме
 * показывать рано: неизвестно, как выглядит настоящее расхождение, и ложное красное
 * подорвало бы доверие к проверке с первого дня. Всем остальным страница отвечает 404,
 * как будто её нет.
 *
 * Фильтров два, оба в адресе страницы:
 *   - отчётный период, то есть период, за который составлены документы, а не месяц
 *     задачи. По умолчанию самый свежий;
 *   - проверка. По умолчанию все.
 */
class AutoAuditController extends Controller
{
    public function index(Request $request): View
    {
        $this->vendorOnly();

        $all = AutoAuditResult::with('client:id,name')->get();

        // Периоды от свежих к старым: первый в списке и показываем по умолчанию.
        $periods = $all
            ->sortByDesc(fn (AutoAuditResult $r) => [$r->period_to?->timestamp, $r->period_from?->timestamp])
            ->mapWithKeys(fn (AutoAuditResult $r) => [$r->periodKey() => $r->periodLabel()])
            ->all();

        $period = $request->query('period');

        if (!is_string($period) || !array_key_exists($period, $periods)) {
            $period = array_key_first($periods);
        }

        $rule = $request->query('rule');

        if (!is_string($rule) || !ctype_digit($rule) || !isset(AutoAuditRunner::RULES[(int) $rule])) {
            $rule = null;
        }

        $results = $all
            ->filter(fn (AutoAuditResult $r) => $r->periodKey() === $period)
            // «Нет документа» бывает общим для нескольких проверок: строка видна под каждой.
            ->filter(fn (AutoAuditResult $r) => $rule === null || in_array((int) $rule, $r->ruleNumbers(), true))
            ->sort(fn (AutoAuditResult $a, AutoAuditResult $b) => $this->sortKey($a) <=> $this->sortKey($b))
            ->values();

        return view('auto-audit.index', [
            'results'   => $results,
            'periods'   => $periods,
            'period'    => $period,
            'rule'      => $rule,
            'counts'    => $results->countBy('outcome'),
            'checkedAt' => $all->max('created_at'),
        ]);
    }

    public function run(AutoAuditRunner $runner): RedirectResponse
    {
        $this->vendorOnly();

        // Разбор PDF и таблиц занимает секунды на документ, у фирмы их десятки.
        set_time_limit(300);

        $started = microtime(true);
        $counts  = $runner->run();

        $summary = collect(AutoAuditResult::LABELS)
            ->map(fn (string $label, string $outcome) => $label . ': ' . ($counts[$outcome] ?? 0))
            ->implode('; ');

        return redirect()->route('auto-audit.index')->with('success', sprintf(
            'Проверка прошла за %.1f с. %s.',
            microtime(true) - $started,
            $summary,
        ));
    }

    private function vendorOnly(): void
    {
        abort_unless(Impersonation::isActive(), 404);
    }

    /** По порядку номеров проверок, внутри по клиенту. */
    private function sortKey(AutoAuditResult $result): array
    {
        return [
            (int) $result->rule,
            mb_strtolower($result->client?->name ?? ''),
            $result->outcome,
        ];
    }
}
