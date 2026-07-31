<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Аудит = проверка качества работы по одному клиенту за один период.
 * Единица проверки — закрытая задача (BuhTaskLog); параллельно ведётся чек-лист
 * контрольных точек, скопированный из стандарта.
 */
class Audit extends Model
{
    use BelongsToTenant;

    const STATUS_DRAFT       = 'draft';
    const STATUS_IN_PROGRESS = 'in_progress';
    const STATUS_COMPLETED   = 'completed';

    public static array $statuses = [
        self::STATUS_DRAFT       => 'Черновик',
        self::STATUS_IN_PROGRESS => 'В работе',
        self::STATUS_COMPLETED   => 'Завершён',
    ];

    public static array $severities = [
        'critical' => 'Критично',
        'major'    => 'Существенно',
        'minor'    => 'Незначительно',
    ];

    protected $fillable = [
        'client_id', 'auditor_id', 'template_id',
        'period_start', 'period_end',
        'status', 'summary', 'completed_at',
    ];

    protected $casts = [
        'period_start' => 'date',
        'period_end'   => 'date',
        'completed_at' => 'datetime',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function auditor(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'auditor_id');
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(AuditChecklistTemplate::class, 'template_id');
    }

    public function taskReviews(): HasMany
    {
        return $this->hasMany(AuditTaskReview::class);
    }

    public function checklistItems(): HasMany
    {
        return $this->hasMany(AuditChecklistItem::class)->orderBy('sort_order')->orderBy('id');
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    public function getStatusLabelAttribute(): string
    {
        return self::$statuses[$this->status] ?? $this->status;
    }

    /** «01.01.2026 – 30.04.2026» — период человеческим языком. */
    public function getPeriodLabelAttribute(): string
    {
        return $this->period_start->format('d.m.Y') . ' – ' . $this->period_end->format('d.m.Y');
    }

    /**
     * Закрытые задачи клиента, попадающие в аудит.
     *
     * Период у аудита в датах, а у задачи — год+месяц (слот), поэтому сравниваем
     * по номеру месяца: задача входит, если её месяц лежит между месяцем начала
     * и месяцем конца периода включительно.
     */
    public function closedTaskLogs()
    {
        $from = $this->period_start->year * 12 + $this->period_start->month;
        $to   = $this->period_end->year * 12 + $this->period_end->month;

        return BuhTaskLog::query()
            ->where('client_id', $this->client_id)
            ->where('status', 'completed')
            ->whereRaw('(year * 12 + month) between ? and ?', [$from, $to])
            ->with(['employee', 'estimateItem.service', 'documents'])
            ->orderBy('year')
            ->orderBy('month');
    }

    /** Копирует пункты стандарта в чек-лист аудита (одноразово, при создании). */
    public function copyChecklistFrom(AuditChecklistTemplate $template): void
    {
        foreach ($template->items as $i => $item) {
            $this->checklistItems()->create([
                'section'    => $item->section,
                'account'    => $item->account,
                'point'      => $item->point,
                'how'        => $item->how,
                'sort_order' => $i,
            ]);
        }
    }
}
