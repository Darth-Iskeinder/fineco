<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Tenant;
use App\Services\Settlements\ClientInvoices;
use App\Services\Settlements\SettlementsAccess;
use App\Support\Impersonation;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Раздел «Расчёты с клиентами», этап 1: счёт клиенту за месяц.
 *
 * Кто видит раздел и каких клиентов, см. SettlementsAccess. Остальным пункта в меню нет,
 * а страницы отвечают 404. Чужой клиент тоже 404.
 *
 * Список всегда за текущий месяц. Прошлые месяцы открываются из истории на странице счёта.
 */
class ClientSettlementsController extends Controller
{
    public function __construct(private readonly ClientInvoices $invoices)
    {
    }

    /** Виден ли раздел текущему сотруднику, см. SettlementsAccess. */
    public static function visible(): bool
    {
        return SettlementsAccess::canUse(auth('employee')->user());
    }

    public function index(): View
    {
        $this->allowedOnly();

        $period = ClientInvoices::currentPeriod();
        $rows   = $this->invoices->rows($period, auth('employee')->user());
        $issued = $rows->filter(fn (array $r) => $r['invoice']?->isIssued());

        return view('settlements.index', [
            'period'      => $period,
            'month'       => ClientInvoices::monthName($period),
            'rows'        => $rows,
            'issuedCount' => $issued->count(),
            'issuedSum'   => $issued->sum(fn (array $r) => (float) $r['invoice']->total),
            'vendor'      => Impersonation::isActive(),
            'openToManager' => (bool) Tenant::find(TenantContext::id())?->settlementsEnabled(),
        ]);
    }

    public function show(Request $request, Client $client): View
    {
        $this->allowedOnly($client);

        $period = $this->period($request);

        return view('settlements.show', [
            'client'  => $client->load('responsibleEmployee:id,full_name'),
            'period'  => $period,
            'month'   => ClientInvoices::monthName($period),
            'invoice' => $this->invoices->forMonth($client, $period),
            'hint'    => $this->invoices->hintFor($client, $period),
            'history' => $this->invoices->history($client),
            'current' => $period->equalTo(ClientInvoices::currentPeriod()),
        ]);
    }

    public function save(Request $request, Client $client): RedirectResponse
    {
        $this->allowedOnly($client);

        $period = $this->period($request);
        $issue  = $request->input('action') === 'issue';
        $amount = $this->parseMoney($request->input('amount'));
        $extras = $this->extras($request);

        if ($issue && !($amount > 0)) {
            throw ValidationException::withMessages(['amount' => 'Впишите сумму за месяц']);
        }

        $wasIssued = $this->invoices->forMonth($client, $period)->isIssued();
        $invoice   = $this->invoices->save($client, $period, $amount, $extras, $issue, auth('employee')->user());

        $message = $issue && !$wasIssued ? 'Счёт № ' . $invoice->number . ' выставлен' : 'Сохранено';

        return redirect()->route('settlements.show', [$client, 'month' => $period->format('Y-m')])->with('success', $message);
    }

    public function unissue(Request $request, Client $client): RedirectResponse
    {
        $this->allowedOnly($client);

        $period  = $this->period($request);
        $invoice = $this->invoices->forMonth($client, $period);

        if ($invoice->exists && $invoice->isIssued()) {
            $this->invoices->unissue($invoice);
        }

        return redirect()->route('settlements.show', [$client, 'month' => $period->format('Y-m')])
            ->with('success', 'Счёт снова не выставлен');
    }

    /** Нет доступа к разделу или клиент не свой: 404, как будто страницы нет. */
    private function allowedOnly(?Client $client = null): void
    {
        abort_unless(self::visible(), 404);

        if ($client) {
            abort_unless(SettlementsAccess::canSeeClient(auth('employee')->user(), $client), 404);
        }
    }

    /** Месяц из адреса, «2026-10». Будущие месяцы и мусор дают текущий. */
    private function period(Request $request): CarbonImmutable
    {
        $current = ClientInvoices::currentPeriod();
        $value   = (string) $request->input('month');

        if (preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value, $m)) {
            $period = CarbonImmutable::create((int) $m[1], (int) $m[2], 1);

            if ($period->lte($current) && $period->year >= 2020) {
                return $period;
            }
        }

        return $current;
    }

    /** «20 000», «20000,50», «20 000.5» → число. Пусто → null. */
    private function parseMoney(mixed $value): ?float
    {
        $clean = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], trim((string) $value));

        if ($clean === '') {
            return null;
        }

        if (!is_numeric($clean) || (float) $clean < 0 || (float) $clean >= 1e10) {
            throw ValidationException::withMessages(['amount' => 'Сумма записана непонятно: ' . $value]);
        }

        return round((float) $clean, 2);
    }

    /**
     * Дополнительные услуги из формы. Совсем пустые строки выбрасываем, у остальных
     * нужны и название, и сумма.
     *
     * @return array<int, array{name: string, amount: float}>
     */
    private function extras(Request $request): array
    {
        $result = [];

        foreach ((array) $request->input('extras', []) as $row) {
            $name   = trim((string) ($row['name'] ?? ''));
            $amount = trim((string) ($row['amount'] ?? ''));

            if ($name === '' && $amount === '') {
                continue;
            }

            $sum = $this->parseMoney($amount);

            if ($name === '' || !($sum > 0)) {
                throw ValidationException::withMessages(['extras' => 'У дополнительной услуги нужны название и сумма']);
            }

            $result[] = ['name' => mb_substr($name, 0, 255), 'amount' => $sum];
        }

        return $result;
    }
}
