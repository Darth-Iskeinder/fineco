<?php

namespace App\Http\Controllers;

use App\Models\BuhTaskLog;
use Illuminate\Http\Request;

class ReviewController extends Controller
{
    public function index()
    {
        $logs = BuhTaskLog::where('status', 'review')
            ->with(['employee:id,full_name', 'client:id,name', 'estimateItem.service:id,name'])
            ->orderBy('updated_at')
            ->get()
            ->map(fn (BuhTaskLog $log) => $this->formatForReview($log))
            ->values();

        return view('review.index', ['logs' => $logs]);
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

        return response()->json(['success' => true]);
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

    private function formatForReview(BuhTaskLog $log): array
    {
        return [
            'id'              => $log->id,
            'employee_name'   => $log->employee?->full_name,
            'client_name'     => $log->client?->name,
            'service_name'    => $log->estimateItem?->service?->name ?? $log->estimateItem?->name,
            'elapsed_seconds' => $log->paused_seconds,
            'submitted_at'    => $log->updated_at?->format('d.m.Y H:i'),
        ];
    }
}
