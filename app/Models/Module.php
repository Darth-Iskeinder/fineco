<?php

namespace App\Models;

use App\Support\TenantContext;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Module extends Model
{
    /** «Расчёты с клиентами»: виден только там, где фирме открыли раздел, см. SettlementsAccess. */
    public const SETTLEMENTS = 'settlements';

    protected $fillable = [
        'name',
        'display_name',
        'icon',
        'route',
        'sort_order',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function employees(): BelongsToMany
    {
        $relation = $this->belongsToMany(Employee::class, 'employee_module')->withTimestamps();

        // Модули общие для всех аккаунтов, своей фирмы у них нет — берём текущую.
        return TenantContext::has()
            ? $relation->withPivotValue('tenant_id', TenantContext::id())
            : $relation;
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Модули, которые есть в этой фирме. Расчёты с клиентами прячем, пока фирме их
     * не открыли: иначе галочка в карточке сотрудника вела бы в никуда.
     */
    public function scopeAvailableHere($query)
    {
        return \App\Services\Settlements\SettlementsAccess::openHere()
            ? $query
            : $query->where('modules.name', '!=', self::SETTLEMENTS);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
