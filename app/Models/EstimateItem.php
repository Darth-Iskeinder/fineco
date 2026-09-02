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
        'tasks_end_at',
    ];

    protected $casts = [
        'cost'             => 'decimal:2',
        'total'            => 'decimal:2',
        'due_day'          => 'integer',
        'tasks_start_from' => 'date',
        'tasks_end_at'     => 'date',
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
        // Порядок важен: «31 августа плюс месяц» переливается на 1 октября, а
        // «первое число августа плюс месяц» честно даёт 1 сентября.
        return CarbonImmutable::now()->startOfMonth()->addMonth();
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

    /**
     * Каким числом закрывается позиция, которую выключают прямо сейчас.
     *
     * Конец текущего месяца: месяц уже отработан по-старому, его задачи и сроки
     * должны остаться. Для квартальных и годовых БП дату потом правят руками —
     * последняя декларация за отработанный период сдаётся уже после закрытия.
     */
    public static function tasksEndForClosing(): CarbonImmutable
    {
        return CarbonImmutable::now()->endOfMonth()->startOfDay();
    }

    /**
     * Верхняя граница задач этой позиции, если она задана.
     *
     * Пусто у всего действующего. Заполнена у позиций, которые выключили в смете,
     * но не удалили: по ним есть история выполнения, и она должна пережить выключение.
     */
    public function tasksEndAt(): ?CarbonImmutable
    {
        return $this->tasks_end_at
            ? CarbonImmutable::parse($this->tasks_end_at)->endOfDay()
            : null;
    }

    /** Позиция закрыта: новые задачи по ней не появляются, старые остаются. */
    public function isClosed(): bool
    {
        return $this->tasks_end_at !== null;
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
