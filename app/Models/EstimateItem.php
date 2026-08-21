<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstimateItem extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'estimate_id',
        'parent_id',
        'service_id',
        'assignee_id',
        'tax_office_code',
        'branch_label',
        'type',
        'name',
        'periodicity',
        'due_day',
        'cost',
        'quantity',
        'total',
        'sort_order',
        'tasks_start_from',
    ];

    protected $casts = [
        'cost'             => 'decimal:2',
        'total'            => 'decimal:2',
        'due_day'          => 'integer',
        'tasks_start_from' => 'date',
    ];

    /**
     * С какого дня пойдут задачи по позиции, добавленной прямо сейчас.
     *
     * Месяц добавления холостой — БП успевают обсудить с клиентом и собрать
     * документы. Иначе включённый в середине месяца БП сразу выдавал бы
     * просрочку за срок, который в этом месяце уже прошёл.
     */
    public static function tasksStartForNew(): CarbonImmutable
    {
        return CarbonImmutable::now()->addMonth()->startOfMonth();
    }

    /**
     * Нижняя граница задач этой позиции, если она задана.
     *
     * Пусто у всего, что завели до появления границы, и у разовых доп. услуг:
     * их добавляют, чтобы сделать сейчас, а не в следующем месяце.
     */
    public function tasksStartFrom(): ?CarbonImmutable
    {
        return $this->tasks_start_from
            ? CarbonImmutable::parse($this->tasks_start_from)->startOfDay()
            : null;
    }

    public function estimate(): BelongsTo
    {
        return $this->belongsTo(Estimate::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(EstimateItem::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(EstimateItem::class, 'parent_id')->orderBy('sort_order');
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /** Исполнитель БП (на кого генерятся задачи по этой позиции сметы). */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'assignee_id');
    }
}
