@extends('layouts.app')
@section('title', $client->name . ': счёт за ' . $month)
@section('page-title', 'Расчёты с клиентами')

@section('content')
@php
    use App\Models\ClientInvoice as Invoice;
    use App\Services\Settlements\ClientInvoices;

    $issued = $invoice->isIssued();
    $status = Invoice::STATUSES[$invoice->status()];
    $money  = fn ($v) => $v === null || (float) $v == 0.0 ? '' : Invoice::money($v);

    // После ошибки показываем то, что человек ввёл, а не то, что лежит в базе.
    $amount = old('amount', $money($invoice->amount));
    $extras = old('extras', $invoice->extras->map(fn ($e) => ['name' => $e->name, 'amount' => $money($e->amount)])->all());
    $extras = array_values(array_filter((array) $extras, 'is_array'));
    $monthKey = $period->format('Y-m');
@endphp

<div class="max-w-4xl space-y-4">

    <nav class="flex flex-wrap gap-1.5 text-sm text-slate-500">
        <a href="{{ route('settlements.index') }}" class="text-indigo-600 hover:underline">Расчёты с клиентами</a>
        <span>/</span>
        <span>{{ $client->name }}</span>
    </nav>

    <form method="POST" action="{{ route('settlements.save', [$client, 'month' => $monthKey]) }}"
          class="bg-white rounded-2xl shadow-sm border border-slate-200/50 px-6 py-5 space-y-5"
          x-data="settlementInvoice(@js($amount), @js($extras))">
        @csrf

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 class="text-xl font-semibold text-slate-900">{{ $client->name }}</h2>
                <p class="text-sm text-slate-500">
                    Счёт за {{ $month }} {{ $period->year }}, ответственный {{ $client->responsibleEmployee?->full_name ?? 'не назначен' }}
                </p>
            </div>
            <span class="inline-block whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $status['class'] }}" data-status>{{ $status['label'] }}</span>
        </div>

        <label class="block max-w-xs">
            <span class="text-[13px] font-medium text-slate-600">Сумма за месяц, сом</span>
            <input type="text" inputmode="decimal" name="amount" x-model="amount" placeholder="Например, 20 000" autocomplete="off"
                   class="mt-1 w-full rounded-lg border px-3 py-2 text-sm tabular-nums focus:border-indigo-500 focus:ring-indigo-500"
                   :class="parse(amount) > 0 ? 'border-slate-200' : 'border-amber-300'">
            @unless ($issued)
                <span class="mt-1 block text-xs text-slate-400">{{ $hint }}</span>
            @endunless
        </label>

        <div class="rounded-xl border border-slate-200 overflow-hidden">
            <div class="flex items-center justify-between gap-2 bg-slate-50 px-4 py-2">
                <span class="text-[13px] font-semibold text-slate-700">Дополнительные услуги</span>
                <button type="button" @click="add()"
                        class="rounded-lg border border-slate-200 bg-white px-3 py-1 text-[13px] font-medium text-slate-700 hover:bg-slate-50">Добавить</button>
            </div>
            <template x-for="(row, i) in extras" :key="row.key">
                <div class="flex items-center gap-2 border-t border-slate-100 px-4 py-2">
                    <input type="text" :name="`extras[${i}][name]`" x-model="row.name" placeholder="Что сделали" aria-label="Услуга"
                           class="min-w-0 flex-1 rounded-lg border border-slate-200 px-3 py-1.5 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                    <input type="text" inputmode="decimal" :name="`extras[${i}][amount]`" x-model="row.amount" placeholder="0" aria-label="Сумма"
                           class="w-28 rounded-lg border border-slate-200 px-3 py-1.5 text-right text-sm tabular-nums focus:border-indigo-500 focus:ring-indigo-500">
                    <button type="button" @click="extras.splice(i, 1)" aria-label="Убрать услугу"
                            class="px-1 text-lg leading-none text-slate-400 hover:text-red-600">×</button>
                </div>
            </template>
            <div x-show="!extras.length" class="border-t border-slate-100 px-4 py-2 text-[13px] text-slate-400">В этом месяце нет</div>
        </div>

        <div class="flex items-center justify-between gap-3 rounded-xl bg-indigo-50 px-4 py-3 text-indigo-700">
            <span class="font-semibold">Итого</span>
            <b class="text-xl font-bold tabular-nums whitespace-nowrap" x-text="format(total()) + ' сом'"></b>
        </div>

        <div class="grid gap-3 sm:grid-cols-2 text-sm">
            <div>
                <div class="text-[13px] font-medium text-slate-600">№ и дата счёта</div>
                <div class="mt-1 text-slate-900">{{ $invoice->title() ?? 'появятся при выставлении' }}</div>
            </div>
            <div>
                <div class="text-[13px] font-medium text-slate-600">Срок оплаты</div>
                <div class="mt-1 text-slate-900">
                    @if ($issued)
                        до {{ $invoice->due_on->format('d.m.Y') }}
                    @else
                        {{ Invoice::DUE_DAYS }} дней после выставления
                    @endif
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center justify-end gap-2 border-t border-slate-100 pt-4">
            <div class="flex-1">
                @if ($issued)
                    <button type="submit" form="unissue" class="text-[13px] text-slate-500 hover:text-red-600"
                            onclick="return confirm('Вернуть счёт № {{ $invoice->number }} в черновик? Номер освободится.')">Отменить выставление</button>
                @endif
            </div>
            <a href="{{ route('settlements.index') }}" class="rounded-lg border border-slate-200 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-50">К списку</a>
            @if ($issued)
                <button type="submit" name="action" value="save" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Сохранить изменения</button>
            @else
                <button type="submit" name="action" value="issue" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Выставить счёт</button>
            @endif
        </div>
    </form>

    @if ($issued)
        <form id="unissue" method="POST" action="{{ route('settlements.unissue', [$client, 'month' => $monthKey]) }}" class="hidden">@csrf</form>
    @endif

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
        <div class="px-6 py-4">
            <h2 class="text-[15px] font-semibold text-slate-800">История счетов</h2>
        </div>
        @if ($history->isEmpty())
            <p class="border-t border-slate-100 px-6 py-8 text-center text-sm text-slate-500">Счетов пока нет. Первый появится здесь после выставления.</p>
        @else
            <div class="overflow-x-auto border-t border-slate-100">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-slate-100 text-left text-xs font-medium text-slate-500">
                            <th class="px-6 py-2.5">Месяц</th>
                            <th class="px-3 py-2.5">Счёт</th>
                            <th class="px-3 py-2.5 text-right">Сумма, сом</th>
                            <th class="px-6 py-2.5">Срок оплаты</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($history as $row)
                            <tr @class(['hover:bg-slate-50', 'font-semibold' => $row->period->equalTo($period)])>
                                <td class="px-6 py-2.5">
                                    <a href="{{ route('settlements.show', [$client, 'month' => $row->period->format('Y-m')]) }}" class="text-slate-900 hover:text-indigo-700">
                                        {{ mb_convert_case(ClientInvoices::monthName($row->period), MB_CASE_TITLE) }} {{ $row->period->year }}
                                    </a>
                                </td>
                                <td class="px-3 py-2.5 text-slate-500">{{ $row->title() }}</td>
                                <td class="px-3 py-2.5 text-right tabular-nums">{{ Invoice::money($row->total) }}</td>
                                <td class="px-6 py-2.5 text-slate-500">{{ $row->due_on?->format('d.m.Y') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection

@push('scripts')
<script>
    function settlementInvoice(amount, extras) {
        let key = 0;

        return {
            amount: amount ?? '',
            extras: (extras || []).map(e => ({ key: ++key, name: e.name ?? '', amount: e.amount ?? '' })),

            add() {
                this.extras.push({ key: ++key, name: '', amount: '' });
                this.$nextTick(() => this.$root.querySelector(`[name="extras[${this.extras.length - 1}][name]"]`)?.focus());
            },

            parse(value) {
                const n = parseFloat(String(value ?? '').replace(/[\s  ]/g, '').replace(',', '.'));
                return isNaN(n) ? 0 : n;
            },

            total() {
                return this.parse(this.amount) + this.extras.reduce((sum, e) => sum + this.parse(e.amount), 0);
            },

            format(n) {
                return n.toLocaleString('ru-RU', { maximumFractionDigits: 2 });
            },
        };
    }
</script>
@endpush
