<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Долг или аванс клиента на начало работы в Kubik. Один на клиента.
 * amount больше нуля: долг, меньше нуля: аванс. Как он гасится, см. ClientLedger.
 */
class ClientOpeningBalance extends Model
{
    use BelongsToTenant;

    protected $fillable = ['client_id', 'amount', 'as_of', 'created_by'];

    protected $casts = [
        'amount' => 'decimal:2',
        'as_of'  => 'immutable_date',
    ];

    public function isAdvance(): bool
    {
        return (float) $this->amount < 0;
    }
}
