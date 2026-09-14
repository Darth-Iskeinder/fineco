<?php

namespace App\Http\Controllers;

use App\Models\AutoAuditResult;
use App\Services\AutoAudit\AutoAuditRunner;
use App\Support\Impersonation;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

/**
 * Страница автоаудита: что сошлось, что нет.
 *
 * Пока её видит только владелец системы, зашедший в фирму. Сырые результаты фирме
 * показывать рано: неизвестно, как выглядит настоящее расхождение, и ложное красное
 * подорвало бы доверие к проверке с первого дня. Всем остальным страница отвечает 404,
 * как будто её нет.
 */
class AutoAuditController extends Controller
{
    /** Сначала то, на что надо смотреть. */
    private const OUTCOME_ORDER = [
        AutoAuditResult::MISMATCH     => 0,
        AutoAuditResult::NO_DOCUMENTS => 1,
        AutoAuditResult::MATCHED      => 2,
    ];

    public function index(): View
    {
        $this->vendorOnly();

        $results = AutoAuditResult::with('client:id,name')->get()
            ->sort(fn (AutoAuditResult $a, AutoAuditResult $b) => $this->sortKey($a) <=> $this->sortKey($b))
            ->values();

        return view('auto-audit.index', [
            'results'   => $results,
            'counts'    => $results->countBy('outcome'),
            'checkedAt' => $results->max('created_at'),
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
            'Проверка прошла за %.1f с. Совпало: %d, не совпало: %d, нет документов: %d.',
            microtime(true) - $started,
            $counts[AutoAuditResult::MATCHED] ?? 0,
            $counts[AutoAuditResult::MISMATCH] ?? 0,
            $counts[AutoAuditResult::NO_DOCUMENTS] ?? 0,
        ));
    }

    private function vendorOnly(): void
    {
        abort_unless(Impersonation::isActive(), 404);
    }

    private function sortKey(AutoAuditResult $result): array
    {
        return [
            self::OUTCOME_ORDER[$result->outcome] ?? 9,
            mb_strtolower($result->client?->name ?? ''),
            $result->period_from?->timestamp ?? 0,
            $result->rule,
        ];
    }
}
