<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Пункт чек-листа внутри аудита (копия пункта стандарта, дальше живёт отдельно). */
class AuditChecklistItem extends Model
{
    use BelongsToTenant;

    const STATUS_OK    = 'ok';    // проверено
    const STATUS_ERROR = 'err';   // ошибка
    const STATUS_ASK   = 'ask';   // нужны пояснения
    const STATUS_NA    = 'na';    // не применимо

    public static array $statuses = [
        self::STATUS_OK    => 'Проверено',
        self::STATUS_ERROR => 'Ошибка',
        self::STATUS_ASK   => 'Нужны пояснения',
        self::STATUS_NA    => 'Не применимо',
    ];

    protected $fillable = [
        'audit_id', 'section', 'account', 'point', 'how',
        'status', 'doc_link', 'comment', 'sort_order',
    ];

    protected $casts = [
        'sort_order' => 'integer',
    ];

    public function audit(): BelongsTo
    {
        return $this->belongsTo(Audit::class);
    }

    /** Пункт «закрыт», если аудитор выставил любой статус. */
    public function isClosed(): bool
    {
        return $this->status !== null && $this->status !== '';
    }
}
