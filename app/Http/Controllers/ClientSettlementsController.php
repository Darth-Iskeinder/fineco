<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientInvoice;
use App\Models\ClientOpeningBalance;
use App\Models\ClientPayment;
use App\Models\ClientSettlementComment;
use App\Services\AutoAudit\AutoAuditClientBoard;
use App\Services\Settlements\ClientInvoices;
use App\Services\Settlements\ClientLedger;
use App\Services\Settlements\SettlementsAccess;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Раздел «Расчёты с клиентами»: счета клиентам за месяц и оплаты.
 *
 * Оплата вносится на клиента и раскладывается по счетам от старого к новому,
 * лишнее остаётся авансом (ClientLedger).
 *
 * Кто видит раздел и каких клиентов, см. SettlementsAccess. Остальным пункта в меню нет,
 * а страницы отвечают 404. Чужой клиент тоже 404.
 *
 * Список всегда за текущий месяц. Прошлые месяцы открываются из истории на странице счёта.
 */
class ClientSettlementsController extends Controller
{
    public function __construct(
        private readonly ClientInvoices $invoices,
        private readonly ClientLedger $ledger,
    ) {
    }

    /** Виден ли раздел текущему сотруднику, см. SettlementsAccess. */
    public static function visible(): bool
    {
        return SettlementsAccess::canUse(auth('employee')->user());
    }

    public function index(): View
    {
        $this->allowedOnly();

        $viewer = auth('employee')->user();
        $period = ClientInvoices::currentPeriod();
        $rows   = $this->invoices->rows($period, $viewer);
        $issued = $rows->filter(fn (array $r) => $r['invoice']?->isIssued());

        // Дебиторка по всем, у кого были деньги, а не только по строкам списка:
        // долг ушедшего клиента тоже долг.
        $withMoney = $this->ledger->clientsWithMoney($viewer);
        $balances  = $this->ledger->forClients($withMoney->merge($rows->map(fn (array $r) => $r['client']->id)));
        $debtors   = $withMoney->map(fn (int $id) => $balances[$id]);
        $issuedSum = $issued->sum(fn (array $r) => (float) $r['invoice']->total);
        $received  = $this->ledger->receivedIn($period, $withMoney);
        $comments  = ClientSettlementComment::query()->with('author:id,full_name')
            ->whereIn('client_id', $rows->map(fn (array $r) => $r['client']->id))
            ->get()->keyBy('client_id');

        return view('settlements.index', [
            'period'       => $period,
            'month'        => ClientInvoices::monthName($period),
            'rows'         => $rows,
            'balances'     => $balances,
            'issuedCount'  => $issued->count(),
            'issuedSum'    => $issuedSum,
            'received'     => $received,
            'receivedPct'  => $issuedSum > 0 ? (int) round($received / $issuedSum * 100) : null,
            'debt'         => $debtors->sum(fn ($b) => $b->debt()),
            'overdue'      => $debtors->sum(fn ($b) => $b->overdue()),
            'overdueCount' => $debtors->filter(fn ($b) => $b->overdue() > 0)->count(),
            'comments'     => $comments,
            'bulkLines'    => $this->bulkLines($rows, $comments),
        ]);
    }

