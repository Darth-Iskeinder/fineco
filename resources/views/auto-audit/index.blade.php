@extends('layouts.app')
@section('title', 'Автоаудит')
@section('page-title', 'Автоаудит')

@section('content')
@php
    use App\Http\Controllers\AutoAuditController;
    use App\Models\AutoAuditResult;

    $outcomes = [
        AutoAuditResult::MISMATCH => ['Не совпало', 'bg-red-50 text-red-700'],
        AutoAuditResult::MATCHED  => ['Совпало',    'bg-emerald-50 text-emerald-700'],
    ];

    $tabs = [
        [AutoAuditResult::MISMATCH, 'Не совпало', $outcomeCounts[AutoAuditResult::MISMATCH] ?? 0],
        [AutoAuditResult::MATCHED,  'Совпало',    $outcomeCounts[AutoAuditResult::MATCHED] ?? 0],
        [null,                      'Все',        $total],
    ];

    $money = fn ($value) => $value === null ? '' : number_format((float) $value, 2, ',', ' ');

    // Ссылка на эту же страницу с другим значением одного фильтра: остальные сохраняются.
    $url = fn (array $change) => route('auto-audit.index', array_filter(
        array_merge($filters, $change),
        fn ($value) => $value !== null,
    ));

    $showRule   = $filters['rule'] === null;
    $showPeriod = $filters['period'] === AutoAuditController::ALL_PERIODS;
@endphp

