<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Services\AutoAudit\AutoAuditRunner;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Итог одной проверки автоаудита у одного клиента за один период.
 *
 * Пишет его только AutoAuditRunner, целиком заменяя результаты фирмы при каждом прогоне.
 */
class AutoAuditResult extends Model
{
    use BelongsToTenant;

    public const MATCHED      = 'matched';       // числа совпали
    public const MISMATCH     = 'mismatch';      // числа разные
    public const NO_DOCUMENTS = 'no_documents';  // сравнивать не с чем: документа нет или он не прочитался

    protected $fillable = [
        'client_id', 'rule', 'period_from', 'period_to', 'outcome',
        'left_value', 'right_value', 'difference', 'reason', 'sources',
    ];

    protected $casts = [
        'period_from' => 'date',
        'period_to'   => 'date',
        'left_value'  => 'decimal:2',
        'right_value' => 'decimal:2',
        'difference'  => 'decimal:2',
        'sources'     => 'array',
    ];

    public function client(): BelongsTo
    {
        // Клиента могли удалить после прогона: строка должна показаться, а не упасть.
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function ruleName(): string
    {
        return AutoAuditRunner::RULES[$this->rule]['name'] ?? $this->rule;
    }

    /** «июль 2026» для месяца, даты через тире для всего остального. */
    public function periodLabel(): string
    {
        if (!$this->period_from || !$this->period_to) {
            return 'период не разобран';
        }

        $from = $this->period_from->locale('ru');

        if ($from->day === 1 && $this->period_to->isSameDay($from->endOfMonth())) {
            return $from->isoFormat('MMMM YYYY');
        }

        return $from->format('d.m.Y') . ' – ' . $this->period_to->format('d.m.Y');
    }
}
