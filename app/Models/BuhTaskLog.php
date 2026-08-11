<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class BuhTaskLog extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'employee_id', 'client_id', 'estimate_item_id',
        'year', 'month', 'due_date', 'status', 'review_comment', 'rework_count', 'employee_comment', 'rework_seen_at',
        'force_closed', 'force_close_comment',
        'started_at', 'resumed_at', 'paused_seconds', 'completed_at',
        'reviewed_at', 'reviewed_by', 'review_started_at', 'actual_quantity',
        'document_path', 'document_name',
    ];

    protected $casts = [
        'due_date'     => 'date',
        'started_at'   => 'datetime',
        'resumed_at'   => 'datetime',
        'completed_at' => 'datetime',
        'reviewed_at'  => 'datetime',
        'review_started_at' => 'datetime',
        'rework_seen_at' => 'datetime',
        'paused_seconds' => 'integer',
        'actual_quantity' => 'integer',
        'rework_count' => 'integer',
        'force_closed' => 'boolean',
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

    public function documents(): MorphMany
    {
        return $this->morphMany(BuhTaskDocument::class, 'documentable');
    }
}
