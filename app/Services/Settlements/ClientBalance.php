<?php

namespace App\Services\Settlements;

use App\Models\ClientInvoice;
use App\Models\ClientOpeningBalance;

/**
 * Итог раскладки оплат одного клиента, см. ClientLedger. Суммы внутри в тыйынах,
 * наружу отдаются в сомах.
 */
class ClientBalance
{
    /**
     * @param  array<int, int>  $covered  id счёта => сколько на него легло
     * @param  array<int, array{label: string, debt: int}>  $open  непогашенные долги, старые первыми
     * @param  int  $openingPaid  сколько легло на долг на начало
     */
    public function __construct(
        private readonly array $covered,
        private readonly array $open,
        private readonly int $debt,
        private readonly int $advance,
        private readonly int $overdue,
        private readonly ?ClientOpeningBalance $opening = null,
        private readonly int $openingPaid = 0,
    ) {
    }

    /** Сколько клиент должен всего. */
    public function debt(): float
    {
        return $this->debt / 100;
    }

    /** Деньги сверх всех долгов. */
    public function advance(): float
    {
        return $this->advance / 100;
    }

    /** Долг, срок оплаты которого прошёл больше ClientLedger::OVERDUE_DAYS назад. */
    public function overdue(): float
    {
        return $this->overdue / 100;
    }

    /** Долг или аванс на начало, если вписан. */
    public function opening(): ?ClientOpeningBalance
    {
        return $this->opening;
    }

    /** Сколько легло на долг на начало. */
    public function openingPaid(): float
    {
        return $this->openingPaid / 100;
    }

    /** Остаток долга на начало. */
    public function openingDebt(): float
    {
        if (!$this->opening || $this->opening->isAdvance()) {
            return 0.0;
        }

        return (ClientLedger::cents($this->opening->amount) - $this->openingPaid) / 100;
    }

    /** Сколько легло на этот счёт. */
    public function paidFor(ClientInvoice $invoice): float
    {
        return ($this->covered[$invoice->id] ?? 0) / 100;
    }

    public function debtFor(ClientInvoice $invoice): float
    {
        if (!$invoice->isIssued()) {
            return 0.0;
        }

        return max(0, ClientLedger::cents($invoice->total) - ($this->covered[$invoice->id] ?? 0)) / 100;
    }

    /** Непогашенные долги для окна оплаты: подпись и сумма, старые первыми. */
    public function openForForm(): array
    {
        return array_map(fn (array $item) => ['month' => $item['label'], 'debt' => $item['debt'] / 100], $this->open);
    }

    /** Статус счёта с учётом оплат. */
    public function statusFor(?ClientInvoice $invoice): string
    {
        if (!$invoice || !$invoice->isIssued()) {
            return ClientInvoice::STATUS_DRAFT;
        }

        $covered = $this->covered[$invoice->id] ?? 0;

        if ($covered <= 0) {
            return ClientInvoice::STATUS_ISSUED;
        }

        return $covered >= ClientLedger::cents($invoice->total) ? ClientInvoice::STATUS_PAID : ClientInvoice::STATUS_PARTIAL;
    }

    /** Статус долга на начало: как у счёта, только без «Не выставлен». */
    public function openingStatus(): string
    {
        if ($this->openingPaid <= 0) {
            return ClientInvoice::STATUS_ISSUED;
        }

        return $this->openingDebt() > 0 ? ClientInvoice::STATUS_PARTIAL : ClientInvoice::STATUS_PAID;
    }
}
