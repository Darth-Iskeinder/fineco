<?php

namespace App\Services\Settlements;

use App\Models\Client;
use App\Models\ClientInvoice;
use App\Models\Employee;
use App\Models\Tenant;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Счета клиентам за месяц: кто в списке, откуда сумма, выставление и отмена.
 *
 * Договорились (прототип 02.11.2026):
 *   - начисление это сам счёт, смету и тарифы не трогаем;
 *   - сумма нового счёта берётся из последнего выставленного, первую вписывают руками;
 *   - дополнительные услуги в следующий месяц не переходят;
 *   - номер сквозной по фирме, даётся при выставлении.
 */
class ClientInvoices
{
    /** Первое число текущего месяца по времени Бишкека. */
    public static function currentPeriod(): CarbonImmutable
    {
        return self::today()->startOfMonth();
    }

    public static function today(): CarbonImmutable
    {
        return CarbonImmutable::now(config('app.display_timezone'))->startOfDay();
    }

    /** «ноябрь» */
    public static function monthName(CarbonImmutable $period): string
    {
        return $period->locale('ru')->isoFormat('MMMM');
    }

    /**
     * Строки списка за месяц: клиент, его счёт (или null) и подсказка суммы.
     * В списке действующие клиенты, начавшие обслуживаться не позже этого месяца,
     * и все, кому за этот месяц уже есть счёт. С $viewer только те, кого он видит.
     *
     * @return Collection<int, array{client: Client, invoice: ?ClientInvoice, suggested: ?string, hint: string}>
     */
    public function rows(CarbonImmutable $period, ?Employee $viewer = null): Collection
    {
        $invoices = ClientInvoice::query()->where('period', $period->toDateString())->get()->keyBy('client_id');

        $clients = Client::query()
            ->with('responsibleEmployee:id,full_name')
            // Главбух с доступом к модулю видит только своих клиентов, как в «Клиентах».
            ->when($viewer, fn ($q) => $q->visibleTo($viewer))
            ->where(function ($q) use ($period, $invoices) {
                $q->where(function ($active) use ($period) {
                    $active->active()->where(function ($start) use ($period) {
                        $start->whereNull('service_start_date')
                            ->orWhere('service_start_date', '<=', $period->endOfMonth()->toDateString());
                    });
                })->orWhereIn('id', $invoices->keys());
            })
            ->orderBy('name')
            ->get();

        $previous = $this->lastIssuedBefore($clients->pluck('id'), $period);

        return $clients->map(function (Client $client) use ($invoices, $previous) {
            $hint = $this->hint($previous->get($client->id));

            return [
                'client'    => $client,
                'invoice'   => $invoices->get($client->id),
                'suggested' => $previous->get($client->id)?->amount,
                'hint'      => $hint,
            ];
        })
            // Невыставленные наверх: с ними и надо работать.
            ->sortBy(fn (array $row) => $row['invoice']?->isIssued() ? 1 : 0, SORT_REGULAR)
            ->values();
    }

    /** Счёт клиента за месяц из базы или новый несохранённый черновик с подсказанной суммой. */
    public function forMonth(Client $client, CarbonImmutable $period): ClientInvoice
    {
        $invoice = ClientInvoice::query()
            ->with('extras')
            ->where('client_id', $client->id)
            ->where('period', $period->toDateString())
            ->first();

        if ($invoice) {
            return $invoice;
        }

        $previous = $this->lastIssuedBefore(collect([$client->id]), $period)->get($client->id);

        $draft = new ClientInvoice([
            'client_id' => $client->id,
            'period'    => $period,
            'amount'    => $previous?->amount,
            'total'     => $previous?->amount ?? 0,
        ]);
        $draft->setRelation('extras', collect());

        return $draft;
    }

    /** Подсказка, откуда сумма в черновике. */
    public function hintFor(Client $client, CarbonImmutable $period): string
    {
        return $this->hint($this->lastIssuedBefore(collect([$client->id]), $period)->get($client->id));
    }

    /** Все выставленные счета клиента, новые сверху. */
    public function history(Client $client): Collection
    {
        return ClientInvoice::query()
            ->where('client_id', $client->id)
            ->whereNotNull('number')
            ->orderByDesc('period')
            ->get();
    }

    /**
     * Сохранить счёт и, если просят, выставить.
     *
     * @param  array<int, array{name: string, amount: float}>  $extras
     */
    public function save(Client $client, CarbonImmutable $period, ?float $amount, array $extras, bool $issue, ?Employee $by): ClientInvoice
    {
        return DB::transaction(function () use ($client, $period, $amount, $extras, $issue, $by) {
            $invoice = ClientInvoice::query()
                ->where('client_id', $client->id)
                ->where('period', $period->toDateString())
                ->lockForUpdate()
                ->first()
                ?? new ClientInvoice(['client_id' => $client->id, 'period' => $period]);

            $invoice->amount = $amount;
            $invoice->total  = round(($amount ?? 0) + array_sum(array_column($extras, 'amount')), 2);

            if ($issue && !$invoice->isIssued()) {
                $today = self::today();

                $invoice->number    = $this->nextNumber();
                $invoice->issued_on = $today;
                $invoice->due_on    = $today->addDays(ClientInvoice::DUE_DAYS);
                $invoice->issued_by = $by?->id;
            }

            $invoice->save();

            $invoice->extras()->delete();
            foreach (array_values($extras) as $i => $extra) {
                $invoice->extras()->create(['name' => $extra['name'], 'amount' => $extra['amount'], 'sort' => $i]);
            }

            return $invoice->load('extras');
        });
    }

    /** Вернуть счёт в черновик. Номер освобождается. */
    public function unissue(ClientInvoice $invoice): void
    {
        $invoice->update(['number' => null, 'issued_on' => null, 'due_on' => null, 'issued_by' => null]);
    }

    /**
     * Следующий номер в фирме. Строку фирмы держим до конца транзакции: два человека,
     * выставляющие счета одновременно, иначе получили бы один номер.
     */
    private function nextNumber(): int
    {
        Tenant::query()->whereKey(TenantContext::id())->lockForUpdate()->first();

        return (int) ClientInvoice::query()->max('number') + 1;
    }

    /**
     * Последний выставленный счёт до этого месяца у каждого клиента.
     *
     * @return Collection<int, ClientInvoice> по client_id
     */
    private function lastIssuedBefore(Collection $clientIds, CarbonImmutable $period): Collection
    {
        if ($clientIds->isEmpty()) {
            return collect();
        }

        $latest = ClientInvoice::query()
            ->whereIn('client_id', $clientIds)
            ->whereNotNull('number')
            ->where('period', '<', $period->toDateString())
            ->groupBy('client_id')
            ->selectRaw('client_id, max(period) as last_period')
            ->get();

        if ($latest->isEmpty()) {
            return collect();
        }

        return ClientInvoice::query()
            ->where(function ($q) use ($latest) {
                foreach ($latest as $row) {
                    $q->orWhere(fn ($one) => $one->where('client_id', $row->client_id)->where('period', $row->last_period));
                }
            })
            ->get()
            ->keyBy('client_id');
    }

    private function hint(?ClientInvoice $previous): string
    {
        return $previous
            ? 'из счёта за ' . self::monthName($previous->period)
            : 'новый клиент, впишите сумму один раз';
    }
}
