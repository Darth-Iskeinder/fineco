<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientStatus extends Model
{
    protected $fillable = [
        'name',
        'color',
        'closes_service',
        'stops_tasks',
        'sort_order',
    ];

    protected $casts = [
        'closes_service' => 'boolean',
        'stops_tasks' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }
}
