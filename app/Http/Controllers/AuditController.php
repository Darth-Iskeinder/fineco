<?php

namespace App\Http\Controllers;

use App\Models\Audit;
use App\Models\AuditChecklistItem;
use App\Models\AuditChecklistTemplate;
use App\Models\AuditTaskReview;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Employee;
use App\Services\AuditRemediation;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Модуль «Аудит» — независимая проверка качества закрытой работы по клиенту за период.
 * Два экрана: список аудитов и рабочий экран (вкладки «БП по секциям» и «Чек-лист»).
 */
class AuditController extends Controller
{
    /** Список аудитов + данные для формы создания. */
    public function index()
    {
        $audits = Audit::with(['client', 'auditor', 'taskReviews'])
            ->withCount([
                'checklistItems',
                'checklistItems as checklist_closed_count' => fn ($q) => $q->whereNotNull('status'),
            ])
            ->latest('id')
            ->get()
            ->map(function (Audit $audit) {
                $findings = $audit->taskReviews->where('verdict', AuditTaskReview::VERDICT_FINDING);

                return [
                    'id'            => $audit->id,
                    'client'        => $audit->client?->name ?? '—',
                    'period'        => $audit->period_label,
                    'auditor'       => $audit->auditor?->full_name ?? '—',
                    'status'        => $audit->status,
                    'status_label'  => $audit->status_label,
                    'critical'      => $findings->where('severity', 'critical')->count(),
                    'major'         => $findings->where('severity', 'major')->count(),
                    'minor'         => $findings->where('severity', 'minor')->count(),
                    'checklist'     => $audit->checklist_closed_count . ' / ' . $audit->checklist_items_count,
                    'updated'       => $audit->updated_at?->format('d.m.Y'),
                    'url'           => route('audit.show', $audit),
                ];
            });

        // Счётчик незакрытых замечаний — вход в реестр из шапки списка
        $findings = AuditTaskReview::query()->findings()->unresolved()->with('adhocTask')->get();

        return view('audit.index', [
            'audits'          => $audits,
            'clients'         => Client::orderBy('name')->get(['id', 'name']),
            'standard'        => AuditChecklistTemplate::active()->orderBy('id')->first(),
            'openFindings'    => $findings->count(),
            'overdueFindings' => $findings->filter(fn (AuditTaskReview $r) => $r->is_overdue)->count(),
        ]);
    }

    /** Создать аудит и сразу скопировать в него чек-лист выбранного стандарта. */
    public function store(Request $request)
    {
        $data = $request->validate([
            'client_id'    => ['required', 'exists:clients,id'],
            'period_start' => ['required', 'date'],
            'period_end'   => ['required', 'date', 'after_or_equal:period_start'],
        ], [], [
            'client_id'    => 'клиент',
            'period_start' => 'начало периода',
            'period_end'   => 'конец периода',
        ]);

        // Стандарт не выбирают руками: берём действующий и копируем его в аудит
        $template = AuditChecklistTemplate::active()->orderBy('id')->first();

        $audit = Audit::create($data + [
            'auditor_id'  => auth('employee')->id(),
            'template_id' => $template?->id,
            'status'      => Audit::STATUS_IN_PROGRESS,
        ]);

        if ($template) {
            $audit->copyChecklistFrom($template);
        }

        return redirect()
            ->route('audit.show', $audit)
            ->with('success', 'Аудит создан. Чек-лист скопирован из стандарта.');
    }

