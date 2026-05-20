<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuhAdhocTask extends Model
{
    protected $fillable = [
        'employee_id', 'client_id', 'name', 'cost',
        'year', 'month', 'status',
        'started_at', 'resumed_at', 'paused_seconds', 'completed_at',
    ];

    protected $casts = [
        'cost'           => 'decimal:2',
        'started_at'     => 'datetime',
        'resumed_at'     => 'datetime',
        'completed_at'   => 'datetime',
        'paused_seconds' => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
