@extends('layouts.app')
@section('title', 'Автоаудит')
@section('page-title', 'Автоаудит')

@section('content')
@php
    use App\Models\AutoAuditResult;

    $statusClasses = [
        AutoAuditResult::MATCHED        => 'bg-emerald-50 text-emerald-700',
        AutoAuditResult::MISMATCH       => 'bg-red-50 text-red-700',
        AutoAuditResult::WRONG_DOCUMENT => 'bg-amber-50 text-amber-700',
        AutoAuditResult::SCAN           => 'bg-slate-100 text-slate-600',
        AutoAuditResult::UNREADABLE     => 'bg-slate-100 text-slate-600',
    ];

    $money = fn ($value) => $value === null ? '' : number_format((float) $value, 2, ',', ' ');
@endphp

<div class="space-y-4">

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50">
        <div class="px-6 py-4 flex items-start justify-between flex-wrap gap-4">
            <div>
                <h2 class="text-lg font-semibold text-slate-800">Автоаудит</h2>
                <p class="text-sm text-slate-500 mt-0.5">
                    Страницу видит только владелец системы, сотрудники фирмы её не видят.
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

        @if ($periods)
            <div class="px-6 pb-4">
                <form method="GET" action="{{ route('auto-audit.index') }}" class="flex items-center flex-wrap gap-3">
                    <label for="period" class="text-sm font-medium text-slate-700">Отчётный период</label>
                    <select id="period" name="period" onchange="this.form.submit()"
                            class="rounded-lg border border-slate-200 text-sm px-3 py-2">
                        @foreach ($periods as $key => $label)
                            <option value="{{ $key }}" @selected($period === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <span class="text-sm text-slate-500">
                        @foreach (AutoAuditResult::LABELS as $outcome => $label)
                            {{ $label }}: {{ $counts[$outcome] ?? 0 }}{{ $loop->last ? '' : ';' }}
                        @endforeach
                    </span>
                </form>
                <p class="text-xs text-slate-400 mt-2">
                    Период, за который составлены документы, а не месяц задачи: отчёт за июль сдают в августе, и он здесь в июле.
                    Если документ прочитать не удалось (не тот, скан или не открылся), период взят как месяц перед задачей.
                </p>
            </div>
        @endif
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
                        <th class="px-4 py-3">Проверка</th>
                        <th class="px-4 py-3">Клиент</th>
                        <th class="px-4 py-3">Период</th>
                        <th class="px-4 py-3 text-right">ОСВ</th>
                        <th class="px-4 py-3 text-right">Отчёт</th>
                        <th class="px-4 py-3 text-right">Разница</th>
                        <th class="px-4 py-3">Документы</th>
                        <th class="px-4 py-3">Статус</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($results as $result)
                        <tr class="align-top">
                            <td class="px-4 py-3 text-slate-700">№{{ $result->rule }} {{ $result->ruleName() }}</td>
                            <td class="px-4 py-3 font-medium text-slate-800">{{ $result->client?->name ?? 'клиент удалён' }}</td>
                            <td class="px-4 py-3 text-slate-700 whitespace-nowrap">
                                {{ $result->periodLabel() }}
                                {{-- Из непрочитанного файла период не узнать: он взят по задаче, и это надо видеть. --}}
                                @if (in_array($result->outcome, AutoAuditResult::DOCUMENT_PROBLEMS, true))
                                    <p class="text-xs text-slate-400">по месяцу задачи</p>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">{{ $money($result->left_value) }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">{{ $money($result->right_value) }}</td>
                            <td @class([
                                'px-4 py-3 text-right whitespace-nowrap',
                                'font-semibold text-red-600' => $result->outcome === AutoAuditResult::MISMATCH,
                            ])>{{ $money($result->difference) }}</td>
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
                            <td class="px-4 py-3">
                                <span class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold whitespace-nowrap {{ $statusClasses[$result->outcome] ?? 'bg-slate-100 text-slate-600' }}">
                                    {{ AutoAuditResult::LABELS[$result->outcome] ?? $result->outcome }}
                                </span>
                                @if ($result->reason)
                                    <p class="mt-1 text-xs text-slate-500">{{ $result->reason }}</p>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

</div>
@endsection
