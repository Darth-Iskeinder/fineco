@extends('layouts.app')
@section('title', $client->name . ': счёт за ' . $month)
@section('page-title', 'Расчёты с клиентами')

@section('content')
@php
    use App\Models\ClientInvoice as Invoice;
    use App\Models\ClientPayment;
    use App\Services\Settlements\ClientInvoices;

    $issued = $invoice->isIssued();
    $status = Invoice::STATUSES[$balance->statusFor($invoice)];
    $money  = fn ($v) => $v === null || (float) $v == 0.0 ? '' : Invoice::money($v);
    $paidHere = $issued ? $balance->paidFor($invoice) : 0.0;

    // После ошибки показываем то, что человек ввёл, а не то, что лежит в базе.
    $amount = old('amount', $money($invoice->amount));
    $extras = old('extras', $invoice->extras->map(fn ($e) => ['name' => $e->name, 'amount' => $money($e->amount)])->all());
    $extras = array_values(array_filter((array) $extras, 'is_array'));
    $monthKey = $period->format('Y-m');

    // Окно оплаты открыто снова, если в нём была ошибка.
    $payErrors = $errors->has('payment_amount') || $errors->has('paid_on') || $errors->has('method');
    $openingErrors = $errors->has('opening_amount') || $errors->has('opening_as_of') || $errors->has('opening_kind');
    $opening = $balance->opening();
@endphp

