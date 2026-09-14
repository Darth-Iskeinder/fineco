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
 * Фильтр один: отчётный период, то есть период, за который составлены документы, а не
 * месяц задачи. По умолчанию самый свежий.
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

        $results = $all
            ->filter(fn (AutoAuditResult $r) => $r->periodKey() === $period)
            ->sort(fn (AutoAuditResult $a, AutoAuditResult $b) => $this->sortKey($a) <=> $this->sortKey($b))
            ->values();

        return view('auto-audit.index', [
            'results'   => $results,
            'periods'   => $periods,
            'period'    => $period,
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

    /** Проверки по порядку номеров, «не тот документ» после них, внутри по клиенту. */
    private function sortKey(AutoAuditResult $result): array
    {
        return [
            $result->rule === null ? PHP_INT_MAX : (int) $result->rule,
            $result->expectedDocument(),
            mb_strtolower($result->client?->name ?? ''),
        ];
    }
}
