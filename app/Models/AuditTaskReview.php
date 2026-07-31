<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Вердикт аудитора по одной закрытой задаче (BuhTaskLog).
 * Если вердикт — замечание, оно дальше живёт своим циклом устранения:
 * передано бухгалтеру → он исправил → аудитор подтвердил или вернул.
 */
class AuditTaskReview extends Model
{
    use BelongsToTenant;

    const VERDICT_OK      = 'ok';
    const VERDICT_FINDING = 'finding';

    // Стадии устранения (считаются от дат и статуса задачи, отдельной колонки нет)
    const STATE_DRAFT     = 'draft';      // не передано бухгалтеру
    const STATE_OPEN      = 'open';       // на исправлении
    const STATE_SUBMITTED = 'submitted';  // бухгалтер исправил, ждёт проверки аудитора
    const STATE_RESOLVED  = 'resolved';   // аудитор подтвердил устранение

    public static array $states = [
        self::STATE_DRAFT     => 'Не передано',
        self::STATE_OPEN      => 'На исправлении',
        self::STATE_SUBMITTED => 'На проверке аудитора',
        self::STATE_RESOLVED  => 'Устранено',
    ];

    /** Сколько рабочих дней даётся на исправление по умолчанию. */
    public const DEFAULT_DUE_WEEKDAYS = 10;

    protected $fillable = [
        'audit_id', 'buh_task_log_id', 'task_name', 'section',
        'verdict', 'severity', 'comment', 'reviewed_by', 'reviewed_at',
        'assignee_id', 'due_date', 'sent_at', 'adhoc_task_id',
        'resolved_at', 'resolved_by', 'returns_count',
    ];

    protected $casts = [
        'reviewed_at'   => 'datetime',
        'due_date'      => 'date',
        'sent_at'       => 'datetime',
        'resolved_at'   => 'datetime',
        'returns_count' => 'integer',
    ];

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    public function taskLog(): BelongsTo
    {
        return $this->belongsTo(BuhTaskLog::class, 'buh_task_log_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewed_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assignee_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'resolved_by');
    }

    /** Задача бухгалтера, через которую идёт исправление. */
    public function adhocTask(): BelongsTo
    {
        return $this->belongsTo(BuhAdhocTask::class, 'adhoc_task_id');
    }

    public function isFinding(): bool
    {
        return $this->verdict === self::VERDICT_FINDING;
    }

    /**
     * Стадия устранения. Считается, а не хранится: статус задачи бухгалтера —
     * единственный источник правды о том, исправлено ли, и рассинхрона тут быть не должно.
     */
    public function getStateAttribute(): string
    {
        if ($this->resolved_at) {
            return self::STATE_RESOLVED;
        }

        if (!$this->sent_at) {
            return self::STATE_DRAFT;
        }

        return $this->adhocTask?->status === 'completed'
            ? self::STATE_SUBMITTED
            : self::STATE_OPEN;
    }

    public function getStateLabelAttribute(): string
    {
        return self::$states[$this->state] ?? $this->state;
    }

    /** Просрочено: срок прошёл, а замечание ещё на исправлении. */
    public function getIsOverdueAttribute(): bool
    {
        return $this->state === self::STATE_OPEN
            && $this->due_date
            && $this->due_date->isPast();
    }

    public function scopeFindings($query)
    {
        return $query->where('verdict', self::VERDICT_FINDING);
    }

    public function scopeSent($query)
    {
        return $query->whereNotNull('sent_at');
    }

    public function scopeUnresolved($query)
    {
        return $query->whereNull('resolved_at');
    }
}
