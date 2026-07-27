<?php

namespace App\Services;

use App\Models\AuditTaskReview;
use App\Models\BuhAdhocTask;
use App\Models\Employee;
use Carbon\CarbonImmutable;

/**
 * Передача замечаний аудита на исправление и приём результата.
 *
 * Исправление идёт обычной внеплановой задачей бухгалтера: он видит её в своём
 * списке задач вместе со всей остальной работой, а главбух — в списке задач своих
 * бухгалтеров. Проверка главбуха для таких задач не включается: замечание аудита
 * закрывает аудитор, это отдельный контур.
 */
class AuditRemediation
{
    /** Кому по умолчанию исправлять: кто закрывал задачу, иначе ответственный по клиенту. */
    public function defaultAssignee(AuditTaskReview $review): ?Employee
    {
        $closer = $review->taskLog?->employee;

        if ($closer && $closer->isActive() && !$closer->isFired()) {
            return $closer;
        }

        return $review->audit?->client?->responsibleEmployee;
    }

    /** Срок по умолчанию — 10 рабочих дней. */
    public function defaultDueDate(): CarbonImmutable
    {
        return CarbonImmutable::today()->addWeekdays(AuditTaskReview::DEFAULT_DUE_WEEKDAYS);
    }

    /** Передать замечание бухгалтеру: создаётся задача, замечание уходит в «На исправлении». */
    public function send(AuditTaskReview $review, Employee $assignee, CarbonImmutable $due): AuditTaskReview
    {
        $audit = $review->audit;

        $adhoc = BuhAdhocTask::create([
            'employee_id'     => $assignee->id,
            'client_id'       => $audit->client_id,
            'name'            => 'Аудит: ' . $review->task_name,
            'description'     => $this->description($review),
            'requires_review' => false, // замечание принимает аудитор, а не главбух
            'cost'            => 0,
            'year'            => $due->year,
            'month'           => $due->month,
            'due_day'         => $due->day,
            'status'          => 'pending',
            'paused_seconds'  => 0,
        ]);

        $review->update([
            'assignee_id'   => $assignee->id,
            'due_date'      => $due->toDateString(),
            'sent_at'       => now(),
            'adhoc_task_id' => $adhoc->id,
        ]);

        return $review->refresh();
    }

    /** Аудитор подтвердил устранение. Задача бухгалтера остаётся закрытой. */
    public function resolve(AuditTaskReview $review, Employee $auditor): void
    {
        $review->update([
            'resolved_at' => now(),
            'resolved_by' => $auditor->id,
        ]);
    }

    /** Аудитор вернул: задача снова открывается у бухгалтера с комментарием. */
    public function returnForRework(AuditTaskReview $review, string $comment): void
    {
        $adhoc = $review->adhocTask;

        if ($adhoc) {
            $adhoc->update([
                'status'         => 'pending',
                'completed_at'   => null,
                'started_at'     => null,
                'resumed_at'     => null,
                'paused_seconds' => 0,
                'review_comment' => $comment,
                'rework_count'   => (int) $adhoc->rework_count + 1,
            ]);
        }

        $review->update([
            'resolved_at'   => null,
            'resolved_by'   => null,
            'returns_count' => (int) $review->returns_count + 1,
        ]);
    }

    /** Переназначить исполнителя (например, когда бухгалтер уволился). */
    public function reassign(AuditTaskReview $review, Employee $assignee): void
    {
        $review->adhocTask?->update(['employee_id' => $assignee->id]);
        $review->update(['assignee_id' => $assignee->id]);
    }

    /** Текст задачи: что нашли, где и за какой период. */
    private function description(AuditTaskReview $review): string
    {
        $audit  = $review->audit;
        $lines  = [];

        $lines[] = 'Замечание аудита за период ' . $audit->period_label
            . ' (' . ($audit->client?->name ?? 'клиент') . ').';

        if ($review->section) {
            $lines[] = 'Участок: ' . $review->section . '.';
        }

        $lines[] = 'Уровень: ' . (\App\Models\Audit::$severities[$review->severity] ?? 'не указан') . '.';

        if ($review->comment) {
            $lines[] = '';
            $lines[] = $review->comment;
        }

        $lines[] = '';
        $lines[] = 'После исправления закройте задачу — замечание уйдёт на проверку аудитору.';

        return implode("\n", $lines);
    }
}
