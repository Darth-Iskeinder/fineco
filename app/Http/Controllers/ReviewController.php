<?php

namespace App\Http\Controllers;

use App\Models\BuhAdhocTask;
use App\Models\BuhTaskLog;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    /** Срок проверки задачи проверяющим (календарных дней с момента отправки) */
    private const REVIEW_SLA_DAYS = 3;

    /** Глубина истории во вкладке «Проверенные» (дней назад) */
    private const HISTORY_DAYS = 30;

    public function index()
    {
        $with = ['employee:id,full_name', 'client:id,name', 'reviewer:id,full_name', 'estimateItem.service', 'estimateItem.children.service'];

        // На проверке — текущие
        $pending = BuhTaskLog::where('status', 'review')
            ->with($with)
            ->orderByRaw('COALESCE(review_started_at, updated_at) asc') // самые горящие/просроченные наверх
            ->get();

        // Проверенные (одобренные) — история за последние 30 дней
        $reviewed = BuhTaskLog::where('status', 'completed')
            ->whereNotNull('reviewed_at')
            ->where('reviewed_at', '>=', now()->subDays(self::HISTORY_DAYS))
            ->with($with)
            ->orderByDesc('reviewed_at')
            ->get();

        // Логи подпунктов для обоих списков — одной выборкой
        $childLogs = $this->childLogsFor($pending->concat($reviewed));

        // Внеплановые задачи «на проверку» (вариант a — общий с плановыми Review-модуль)
        $adhocWith = ['employee:id,full_name', 'client:id,name', 'reviewer:id,full_name'];
        $pendingAdhoc = BuhAdhocTask::where('status', 'review')->with($adhocWith)
            ->orderByRaw('COALESCE(review_started_at, updated_at) asc')->get();
        $reviewedAdhoc = BuhAdhocTask::where('status', 'completed')
            ->whereNotNull('reviewed_at')
            ->where('reviewed_at', '>=', now()->subDays(self::HISTORY_DAYS))
            ->with($adhocWith)->orderByDesc('reviewed_at')->get();

        // Объединяем плановые и внеплановые, сортируя единообразно по сроку/проверке.
        $logs = $pending->map(fn (BuhTaskLog $log) => $this->formatForReview($log, $childLogs))
            ->concat($pendingAdhoc->map(fn (BuhAdhocTask $t) => $this->formatAdhocForReview($t)))
            ->sortBy('review_started_date')->values();
        $reviewed = $reviewed->map(fn (BuhTaskLog $log) => $this->formatForReview($log, $childLogs))
            ->concat($reviewedAdhoc->map(fn (BuhAdhocTask $t) => $this->formatAdhocForReview($t)))
            ->sortByDesc('reviewed_at')->values();

        return view('review.index', [
            'logs'        => $logs,
            'reviewed'    => $reviewed,
            'slaDays'     => self::REVIEW_SLA_DAYS,
            'historyDays' => self::HISTORY_DAYS,
        ]);
    }

    public function approveAdhoc(BuhAdhocTask $task)
    {
        abort_if($task->status !== 'review', 422, 'Задача не на проверке');

        $task->update([
            'status'       => 'completed',
            'completed_at' => now(),
            'reviewed_at'  => now(),
            'reviewed_by'  => auth('employee')->id(),
        ]);

        $task->load(['employee:id,full_name', 'client:id,name', 'reviewer:id,full_name']);

        return response()->json(['success' => true, 'item' => $this->formatAdhocForReview($task)]);
    }

    public function rejectAdhoc(Request $request, BuhAdhocTask $task)
    {
        abort_if($task->status !== 'review', 422, 'Задача не на проверке');

        $validated = $request->validate([
            'comment' => ['required', 'string', 'max:2000'],
        ], [
            'comment.required' => 'Укажите, что нужно исправить',
        ]);

        $task->update([
            'status'         => 'rework',
            'review_comment' => $validated['comment'],
            'reviewed_at'    => now(),
            'reviewed_by'    => auth('employee')->id(),
        ]);

        return response()->json(['success' => true]);
    }

    public function approve(BuhTaskLog $log)
    {
        abort_if($log->status !== 'review', 422, 'Задача не на проверке');

        $log->update([
            'status'       => 'completed',
            'completed_at' => now(),
            'reviewed_at'  => now(),
            'reviewed_by'  => auth('employee')->id(),
        ]);

        // Отдаём отформатированную задачу, чтобы фронт сразу добавил её во вкладку «Проверенные»
        $log->load(['employee:id,full_name', 'client:id,name', 'reviewer:id,full_name', 'estimateItem.service', 'estimateItem.children.service']);

        return response()->json([
            'success' => true,
            'item'    => $this->formatForReview($log, $this->childLogsFor(collect([$log]))),
        ]);
    }

    public function reject(Request $request, BuhTaskLog $log)
    {
        abort_if($log->status !== 'review', 422, 'Задача не на проверке');

        $validated = $request->validate([
            'comment' => ['required', 'string', 'max:2000'],
        ], [
            'comment.required' => 'Укажите, что нужно исправить',
        ]);

        $log->update([
            'status'         => 'rework',
            'review_comment' => $validated['comment'],
            'reviewed_at'    => now(),
            'reviewed_by'    => auth('employee')->id(),
        ]);

        return response()->json(['success' => true]);
    }

    /** Логи подпунктов для набора задач — одной выборкой; ключ: employee-year-month-itemId */
    private function childLogsFor($logs)
    {
        $childIds = $logs
            ->flatMap(fn (BuhTaskLog $l) => $l->estimateItem?->children->pluck('id') ?? collect())
            ->unique()->values();

        return $childIds->isNotEmpty()
            ? BuhTaskLog::whereIn('estimate_item_id', $childIds)->get()
                ->keyBy(fn (BuhTaskLog $l) => $l->employee_id . '-' . $l->year . '-' . $l->month . '-' . $l->estimate_item_id)
            : collect();
    }

    private function formatForReview(BuhTaskLog $log, $childLogs): array
    {
        $item    = $log->estimateItem;
        $service = $item?->service;

        return [
            'id'              => $log->id,
            'type'            => 'planned',
            'employee_name'   => $log->employee?->full_name,
            'client_name'     => $log->client?->name,
            'service_name'    => $service?->name ?? $item?->name,
            'elapsed_seconds' => $log->paused_seconds,
            'submitted_at'    => $log->updated_at?->format('d.m.Y H:i'),
            'submitted_ts'    => $log->updated_at?->timestamp, // для сортировки колонки «Отправлено»
            // Дата отсчёта срока проверки (календарные дни). Fallback на updated_at для старых строк.
            'review_started_date' => ($log->review_started_at ?? $log->updated_at)?->format('Y-m-d'),

            // Кто и когда проверил (для вкладки «Проверенные»)
            'reviewed_by_name' => $log->reviewer?->full_name,
            'reviewed_at'      => $log->reviewed_at?->format('d.m.Y H:i'),

            // Детали для попапа (двойной клик по строке)
            'description'       => $service?->description,
            'comment'          => $service?->comment,
            'periodicity'      => $item?->periodicity,
            'allows_quantity'  => (bool) ($service?->allows_quantity),
            'quantity'         => (int) ($item?->quantity ?? 0),
            'actual_quantity'  => $log->actual_quantity,
            'requires_document' => (bool) ($service?->requires_document),
            'document_name'    => $log->document_name,
            'document_path'    => $log->document_path,

            // Подпункты (если у БП есть дочерние позиции)
            'children'         => ($item?->children ?? collect())->map(function ($child) use ($log, $childLogs) {
                $childLog = $childLogs->get($log->employee_id . '-' . $log->year . '-' . $log->month . '-' . $child->id);
                $cs = $child->service;

                return [
                    'id'                => $child->id,
                    'name'              => $child->name,
                    'status'            => $childLog?->status ?? 'pending',
                    'allows_quantity'   => (bool) ($cs?->allows_quantity),
                    'quantity'          => (int) $child->quantity,
                    'actual_quantity'   => $childLog?->actual_quantity,
                    'requires_document' => (bool) ($cs?->requires_document),
                    'document_name'     => $childLog?->document_name,
                    'document_path'     => $childLog?->document_path,
                ];
            })->values()->toArray(),
        ];
    }

    /** Формат внеплановой задачи для Review-экрана — та же форма, что и у плановой. */
    private function formatAdhocForReview(BuhAdhocTask $task): array
    {
        return [
            'id'              => $task->id,
            'type'            => 'adhoc',
            'employee_name'   => $task->employee?->full_name,
            'client_name'     => $task->client?->name,
            'service_name'    => $task->name,
            'elapsed_seconds' => $task->paused_seconds,
            'submitted_at'    => $task->updated_at?->format('d.m.Y H:i'),
            'submitted_ts'    => $task->updated_at?->timestamp,
            'review_started_date' => ($task->review_started_at ?? $task->updated_at)?->format('Y-m-d'),
            'reviewed_by_name' => $task->reviewer?->full_name,
            'reviewed_at'      => $task->reviewed_at?->format('d.m.Y H:i'),
            'description'       => $task->description,
            'comment'          => null,
            'periodicity'      => null,
            'allows_quantity'  => false,
            'quantity'         => 0,
            'actual_quantity'  => null,
            'requires_document' => false, // документ опционален
            'document_name'    => $task->document_name,
            'document_path'    => $task->document_path,
            'children'         => [],
        ];
    }
}
