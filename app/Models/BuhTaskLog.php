<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuhTaskLog extends Model
{
    protected $fillable = [
        'employee_id', 'client_id', 'estimate_item_id',
        'year', 'month', 'status',
        'started_at', 'resumed_at', 'paused_seconds', 'completed_at',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'resumed_at'   => 'datetime',
        'completed_at' => 'datetime',
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

    public function estimateItem(): BelongsTo
    {
        return $this->belongsTo(EstimateItem::class);
    }

}
