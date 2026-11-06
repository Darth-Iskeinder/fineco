<?php

namespace App\Services\Settlements;

use App\Models\ClientInvoice;
use Carbon\CarbonImmutable;

/**
 * Окупаемость клиентов за месяц для страницы «Руководитель» (договорились 06.11.2026).
 *
 * Деньги: выставленный счёт за месяц, вместе с доп. услугами. Оплачен он или нет, не важно.
 * Часы: таймеры задач клиента этого месяца (по смете, внеплановые, переданные). Их
 * считает дашборд, сюда они приходят готовыми, чтобы два экрана не расходились.
 * Долг: на сегодня по всем счетам, от выбранного месяца не зависит.
 *
 * Сом за час не считаем, если таймер был меньше чем у половины начатых задач:
 * часов тогда сильно меньше правды, и клиент выглядел бы выгоднее, чем есть.
 * Сравниваем со средним по фирме, своей стоимости часа пока нет.
 */
class ClientPayback
{
    /** Ниже этой доли задач с таймером сом за час не показываем. */
    public const MIN_TIMED_SHARE = 50;

    public function __construct(private readonly ClientLedger $ledger = new ClientLedger())
    {
    }

    /**
     * @param  array<int, array{name: string, elapsed: int, total: int, pending: int, with_time: int}>  $companies
     *         разрез дашборда по компаниям за этот месяц, ключ client_id (0 = без компании)
     * @return array{summary: array, rated: list<array>, weak: list<array>, unbilled: list<array>}
     */
    public function build(array $companies, CarbonImmutable $period): array
    {
        unset($companies[0]);

        $invoices = ClientInvoice::query()
            ->with('client:id,name')
            ->where('period', $period->startOfMonth()->toDateString())
            ->whereNotNull('number')
            ->get(['id', 'client_id', 'total'])
            ->keyBy('client_id');

        // Дебиторка как на странице расчётов: с ушедшими клиентами, которых в таблице нет
        $moneyIds = $this->ledger->clientsWithMoney(null);
        $balances = $this->ledger->forClients(
            $moneyIds->merge(array_keys($companies))->merge($invoices->keys())->unique()
        );

        $rows = [];
        foreach (array_unique([...array_keys($companies), ...$invoices->keys()->all()]) as $id) {
            $c       = $companies[$id] ?? null;
            $invoice = $invoices->get($id);
            $balance = $balances[$id] ?? null;

            $elapsed = (int) ($c['elapsed'] ?? 0);
            $started = $c ? $c['total'] - $c['pending'] : 0;
            $timed   = min($started, (int) ($c['with_time'] ?? 0));
            $sum     = $invoice ? (float) $invoice->total : null;
            $debt    = $balance?->debt() ?? 0.0;

            // Нечего сказать: ни счёта, ни работы, ни долга
            if ($sum === null && $elapsed === 0 && $debt == 0.0) {
                continue;
            }

            $share = $started > 0 ? (int) round($timed / $started * 100) : null;

            $rows[] = [
                'name'    => $c['name'] ?? $invoice->client?->name ?? '—',
                'sum'     => $sum,
                'debt'    => $debt,
                'overdue' => $balance?->overdue() ?? 0.0,
                'elapsed' => $elapsed,
                'time'    => self::duration($elapsed),
                'started' => $started,
                'timed'   => $timed,
                'share'   => $share,
                'rate'    => ($sum !== null && $elapsed > 0 && $share !== null && $share >= self::MIN_TIMED_SHARE)
                    ? (int) round($sum / ($elapsed / 3600))
                    : null,
            ];
        }

        $rated    = array_values(array_filter($rows, fn ($r) => $r['rate'] !== null));
        $weak     = array_values(array_filter($rows, fn ($r) => $r['rate'] === null && $r['sum'] !== null));
        $unbilled = array_values(array_filter($rows, fn ($r) => $r['sum'] === null));

        $ratedSeconds = array_sum(array_column($rated, 'elapsed'));
        $avg = $ratedSeconds > 0
            ? (int) round(array_sum(array_column($rated, 'sum')) / ($ratedSeconds / 3600))
            : null;

        foreach ($rated as &$r) {
            $r['tier'] = match (true) {
                $avg === null         => 'above',
                $r['rate'] < $avg / 2 => 'worst',
                $r['rate'] < $avg     => 'below',
                default               => 'above',
            };
        }
        unset($r);

        usort($rated, fn ($x, $y) => [$x['rate'], $x['name']] <=> [$y['rate'], $y['name']]);
        $byName = fn ($x, $y) => strcmp($x['name'], $y['name']);
        usort($weak, $byName);
        usort($unbilled, $byName);

        $debtAll = 0.0;
        $overdueAll = 0.0;
        $debtors = 0;
        foreach ($moneyIds->merge(array_keys($companies))->unique() as $id) {
            $b = $balances[$id] ?? null;
            if ($b && $b->debt() > 0) {
                $debtAll    += $b->debt();
                $overdueAll += $b->overdue();
                $debtors++;
            }
        }

        $started = array_sum(array_column($rows, 'started'));
        $timed   = array_sum(array_column($rows, 'timed'));
        $seconds = array_sum(array_column($rows, 'elapsed'));

        return [
            'summary' => [
                'billed'       => (float) $invoices->sum('total'),
                'billedCount'  => $invoices->count(),
                'debt'         => $debtAll,
                'debtors'      => $debtors,
                'overdue'      => $overdueAll,
                'time'         => self::duration($seconds),
                'avg'          => $avg,
                'ratedCount'   => count($rated),
                'timedShare'   => $started > 0 ? (int) round($timed / $started * 100) : null,
                'max'          => max([$avg ?? 0, ...array_column($rated, 'rate')]) * 1.08,
            ],
            'rated'    => $rated,
            'weak'     => $weak,
            'unbilled' => $unbilled,
        ];
    }

    /** «16ч 50м», как остальное время на дашборде. */
    public static function duration(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);

        return $seconds === 0 ? '—' : ($h > 0 ? $h . 'ч ' . $m . 'м' : $m . 'м');
    }
}
