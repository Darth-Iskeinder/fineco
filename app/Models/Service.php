<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    use HasFactory;

    protected $fillable = [
        'parent_id',
        'name',
        'description',
        'cost',
        'pricing_rules',
        'periodicity',
        'due_day',
        'is_active',
        'allows_quantity',
        'sort_order',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
        'pricing_rules' => 'array',
        'is_active' => 'boolean',
        'allows_quantity' => 'boolean',
        'due_day' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Service::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function tariffs(): BelongsToMany
    {
        return $this->belongsToMany(Tariff::class)
            ->withPivot('free_limit', 'price_override');
    }

    public function taxSystems(): BelongsToMany
    {
        return $this->belongsToMany(TaxSystem::class, 'service_tax_system');
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function getFormattedCostAttribute(): string
    {
        return number_format($this->cost, 0, ',', ' ') . ' сом';
    }
}