<div class="max-w-6xl space-y-4"
     x-data="settlementPayment(@js($balance->openForForm()), @js($payErrors))"
     @keydown.escape.window="open = false">

    <nav class="flex flex-wrap gap-1.5 text-sm text-slate-500">
        <a href="{{ route('settlements.index') }}" class="text-indigo-600 hover:underline">Расчёты с клиентами</a>
        <span>/</span>
        <span>{{ $client->name }}</span>
    </nav>

    <div class="grid gap-4 xl:grid-cols-3 items-start">
        <div class="xl:col-span-2 space-y-4 min-w-0">

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
                        {{-- На счёт легли деньги: отменить нельзя, только поправить сумму. --}}
                        @if ($issued && $paidHere <= 0)
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
                <div class="px-6 py-4 flex flex-wrap items-baseline justify-between gap-2">
                    <h2 class="text-[15px] font-semibold text-slate-800">История счетов</h2>
                    @if ($balance->advance() > 0)
                        <span class="text-sm text-slate-500">Аванс: <b class="font-semibold text-emerald-700 tabular-nums">{{ Invoice::money($balance->advance()) }} сом</b></span>
                    @else
                        <span class="text-sm text-slate-500">Клиент должен всего: <b class="font-semibold text-slate-900 tabular-nums">{{ Invoice::money($balance->debt()) }} сом</b></span>
                    @endif
                </div>
                @if ($history->isEmpty() && !$opening)
                    <p class="border-t border-slate-100 px-6 py-8 text-center text-sm text-slate-500">Счетов пока нет. Первый появится здесь после выставления.</p>
                @else
                    <div class="overflow-x-auto border-t border-slate-100">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="border-b border-slate-100 text-left text-xs font-medium text-slate-500 whitespace-nowrap">
                                    <th class="px-6 py-2.5">Месяц</th>
                                    <th class="px-3 py-2.5">Счёт</th>
                                    <th class="px-3 py-2.5 text-right">Сумма</th>
                                    <th class="px-3 py-2.5 text-right">Оплачено</th>
                                    <th class="px-3 py-2.5 text-right">Долг</th>
                                    <th class="px-6 py-2.5">Статус</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @php
                                    // Счета и пропущенные месяцы вперемешку, новые сверху.
                                    $timeline = $history->concat($missed)
                                        ->sortByDesc(fn ($item) => ($item instanceof Invoice ? $item->period : $item)->format('Y-m'));
                                @endphp
                                @foreach ($timeline as $row)
                                    @if (!$row instanceof Invoice)
                                        <tr class="hover:bg-slate-50" data-history="{{ $row->format('Y-m') }}" data-missed>
                                            <td class="px-6 py-2.5 whitespace-nowrap">
                                                <a href="{{ route('settlements.show', [$client, 'month' => $row->format('Y-m')]) }}" class="text-slate-900 hover:text-indigo-700">
                                                    {{ mb_convert_case(ClientInvoices::monthName($row), MB_CASE_TITLE) }} {{ $row->year }}
                                                </a>
                                            </td>
                                            <td class="px-3 py-2.5 text-amber-700" colspan="4">
                                                <a href="{{ route('settlements.show', [$client, 'month' => $row->format('Y-m')]) }}" class="hover:underline">Счёт за этот месяц не выставлен</a>
                                            </td>
                                            <td class="px-6 py-2.5">
                                                <span class="inline-block whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold {{ Invoice::STATUSES[Invoice::STATUS_DRAFT]['class'] }}">{{ Invoice::STATUSES[Invoice::STATUS_DRAFT]['label'] }}</span>
                                            </td>
                                        </tr>
                                        @continue
                                    @endif
                                    @php
                                        $rowStatus = Invoice::STATUSES[$balance->statusFor($row)];
                                        $rowDebt   = $balance->debtFor($row);
                                    @endphp
                                    <tr @class(['hover:bg-slate-50', 'font-semibold' => $row->period->equalTo($period)]) data-history="{{ $row->period->format('Y-m') }}">
                                        <td class="px-6 py-2.5 whitespace-nowrap">
                                            <a href="{{ route('settlements.show', [$client, 'month' => $row->period->format('Y-m')]) }}" class="text-slate-900 hover:text-indigo-700">
                                                {{ mb_convert_case(ClientInvoices::monthName($row->period), MB_CASE_TITLE) }} {{ $row->period->year }}
                                            </a>
                                        </td>
                                        <td class="px-3 py-2.5 text-slate-500 whitespace-nowrap">{{ $row->title() }}</td>
                                        <td class="px-3 py-2.5 text-right tabular-nums">{{ Invoice::money($row->total) }}</td>
                                        <td class="px-3 py-2.5 text-right tabular-nums">{{ Invoice::money($balance->paidFor($row)) }}</td>
                                        <td @class(['px-3 py-2.5 text-right tabular-nums', 'text-red-700' => $rowDebt > 0])>{{ Invoice::money($rowDebt) }}</td>
                                        <td class="px-6 py-2.5">
                                            <span class="inline-block whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $rowStatus['class'] }}">{{ $rowStatus['label'] }}</span>
                                        </td>
                                    </tr>
                                @endforeach
                                @if ($opening)
                                    @php $openStatus = Invoice::STATUSES[$balance->openingStatus()]; @endphp
                                    <tr class="hover:bg-slate-50" data-history="opening">
                                        <td class="px-6 py-2.5 whitespace-nowrap text-slate-900">{{ $opening->isAdvance() ? 'Аванс' : 'Долг' }} на начало</td>
                                        <td class="px-3 py-2.5 text-slate-500 whitespace-nowrap">на {{ $opening->as_of->format('d.m.Y') }}</td>
                                        @if ($opening->isAdvance())
                                            <td class="px-3 py-2.5 text-right tabular-nums text-emerald-700" colspan="3">+{{ Invoice::money(abs((float) $opening->amount)) }}</td>
                                            <td class="px-6 py-2.5"></td>
                                        @else
                                            <td class="px-3 py-2.5 text-right tabular-nums">{{ Invoice::money($opening->amount) }}</td>
                                            <td class="px-3 py-2.5 text-right tabular-nums">{{ Invoice::money($balance->openingPaid()) }}</td>
                                            <td @class(['px-3 py-2.5 text-right tabular-nums', 'text-red-700' => $balance->openingDebt() > 0])>{{ Invoice::money($balance->openingDebt()) }}</td>
                                            <td class="px-6 py-2.5">
                                                <span class="inline-block whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $openStatus['class'] }}">{{ $openStatus['label'] === 'Выставлен' ? 'Не оплачен' : $openStatus['label'] }}</span>
                                            </td>
                                        @endif
                                    </tr>
                                @endif
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        {{-- Оплата: вносится на клиента, по счетам ложится сама, от старого к новому. --}}
        <div class="space-y-4 min-w-0">
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 px-6 py-5">
                <h2 class="text-[15px] font-semibold text-slate-800">Оплата</h2>
                @if ($issued)
                    <dl class="mt-3 space-y-1.5 text-sm">
                        <div class="flex justify-between gap-2"><dt class="text-slate-500">К оплате</dt><dd class="font-semibold tabular-nums">{{ Invoice::money($invoice->total) }}</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-slate-500">Оплачено</dt><dd class="font-semibold tabular-nums text-emerald-700">{{ Invoice::money($paidHere) }}</dd></div>
                        <div class="flex justify-between gap-2"><dt class="text-slate-500">Долг по счёту</dt><dd @class(['font-semibold tabular-nums', 'text-red-700' => $balance->debtFor($invoice) > 0])>{{ Invoice::money($balance->debtFor($invoice)) }}</dd></div>
                    </dl>
                @else
                    <p class="mt-2 text-sm text-slate-500">Счёт за {{ $month }} ещё не выставлен.</p>
                @endif
                <div class="mt-3 border-t border-slate-100 pt-3 text-sm flex justify-between gap-2">
                    @if ($balance->advance() > 0)
                        <span class="text-slate-500">Аванс клиента</span>
                        <b class="font-semibold tabular-nums text-emerald-700">{{ Invoice::money($balance->advance()) }}</b>
                    @else
                        <span class="text-slate-500">Клиент должен всего</span>
                        <b @class(['font-semibold tabular-nums', 'text-red-700' => $balance->debt() > 0])>{{ Invoice::money($balance->debt()) }}</b>
                    @endif
                </div>
                <button type="button" @click="show()"
                        class="mt-4 w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-semibold text-white hover:bg-indigo-700">Принять оплату</button>

                {{-- Долг или аванс до Kubik: одной суммой с датой, вписывают один раз. --}}
                <div class="mt-4 border-t border-slate-100 pt-3 text-sm" x-data="{ edit: @js($openingErrors) }">
                    <div class="flex items-baseline justify-between gap-2" x-show="!edit">
                        @if ($opening)
                            <span class="text-slate-500">{{ $opening->isAdvance() ? 'Аванс' : 'Долг' }} на {{ $opening->as_of->format('d.m.Y') }}</span>
                            <span class="flex items-baseline gap-2">
                                <b class="font-semibold tabular-nums">{{ Invoice::money(abs((float) $opening->amount)) }}</b>
                                <button type="button" @click="edit = true" class="text-[13px] text-indigo-600 hover:underline">изменить</button>
                            </span>
                        @else
                            <button type="button" @click="edit = true" class="text-[13px] text-indigo-600 hover:underline">Указать долг на начало</button>
                        @endif
                    </div>
                    <form x-show="edit" style="display: none;" method="POST" action="{{ route('settlements.opening', [$client, 'month' => $monthKey]) }}" class="space-y-3"
                          x-data="{ kind: @js(old('opening_kind', $opening?->isAdvance() ? 'advance' : 'debt')) }">
                        @csrf
                        <p class="text-[13px] text-slate-500">Сколько клиент был должен до Kubik. Оплаты закроют этот долг первым.</p>
                        <div class="flex gap-2" role="radiogroup" aria-label="Долг или аванс">
                            @foreach (['debt' => 'Долг', 'advance' => 'Аванс'] as $key => $label)
                                <label class="cursor-pointer">
                                    <input type="radio" name="opening_kind" value="{{ $key }}" class="sr-only" x-model="kind">
                                    <span class="inline-block rounded-full border px-3 py-1 text-[13px] font-medium"
                                          :class="kind === '{{ $key }}' ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-200 text-slate-600'">{{ $label }}</span>
                                </label>
                            @endforeach
                        </div>
                        <div class="grid gap-2 grid-cols-2">
                            <label class="block">
                                <span class="text-[13px] font-medium text-slate-600">Сумма, сом</span>
                                <input type="text" inputmode="decimal" name="opening_amount" autocomplete="off"
                                       value="{{ old('opening_amount', $opening ? Invoice::money(abs((float) $opening->amount)) : '') }}"
                                       class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm tabular-nums focus:border-indigo-500 focus:ring-indigo-500">
                            </label>
                            <label class="block">
                                <span class="text-[13px] font-medium text-slate-600">На дату</span>
                                <input type="date" name="opening_as_of" max="{{ $today->toDateString() }}"
                                       value="{{ old('opening_as_of', $opening?->as_of->toDateString() ?? $today->startOfMonth()->toDateString()) }}"
                                       class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                            </label>
                        </div>
                        @if ($opening)
                            <p class="text-xs text-slate-400">Чтобы убрать, сотрите сумму и сохраните.</p>
                        @endif
                        <div class="flex justify-end gap-2">
                            <button type="button" @click="edit = false" class="rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] font-medium text-slate-700 hover:bg-slate-50">Отмена</button>
                            <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-[13px] font-semibold text-white hover:bg-indigo-700">Сохранить</button>
                        </div>
                    </form>
                </div>

                {{-- Комментарий про деньги: один на клиента, тот же, что в колонке списка. --}}
                <div class="mt-4 border-t border-slate-100 pt-3 text-sm" x-data="{ edit: @js($errors->has('comment')) }">
                    <div x-show="!edit">
                        <div class="flex items-baseline justify-between gap-2">
                            <span class="text-slate-500">Комментарий</span>
                            <button type="button" @click="edit = true" class="text-[13px] text-indigo-600 hover:underline">{{ $comment ? 'изменить' : 'добавить' }}</button>
                        </div>
                        @if ($comment)
                            <p class="mt-1 whitespace-pre-line break-words text-slate-800">{{ $comment->body }}</p>
                            <p class="mt-1 text-xs text-slate-400">{{ $comment->signature() }}</p>
                        @endif
                    </div>
                    <form x-show="edit" style="display: none;" method="POST" action="{{ route('settlements.comment', [$client, 'month' => $monthKey]) }}" class="space-y-2">
                        @csrf
                        <span class="text-[13px] font-medium text-slate-600">Комментарий</span>
                        <textarea name="comment" rows="4" maxlength="{{ \App\Models\ClientSettlementComment::MAX_LENGTH }}" placeholder="Например: платит в конце месяца"
                                  class="block w-full rounded-lg border border-slate-200 px-3 py-2 text-sm resize-none focus:border-indigo-500 focus:ring-indigo-500">{{ old('comment', $comment?->body) }}</textarea>
                        @error('comment')
                            <p class="text-xs text-red-700">{{ $message }}</p>
                        @enderror
                        <p class="text-xs text-slate-400">Виден в списке расчётов. Чтобы убрать, сотрите текст и сохраните.</p>
                        <div class="flex justify-end gap-2">
                            <button type="button" @click="edit = false" class="rounded-lg border border-slate-200 px-3 py-1.5 text-[13px] font-medium text-slate-700 hover:bg-slate-50">Отмена</button>
                            <button type="submit" class="rounded-lg bg-indigo-600 px-3 py-1.5 text-[13px] font-semibold text-white hover:bg-indigo-700">Сохранить</button>
                        </div>
                    </form>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
                <div class="px-6 py-4">
                    <h2 class="text-[15px] font-semibold text-slate-800">Оплаты клиента</h2>
                </div>
                @forelse ($payments as $payment)
                    <div class="flex items-start justify-between gap-3 border-t border-slate-100 px-6 py-2.5 text-sm" data-payment="{{ $payment->id }}">
                        <div>
                            <div class="text-slate-900">{{ $payment->paid_on->format('d.m.Y') }}</div>
                            <div class="text-xs text-slate-400">{{ $payment->methodLabel() }}@if ($payment->author), {{ $payment->author->full_name }}@endif</div>
                        </div>
                        <div class="flex items-center gap-2">
                            <b class="font-semibold tabular-nums whitespace-nowrap">{{ Invoice::money($payment->amount) }}</b>
                            <form method="POST" action="{{ route('settlements.payments.destroy', [$client, $payment, 'month' => $monthKey]) }}"
                                  onsubmit="return confirm('Удалить оплату {{ Invoice::money($payment->amount) }} сом от {{ $payment->paid_on->format('d.m.Y') }}? Остальные оплаты разложатся по счетам заново.')">
                                @csrf
                                @method('DELETE')
                                <button type="submit" aria-label="Удалить оплату" class="px-1 text-lg leading-none text-slate-300 hover:text-red-600">×</button>
                            </form>
                        </div>
                    </div>
                @empty
                    <p class="border-t border-slate-100 px-6 py-6 text-center text-sm text-slate-500">Оплат пока нет</p>
                @endforelse
            </div>
        </div>
    </div>

    {{-- Окно «Принять оплату» --}}
    <div x-show="open" class="fixed inset-0 z-50 overflow-y-auto" style="display: none;">
        <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm" @click="open = false"></div>
        <div class="flex min-h-full items-center justify-center p-4">
            <form method="POST" action="{{ route('settlements.payments.store', [$client, 'month' => $monthKey]) }}"
                  class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden" role="dialog" aria-modal="true">
                @csrf
                <div class="px-6 pt-5 pb-4 space-y-4">
                    <div>
                        <h3 class="text-lg font-semibold text-slate-800">Принять оплату</h3>
                        <p class="text-sm text-slate-500">{{ $client->name }}</p>
                    </div>

                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="block">
                            <span class="text-[13px] font-medium text-slate-600">Сумма, сом</span>
                            <input type="text" inputmode="decimal" name="payment_amount" x-model="amount" x-ref="amount" autocomplete="off"
                                   class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm tabular-nums focus:border-indigo-500 focus:ring-indigo-500">
                        </label>
                        <label class="block">
                            <span class="text-[13px] font-medium text-slate-600">Дата</span>
                            <input type="date" name="paid_on" value="{{ old('paid_on', $today->toDateString()) }}" max="{{ $today->toDateString() }}"
                                   class="mt-1 w-full rounded-lg border border-slate-200 px-3 py-2 text-sm focus:border-indigo-500 focus:ring-indigo-500">
                        </label>
                    </div>

                    <div class="flex gap-2" role="radiogroup" aria-label="Способ оплаты">
                        @foreach (ClientPayment::METHODS as $key => $label)
                            <label class="cursor-pointer">
                                <input type="radio" name="method" value="{{ $key }}" class="sr-only" x-model="method">
                                <span class="inline-block rounded-full border px-3 py-1 text-[13px] font-medium"
                                      :class="method === '{{ $key }}' ? 'border-indigo-600 bg-indigo-600 text-white' : 'border-slate-200 text-slate-600'">{{ $label }}</span>
                            </label>
                        @endforeach
                    </div>

                    {{-- Куда уйдут деньги: та же раскладка, что на сервере, от старого к новому. --}}
                    <div class="rounded-xl bg-slate-50 px-4 py-3 text-sm space-y-1" x-show="parse(amount) > 0">
                        <template x-for="row in spread()" :key="row.month">
                            <div class="flex justify-between gap-2">
                                <span class="text-slate-600" x-text="(row.closes ? 'закроют ' : 'уйдут в ') + row.month"></span>
                                <b class="font-semibold tabular-nums" x-text="format(row.amount)"></b>
                            </div>
                        </template>
                        <div class="flex justify-between gap-2" x-show="rest() > 0">
                            <span class="text-emerald-700">останутся авансом</span>
                            <b class="font-semibold tabular-nums text-emerald-700" x-text="format(rest())"></b>
                        </div>
                    </div>
                </div>

                <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-end gap-3">
                    <button type="button" @click="open = false"
                            class="inline-flex items-center px-5 py-2.5 border border-slate-200 rounded-xl text-sm font-medium text-slate-600 bg-white hover:bg-slate-50">Отмена</button>
                    <button type="submit"
                            class="inline-flex items-center px-5 py-2.5 rounded-xl bg-indigo-600 text-sm font-semibold text-white hover:bg-indigo-700">Принять</button>
                </div>
            </form>
        </div>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function settlementParse(value) {
        const n = parseFloat(String(value ?? '').replace(/[\s  ]/g, '').replace(',', '.'));
        return isNaN(n) ? 0 : n;
    }

    function settlementFormat(n) {
        return n.toLocaleString('ru-RU', { maximumFractionDigits: 2 });
    }

    function settlementInvoice(amount, extras) {
        let key = 0;

        return {
            amount: amount ?? '',
            extras: (extras || []).map(e => ({ key: ++key, name: e.name ?? '', amount: e.amount ?? '' })),

            add() {
                this.extras.push({ key: ++key, name: '', amount: '' });
                this.$nextTick(() => this.$root.querySelector(`[name="extras[${this.extras.length - 1}][name]"]`)?.focus());
            },

            parse: settlementParse,
            format: settlementFormat,

            total() {
                return this.parse(this.amount) + this.extras.reduce((sum, e) => sum + this.parse(e.amount), 0);
            },
        };
    }

    function settlementPayment(openInvoices, reopen) {
        const debt = openInvoices.reduce((sum, i) => sum + i.debt, 0);

        return {
            open: reopen,
            amount: @js(old('payment_amount')) ?? (debt > 0 ? settlementFormat(debt) : ''),
            method: @js(old('method', ClientPayment::BANK)),

            parse: settlementParse,
            format: settlementFormat,

            show() {
                this.open = true;
                this.$nextTick(() => this.$refs.amount?.select());
            },

            // Раскладка новой оплаты по долгам от старого к новому, в тыйынах.
            spread() {
                let left = Math.round(this.parse(this.amount) * 100);
                const rows = [];

                for (const item of openInvoices) {
                    if (left <= 0) break;
                    const owed = Math.round(item.debt * 100);
                    const take = Math.min(owed, left);
                    left -= take;
                    rows.push({ month: item.month, amount: take / 100, closes: take === owed });
                }

                return rows;
            },

            rest() {
                const taken = this.spread().reduce((sum, r) => sum + Math.round(r.amount * 100), 0);
                return Math.max(0, Math.round(this.parse(this.amount) * 100) - taken) / 100;
            },
        };
    }
</script>
@endpush