    /** Рабочий экран аудита. */
    public function show(Audit $audit)
    {
        $audit->load(['client', 'auditor', 'checklistItems', 'taskReviews']);

        $reviews = $audit->taskReviews->keyBy('buh_task_log_id');
        $logs    = $audit->closedTaskLogs()->get();

        $sections = $logs
            ->groupBy(fn (BuhTaskLog $log) => $log->estimateItem?->service?->service_group ?: 'Прочее')
            ->map(function ($group, $name) use ($reviews) {
                return [
                    'name'  => $name,
                    'tasks' => $group->map(function (BuhTaskLog $log) use ($reviews) {
                        $review = $reviews->get($log->id);

                        return [
                            'id'        => $log->id,
                            'name'      => $log->estimateItem?->name ?? 'БП',
                            'period'    => sprintf('%02d.%d', $log->month, $log->year),
                            'who'       => $log->employee?->full_name ?? '—',
                            'closed'    => $log->completed_at?->format('d.m.Y'),
                            'due'       => $log->due_date?->format('d.m.Y'),
                            'reviewed'  => $log->reviewed_at?->format('d.m.Y'),
                            'forced'    => (bool) $log->force_closed,
                            'rework'    => (int) $log->rework_count,
                            'comment'   => $log->employee_comment,
                            'docs'      => $log->documents->map(fn ($d) => [
                                'name' => $d->name,
                                'url'  => $d->url,
                            ])->values(),
                            'verdict'   => $review?->verdict,
                            'severity'  => $review?->severity,
                            'finding'   => $review?->comment,
                            'state'     => $review?->isFinding() ? $review->state : null,
                            'state_label' => $review?->isFinding() ? $review->state_label : null,
                        ];
                    })->values(),
                ];
            })
            ->sortBy('name')
            ->values();

        $findings = $audit->taskReviews->where('verdict', AuditTaskReview::VERDICT_FINDING);

        return view('audit.show', [
            'audit'     => $audit,
            'sections'  => $sections,
            'checklist' => $audit->checklistItems->map(fn (AuditChecklistItem $i) => [
                'id'       => $i->id,
                'section'  => $i->section,
                'account'  => $i->account,
                'point'    => $i->point,
                'how'      => $i->how,
                'status'   => $i->status ?? '',
                'doc_link' => $i->doc_link,
                'comment'  => $i->comment,
            ])->values(),
            'stats' => [
                'tasks_total'    => $logs->count(),
                'tasks_reviewed' => $reviews->whereIn('buh_task_log_id', $logs->pluck('id'))->count(),
                'critical'       => $findings->where('severity', 'critical')->count(),
                'major'          => $findings->where('severity', 'major')->count(),
                'minor'          => $findings->where('severity', 'minor')->count(),
            ],
            'sectionHints' => \App\Models\ServiceGroup::orderBy('name')->pluck('name'),
            'transfer'     => $this->transferList($audit),
            'employees'    => Employee::assignable()
                ->orderBy('full_name')
                ->get(['id', 'full_name']),
        ]);
    }

    /**
     * Замечания, которые ещё не переданы бухгалтеру, — для шага «Передать на исправление»
     * в окне завершения аудита. Исполнитель и срок предзаполнены, аудитор может поменять.
     */
    private function transferList(Audit $audit): \Illuminate\Support\Collection
    {
        $remediation = app(AuditRemediation::class);
        $due         = $remediation->defaultDueDate()->toDateString();

        return $audit->taskReviews()
            ->findings()
            ->whereNull('sent_at')
            ->with(['taskLog.employee', 'audit.client'])
            ->get()
            ->map(fn (AuditTaskReview $review) => [
                'id'          => $review->id,
                'task_name'   => $review->task_name,
                'section'     => $review->section,
                'severity'    => $review->severity,
                'severity_label' => Audit::$severities[$review->severity] ?? '—',
                'comment'     => $review->comment,
                'assignee_id' => $remediation->defaultAssignee($review)?->id,
                'due_date'    => $due,
            ])
            ->values();
    }

    /** Вердикт по закрытой задаче: «норма» или «замечание» с уровнем и описанием. */
    public function saveVerdict(Request $request, Audit $audit)
    {
        $this->assertEditable($audit);

        $data = $request->validate([
            'buh_task_log_id' => ['required', 'exists:buh_task_logs,id'],
            'verdict'         => ['required', Rule::in([AuditTaskReview::VERDICT_OK, AuditTaskReview::VERDICT_FINDING])],
            'severity'        => ['nullable', Rule::in(array_keys(Audit::$severities))],
            'comment'         => ['nullable', 'string', 'max:5000'],
        ]);

        $log = BuhTaskLog::findOrFail($data['buh_task_log_id']);
        abort_unless($log->client_id === $audit->client_id, 403, 'Задача другого клиента');

        if ($data['verdict'] === AuditTaskReview::VERDICT_FINDING && empty($data['severity'])) {
            return response()->json(['message' => 'Укажите уровень замечания'], 422);
        }

        $review = AuditTaskReview::updateOrCreate(
            ['audit_id' => $audit->id, 'buh_task_log_id' => $log->id],
            [
                'task_name'   => $log->estimateItem?->name ?? 'БП',
                'section'     => $log->estimateItem?->service?->service_group ?: 'Прочее',
                'verdict'     => $data['verdict'],
                'severity'    => $data['verdict'] === AuditTaskReview::VERDICT_FINDING ? $data['severity'] : null,
                'comment'     => $data['comment'] ?? null,
                'reviewed_by' => auth('employee')->id(),
                'reviewed_at' => now(),
            ],
        );

        $audit->touch();

        return response()->json(['review' => $review, 'stats' => $this->stats($audit->fresh(['taskReviews', 'checklistItems']))]);
    }

