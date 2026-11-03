@extends('layouts.app')
@section('title', 'Расчёты с клиентами')
@section('page-title', 'Расчёты с клиентами')

@section('content')
@php
    use App\Models\ClientInvoice as Invoice;
    use App\Services\AutoAudit\AutoAuditClientBoard as Board;
@endphp

<div class="space-y-4">

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 px-6 py-4 space-y-1">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <h2 class="text-[15px] font-semibold text-slate-800">Счета за {{ $month }} {{ $period->year }}</h2>
            <span class="text-sm text-slate-500">
                Выставлено {{ $issuedCount }} из {{ Board::count($rows->count(), ['клиента', 'клиентов', 'клиентов']) }}
                на <b class="font-semibold text-slate-900 tabular-nums whitespace-nowrap">{{ Invoice::money($issuedSum) }} сом</b>
            </span>
        </div>
        <p class="text-sm text-slate-500">Сумма нового счёта берётся из прошлого. Новому клиенту её вписывают один раз.</p>
        @if ($vendor)
            <p class="text-xs text-slate-400">{{ $openToManager ? 'Фирме раздел открыт: его видят руководство и сотрудники с доступом к модулю.' : 'Фирме раздел пока не открыт, его видите только вы.' }}</p>
        @endif
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-slate-100 text-left text-xs font-medium text-slate-500 whitespace-nowrap">
                        <th class="px-6 py-3">Клиент</th>
                        <th class="px-3 py-3">Ответственный</th>
                        <th class="px-3 py-3 text-right">Сумма, сом</th>
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
                            $status  = Invoice::STATUSES[$issued ? Invoice::STATUS_ISSUED : Invoice::STATUS_DRAFT];
                            $url     = route('settlements.show', $client);
                        @endphp
                        <tr class="hover:bg-slate-50" data-client="{{ $client->id }}">
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
                            <td colspan="6" class="px-6 py-10 text-center text-slate-500">Действующих клиентов нет</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
