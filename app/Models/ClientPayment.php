<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Оплата клиента. Вносится на клиента, по счетам раскладывается при показе,
 * см. App\Services\Settlements\ClientLedger.
 */
class ClientPayment extends Model
{
    use BelongsToTenant;

    public const BANK = 'bank';
    public const CASH = 'cash';

    public const METHODS = [
        self::BANK => 'Банк',
        self::CASH => 'Касса',
    ];

    protected $fillable = ['client_id', 'paid_on', 'amount', 'method', 'created_by'];

    protected $casts = [
        'paid_on' => 'immutable_date',
        'amount'  => 'decimal:2',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'created_by')->withTrashed();
    }

    public function methodLabel(): string
    {
        return self::METHODS[$this->method] ?? $this->method;
    }
}