<div class="space-y-4">

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50">
        <div class="px-6 py-4 flex items-center justify-between flex-wrap gap-3">
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

            <div class="flex items-center flex-wrap gap-3">
                @if ($periods)
                    <form method="GET" action="{{ route('auto-audit.index') }}" class="flex items-center gap-2">
                        @foreach (['rule', 'outcome'] as $keep)
                            @if ($filters[$keep] !== null)
                                <input type="hidden" name="{{ $keep }}" value="{{ $filters[$keep] }}">
                            @endif
                        @endforeach
                        <label for="period" class="text-sm text-slate-500">Период</label>
                        <select id="period" name="period" onchange="this.form.submit()"
                                class="rounded-lg border border-slate-200 text-sm px-3 py-2">
                            @foreach ($periods as $key => $label)
                                <option value="{{ $key }}" @selected($filters['period'] === $key)>{{ $label }}</option>
                            @endforeach
                            <option value="{{ AutoAuditController::ALL_PERIODS }}"
                                    @selected($showPeriod)>Все периоды</option>
                        </select>
                    </form>
                @endif

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
        </div>
    </div>

    {{-- Карточки проверок. Нажатие оставляет в таблице только эту проверку, повторное снимает фильтр. --}}
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        @foreach ($cards as $card)
            @php
                $active     = $filters['rule'] === $card['number'];
                $mismatches = $card['counts'][AutoAuditResult::MISMATCH] ?? 0;
            @endphp
            <a href="{{ $url(['rule' => $active ? null : $card['number']]) }}"
               @class([
                   'block rounded-2xl border p-5 transition-colors',
                   'bg-indigo-50 border-indigo-500' => $active,
                   'bg-white border-slate-200/50 shadow-sm hover:border-indigo-300' => !$active,
               ])>
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Проверка №{{ $card['number'] }}</p>
                        <h3 class="mt-1 text-base font-semibold text-slate-800">{{ $card['name'] }}</h3>
                    </div>
                    @if ($active)
                        <span class="text-xs text-indigo-600 whitespace-nowrap">выбрана, нажмите, чтобы снять</span>
                    @endif
                </div>
                <p class="mt-2 text-sm text-slate-600">{{ $card['formula'] }}</p>
                <p class="mt-0.5 text-sm text-slate-500">{{ $card['condition'] }}</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <span @class([
                        'px-3 py-1 rounded-full text-sm font-medium',
                        'bg-red-50 text-red-700'      => $mismatches > 0,
                        'bg-slate-100 text-slate-500' => $mismatches === 0,
                    ])>Не совпало: {{ $mismatches }}</span>
                    <span class="px-3 py-1 rounded-full text-sm font-medium bg-emerald-50 text-emerald-700">
                        Совпало: {{ $card['counts'][AutoAuditResult::MATCHED] ?? 0 }}
                    </span>
                </div>
            </a>
        @endforeach
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50">
        <div class="px-6 border-b border-slate-100">
            <nav class="-mb-px flex space-x-6 overflow-x-auto">
                @foreach ($tabs as [$value, $label, $count])
                    <a href="{{ $url(['outcome' => $value]) }}"
                       @class([
                           'py-3 px-1 border-b-2 font-medium text-sm transition-colors inline-flex items-center gap-2 whitespace-nowrap',
                           'border-indigo-500 text-indigo-600' => $filters['outcome'] === $value,
                           'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' => $filters['outcome'] !== $value,
                       ])>
                        {{ $label }}
                        <span @class([
                            'px-2 py-0.5 rounded-full text-xs font-semibold',
                            'bg-indigo-50 text-indigo-600' => $filters['outcome'] === $value,
                            'bg-red-50 text-red-600'       => $filters['outcome'] !== $value && $value === AutoAuditResult::MISMATCH && $count > 0,
                            'bg-slate-100 text-slate-500'  => $filters['outcome'] !== $value && !($value === AutoAuditResult::MISMATCH && $count > 0),
                        ])>{{ $count }}</span>
                    </a>
                @endforeach
            </nav>
        </div>

        <div class="overflow-x-auto">
            @if ($results->isEmpty())
                <p class="px-6 py-10 text-center text-sm text-slate-500">
                    @if (!$periods)
                        Результатов нет. Нажмите «Проверить сейчас».
                    @else
                        Под выбранные фильтры ничего не попало.
                    @endif
                </p>
            @else
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">
                        <tr>
                            <th class="px-4 py-3">Клиент</th>
                            @if ($showRule)
                                <th class="px-4 py-3">Проверка</th>
                            @endif
                            @if ($showPeriod)
                                <th class="px-4 py-3">Период</th>
                            @endif
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
                                @if ($showRule)
                                    <td class="px-4 py-3 text-slate-600">№{{ $result->rule }} {{ $result->ruleName() }}</td>
                                @endif
                                @if ($showPeriod)
                                    <td class="px-4 py-3 text-slate-600 whitespace-nowrap">{{ $result->periodLabel() }}</td>
                                @endif
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

    {{-- Не тот документ. Фильтры выше сюда не относятся: периода у непрочитанного файла нет. --}}
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50">
        <div class="px-6 py-4 border-b border-slate-100">
            <h2 class="text-lg font-semibold text-slate-800 flex items-center gap-2">
                Не тот документ
                <span @class([
                    'px-2 py-0.5 rounded-full text-xs font-semibold',
                    'bg-red-50 text-red-600'      => $issues->isNotEmpty(),
                    'bg-slate-100 text-slate-500' => $issues->isEmpty(),
                ])>{{ $issues->count() }}</span>
            </h2>
            <p class="text-sm text-slate-500 mt-0.5">
                Задача закрыта и файлы приложены, но ни один не читается как нужная форма. Показаны все месяцы.
            </p>
        </div>

        <div class="overflow-x-auto">
            @if ($issues->isEmpty())
                <p class="px-6 py-10 text-center text-sm text-slate-500">Таких задач нет.</p>
            @else
                <table class="min-w-full text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">
                        <tr>
                            <th class="px-4 py-3">Клиент</th>
                            <th class="px-4 py-3">Ждали</th>
                            <th class="px-4 py-3">Задача за</th>
                            <th class="px-4 py-3">Что приложено</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        @foreach ($issues as $issue)
                            <tr class="align-top">
                                <td class="px-4 py-3 font-medium text-slate-800">{{ $issue->client?->name ?? 'клиент удалён' }}</td>
                                <td class="px-4 py-3 text-slate-600">{{ $issue->expectedDocument() }}</td>
                                <td class="px-4 py-3 text-slate-600 whitespace-nowrap">{{ $issue->taskMonth() }}</td>
                                <td class="px-4 py-3 text-xs space-y-1">
                                    @foreach ($issue->sources ?? [] as $source)
                                        <div>
                                            <a href="{{ route('documents.task', $source['document_id']) }}" target="_blank"
                                               class="text-indigo-600 hover:underline">{{ $source['name'] }}</a>
                                            <span class="text-slate-500">{{ $source['reason'] }}</span>
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

</div>
@endsection
