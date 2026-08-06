<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Что импорт сделал с одним клиентом. `before` хранит прежние значения тех
 * полей, которые он менял, — этого достаточно, чтобы вернуть как было.
 *
 * Своего фильтра по фирме нет: строка живёт только внутри ClientImport и
 * достаётся через него.
 */
class ClientImportRow extends Model
{
    public const ACTION_CREATED = 'created';
    public const ACTION_UPDATED = 'updated';

    protected $fillable = ['client_id', 'action', 'before'];

    protected $casts = ['before' => 'array'];

    public function import(): BelongsTo
    {
        return $this->belongsTo(ClientImport::class, 'client_import_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }
}
