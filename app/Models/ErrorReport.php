<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Строка журнала сбоев. Пишется только через App\Support\ErrorReporter —
 * он один умеет делать это безопасно (см. комментарий там).
 *
 * Про отсутствие BelongsToTenant — см. комментарий в миграции.
 */
class ErrorReport extends Model
{
    public const KIND_SERVER  = 'server';
    public const KIND_BROWSER = 'browser';

    protected $fillable = [
        'tenant_id', 'employee_id', 'kind', 'fingerprint',
        'message', 'source', 'url', 'status', 'context',
        'count', 'first_seen_at', 'last_seen_at', 'resolved_at',
    ];

    protected $casts = [
        'status'        => 'integer',
        'count'         => 'integer',
        'first_seen_at' => 'datetime',
        'last_seen_at'  => 'datetime',
        'resolved_at'   => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** Незакрытые — то, что ещё требует внимания. */
    public function scopeUnresolved(Builder $query): Builder
    {
        return $query->whereNull('resolved_at');
    }

    public function isResolved(): bool
    {
        return $this->resolved_at !== null;
    }
}
