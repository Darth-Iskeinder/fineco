<?php

namespace App\Models;

use App\Support\TenantContext;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Module extends Model
{
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

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order');
    }
}
