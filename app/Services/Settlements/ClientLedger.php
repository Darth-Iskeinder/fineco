<?php

namespace App\Services\Settlements;

use App\Models\Client;
use App\Models\ClientInvoice;
use App\Models\ClientOpeningBalance;
use App\Models\ClientPayment;
use App\Models\Employee;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Взаиморасчёты с клиентом: как его оплаты легли на счета.
 *
 * Договорились 04.11.2026: оплата вносится на клиента, а по счетам раскладывается
 * сначала в самый старый. Что осталось сверх всех счетов, это аванс, он сам ложится
 * в следующий выставленный счёт. Долг на начало (до Kubik) самый старый, его оплаты
 * гасят первым; аванс на начало просто добавляется к оплаченному.
 * Раскладку не храним, а считаем здесь каждый раз:
 * правка суммы счёта и удаление оплаты сами всё перераскладывают.
 *
 * Считаем в тыйынах (целых), чтобы копейки не расползались при сложении.
 */
class ClientLedger
{
    /** Долг старше этого числа дней после срока оплаты считается сильно просроченным. */
    public const OVERDUE_DAYS = 30;

    /**
     * Баланс по каждому клиенту из списка. Клиенты без счетов и оплат тоже есть, с нулями.
     *
     * @param  iterable<int>  $clientIds
     * @return array<int, ClientBalance> по client_id
     */
    public function forClients(iterable $clientIds): array
    {
        $ids = collect($clientIds)->map(fn ($id) => (int) $id)->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $invoices = ClientInvoice::query()
            ->whereIn('client_id', $ids)
            ->whereNotNull('number')
            ->orderBy('period')
            ->orderBy('number')
            ->get(['id', 'client_id', 'period', 'number', 'total', 'due_on'])
            ->groupBy('client_id');

        $paid = ClientPayment::query()
            ->whereIn('client_id', $ids)
            ->groupBy('client_id')
            ->selectRaw('client_id, sum(amount) as paid')
            ->pluck('paid', 'client_id');

        $openings = ClientOpeningBalance::query()->whereIn('client_id', $ids)->get()->keyBy('client_id');

        $overdueBefore = ClientInvoices::today()->subDays(self::OVERDUE_DAYS);
        $result        = [];

        foreach ($ids as $id) {
            $result[$id] = $this->spread(
                $invoices->get($id, collect()),
                self::cents($paid->get($id, 0)),
                $openings->get($id),
                $overdueBefore,
            );
        }

        return $result;
    }

    /**
     * Клиенты, у которых есть выставленный счёт, оплата или долг на начало. С $viewer только
     * те, кого он видит. Нужны для дебиторки: долг ушедшего клиента тоже долг.
     *
     * @return Collection<int, int>
     */
    public function clientsWithMoney(?Employee $viewer): Collection
    {
        $ids = ClientInvoice::query()->whereNotNull('number')->distinct()->pluck('client_id')
            ->merge(ClientPayment::query()->distinct()->pluck('client_id'))
            ->merge(ClientOpeningBalance::query()->pluck('client_id'))
            ->unique()
            ->values();

        if ($ids->isEmpty() || !$viewer) {
            return $ids;
        }

        return Client::query()->whereIn('id', $ids)->visibleTo($viewer)->pluck('id');
    }

    /** Сколько денег пришло за месяц (по дате оплаты) от этих клиентов. */
    public function receivedIn(CarbonImmutable $period, Collection $clientIds): float
    {
        if ($clientIds->isEmpty()) {
            return 0.0;
        }

        return (float) ClientPayment::query()
            ->whereIn('client_id', $clientIds)
            ->whereBetween('paid_on', [$period->toDateString(), $period->endOfMonth()->toDateString()])
            ->sum('amount');
    }

    public function forClient(Client $client): ClientBalance
    {
        return $this->forClients([$client->id])[$client->id];
    }

    public static function cents(float|string|null $value): int
    {
        return (int) round(((float) $value) * 100);
    }

    /** Разложить оплаченное: сначала долг на начало, потом счета от старого к новому. */
    private function spread(Collection $invoices, int $paid, ?ClientOpeningBalance $opening, $overdueBefore): ClientBalance
    {
        $start = $opening ? self::cents($opening->amount) : 0;
        // Аванс на начало это те же деньги клиента, только пришедшие до Kubik.
        $left    = $paid + max(0, -$start);
        $covered = [];
        $open    = [];
        $debt    = 0;
        $overdue = 0;
        $startPaid = 0;

        if ($start > 0) {
            $startPaid = min($start, $left);
            $left     -= $startPaid;
            $rest      = $start - $startPaid;

            if ($rest > 0) {
                $debt  += $rest;
                $open[] = ['label' => 'долг на начало', 'debt' => $rest];

                if ($opening->as_of->lt($overdueBefore)) {
                    $overdue += $rest;
                }
            }
        }

        foreach ($invoices as $invoice) {
            $total = self::cents($invoice->total);
            $take  = min($total, $left);
            $left -= $take;

            $covered[$invoice->id] = $take;
            $rest = $total - $take;

            if ($rest > 0) {
                $debt  += $rest;
                $open[] = [
                    'label' => ClientInvoices::monthName($invoice->period) . ' ' . $invoice->period->year,
                    'debt'  => $rest,
                ];

                if ($invoice->due_on && $invoice->due_on->lt($overdueBefore)) {
                    $overdue += $rest;
                }
            }
        }

        return new ClientBalance($covered, $open, $debt, $left, $overdue, $opening, $startPaid);
    }
}
