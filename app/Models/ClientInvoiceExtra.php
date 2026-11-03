<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/** Дополнительная услуга в счёте за месяц. В следующий месяц не переходит. */
class ClientInvoiceExtra extends Model
{
    use BelongsToTenant;

    protected $fillable = ['invoice_id', 'name', 'amount', 'sort'];

    protected $casts = [
        'amount' => 'decimal:2',
    ];
}
