<?php

namespace App\Http\Controllers;

use App\Jobs\RunAutoAuditJob;
use App\Models\AutoAuditResult;
use App\Services\AutoAudit\AutoAuditRunner;
use App\Support\Impersonation;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Страница автоаудита: одна таблица за выбранный отчётный период.
 *
 * Пока её видит только владелец системы, зашедший в фирму. Сырые результаты фирме
 * показывать рано: неизвестно, как выглядит настоящее расхождение, и ложное красное
 * подорвало бы доверие к проверке с первого дня. Всем остальным страница отвечает 404,
 * как будто её нет.
 *
 * Фильтров три, все в адресе страницы и работают вместе:
 *   - отчётный период, то есть период, за который составлены документы, а не месяц
 *     задачи. По умолчанию самый свежий;
 *   - проверка. По умолчанию все;
 *   - статус. По умолчанию все.
 *
 * Запустить проверку со страницы нельзя: прогон идёт только командой `autoaudit:run` в
 * терминале. Пока он идёт, страница показывает это и сама обновляется.
 */
class AutoAuditController extends Controller
{
    /**
     * «Идёт» дольше этого не бывает. Значит, процесс умер, не записав итог (его, например,
     * убили в терминале), и плашка «идёт проверка» висеть дальше не должна.
     */
    private const STALE_MINUTES = 15;

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

        $status = $request->query('status');

        if (!is_string($status) || !isset(AutoAuditResult::LABELS[$status])) {
            $status = null;
        }

        $inPeriodAndRule = $all
            ->filter(fn (AutoAuditResult $r) => $r->periodKey() === $period)
            // «Нет документа» бывает общим для нескольких проверок: строка видна под каждой.
            ->filter(fn (AutoAuditResult $r) => $rule === null || in_array((int) $rule, $r->ruleNumbers(), true));

        $results = $inPeriodAndRule
            ->filter(fn (AutoAuditResult $r) => $status === null || $r->outcome === $status)
            ->sort(fn (AutoAuditResult $a, AutoAuditResult $b) => $this->sortKey($a) <=> $this->sortKey($b))
            ->values();

        $state = $this->state();

        return view('auto-audit.index', [
            'results'   => $results,
            'periods'   => $periods,
            'period'    => $period,
            'rule'      => $rule,
            'status'    => $status,
            // Счётчики без фильтра статуса: иначе при выборе одного статуса остальные обнулятся.
            'counts'    => $inPeriodAndRule->countBy('outcome'),
            'checkedAt' => $all->max('created_at'),
            'state'     => $state,
            'running'   => $this->isRunning($state),
        ]);
    }

    private function vendorOnly(): void
    {
        abort_unless(Impersonation::isActive(), 404);
    }

    private function state(): ?array
    {
        return Cache::get(RunAutoAuditJob::stateKey(TenantContext::id()));
    }

    private function isRunning(?array $state): bool
    {
        return ($state['status'] ?? null) === RunAutoAuditJob::RUNNING
            && CarbonImmutable::parse($state['started_at'])->greaterThan(now()->subMinutes(self::STALE_MINUTES));
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
