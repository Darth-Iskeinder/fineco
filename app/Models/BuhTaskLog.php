<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuhTaskLog extends Model
{
    protected $fillable = [
        'employee_id', 'client_id', 'estimate_item_id',
        'year', 'month', 'status', 'review_comment',
        'started_at', 'resumed_at', 'paused_seconds', 'completed_at',
        'reviewed_at', 'reviewed_by', 'actual_quantity',
        'document_path', 'document_name',
    ];

    protected $casts = [
        'started_at'   => 'datetime',
        'resumed_at'   => 'datetime',
        'completed_at' => 'datetime',
        'reviewed_at'  => 'datetime',
        'paused_seconds' => 'integer',
        'actual_quantity' => 'integer',
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

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewed_by');
    }
}
