<?php

namespace App\Services\AutoAudit;

use App\Models\AutoAuditFinding;
use App\Models\AutoAuditFindingMessage;
use App\Models\AutoAuditResult;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Tenant;
use App\Support\Impersonation;
use App\Support\TenantContext;
use Illuminate\Support\Collection;

/**
 * Вопросы автоаудита бухгалтеру: открытые находки глазами исполнителя.
 *
 * Кому задаём вопрос (executors):
 *   - «Нет документа»: тому, кто закрыл задачу без файла;
 *   - остальное: исполнителям задач, из которых взяты файлы строки. При «Не совпало» это
 *     оба, и кто делал ведомость, и кто делал отчёт;
 *   - исполнитель уволен или задачи нет вовсе (в квартале нет ведомости за месяц):
 *     ответственному за клиента.
 *
 * Бухгалтер видит только вопросы, которые ждут его ответа. Ответил, и вопрос уходит из
 * списка, пока руководитель не нажмёт «Не принято» или прогон не покажет, что после
 * замены файла всё ещё не сходится.
 *
 * Руководителю и бухгалтерам всё это видно только при флаге фирмы (autoaudit:access
 * --findings-on). Вендору, зашедшему в фирму, всегда.
 */
class AutoAuditQuestions
{
    /** Открыты ли вопросы в текущей фирме. */
    public static function enabled(): bool
    {
        return Impersonation::isActive()
            || (bool) Tenant::find(TenantContext::id())?->autoAuditFindingsEnabled();
    }

    /**
     * Все открытые вопросы фирмы вместе с тем, что нужно для решения «чей»: действующая
     * строка результата, задачи строки и клиент. Один проход на страницу.
     *
     * @return Collection<int, array{finding: AutoAuditFinding, result: AutoAuditResult, logs: Collection, client: ?Client}>
     */
    public function open(): Collection
    {
        $findings = AutoAuditFinding::open()->with('messages.employee:id,full_name')->orderBy('id')->get();

        if ($findings->isEmpty()) {
            return collect();
        }

        $results = AutoAuditResult::current()
            ->whereIn('outcome', AutoAuditResult::FINDING_OUTCOMES)
            ->get()
            ->keyBy(fn (AutoAuditResult $r) => $r->key());

        $logIds = $results->flatMap(fn (AutoAuditResult $r) => self::logIds($r))->unique()->values();

        $logs = BuhTaskLog::whereIn('id', $logIds)
            ->with(['employee' => fn ($q) => $q->withTrashed()->select('id', 'full_name', 'status', 'deleted_at')])
            ->get()
            ->keyBy('id');

        $clients = Client::withTrashed()
            ->whereIn('id', $findings->pluck('client_id')->unique())
            ->get(['id', 'name', 'responsible_employee_id'])
            ->keyBy('id');

        return $findings
            ->filter(fn (AutoAuditFinding $f) => $results->has($f->key))
            ->map(fn (AutoAuditFinding $f) => [
                'finding' => $f,
                'result'  => $results[$f->key],
                'logs'    => collect(self::logIds($results[$f->key]))->map(fn (int $id) => $logs->get($id))->filter()->values(),
                'client'  => $clients->get($f->client_id),
            ])
            ->values();
    }

    /**
     * Вопросы, которые ждут ответа этого сотрудника.
     *
     * @return Collection<int, array> то же, что open(), плюс fixable: задачи, файл которых
     *                                сотрудник может заменить или приложить сам
     */
    public function forEmployee(Employee $employee): Collection
    {
        return $this->open()
            ->filter(fn (array $q) => in_array($employee->id, $this->executors($q), true))
            ->filter(fn (array $q) => $q['finding']->awaitsAnswer($q['result']))
            ->map(fn (array $q) => $q + ['fixable' => $this->fixable($q, $employee)])
            ->values();
    }

    /**
     * Кому задаём вопрос: id сотрудников.
     *
     * @param array{result: AutoAuditResult, logs: Collection, client: ?Client} $question
     */
    public function executors(array $question): array
    {
        $ids = $question['logs']
            ->map(fn (BuhTaskLog $log) => $log->employee)
            ->filter(fn (?Employee $e) => $e && !$e->trashed() && $e->status === Employee::STATUS_ACTIVE)
            ->map(fn (Employee $e) => (int) $e->id)
            ->unique()
            ->values()
            ->all();

        if ($ids) {
            return $ids;
        }

        // Спросить не с кого: исполнитель ушёл, или задачи нет. Спрашиваем с ответственного.
        $responsible = $question['client']?->responsible_employee_id;

        return $responsible ? [(int) $responsible] : [];
    }

