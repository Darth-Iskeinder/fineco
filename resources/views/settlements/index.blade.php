@extends('layouts.app')
@section('title', 'Расчёты с клиентами')
@section('page-title', 'Расчёты с клиентами')

@section('content')
@php
    use App\Models\ClientInvoice as Invoice;
    use App\Services\AutoAudit\AutoAuditClientBoard as Board;
@endphp

@php
    $lateCount   = $rows->filter(fn ($r) => $balances[$r['client']->id]->overdue() > 0)->count();
    $bulkReopen  = $errors->has('bulk') && $bulkLines !== [];
    // Для фильтра и поиска на экране: состояние, просрочка и имя каждой строки.
    $rowMeta = $rows->map(fn ($r) => [
        'state' => $r['invoice']?->isIssued() ? 'yes' : 'no',
        'late'  => $balances[$r['client']->id]->overdue() > 0,
        'name'  => mb_strtolower($r['client']->name),
    ])->values();
@endphp

<div class="space-y-4" x-data="settlementsBoard(@js($bulkLines), @js($bulkReopen), @js($rowMeta))">

    @php
        $notIssued = $rows->count() - $issuedCount;
        $cards = [
            ['label' => 'Выставлено', 'value' => Invoice::money($issuedSum),
             'note' => Board::count($issuedCount, ['счёт', 'счёта', 'счетов']) . ' из ' . $rows->count(), 'tone' => 'text-slate-900'],
            ['label' => 'Пришло денег', 'value' => Invoice::money($received),
             'note' => $receivedPct === null ? 'по дате оплаты' : $receivedPct . '% от выставленного', 'tone' => 'text-emerald-700'],
            ['label' => 'Дебиторка', 'value' => Invoice::money($debt),
             'note' => 'долг клиентов за все месяцы', 'tone' => 'text-slate-900'],
            ['label' => 'Просрочено больше 30 дней', 'value' => Invoice::money($overdue),
             'note' => $overdueCount ? Board::count($overdueCount, ['клиент', 'клиента', 'клиентов']) : 'нет', 'tone' => $overdue > 0 ? 'text-red-700' : 'text-slate-900',
             'filter' => $lateCount ? 'late' : null],
            ['label' => 'Не выставлено', 'value' => (string) $notIssued,
             'note' => $notIssued ? 'показать, кому' : 'всем выставлено', 'tone' => $notIssued ? 'text-amber-700' : 'text-slate-900',
             'filter' => $notIssued ? 'no' : null],
        ];
    @endphp

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50">
        <div class="px-6 py-4 flex flex-wrap items-center justify-between gap-3">
            <h2 class="text-[15px] font-semibold text-slate-800">Счета за {{ $month }} {{ $period->year }}</h2>
            @if ($bulkLines !== [])
                <button type="button" @click="openBulk()" data-bulk-open
                        class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700">
                    Выставить за {{ $month }}: {{ count($bulkLines) }}
                </button>
            @endif
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-5 gap-px bg-slate-100 border-t border-slate-100 rounded-b-2xl overflow-hidden">
            @foreach ($cards as $card)
                @if (!empty($card['filter']))
                    {{-- Карточка включает фильтр списка под ней --}}
                    <button type="button" class="bg-white px-4 py-3 text-left hover:bg-slate-50" data-card="{{ $card['label'] }}"
                            @click="filter = @js($card['filter']); $refs.table.scrollIntoView({ behavior: 'smooth', block: 'start' })">
                @else
                    <div class="bg-white px-4 py-3" data-card="{{ $card['label'] }}">
                @endif
                    <div class="text-[13px] text-slate-500">{{ $card['label'] }}</div>
                    <div class="mt-1 text-2xl leading-none font-semibold tabular-nums {{ $card['tone'] }}">{{ $card['value'] }}</div>
                    <div class="mt-1 text-xs text-slate-400">{{ $card['note'] }}</div>
                @if (!empty($card['filter']))
                    </button>
                @else
                    </div>
                @endif
            @endforeach
        </div>
    </div>

    @php
        $filters = [
            'all'  => ['Все', $rows->count()],
            'no'   => ['Не выставлены', $notIssued],
            'yes'  => ['Выставлены', $issuedCount],
            'late' => ['Просрочены', $lateCount],
        ];
    @endphp

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden" x-ref="table">
        <div class="px-6 py-3 flex flex-wrap items-center justify-between gap-3 border-b border-slate-100">
            <div class="flex flex-wrap gap-2" role="group" aria-label="Фильтр">
                @foreach ($filters as $key => [$label, $count])
                    <button type="button" @click="filter = @js($key)" data-filter="{{ $key }}"
                            class="inline-block whitespace-nowrap rounded-full border px-3 py-1 text-[13px] font-medium"
                            :class="filter === @js($key) ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-200 text-slate-600'">{{ $label }} {{ $count }}</button>
                @endforeach
            </div>
            <input type="search" x-model="search" placeholder="Найти клиента" aria-label="Найти клиента"
                   class="w-full sm:w-72 rounded-lg border border-slate-200 px-3 py-1.5 text-sm focus:border-indigo-500 focus:ring-indigo-500">
        </div>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-left text-xs font-medium text-slate-500 whitespace-nowrap">
                        <th class="px-6 py-3">Клиент</th>
                        <th class="px-3 py-3">Ответственный</th>
                        <th class="px-3 py-3 text-right">Сумма, сом</th>
                        <th class="px-3 py-3 text-right">Долг</th>
                        <th class="px-3 py-3">Счёт за {{ $month }}</th>
                        <th class="px-3 py-3">Статус</th>
                        <th class="px-6 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        @php
                            $client  = $row['client'];
                            $invoice = $row['invoice'];
                            $issued  = $invoice?->isIssued();
                            $sum     = $invoice ? $invoice->total : $row['suggested'];
                            $balance = $balances[$client->id];
                            $status  = Invoice::STATUSES[$balance->statusFor($invoice)];
                            $url     = route('settlements.show', $client);
                        @endphp
                        <tr class="hover:bg-slate-50" data-client="{{ $client->id }}"
                            x-show="rowShown(rowMeta[{{ $loop->index }}])">
                            <td class="px-6 py-3">
                                <a href="{{ $url }}" class="font-medium text-slate-900 hover:text-indigo-700">{{ $client->name }}</a>
                            </td>
                            <td class="px-3 py-3 text-slate-500">{{ $client->responsibleEmployee?->full_name ?? 'не назначен' }}</td>
                            <td class="px-3 py-3 text-right tabular-nums whitespace-nowrap">
                                @if ($issued)
                                    <span class="font-semibold text-slate-900">{{ Invoice::money($sum) }}</span>
                                @elseif ((float) $sum > 0)
                                    <span class="text-slate-500">{{ Invoice::money($sum) }}</span>
                                @else
                                    <span class="font-medium text-amber-700">впишите</span>
                                @endif
                            </td>
                            <td @class(['px-3 py-3 text-right tabular-nums whitespace-nowrap', 'font-semibold text-red-700' => $balance->debt() > 0, 'text-slate-400' => $balance->debt() <= 0])>
                                @if ($balance->advance() > 0)
                                    <span class="font-normal text-emerald-700" title="Аванс">+{{ Invoice::money($balance->advance()) }}</span>
                                @else
                                    {{ Invoice::money($balance->debt()) }}
                                @endif
                            </td>
                            <td class="px-3 py-3 text-[13px]">
                                @if ($issued)
                                    <span class="text-slate-600">{{ $invoice->title() }}</span>
                                @else
                                    <span class="text-slate-400">{{ $row['suggested'] === null ? 'новый клиент' : $row['hint'] }}</span>
                                @endif
                            </td>
                            <td class="px-3 py-3">
                                <span class="inline-block whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $status['class'] }}">{{ $status['label'] }}</span>
                            </td>
                            <td class="px-6 py-3 text-right">
                                @if ($issued)
                                    <a href="{{ $url }}" class="inline-flex items-center rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] font-medium text-slate-700 hover:bg-slate-50">Открыть</a>
                                @else
                                    <a href="{{ $url }}" class="inline-flex items-center rounded-lg bg-indigo-600 px-3 py-1.5 text-[13px] font-medium text-white hover:bg-indigo-700">Выставить</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-10 text-center text-slate-500">Действующих клиентов нет</td>
                        </tr>
                    @endforelse
                    @if ($rows->isNotEmpty())
                        <tr x-show="!anyRowShown()" style="display: none;">
                            <td colspan="7" class="px-6 py-10 text-center text-slate-500">В этом списке никого нет</td>
                        </tr>
                    @endif
                </tbody>
            </table>
        </div>
    </div>

    @if ($bulkLines !== [])
        {{-- Окно «Выставить всем». Ничего не выставляется, пока не нажали кнопку внизу. --}}
        <div x-show="bulkOpen" class="fixed inset-0 z-50 overflow-y-auto" style="display: none;" @keydown.escape.window="bulkOpen = false">
            <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm" @click="bulkOpen = false"></div>
            <div class="flex min-h-full items-center justify-center p-4">
                <form method="POST" action="{{ route('settlements.bulk') }}" @submit="sending = true"
                      class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl overflow-hidden" role="dialog" aria-modal="true" aria-label="Выставить счета">
                    @csrf
                    <div class="flex items-start justify-between gap-3 px-6 pt-5 pb-3">
                        <div>
                            <h3 class="text-lg font-semibold text-slate-800">Выставить счета за {{ $month }}</h3>
                            <p class="text-sm text-slate-500">Суммы взяты из прошлого счёта, их можно поправить. Снимите галочку, если этому клиенту счёт пока не нужен.</p>
                        </div>
                        <button @click="bulkOpen = false" type="button" aria-label="Закрыть" class="p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    {{-- Имя у поля суммы только у отмеченной строки: в запрос уходят одни они.
                         Иначе на сотнях клиентов форма упрётся в max_input_vars PHP (1000),
                         и хвост строк молча пропадёт. --}}
                    <div class="max-h-[55vh] overflow-y-auto border-y border-slate-100">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-100 text-left text-xs font-medium text-slate-500 whitespace-nowrap">
                                    <th class="pl-6 pr-2 py-2.5">
                                        <input type="checkbox" aria-label="Отметить всех" :checked="allChecked()" @change="checkAll($event.target.checked)"
                                               class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                    </th>
                                    <th class="px-3 py-2.5">Клиент</th>
                                    <th class="px-3 py-2.5">Откуда сумма</th>
                                    <th class="pl-3 pr-6 py-2.5 text-right">Сумма, сом</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                <template x-for="line in lines" :key="line.id">
                                    <tr :data-bulk-line="line.id">
                                        <td class="pl-6 pr-2 py-2 align-top">
                                            <input type="checkbox" value="1" :name="`bulk[${line.id}][on]`" x-model="line.on" :aria-label="'Выставить счёт: ' + line.name"
                                                   class="mt-1.5 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500">
                                        </td>
                                        <td class="px-3 py-2 align-top">
                                            <div class="pt-1 font-medium text-slate-900" :class="line.on ? '' : 'text-slate-400'" x-text="line.name"></div>
                                            <div class="text-xs text-slate-500" x-show="line.extra > 0" x-text="'+ дополнительные услуги ' + money(line.extra)"></div>
                                        </td>
                                        <td class="px-3 py-2 align-top">
                                            <div class="pt-1 text-[13px] text-slate-500" x-text="line.hint"></div>
                                        </td>
                                        <td class="pl-3 pr-6 py-2 text-right align-top">
                                            <input type="text" inputmode="decimal" autocomplete="off" :name="line.on ? `bulk[${line.id}][amount]` : null" x-model="line.amount" @input="amountTyped(line)"
                                                   @change="line.amount = parse(line.amount) > 0 ? money(parse(line.amount)) : line.amount"
                                                   placeholder="впишите" :aria-label="'Сумма: ' + line.name"
                                                   class="w-32 rounded-lg border px-3 py-1.5 text-right text-sm tabular-nums focus:border-indigo-500 focus:ring-indigo-500"
                                                   :class="line.on && !(parse(line.amount) > 0) ? 'border-amber-300' : 'border-slate-200'">
                                            <div class="mt-0.5 text-xs text-amber-700" x-show="changed(line)" x-text="'было ' + money(line.was)"></div>
                                        </td>
                                    </tr>
                                </template>
                            </tbody>
                        </table>
                    </div>

                    <div class="flex flex-wrap items-center justify-end gap-3 px-6 py-4">
                        <div class="flex-1 min-w-0 text-sm text-slate-500">
                            <span x-text="'Итого ' + money(readyTotal()) + ' сом'"></span><span x-show="missingCount() > 0" class="text-amber-700" x-text="'. Без суммы: ' + missingCount() + ', их не выставим'"></span>
                        </div>
                        <button type="button" @click="bulkOpen = false" class="inline-flex items-center rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">Отмена</button>
                        <button type="submit" data-bulk-submit :disabled="readyCount() === 0 || sending"
                                class="inline-flex items-center rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50 disabled:cursor-not-allowed"
                                x-text="sending ? 'Выставляем…' : readyCount() === 0 ? 'Нечего выставлять' : 'Выставить ' + readyCount() + ' ' + plural(readyCount()) + ' на ' + money(readyTotal()) + ' сом'"></button>
                    </div>
                </form>
            </div>
        </div>
    @endif
