<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BuhAdhocTask extends Model
{
    protected $fillable = [
        'employee_id', 'client_id', 'service_id', 'name', 'description', 'cost',
        'year', 'month', 'due_day', 'status', 'requires_review',
        'started_at', 'resumed_at', 'paused_seconds', 'completed_at',
        'review_comment', 'rework_count', 'employee_comment', 'review_started_at', 'reviewed_at', 'reviewed_by',
        'document_path', 'document_name',
    ];

    protected $casts = [
        'cost'              => 'decimal:2',
        'due_day'           => 'integer',
        'requires_review'   => 'boolean',
        'started_at'        => 'datetime',
        'resumed_at'        => 'datetime',
        'completed_at'      => 'datetime',
        'review_started_at' => 'datetime',
        'reviewed_at'       => 'datetime',
        'paused_seconds'    => 'integer',
        'rework_count'      => 'integer',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewed_by');
    }
}