    /** Снять вердикт (вернуть задачу в «не проверено»). */
    public function deleteVerdict(Request $request, Audit $audit)
    {
        $this->assertEditable($audit);

        $logId = $request->validate(['buh_task_log_id' => ['required', 'integer']])['buh_task_log_id'];

        $audit->taskReviews()->where('buh_task_log_id', $logId)->delete();
        $audit->touch();

        return response()->json(['stats' => $this->stats($audit->fresh(['taskReviews', 'checklistItems']))]);
    }

    /** Новый пункт чек-листа в разделе. */
    public function storeChecklistItem(Request $request, Audit $audit)
    {
        $this->assertEditable($audit);

        $data = $request->validate([
            'section' => ['required', 'string', 'max:255'],
        ]);

        $item = $audit->checklistItems()->create([
            'section'    => $data['section'],
            'sort_order' => (int) $audit->checklistItems()->max('sort_order') + 1,
        ]);

        return response()->json(['item' => $item]);
    }

    /** Правка ячейки чек-листа (одна или несколько сразу). */
    public function updateChecklistItem(Request $request, Audit $audit, AuditChecklistItem $item)
    {
        $this->assertEditable($audit);
        abort_unless($item->audit_id === $audit->id, 404);

        // Пустая строка из ячейки/селекта означает «не заполнено», а не невалидное значение
        foreach (['account', 'point', 'how', 'status', 'doc_link', 'comment'] as $field) {
            if ($request->input($field) === '') {
                $request->merge([$field => null]);
            }
        }

        $data = $request->validate([
            'section'  => ['sometimes', 'string', 'max:255'],
            'account'  => ['sometimes', 'nullable', 'string', 'max:255'],
            'point'    => ['sometimes', 'nullable', 'string', 'max:2000'],
            'how'      => ['sometimes', 'nullable', 'string', 'max:5000'],
            'status'   => ['sometimes', 'nullable', Rule::in(array_keys(AuditChecklistItem::$statuses))],
            'doc_link' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'comment'  => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);

        $item->update($data);
        $audit->touch();

        return response()->json(['item' => $item, 'stats' => $this->stats($audit->fresh(['taskReviews', 'checklistItems']))]);
    }

    /** Удалить пункт чек-листа. */
    public function destroyChecklistItem(Audit $audit, AuditChecklistItem $item)
    {
        $this->assertEditable($audit);
        abort_unless($item->audit_id === $audit->id, 404);

        $item->delete();
        $audit->touch();

        return response()->json(['stats' => $this->stats($audit->fresh(['taskReviews', 'checklistItems']))]);
    }

    /** Переименовать раздел учёта целиком. */
    public function renameSection(Request $request, Audit $audit)
    {
        $this->assertEditable($audit);

        $data = $request->validate([
            'from' => ['required', 'string', 'max:255'],
            'to'   => ['required', 'string', 'max:255'],
        ]);

        $audit->checklistItems()->where('section', $data['from'])->update(['section' => $data['to']]);

        return response()->json(['ok' => true]);
    }

    /** Удалить раздел учёта со всеми пунктами. */
    public function destroySection(Request $request, Audit $audit)
    {
        $this->assertEditable($audit);

        $section = $request->validate(['section' => ['required', 'string', 'max:255']])['section'];

        $audit->checklistItems()->where('section', $section)->delete();
        $audit->touch();

        return response()->json(['stats' => $this->stats($audit->fresh(['taskReviews', 'checklistItems']))]);
    }

    /**
     * Завершить аудит: зафиксировать балл и резюме.
     * Пока в чек-листе остались непроверенные пункты — не завершаем и возвращаем
     * предупреждение; осознанно завершить всё равно можно флагом force.
     */
    public function complete(Request $request, Audit $audit)
    {
        $this->assertEditable($audit);

        $data = $request->validate([
            'summary'                => ['nullable', 'string', 'max:5000'],
            'force'                  => ['nullable', 'boolean'],
            'findings'               => ['nullable', 'array'],
            'findings.*.send'        => ['nullable', 'boolean'],
            'findings.*.assignee_id' => ['nullable', 'exists:employees,id'],
            'findings.*.due_date'    => ['nullable', 'date'],
        ]);

        $audit->load(['taskReviews', 'checklistItems']);

        $unchecked = $audit->checklistItems->whereNull('status')->count();

        if ($unchecked > 0 && empty($data['force'])) {
            return redirect()
                ->route('audit.show', $audit)
                ->with('error', "Аудит не завершён: в чек-листе осталось непроверенных пунктов — {$unchecked}. Проставьте статус каждому пункту (в том числе «Не применимо»).");
        }

        $sent = $this->sendFindings($audit, $data['findings'] ?? []);

        $audit->update([
            'status'       => Audit::STATUS_COMPLETED,
            'summary'      => $data['summary'] ?? null,
            'completed_at' => now(),
        ]);

        $message = "Аудит «{$audit->client?->name}, {$audit->period_label}» завершён.";
        if ($sent > 0) {
            $message .= " Передано на исправление замечаний: {$sent}.";
        }

        return redirect()->route('audit.index')->with('success', $message);
    }

    /**
     * Передаёт отмеченные замечания бухгалтерам: на каждое заводится задача в его
     * списке. Замечание, у которого не отмечена галочка, остаётся наблюдением
     * в отчёте — его можно передать позже из реестра замечаний.
     */
    private function sendFindings(Audit $audit, array $rows): int
    {
        $remediation = app(AuditRemediation::class);
        $sent        = 0;

        foreach ($rows as $reviewId => $row) {
            if (empty($row['send'])) {
                continue;
            }

            $review = $audit->taskReviews()
                ->findings()
                ->whereNull('sent_at')
                ->find($reviewId);

            if (!$review) {
                continue;
            }

            $assignee = !empty($row['assignee_id'])
                ? Employee::find($row['assignee_id'])
                : $remediation->defaultAssignee($review);

            if (!$assignee) {
                continue;
            }

            $due = !empty($row['due_date'])
                ? CarbonImmutable::parse($row['due_date'])
                : $remediation->defaultDueDate();

            $remediation->send($review, $assignee, $due);
            $sent++;
        }

        return $sent;
    }

    /** Вернуть завершённый аудит в работу. */
    public function reopen(Audit $audit)
    {
        $audit->update([
            'status'       => Audit::STATUS_IN_PROGRESS,
            'completed_at' => null,
        ]);

        return back()->with('success', 'Аудит снова в работе.');
    }

    public function destroy(Audit $audit)
    {
        $audit->delete();

        return redirect()->route('audit.index')->with('success', 'Аудит удалён.');
    }

    /**
     * Реестр замечаний — живёт отдельно от отчётов: аудит завершён и заморожен,
     * а замечания продолжают отрабатываться, пока аудитор их не закроет.
     */
    public function findings(Request $request)
    {
        $filter = $request->query('filter', 'active');

        $reviews = AuditTaskReview::query()
            ->findings()
            ->with(['audit.client', 'assignee', 'adhocTask', 'resolver'])
            ->orderByRaw('resolved_at is not null')      // незакрытые сверху
            ->orderByRaw('due_date is null')
            ->orderBy('due_date')
            ->get()
            ->filter(fn (AuditTaskReview $r) => match ($filter) {
                'active'    => $r->state !== AuditTaskReview::STATE_RESOLVED,
                'overdue'   => $r->is_overdue,
                'submitted' => $r->state === AuditTaskReview::STATE_SUBMITTED,
                'resolved'  => $r->state === AuditTaskReview::STATE_RESOLVED,
                'draft'     => $r->state === AuditTaskReview::STATE_DRAFT,
                default     => true,
            })
            ->values();

        $all = AuditTaskReview::query()->findings()->with('adhocTask')->get();

        return view('audit.findings', [
            'reviews'   => $reviews,
            'filter'    => $filter,
            'employees' => Employee::assignable()
                ->orderBy('full_name')
                ->get(['id', 'full_name']),
            'counts' => [
                'active'    => $all->filter(fn ($r) => $r->state !== AuditTaskReview::STATE_RESOLVED)->count(),
                'draft'     => $all->filter(fn ($r) => $r->state === AuditTaskReview::STATE_DRAFT)->count(),
                'submitted' => $all->filter(fn ($r) => $r->state === AuditTaskReview::STATE_SUBMITTED)->count(),
                'overdue'   => $all->filter(fn ($r) => $r->is_overdue)->count(),
                'resolved'  => $all->filter(fn ($r) => $r->state === AuditTaskReview::STATE_RESOLVED)->count(),
                'all'       => $all->count(),
            ],
        ]);
    }

    /** Передать замечание на исправление из реестра (если не передали при завершении). */
    public function sendFinding(Request $request, AuditTaskReview $review, AuditRemediation $remediation)
    {
        abort_unless($review->isFinding(), 404);
        abort_if($review->sent_at !== null, 422, 'Замечание уже передано');

        $data = $request->validate([
            'assignee_id' => ['required', 'exists:employees,id'],
            'due_date'    => ['required', 'date'],
        ], [], ['assignee_id' => 'исполнитель', 'due_date' => 'срок']);

        $remediation->send($review, Employee::findOrFail($data['assignee_id']), CarbonImmutable::parse($data['due_date']));

        return back()->with('success', 'Замечание передано на исправление.');
    }

    /** Аудитор подтвердил, что замечание устранено. */
    public function resolveFinding(AuditTaskReview $review, AuditRemediation $remediation)
    {
        abort_unless($review->isFinding(), 404);

        $remediation->resolve($review, auth('employee')->user());

        return back()->with('success', 'Замечание закрыто.');
    }

    /** Аудитор вернул исправление: задача снова открывается у бухгалтера. */
    public function returnFinding(Request $request, AuditTaskReview $review, AuditRemediation $remediation)
    {
        abort_unless($review->isFinding(), 404);

        $comment = $request->validate([
            'comment' => ['required', 'string', 'max:2000'],
        ], [], ['comment' => 'причина возврата'])['comment'];

        $remediation->returnForRework($review, $comment);

        return back()->with('success', 'Замечание возвращено на доработку.');
    }

    /** Сменить исполнителя (например, если бухгалтер уволился). */
    public function reassignFinding(Request $request, AuditTaskReview $review, AuditRemediation $remediation)
    {
        abort_unless($review->isFinding(), 404);

        $data = $request->validate([
            'assignee_id' => ['required', 'exists:employees,id'],
        ], [], ['assignee_id' => 'исполнитель']);

        $remediation->reassign($review, Employee::findOrFail($data['assignee_id']));

        return back()->with('success', 'Исполнитель заменён.');
    }

    /** Завершённый аудит редактировать нельзя — сначала вернуть в работу. */
    private function assertEditable(Audit $audit): void
    {
        abort_if($audit->isCompleted(), 422, 'Аудит завершён. Верните его в работу, чтобы вносить изменения.');
    }

    private function stats(Audit $audit): array
    {
        $findings = $audit->taskReviews->where('verdict', AuditTaskReview::VERDICT_FINDING);
        $logIds   = $audit->closedTaskLogs()->pluck('buh_task_logs.id');

        return [
            'tasks_total'      => $logIds->count(),
            'tasks_reviewed'   => $audit->taskReviews->whereIn('buh_task_log_id', $logIds)->count(),
            'critical'         => $findings->where('severity', 'critical')->count(),
            'major'            => $findings->where('severity', 'major')->count(),
            'minor'            => $findings->where('severity', 'minor')->count(),
            'checklist_total'  => $audit->checklistItems->count(),
            'checklist_closed' => $audit->checklistItems->whereNotNull('status')->count(),
            'checklist_errors' => $audit->checklistItems->where('status', AuditChecklistItem::STATUS_ERROR)->count(),
        ];
    }
}
