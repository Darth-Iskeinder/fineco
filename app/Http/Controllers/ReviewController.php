<?php

namespace App\Http\Controllers;

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

        return view('review.index', [
            'logs'        => $pending->map(fn (BuhTaskLog $log) => $this->formatForReview($log, $childLogs))->values(),
            'reviewed'    => $reviewed->map(fn (BuhTaskLog $log) => $this->formatForReview($log, $childLogs))->values(),
            'slaDays'     => self::REVIEW_SLA_DAYS,
            'historyDays' => self::HISTORY_DAYS,
        ]);
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
}
