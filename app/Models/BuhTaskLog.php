<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class BuhTaskLog extends Model
{
    use BelongsToTenant;

    /**
     * Причины принудительного закрытия: почему документа нет. Выбирает бухгалтер в окне
     * закрытия, комментарий к ним по желанию, кроме «Другое».
     *
     * Автоаудит смотрит только сюда, а не в текст: «Нулевой» у него значит ноль по
     * документу, «Освобождён» ноль по налогу. Раз в квартал и «Другое» не значат ничего.
     */
    public const FORCE_ZERO      = 'zero';
    public const FORCE_QUARTERLY = 'quarterly';
    public const FORCE_EXEMPT    = 'exempt';
    public const FORCE_OTHER     = 'other';

    /** Ключ => [как в окне закрытия, как в списках рядом с комментарием]. */
    public const FORCE_REASONS = [
        self::FORCE_ZERO      => ['Нулевой: операций, выручки, зарплаты не было', 'Нулевой'],
        self::FORCE_QUARTERLY => ['Сдаётся раз в квартал, в этом месяце не нужно', 'Раз в квартал'],
        self::FORCE_EXEMPT    => ['Клиент освобождён от этого отчёта', 'Освобождён'],
        self::FORCE_OTHER     => ['Другое', 'Другое'],
    ];

    protected $fillable = [
        'employee_id', 'client_id', 'estimate_item_id',
        'year', 'month', 'due_date', 'status', 'review_comment', 'rework_count', 'employee_comment', 'rework_seen_at',
        'force_closed', 'force_close_reason', 'force_close_comment',
        'started_at', 'resumed_at', 'paused_seconds', 'completed_at',
        'reviewed_at', 'reviewed_by', 'review_started_at', 'actual_quantity',
        'document_path', 'document_name',
    ];

    protected $casts = [
        'due_date'     => 'date',
        'started_at'   => 'datetime',
        'resumed_at'   => 'datetime',
        'completed_at' => 'datetime',
        'reviewed_at'  => 'datetime',
        'review_started_at' => 'datetime',
        'rework_seen_at' => 'datetime',
        'paused_seconds' => 'integer',
        'actual_quantity' => 'integer',
        'rework_count' => 'integer',
        'force_closed' => 'boolean',
    ];

    /**
     * Причина принудительного закрытия одной строкой для людей: «Нулевой. нулевой отчёт не
     * принимается». Её отдают во все списки под прежним ключом force_close_comment, и экраны
     * показывают причину без правок. У «Другое» и старых задач без выбора только текст.
     */
    public function forceCloseNote(): ?string
    {
        $comment = trim((string) $this->force_close_comment);
        $reason  = $this->force_close_reason;

        if (!$reason || $reason === self::FORCE_OTHER || !isset(self::FORCE_REASONS[$reason])) {
            return $comment !== '' ? $comment : null;
        }

        $label = self::FORCE_REASONS[$reason][1];

        return $comment !== '' ? "{$label}. {$comment}" : $label;
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class);
    }

    public function estimateItem(): BelongsTo
    {
        return $this->belongsTo(EstimateItem::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'reviewed_by');
    }

    public function documents(): MorphMany
    {
        return $this->morphMany(BuhTaskDocument::class, 'documentable');
    }
}
