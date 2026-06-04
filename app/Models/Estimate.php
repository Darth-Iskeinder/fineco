<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Estimate extends Model
{
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

    public function recalculateTotal(): void
    {
        $this->total = $this->items()->whereNull('parent_id')->sum('total');
        $this->save();
    }
}
