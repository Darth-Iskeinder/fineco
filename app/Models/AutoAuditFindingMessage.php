<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Одно сообщение по находке автоаудита: ответ бухгалтера или решение руководителя.
 *
 * result_id: строка результата, к которой относилось сообщение. По ней видно, держится ли
 * «Принято» (см. AutoAuditFinding::state).
 */
class AutoAuditFindingMessage extends Model
{
    use BelongsToTenant;

    public const EXPLAINED = 'explained';   // бухгалтер объяснил, почему так
    public const FIXED     = 'fixed';       // бухгалтер исправил, ждём прогона
    public const ACCEPTED  = 'accepted';    // руководитель принял
    public const REJECTED  = 'rejected';    // руководитель не принял, комментарий обязателен

    public const LABELS = [
        self::EXPLAINED => 'Объяснил',
        self::FIXED     => 'Исправил',
        self::ACCEPTED  => 'Принято',
        self::REJECTED  => 'Не принято',
    ];

    protected $fillable = ['finding_id', 'result_id', 'employee_id', 'by_vendor', 'kind', 'body'];

    protected $casts = [
        'by_vendor' => 'boolean',
    ];

    public function finding(): BelongsTo
    {
        return $this->belongsTo(AutoAuditFinding::class, 'finding_id');
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class)->withTrashed();
    }

    /** Кто написал. Вендор входит под учёткой сотрудника фирмы, поэтому отмечаем его отдельно. */
    public function authorName(): string
    {
        if ($this->by_vendor) {
            return 'Kubik';
        }

        return $this->employee?->full_name ?? 'сотрудник удалён';
    }
}
