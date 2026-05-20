<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Tariff extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'code',
        'price',
        'description',
        'is_active',
        'sort_order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'is_active' => 'boolean',
    ];

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(Service::class)
            ->withPivot('free_limit', 'price_override');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function getFormattedPriceAttribute(): string
    {
        return number_format($this->price, 0, ',', ' ') . ' сом';
    }
}
