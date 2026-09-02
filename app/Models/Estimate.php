<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Estimate extends Model
{
    use BelongsToTenant;

    protected $fillable = ['client_id', 'total', 'notes'];

    protected $casts = [
        'total' => 'decimal:2',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(EstimateItem::class)->orderBy('sort_order');
    }

    public function rootItems(): HasMany
    {
        return $this->hasMany(EstimateItem::class)->whereNull('parent_id')->orderBy('sort_order');
    }

    /**
     * С какого дня по этой смете идут задачи. Месяц, в котором смету завели, — холостой:
     * клиента «сажают» (собирают документы, договариваются), задач в этом месяце нет.
     * Генерация начинается с 1-го числа следующего месяца.
     *
     * Это отдельная граница от даты начала обслуживания клиента: клиента могли завести
     * раньше, а список работ по нему появился только сейчас. Обе границы применяются
     * вместе — побеждает более поздняя (см. BuhTasksController/DashboardController).
     */
    public function tasksStartFrom(): CarbonImmutable
    {
        // Сначала первое число месяца, потом плюс месяц, а не наоборот: у сметы,
        // заведённой 31 августа, «плюс месяц» даёт несуществующее 31 сентября, PHP
        // переливает его на 1 октября, и клиент теряет целый месяц задач.
        return CarbonImmutable::parse($this->created_at)->startOfMonth()->addMonth();
    }

    public function recalculateTotal(): void
    {
        $this->total = $this->items()->whereNull('parent_id')->sum('total');
        $this->save();
    }
}
