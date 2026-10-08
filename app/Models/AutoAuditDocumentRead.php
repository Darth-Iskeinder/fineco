<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Что узнали из файла, прочитав его: период, исход чтения, ИНН из шапки.
 *
 * Пишет AutoAuditWatch после прогона. Пока только для наблюдения: по периоду видно,
 * какие месяцы задел новый, заменённый или удалённый файл.
 */
class AutoAuditDocumentRead extends Model
{
    use BelongsToTenant;

    protected $fillable = ['document_id', 'client_id', 'side', 'path', 'period_from', 'period_to', 'status', 'inn'];

    protected $casts = [
        'period_from' => 'date',
        'period_to'   => 'date',
    ];
}