    /**
     * Задачи строки, файл которых сотрудник может заменить или приложить сам: только свои.
     * Файлы чужой задачи, даже уволенного, не трогаем: вопрос к ответственному, но
     * исправление за другого человека это уже решение руководителя.
     */
    public function fixable(array $question, Employee $employee): Collection
    {
        return $question['logs']->filter(fn (BuhTaskLog $log) => (int) $log->employee_id === (int) $employee->id)->values();
    }

    /**
     * Задачи, из-за которых вопрос. У «нет документа» это задачи без файла; остальное
     * берём по файлам строки.
     *
     * @return int[]
     */
    public static function logIds(AutoAuditResult $result): array
    {
        $sources = collect($result->sources ?? []);

        if ($result->outcome === AutoAuditResult::MISSING_DOCUMENT) {
            $sources = $sources->where('status', AutoAuditRunner::SOURCE_MISSING);
        }

        return $sources->pluck('log_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /**
     * Вопрос в том виде, в каком его рисует БухЗадачник.
     *
     * fix: задачи, куда сотрудник может приложить файл сам. action replace, когда в задаче
     * есть файлы строки (заменить их), attach, когда задача закрыта без файла.
     */
    public function toFront(array $question): array
    {
        /** @var AutoAuditFinding $finding */
        /** @var AutoAuditResult $result */
        $finding = $question['finding'];
        $result  = $question['result'];
        $sources = collect($result->sources ?? []);

        $rejection = self::rejection($finding);

        return [
            'uid'           => 'audit_' . $finding->id,
            'id'            => $finding->id,
            'result_id'     => $result->id,
            'client_id'     => $finding->client_id,
            'client_name'   => $question['client']?->name ?? 'клиент удалён',
            'outcome'       => $result->outcome,
            'outcome_label' => AutoAuditResult::LABELS[$result->outcome] ?? $result->outcome,
            'period_label'  => $result->periodLabel(),
            'period_from'   => $result->period_from?->toDateString(),
            'subject'       => $this->subject($result),
            'rules'         => array_map(
                fn (int $n) => '№' . $n . ' ' . (AutoAuditRunner::RULES[$n]['name'] ?? ''),
                $result->ruleNumbers(),
            ),
            'left_value'    => $result->left_value,
            'right_value'   => $result->right_value,
            'difference'    => $result->difference,
            'reason'        => $result->reason,
            'days'          => $finding->daysOpen(),
            'fix_failed'    => $finding->fixDidNotHelp($result),
            'outdated'      => $finding->explanationOutdated($result),
            'rejection'     => $rejection ? [
                'author' => $rejection->authorName(),
                'date'   => $rejection->created_at->format('d.m.Y'),
                'body'   => $rejection->body,
            ] : null,
            'sources'       => $sources->map(fn (array $s) => [
                'label'    => $s['label'] ?? '',
                'employee' => $s['employee'] ?? null,
                'month'    => $s['task_month'] ?? null,
                'name'     => $s['name'] ?? null,
                'url'      => empty($s['document_id']) ? null : route('documents.task', $s['document_id']),
                'value'    => $s['value'] ?? null,
                'reason'   => $s['reason'] ?? null,
            ])->values()->all(),
            'fix'           => ($question['fixable'] ?? collect())->map(function (BuhTaskLog $log) use ($sources) {
                $source = $sources->firstWhere('log_id', $log->id);
                $files  = $sources->where('log_id', $log->id)->pluck('name')->filter()->values()->all();

                return [
                    'log_id' => $log->id,
                    'label'  => ($source['label'] ?? 'Задача') . ' за ' . sprintf('%02d.%d', $log->month, $log->year),
                    'action' => $files ? 'replace' : 'attach',
                    'files'  => $files,
                ];
            })->values()->all(),
        ];
    }

    /**
     * О чём вопрос, коротко, для строки списка. У сверки это проверка, у беды с документом
     * сам документ: «Нет документа: Отчёт по ЕН» говорит больше, чем «2 проверки».
     */
    private function subject(AutoAuditResult $result): string
    {
        $sources = collect($result->sources ?? []);

        return match (true) {
            $result->outcome === AutoAuditResult::MISMATCH => $result->ruleName(),
            // Задачи без файла нет только в квартале без ведомости за месяц.
            $result->outcome === AutoAuditResult::MISSING_DOCUMENT => $sources->where('status', AutoAuditRunner::SOURCE_MISSING)->pluck('label')->unique()->join(', ') ?: 'ОСВ',
            default => $sources->pluck('label')->unique()->join(', '),
        };
    }

    /** Последний комментарий руководителя «Не принято», если вопрос вернулся с ним. */
    public static function rejection(AutoAuditFinding $finding): ?AutoAuditFindingMessage
    {
        $last = $finding->messages->last();

        return $last?->kind === AutoAuditFindingMessage::REJECTED ? $last : null;
    }
}
