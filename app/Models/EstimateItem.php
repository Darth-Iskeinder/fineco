<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class EstimateItem extends Model
{
    protected $fillable = [
        'estimate_id',
        'parent_id',
        'service_id',
        'is_extra',
        'name',
        'periodicity',
        'cost',
        'quantity',
        'total',
        'sort_order',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
        'total' => 'decimal:2',
        'is_extra' => 'boolean',
    ];

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
}
