@extends('layouts.app')
@section('title', 'Автоаудит')
@section('page-title', 'Автоаудит')

@section('content')
@php
    use App\Models\AutoAuditResult;

    $outcomes = [
        AutoAuditResult::MISMATCH     => ['Не совпало',     'bg-red-50 text-red-700'],
        AutoAuditResult::NO_DOCUMENTS => ['Нет документов', 'bg-slate-100 text-slate-600'],
        AutoAuditResult::MATCHED      => ['Совпало',        'bg-emerald-50 text-emerald-700'],
    ];

    $money = fn ($value) => $value === null ? '' : number_format((float) $value, 2, ',', ' ');
@endphp

<div class="space-y-4">

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50">
        <div class="px-6 py-4 flex items-center justify-between flex-wrap gap-3">
            <div>
                <h2 class="text-lg font-semibold text-slate-800">Автоаудит</h2>
                <p class="text-sm text-slate-500 mt-0.5">
                    Сверка ОСВ с отчётом по единому налогу. Страницу видит только владелец системы, сотрудники фирмы её не видят.
                </p>
                <p class="text-sm text-slate-500 mt-0.5">
                    @if ($checkedAt)
                        Последняя проверка: {{ $checkedAt->format('d.m.Y H:i') }}
                    @else
                        Проверки ещё не было
                    @endif
                </p>
            </div>
            <form method="POST" action="{{ route('auto-audit.run') }}"
                  x-data="{ busy: false }" @submit="busy = true">
                @csrf
                <button type="submit" :disabled="busy"
                        class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-violet-500 to-indigo-600 text-white text-sm font-medium rounded-lg shadow-sm disabled:opacity-60 transition">
                    {{-- x-text, а не x-show с x-cloak: правила [x-cloak] в проекте нет --}}
                    <span x-text="busy ? 'Проверяю, это займёт до минуты…' : 'Проверить сейчас'">Проверить сейчас</span>
                </button>
            </form>
        </div>

        <div class="px-6 pb-4 flex flex-wrap gap-2">
            @foreach ($outcomes as $key => [$label, $classes])
                <span class="px-3 py-1 rounded-full text-sm font-medium {{ $classes }}">
                    {{ $label }}: {{ $counts[$key] ?? 0 }}
                </span>
            @endforeach
        </div>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-x-auto">
        @if ($results->isEmpty())
            <p class="px-6 py-10 text-center text-sm text-slate-500">
                Результатов нет. Нажмите «Проверить сейчас».
            </p>
        @else
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">
                    <tr>
                        <th class="px-4 py-3">Клиент</th>
                        <th class="px-4 py-3">Проверка</th>
                        <th class="px-4 py-3">Период</th>
                        <th class="px-4 py-3 text-right">ОСВ</th>
                        <th class="px-4 py-3 text-right">Отчёт</th>
                        <th class="px-4 py-3 text-right">Разница</th>
                        <th class="px-4 py-3">Итог</th>
                        <th class="px-4 py-3">Документы</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($results as $result)
                        @php [$label, $classes] = $outcomes[$result->outcome] ?? [$result->outcome, 'bg-slate-100 text-slate-600']; @endphp
                        <tr class="align-top">
                            <td class="px-4 py-3 font-medium text-slate-800">{{ $result->client?->name ?? 'клиент удалён' }}</td>
                            <td class="px-4 py-3 text-slate-600">{{ $result->ruleName() }}</td>
                            <td class="px-4 py-3 text-slate-600 whitespace-nowrap">{{ $result->periodLabel() }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">{{ $money($result->left_value) }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">{{ $money($result->right_value) }}</td>
                            <td @class([
                                'px-4 py-3 text-right whitespace-nowrap',
                                'font-semibold text-red-600' => $result->outcome === AutoAuditResult::MISMATCH,
                            ])>{{ $money($result->difference) }}</td>
                            <td class="px-4 py-3">
                                <span class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold whitespace-nowrap {{ $classes }}">{{ $label }}</span>
                                @if ($result->reason)
                                    <p class="mt-1 text-xs text-slate-500">{{ $result->reason }}</p>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-xs space-y-1">
                                @foreach ($result->sources ?? [] as $source)
                                    <div>
                                        <span class="text-slate-400">{{ $source['side'] === 'osv' ? 'ОСВ' : 'Отчёт' }}, задача за {{ $source['task_month'] }}:</span>
                                        <a href="{{ route('documents.task', $source['document_id']) }}" target="_blank"
                                           class="text-indigo-600 hover:underline">{{ $source['name'] }}</a>
                                        @if ($source['value'] !== null)
                                            <span class="text-slate-500 whitespace-nowrap">{{ $money($source['value']) }}</span>
                                        @elseif ($source['reason'])
                                            <span class="text-slate-500">{{ $source['reason'] }}</span>
                                        @endif
                                    </div>
                                @endforeach
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

</div>
@endsection
