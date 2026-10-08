<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Снимок того, на чём построены сверки клиента, после последней проверки. См. AutoAuditWatch. */
class AutoAuditSnapshot extends Model
{
    use BelongsToTenant;

    protected $fillable = ['client_id', 'hash', 'parts', 'months'];

    protected $casts = [
        'parts'  => 'array',
        'months' => 'array',
    ];
}
