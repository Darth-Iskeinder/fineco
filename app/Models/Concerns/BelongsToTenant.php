<?php

namespace App\Models\Concerns;

use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Данные принадлежат фирме.
 *
 * Делает две вещи:
 *   - к каждому запросу дописывает «...и только этой фирмы»;
 *   - новой строке проставляет фирму, если её не задали явно.
 *
 * Оба правила живут здесь, а не в контроллерах. Это главное: обойти сотню
 * страниц и в каждой не забыть про фильтр невозможно, а подключить трейт к
 * модели один раз — можно. Забытая страница показала бы чужие данные.
 *
 * Пока фирма в контексте не задана (консоль, крон, момент авторизации), фильтр
 * не применяется — см. TenantContext.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope('tenant', function (Builder $query) {
            if (!TenantContext::has()) {
                return;
            }

            $query->where(
                $query->getModel()->qualifyColumn('tenant_id'),
                TenantContext::id(),
            );
        });

        static::creating(function (Model $model) {
            // tenant_id намеренно не в $fillable — из формы его подсунуть нельзя,
            // проставляем только здесь.
            if (empty($model->tenant_id) && TenantContext::has()) {
                $model->tenant_id = TenantContext::id();
            }
        });
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    /** Осознанный выход за пределы своей фирмы: копирование образца, супер-админка. */
    public function scopeAcrossTenants(Builder $query): Builder
    {
        return $query->withoutGlobalScope('tenant');
    }
}
