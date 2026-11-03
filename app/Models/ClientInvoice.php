<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Счёт клиенту за месяц. Без номера это черновик, с номером выставлен.
 * Как выставляют и откуда берётся сумма, см. App\Services\Settlements\ClientInvoices.
 */
class ClientInvoice extends Model
{
    use BelongsToTenant;

    /** Срок оплаты после выставления, дней. Пока у всех одинаковый. */
    public const DUE_DAYS = 10;

    public const STATUS_DRAFT  = 'draft';
    public const STATUS_ISSUED = 'issued';

    /** Подпись и цвет статуса: значок в списке, на счёте и в истории. */
    public const STATUSES = [
        self::STATUS_DRAFT  => ['label' => 'Не выставлен', 'class' => 'border border-dashed border-amber-300 text-amber-700'],
        self::STATUS_ISSUED => ['label' => 'Выставлен',    'class' => 'bg-violet-50 text-violet-700'],
    ];

    protected $fillable = ['client_id', 'period', 'number', 'issued_on', 'due_on', 'amount', 'total', 'issued_by'];

    protected $casts = [
        'period'    => 'immutable_date',
        'issued_on' => 'immutable_date',
        'due_on'    => 'immutable_date',
        'amount'    => 'decimal:2',
        'total'     => 'decimal:2',
    ];

    public function client(): BelongsTo
    {
        return $this->belongsTo(Client::class)->withTrashed();
    }

    public function extras(): HasMany
    {
        return $this->hasMany(ClientInvoiceExtra::class, 'invoice_id')->orderBy('sort')->orderBy('id');
    }

    public function isIssued(): bool
    {
        return $this->number !== null;
    }

    public function status(): string
    {
        return $this->isIssued() ? self::STATUS_ISSUED : self::STATUS_DRAFT;
    }

    /** «№ 412 от 02.11.2026» или пусто у черновика. */
    public function title(): ?string
    {
        return $this->isIssued() ? '№ ' . $this->number . ' от ' . $this->issued_on->format('d.m.Y') : null;
    }

    /** Сумма для глаз: «20 000» или «20 000,50». */
    public static function money(float|string|null $value): string
    {
        $value = (float) $value;

        return number_format($value, fmod($value, 1.0) == 0.0 ? 0 : 2, ',', ' ');
    }
}
