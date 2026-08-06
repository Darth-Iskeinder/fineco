<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Одна загрузка клиентов из файла — со счётчиками и построчным журналом.
 */
class ClientImport extends Model
{
    use BelongsToTenant;

    public const STATUS_APPLIED     = 'applied';
    public const STATUS_ROLLED_BACK = 'rolled_back';

    protected $fillable = [
        'employee_id',
        'file_name',
        'created_count',
        'updated_count',
        'skipped_count',
        'update_existing',
        'status',
        'rolled_back_at',
    ];

    protected $casts = [
        'update_existing' => 'boolean',
        'rolled_back_at'  => 'datetime',
    ];

    public function rows(): HasMany
    {
        return $this->hasMany(ClientImportRow::class);
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function isRolledBack(): bool
    {
        return $this->status === self::STATUS_ROLLED_BACK;
    }
}