</div>
@endsection

@push('scripts')
<script>
    /**
     * Список «Расчёты с клиентами»: фильтр, поиск и окно «Выставить всем».
     * Фильтр и поиск только прячут строки на экране, данные не перезапрашивают.
     */
    function settlementsBoard(lines, reopen, rowMeta) {
        const parseMoney = (value) => {
            const n = parseFloat(String(value ?? '').replace(/[\s\u00A0\u202F]/g, '').replace(',', '.'));
            return isNaN(n) ? 0 : n;
        };

        return {
            filter: 'all',
            search: '',
            bulkOpen: reopen,
            sending: false,
            lines: lines.map(l => ({ ...l, blank: !l.on && !(parseMoney(l.amount) > 0) })),
            rowMeta: rowMeta,

            rowShown(row) {
                const query = this.search.trim().toLowerCase();
                if (query && !row.name.includes(query)) return false;
                if (this.filter === 'no' || this.filter === 'yes') return row.state === this.filter;
                if (this.filter === 'late') return row.late;
                return true;
            },

            anyRowShown() {
                return this.rowMeta.some(row => this.rowShown(row));
            },

            // Новому клиенту галочка не стоит, пока нет суммы. Вписали сумму, ставим сами,
            // иначе её легко вписать и забыть отметить.
            amountTyped(line) {
                if (line.blank && parseMoney(line.amount) > 0) {
                    line.on = true;
                    line.blank = false;
                }
            },

            openBulk() {
                this.sending = false;
                this.bulkOpen = true;
            },

            parse: parseMoney,

            money(n) {
                return Number(n).toLocaleString('ru-RU', { maximumFractionDigits: 2 });
            },

            plural(n) {
                const a = n % 10, b = n % 100;
                return a === 1 && b !== 11 ? 'счёт' : (a >= 2 && a <= 4 && (b < 10 || b >= 20) ? 'счёта' : 'счетов');
            },

            changed(line) {
                const now = parseMoney(line.amount);
                return line.was !== null && now > 0 && Math.round(now * 100) !== Math.round(line.was * 100);
            },

            ready() {
                return this.lines.filter(l => l.on && parseMoney(l.amount) > 0);
            },

            readyCount() {
                return this.ready().length;
            },

            readyTotal() {
                return this.ready().reduce((sum, l) => sum + parseMoney(l.amount) + l.extra, 0);
            },

            missingCount() {
                return this.lines.filter(l => l.on && !(parseMoney(l.amount) > 0)).length;
            },

            allChecked() {
                return this.lines.every(l => l.on);
            },

            checkAll(on) {
                this.lines.forEach(l => { l.on = on; });
            },
        };
    }
</script>
@endpush