    public function show(Request $request, Client $client): View
    {
        $this->allowedOnly($client);

        $period = $this->period($request);

        return view('settlements.show', [
            'client'   => $client->load('responsibleEmployee:id,full_name'),
            'period'   => $period,
            'month'    => ClientInvoices::monthName($period),
            'invoice'  => $this->invoices->forMonth($client, $period),
            'hint'     => $this->invoices->hintFor($client, $period),
            'history'  => $history = $this->invoices->history($client),
            'missed'   => $this->invoices->missedMonths($history),
            'balance'  => $this->ledger->forClient($client),
            'comment'  => ClientSettlementComment::query()->with('author:id,full_name')->where('client_id', $client->id)->first(),
            'payments' => ClientPayment::query()->with('author:id,full_name')
                ->where('client_id', $client->id)->orderByDesc('paid_on')->orderByDesc('id')->get(),
            'today'    => ClientInvoices::today(),
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

    /**
     * «Выставить всем» за текущий месяц. Из окна приходят отмеченные клиенты и их суммы.
     *
     * Сервер перепроверяет всё сам: клиент должен быть в списке этого сотрудника и ещё
     * без счёта. Чужие и лишние id молча отбрасываем. Хоть одна непонятная сумма у
     * отмеченного клиента, и не выставляем ничего: лучше переспросить, чем выставить
     * половину пачки.
     */
    public function bulk(Request $request): RedirectResponse
    {
        $this->allowedOnly();

        $period = ClientInvoices::currentPeriod();
        $back   = redirect()->route('settlements.index');

        // Кого можно выставить: строки списка этого сотрудника. Идём по ним, а не по
        // форме: чужие и лишние id так отбрасываются сами, а номера идут по алфавиту (список отсортирован базой).
        $rows  = $this->invoices->rows($period, auth('employee')->user());
        $input = (array) $request->input('bulk', []);

        $amounts = [];
        $already = 0;

        foreach ($rows as $row) {
            $client = $row['client'];
            $line   = $input[$client->id] ?? null;

            if (!is_array($line) || empty($line['on'])) {
                continue;
            }

            if ($row['invoice']?->isIssued()) {
                $already++;
                continue;
            }

            try {
                $amount = $this->parseMoney($line['amount'] ?? null, 'bulk');
            } catch (ValidationException) {
                throw ValidationException::withMessages(['bulk' => $client->name . ': сумма записана непонятно. Ничего не выставлено.']);
            }

            if ($amount > 0) {
                $amounts[$client->id] = $amount;
            }
        }

        if ($amounts === []) {
            return $back->withErrors(['bulk' => $already
                ? 'Этим клиентам счёт уже выставили, пока окно было открыто'
                : 'Не отмечено ни одного клиента с суммой']);
        }

        $result  = $this->invoices->issueMany($period, $amounts, auth('employee')->user());
        $issued  = $result['issued'];
        $already += $result['already'];

        $message = 'Выставлено ' . ($issued->count() ? $this->issuedSummary($issued) : 'счетов: 0');
        if ($already) {
            $message .= '. Пропущено: ' . $already . ', им счёт уже выставили, пока окно было открыто';
        }

        return $back->with('success', $message);
    }

    /**
     * Строки окна «Выставить всем»: клиенты без выставленного счёта. Сумма из черновика,
     * иначе из прошлого счёта; галочка у всех, у кого сумма есть. После ошибки окно
     * открывается снова с тем, что человек уже вписал и отметил.
     *
     * @return array<int, array{id: int, name: string, hint: string, was: ?float, amount: string, extra: float, on: bool}>
     */
    private function bulkLines(Collection $rows, Collection $comments): array
    {
        $old = old('bulk');

        return $rows->reject(fn (array $r) => $r['invoice']?->isIssued())->map(function (array $r) use ($old, $comments) {
            $draft  = $r['invoice'];
            $amount = $draft?->amount ?? $r['suggested'];
            $extra  = $draft ? round((float) $draft->total - (float) $draft->amount, 2) : 0.0;
            $id     = $r['client']->id;

            $line = [
                'id'     => $id,
                'name'   => $r['client']->name,
                'hint'   => $draft?->amount !== null ? 'из черновика' : ($r['suggested'] === null ? 'новый клиент' : $r['hint']),
                'was'    => $r['suggested'] !== null ? (float) $r['suggested'] : null,
                'amount' => $amount !== null ? ClientInvoice::money($amount) : '',
                'extra'  => $extra,
                'on'     => (float) $amount > 0,
                // Комментарий в окне: чтобы не выставить тому, с кем договорились иначе
                'comment' => $comments->get($id)?->body ?? '',
            ];

            if (is_array($old)) {
                $line['amount'] = (string) ($old[$id]['amount'] ?? $line['amount']);
                $line['on']     = !empty($old[$id]['on']);
            }

            return $line;
        })->values()->all();
    }

    /** «35 счетов, № 101–135». Если номера не подряд (выставляли параллельно), только число. */
    private function issuedSummary(Collection $issued): string
    {
        $count = $issued->count();
        $words = AutoAuditClientBoard::count($count, ['счёт', 'счёта', 'счетов']);
        $min   = $issued->min('number');
        $max   = $issued->max('number');

        if ($count === 1) {
            return $words . ', № ' . $min;
        }

        return $max - $min + 1 === $count ? $words . ', № ' . $min . '–' . $max : $words;
    }

    public function unissue(Request $request, Client $client): RedirectResponse
    {
        $this->allowedOnly($client);

        $period  = $this->period($request);
        $invoice = $this->invoices->forMonth($client, $period);
        $back    = redirect()->route('settlements.show', [$client, 'month' => $period->format('Y-m')]);

        if (!$invoice->exists || !$invoice->isIssued()) {
            return $back;
        }

        // На счёт уже легли деньги: отмена переложила бы их молча в другой счёт.
        if ($this->ledger->forClient($client)->paidFor($invoice) > 0) {
            return $back->withErrors(['invoice' => 'На этот счёт уже легла оплата, отменить его нельзя. Поправьте сумму: лишнее станет авансом.']);
        }

        $this->invoices->unissue($invoice);

        return $back->with('success', 'Счёт снова не выставлен');
    }

    /** Принять оплату от клиента. По счетам она раскладывается сама, от старого к новому. */
    public function storePayment(Request $request, Client $client): RedirectResponse
    {
        $this->allowedOnly($client);

        $data = $request->validate([
            'paid_on' => ['required', 'date_format:Y-m-d', 'after_or_equal:2020-01-01', 'before_or_equal:' . ClientInvoices::today()->toDateString()],
            'method'  => ['required', 'in:' . implode(',', array_keys(ClientPayment::METHODS))],
        ], [
            'paid_on.required'        => 'Укажите дату оплаты',
            'paid_on.before_or_equal' => 'Дата оплаты не может быть в будущем',
            'paid_on.*'               => 'Дата оплаты записана непонятно',
            'method.*'                => 'Выберите: банк или касса',
        ]);

        // Своё имя поля: у счёта на той же странице есть «amount», и после ошибки
        // в оплате old('amount') подставился бы в сумму счёта.
        $amount = $this->parseMoney($request->input('payment_amount'), 'payment_amount');

        if (!($amount > 0)) {
            throw ValidationException::withMessages(['payment_amount' => 'Впишите сумму оплаты']);
        }

        ClientPayment::create([
            'client_id'  => $client->id,
            'paid_on'    => $data['paid_on'],
            'amount'     => $amount,
            'method'     => $data['method'],
            'created_by' => auth('employee')->id(),
        ]);

        return redirect()->route('settlements.show', [$client, 'month' => $this->period($request)->format('Y-m')])
            ->with('success', 'Оплата принята: ' . ClientInvoice::money($amount) . ' сом');
    }

    /**
     * Долг или аванс клиента на начало работы в Kubik, одной суммой с датой.
     * Пустая сумма убирает его. Оплаты по нему перераскладываются сами.
     */
    public function saveOpening(Request $request, Client $client): RedirectResponse
    {
        $this->allowedOnly($client);

        $data = $request->validate([
            'opening_kind'  => ['required', 'in:debt,advance'],
            'opening_as_of' => ['required', 'date_format:Y-m-d', 'after_or_equal:2020-01-01', 'before_or_equal:' . ClientInvoices::today()->toDateString()],
        ], [
            'opening_kind.*'                => 'Выберите: долг или аванс',
            'opening_as_of.required'        => 'Укажите, на какую дату',
            'opening_as_of.before_or_equal' => 'Дата не может быть в будущем',
            'opening_as_of.*'               => 'Дата записана непонятно',
        ]);

        $amount = $this->parseMoney($request->input('opening_amount'), 'opening_amount');
        $back   = redirect()->route('settlements.show', [$client, 'month' => $this->period($request)->format('Y-m')]);

        if (!($amount > 0)) {
            ClientOpeningBalance::query()->where('client_id', $client->id)->delete();

            return $back->with('success', 'Долг на начало убран');
        }

        ClientOpeningBalance::query()->updateOrCreate(['client_id' => $client->id], [
            'amount'     => $data['opening_kind'] === 'advance' ? -$amount : $amount,
            'as_of'      => $data['opening_as_of'],
            'created_by' => auth('employee')->id(),
        ]);

        return $back->with('success', ($data['opening_kind'] === 'advance' ? 'Аванс' : 'Долг') . ' на начало сохранён');
    }

    /**
     * Комментарий про деньги клиента. Из списка приходит запросом и ждёт JSON,
     * со страницы клиента обычной формой. Пустой текст убирает комментарий.
     */
    public function saveComment(Request $request, Client $client): JsonResponse|RedirectResponse
    {
        $this->allowedOnly($client);

        $data = $request->validate([
            'comment' => ['nullable', 'string', 'max:' . ClientSettlementComment::MAX_LENGTH],
        ], [
            'comment.max' => 'Комментарий длиннее ' . ClientSettlementComment::MAX_LENGTH . ' знаков, сократите его',
            'comment.*'   => 'Комментарий записан непонятно',
        ]);

        $body    = trim((string) ($data['comment'] ?? ''));
        $comment = ClientSettlementComment::saveFor($client, $body, auth('employee')->user());

        if ($request->expectsJson()) {
            return response()->json([
                'comment'   => $comment?->body ?? '',
                'signature' => $comment?->signature() ?? '',
            ]);
        }

        return redirect()->route('settlements.show', [$client, 'month' => $this->period($request)->format('Y-m')])
            ->with('success', $comment ? 'Комментарий сохранён' : 'Комментарий убран');
    }

    /** Удалить ошибочную оплату. Остальные сами перераскладываются по счетам. */
    public function destroyPayment(Request $request, Client $client, ClientPayment $payment): RedirectResponse
    {
        $this->allowedOnly($client);
        abort_unless($payment->client_id === $client->id, 404);

        $payment->delete();

        return redirect()->route('settlements.show', [$client, 'month' => $this->period($request)->format('Y-m')])
            ->with('success', 'Оплата удалена');
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
    private function parseMoney(mixed $value, string $field = 'amount'): ?float
    {
        $clean = str_replace([' ', "\u{00A0}", "\u{202F}", ','], ['', '', '', '.'], trim((string) $value));

        if ($clean === '') {
            return null;
        }

        if (!is_numeric($clean) || (float) $clean < 0 || (float) $clean >= 1e10) {
            throw ValidationException::withMessages([$field => 'Сумма записана непонятно: ' . $value]);
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
