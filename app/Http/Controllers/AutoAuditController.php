<?php

namespace App\Http\Controllers;

use App\Jobs\RunAutoAuditJob;
use App\Models\AutoAuditFinding;
use App\Models\AutoAuditFindingMessage;
use App\Models\AutoAuditResult;
use App\Models\Tenant;
use App\Services\AutoAudit\AutoAuditQuestions;
use App\Services\AutoAudit\AutoAuditRunner;
use App\Support\Impersonation;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\View\View;

/**
 * Страница автоаудита: одна таблица за выбранный отчётный период.
 *
 * Видят её владелец системы, зашедший в фирму, и руководитель фирмы, если фирме её
 * открыли (флаг autoaudit:access). Открываем по одной фирме: ложное красное подорвало бы
 * доверие к проверке с первого дня. Всем остальным страница отвечает 404, как будто её нет.
 *
 * Фильтров три, все в адресе страницы и работают вместе:
 *   - отчётный период, то есть период, за который составлены документы, а не месяц
 *     задачи. По умолчанию самый свежий;
 *   - проверка. По умолчанию все;
 *   - статус. По умолчанию все.
 *
 * Запустить проверку со страницы нельзя: прогон идёт только командой `autoaudit:run` в
 * терминале. Пока он идёт, страница показывает это и сама обновляется.
 *
 * Находки (см. AutoAuditFinding): у проблемных строк колонка «Ответ» с перепиской и
 * кнопками «Принять» и «Не принято», и фильтр «Без ответа». Руководителю это видно, только
 * когда фирме включили флаг autoaudit:access --findings-on, вендору всегда.
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
        $this->allowedOnly();

        // Список периодов из базы, а строки только выбранного: история растёт с каждым
        // месяцем, и тянуть её в память целиком ради одного периода незачем.
        // Везде только действующие строки: история лежит в той же таблице.
        $periods = AutoAuditResult::current()
            ->select(['period_from', 'period_to'])
            ->distinct()
            ->get()
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

        $inPeriodAndRule = $this->inPeriod($period)
            // «Нет документа» бывает общим для нескольких проверок: строка видна под каждой.
            ->filter(fn (AutoAuditResult $r) => $rule === null || in_array((int) $rule, $r->ruleNumbers(), true));

        $showFindings = $this->findingsVisible();
        $findings     = $showFindings ? $this->openFindings($inPeriodAndRule) : [];

        $awaiting = fn (AutoAuditResult $r) => isset($findings[$r->key()]) && $findings[$r->key()]->awaitsAnswer($r);

        // Фильтр «Без ответа» работает вместе с остальными, но только там, где находки видны.
        $unanswered = $showFindings && $request->query('answer') === 'none';

        $results = $inPeriodAndRule
            ->filter(fn (AutoAuditResult $r) => $status === null || $r->outcome === $status)
            ->filter(fn (AutoAuditResult $r) => !$unanswered || $awaiting($r))
            ->sort(fn (AutoAuditResult $a, AutoAuditResult $b) => $this->sortKey($a) <=> $this->sortKey($b))
            ->values();

        $state = $this->state();

        return view('auto-audit.index', [
            'results'       => $results,
            'periods'       => $periods,
            'period'        => $period,
            'rule'          => $rule,
            'status'        => $status,
            // Счётчики без фильтра статуса: иначе при выборе одного статуса остальные обнулятся.
            'counts'        => $inPeriodAndRule->countBy('outcome'),
            // Прогон сдвигает время обновления у каждой действующей строки, даже если итог
            // не поменялся. Время создания тут не годится: строка живёт много прогонов.
            'checkedAt'     => AutoAuditResult::current()->latest('updated_at')->value('updated_at'),
            'state'         => $state,
            'running'       => $this->isRunning($state),
            'vendor'        => Impersonation::isActive(),
            'openToManager' => (bool) Tenant::find(TenantContext::id())?->autoAuditEnabled(),
            'showFindings'  => $showFindings,
            'findings'      => $findings,
            'unanswered'    => $unanswered,
            // Без фильтра статуса, как плитки: число не должно зависеть от выбранной плитки.
            'awaitingCount' => $inPeriodAndRule->filter($awaiting)->count(),
        ]);
    }

    /** Руководитель принял объяснение: строка становится «Объяснено», пока итог тот же. */
    public function accept(Request $request, AutoAuditFinding $finding): RedirectResponse
    {
        return $this->decide($request, $finding, AutoAuditFindingMessage::ACCEPTED, null);
    }

    /** Руководитель не принял ответ: комментарий обязателен, находка снова ждёт бухгалтера. */
    public function reject(Request $request, AutoAuditFinding $finding): RedirectResponse
    {
        $data = $request->validate(
            ['body' => ['required', 'string', 'max:2000']],
            ['body.required' => 'Напишите, что не так с ответом'],
        );

        return $this->decide($request, $finding, AutoAuditFindingMessage::REJECTED, trim($data['body']));
    }

    /**
     * Записать решение руководителя по находке.
     *
     * Страница присылает id строки, которую человек видел. Если с тех пор прошёл прогон и
     * итог сменился, решение относилось бы к другому, и мы его не пишем, а просим обновить.
     */
    private function decide(Request $request, AutoAuditFinding $finding, string $kind, ?string $body): RedirectResponse
    {
        $this->decidersOnly();

        $result = AutoAuditResult::current()->find((int) $request->input('result_id'));

        if (
            $finding->closed_at !== null
            || $result === null
            || $result->key() !== $finding->key
            || !in_array($result->outcome, AutoAuditResult::FINDING_OUTCOMES, true)
        ) {
            return back()->with('error', 'После вашего входа на страницу прошла проверка и строка изменилась. Обновите страницу и посмотрите ещё раз');
        }

        $finding->messages()->create([
            'result_id'   => $result->id,
            'employee_id' => auth('employee')->id(),
            'by_vendor'   => Impersonation::isActive(),
            'kind'        => $kind,
            'body'        => $body,
        ]);

        return back()->with('success', $kind === AutoAuditFindingMessage::ACCEPTED ? 'Принято' : 'Отправлено бухгалтеру');
    }

    /**
     * Открытые находки строк периода, по ключу строки. Переписка подгружается сразу: по ней
     * считается состояние каждой строки.
     *
     * @return array<string, AutoAuditFinding>
     */
    private function openFindings(Collection $results): array
    {
        $keys = $results->map(fn (AutoAuditResult $r) => $r->key())->unique()->values()->all();

        if (!$keys) {
            return [];
        }

        return AutoAuditFinding::open()
            ->whereIn('key', $keys)
            ->with('messages.employee:id,full_name')
            ->orderBy('id')
            ->get()
            // Если открытых с одним ключом вдруг две, прогон закроет младшую. До тех пор
            // показываем старшую: у неё переписка.
            ->reverse()
            ->keyBy('key')
            ->all();
    }

    /** Видны ли находки: вендору всегда, руководителю по флагу фирмы. */
    private function findingsVisible(): bool
    {
        return AutoAuditQuestions::enabled();
    }

    /** Решать по находкам могут только руководитель (при включённом флаге) и вендор. */
    private function decidersOnly(): void
    {
        $this->allowedOnly();

        abort_unless($this->findingsVisible(), 404);
    }

    /**
     * Строки одного периода. Ключ «2026-07-01..2026-07-31»; у строки с неразобранным
     * периодом обе даты пустые, и ключ тогда «..».
     */
    private function inPeriod(?string $period): Collection
    {
        if ($period === null) {
            return new Collection();
        }

        [$from, $to] = explode('..', $period, 2);

        return AutoAuditResult::current()
            ->with('client:id,name')
            ->when($from === '', fn ($q) => $q->whereNull('period_from'), fn ($q) => $q->whereDate('period_from', $from))
            ->when($to === '', fn ($q) => $q->whereNull('period_to'), fn ($q) => $q->whereDate('period_to', $to))
            ->get();
    }

    private function allowedOnly(): void
    {
        abort_unless(
            Impersonation::isActive() || auth('employee')->user()?->canSeeAutoAudit(),
            404,
        );
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
