<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class BuhAdhocTask extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'employee_id', 'created_by', 'client_id', 'service_id', 'name', 'description', 'clarification', 'checklist', 'cost',
        'year', 'month', 'due_day', 'status', 'requires_review',
        'started_at', 'resumed_at', 'paused_seconds', 'completed_at',
        'review_comment', 'rework_count', 'employee_comment', 'review_started_at', 'reviewed_at', 'reviewed_by',
        'assign_seen_at', 'rework_seen_at',
        'trigger_source_type', 'trigger_source_id', 'trigger_source_name',
        'document_path', 'document_name',
    ];

    protected $casts = [
        'cost'              => 'decimal:2',
        'checklist'         => 'array',
        'due_day'           => 'integer',
        'requires_review'   => 'boolean',
        'started_at'        => 'datetime',
        'resumed_at'        => 'datetime',
        'completed_at'      => 'datetime',
        'review_started_at' => 'datetime',
        'reviewed_at'       => 'datetime',
        'assign_seen_at'    => 'datetime',
        'rework_seen_at'    => 'datetime',
        'paused_seconds'    => 'integer',
        'rework_count'      => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewed_by');
    }

    /** Кто поручил задачу. NULL у задач, созданных до появления поля. */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by');
    }

    /**
     * Подпункты для фронта — в том же виде, что и у плановых задач, чтобы карточка
     * задачи рисовала их одной и той же разметкой. Ключ — позиция в снимке: список
     * после создания не меняется, поэтому индекс стабилен.
     *
     * @return array<int, array{id:int, name:string, status:string}>
     */
    public function checklistForView(): array
    {
        return collect($this->checklist ?? [])
            ->values()
            ->map(fn (array $item, int $i) => [
                'id'     => $i,
                'name'   => $item['name'] ?? '',
                'status' => !empty($item['done']) ? 'completed' : 'pending',
            ])
            ->all();
    }

    /** Остались неотмеченные подпункты — задачу закрывать рано. */
    public function hasUncheckedItems(): bool
    {
        return collect($this->checklist ?? [])->contains(fn (array $item) => empty($item['done']));
    }

    /** Поручение: задачу завёл один сотрудник, а делает другой. */
    public function isAssignment(): bool
    {
        return $this->created_by !== null
            && (int) $this->created_by !== (int) $this->employee_id;
    }

    /**
     * Задача-родитель, из-за выполнения которой родилась эта («по событию»).
     * NULL у всех обычных задач.
     */
    public function triggerSource(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Задача рождена триггером «по событию».
     *
     * Такая задача сама триггер больше не запускает: цепочка идёт ровно
     * на одну ступень, поэтому кольцо из двух БП безопасно.
     */
    public function isTriggered(): bool
    {
        return $this->trigger_source_id !== null;
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(BuhTaskDocument::class, 'documentable');
    }
}
