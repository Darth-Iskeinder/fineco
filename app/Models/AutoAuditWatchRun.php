<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Итог наблюдения за одним прогоном автоаудита. См. AutoAuditWatch. */
class AutoAuditWatchRun extends Model
{
    use BelongsToTenant;

    public const NIGHT   = 'night';
    public const COMMAND = 'command';

    protected $fillable = [
        'trigger', 'seconds', 'clients', 'clients_needed', 'checks', 'checks_by_client',
        'checks_by_month', 'alarms', 'alarms_by_month', 'code_hash',
    ];

    public function clients(): HasMany
    {
        return $this->hasMany(AutoAuditWatchClient::class, 'run_id');
    }
}
