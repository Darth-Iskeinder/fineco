@extends('layouts.app')

@section('title', 'Руководитель')
@section('page-title', 'Руководитель')

@php
    // Русский плюрал: формы [1, 2–4, 5+] (локаль приложения en — trans_choice не подходит)
    $plural = fn (int $n, array $f) => $f[($n % 10 === 1 && $n % 100 !== 11) ? 0 : (($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 10 || $n % 100 >= 20)) ? 1 : 2)];

    // Цвет мини-индикатора «вовремя»: severity несёт заливка, текст остаётся чернильным.
    // Оттенки-600 — палитра прошла валидацию на дальтонизм и контраст (см. dataviz)
    $meterColor = fn (?int $pct) => $pct === null ? '' : ($pct >= 90 ? 'bg-emerald-600' : ($pct >= 70 ? 'bg-amber-600' : 'bg-red-600'));

    // Статус задачи → [подпись, цвет точки]; цвет живёт в точке, не в тексте
    $statusDot = function (string $status, bool $late) {
        return match ($status) {
            'completed' => $late ? ['выполнена с опозданием', 'bg-amber-600'] : ['выполнена', 'bg-emerald-600'],
            'review'    => ['на проверке', 'bg-amber-600'],
            'rework'    => ['на доработке', 'bg-amber-600'],
            'running'   => ['в работе', 'bg-indigo-600'],
            'paused'    => ['на паузе', 'bg-slate-300'],
            default     => $late ? ['не начата', 'bg-red-600'] : ['не начата', 'bg-slate-300'],
        };
    };
@endphp

@section('content')
<style>[x-cloak]{display:none!important}</style>
<div class="space-y-6">

    {{-- Фильтр периода --}}
    <div class="flex flex-wrap items-center gap-3">
        <div class="flex items-center bg-white border border-slate-200/80 rounded-xl overflow-hidden">
            <a href="{{ route('dashboard.index', $prev) }}"
               class="px-4 py-2.5 text-slate-400 hover:text-slate-700 hover:bg-slate-50 transition-colors" title="Предыдущий месяц">‹</a>
            <span class="px-3 py-2.5 text-sm font-semibold text-slate-800 min-w-[130px] text-center">{{ $monthLabel }}</span>
            @if($isCurrent)
                <span class="px-4 py-2.5 text-slate-200 cursor-not-allowed select-none" title="Будущие месяцы недоступны">›</span>
            @else
                <a href="{{ route('dashboard.index', $next) }}"
                   class="px-4 py-2.5 text-slate-400 hover:text-slate-700 hover:bg-slate-50 transition-colors" title="Следующий месяц">›</a>
            @endif
        </div>
        @unless($isCurrent)
            <a href="{{ route('dashboard.index') }}"
               class="text-sm font-medium text-indigo-600 hover:text-indigo-800 transition-colors">Текущий месяц</a>
        @endunless
        <span class="ml-auto text-xs text-slate-400">Период не влияет на «Просрочено сейчас» и график дисциплины</span>
    </div>

    {{-- Плитки-итоги: одна карточка, разделители-волоски, цифры чернильные, цвет — только у просрочки --}}
    <div class="bg-white rounded-2xl border border-slate-200/80 overflow-hidden">
        <div class="grid grid-cols-2 md:grid-cols-3 xl:grid-cols-6 gap-px bg-slate-100">

            <div class="bg-white px-5 py-4">
                <div class="text-[13px] text-slate-500">Задач в месяце</div>
                <div class="mt-2 text-[32px] leading-none font-semibold text-slate-900">{{ $stats['total'] }}</div>
                <div class="mt-2 text-xs text-slate-400">
                    @if($stats['adhoc'] > 0) {{ $stats['adhoc'] }} вне сметы @else все по смете @endif
                </div>
            </div>

            <div class="bg-white px-5 py-4">
                <div class="text-[13px] text-slate-500">Выполнено</div>
                <div class="mt-2 text-[32px] leading-none font-semibold text-slate-900">{{ $stats['completed'] }}</div>
                <div class="mt-2 text-xs text-slate-400">
                    @if($stats['total'] > 0) {{ (int) round($stats['completed'] / $stats['total'] * 100) }}% плана месяца @else нет задач в периоде @endif
                </div>
            </div>

            <div class="bg-white px-5 py-4">
                <div class="text-[13px] text-slate-500">Вовремя</div>
                <div class="mt-2 text-[32px] leading-none font-semibold {{ $onTimePct === null ? 'text-slate-300' : 'text-slate-900' }}">
                    {{ $onTimePct === null ? '—' : $onTimePct . '%' }}
                </div>
                @if($onTimePct !== null)
                    <div class="mt-2.5 h-1 rounded-full bg-slate-100 overflow-hidden">
                        <div class="h-full rounded-full {{ $meterColor($onTimePct) }}" style="width: {{ $onTimePct }}%"></div>
                    </div>
                    <div class="mt-1.5 text-xs text-slate-400">{{ $stats['on_time'] }} из {{ $stats['completed'] }} выполненных</div>
                @else
                    <div class="mt-2 text-xs text-slate-400">ещё нет выполненных</div>
                @endif
            </div>

            <div class="bg-white px-5 py-4">
                <div class="text-[13px] text-slate-500">Просрочено сейчас</div>
                <div class="mt-2 text-[32px] leading-none font-semibold {{ count($overdue) > 0 ? 'text-red-600' : 'text-slate-900' }}">{{ count($overdue) }}</div>
                <div class="mt-2 text-xs text-slate-400">
                    @if(count($overdue) > 0)
                        у {{ $overdueEmployees }} {{ $plural($overdueEmployees, ['сотрудника', 'сотрудников', 'сотрудников']) }}
                    @else
                        всё в срок
                    @endif
                </div>
            </div>

            <div class="bg-white px-5 py-4">
                <div class="text-[13px] text-slate-500">На проверке</div>
                <div class="mt-2 text-[32px] leading-none font-semibold text-slate-900">{{ $stats['review'] }}</div>
                <div class="mt-2 text-xs text-slate-400">@if($stats['review'] > 0) ждут главбуха @else очередь пуста @endif</div>
            </div>

            <div class="bg-white px-5 py-4">
                <div class="text-[13px] text-slate-500">Затрачено времени</div>
                <div class="mt-2 text-[32px] leading-none font-semibold text-slate-900">{{ $totalTime }}</div>
                <div class="mt-2 text-xs text-slate-400">
                    @if($avgTime) ~{{ $avgTime }} на задачу @else таймеры не запускались @endif
                </div>
            </div>

        </div>

        {{-- Состав месяца одной полоской: из чего складывается план --}}
        @php
            $comp = [
                ['Выполнено', $stats['completed'], 'bg-emerald-600'],
                ['На проверке', $stats['review'], 'bg-amber-600'],
                ['В работе', $stats['in_progress'], 'bg-indigo-600'],
                ['Не начато', $stats['pending'], 'bg-slate-300'],
            ];
        @endphp
        @if($stats['total'] > 0)
            <div class="border-t border-slate-100 px-5 py-4">
                <div class="flex h-2.5 gap-0.5">
                    @foreach($comp as [$label, $n, $cls])
                        @if($n > 0)
                            <div class="{{ $cls }} rounded-full min-w-[6px]" style="width: {{ round($n / $stats['total'] * 100, 1) }}%"
                                 title="{{ $label }}: {{ $n }} из {{ $stats['total'] }}"></div>
                        @endif
                    @endforeach
                </div>
                <div class="mt-2.5 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                    @foreach($comp as [$label, $n, $cls])
                        <span class="inline-flex items-center gap-1.5 {{ $n > 0 ? 'text-slate-500' : 'text-slate-300' }}">
                            <span class="w-1.5 h-1.5 rounded-full {{ $n > 0 ? $cls : 'bg-slate-200' }}"></span>{{ $label }} · {{ $n }}
                        </span>
                    @endforeach
                </div>
            </div>
        @endif
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-5 gap-6 items-start">

    {{-- Просрочено сейчас --}}
    <div class="xl:col-span-3 bg-white rounded-2xl border border-slate-200/80 overflow-hidden">
        <div class="flex flex-wrap items-baseline gap-3 px-6 pt-5">
            <h2 class="text-base font-semibold text-slate-800">Просрочено сейчас</h2>
            <span class="text-xs text-slate-400">срок прошёл, задача не сдана</span>
            @if(count($overdue) > 0)
                <span class="ml-auto text-xs font-semibold text-red-600">
                    {{ count($overdue) }} {{ $plural(count($overdue), ['задача', 'задачи', 'задач']) }}
                </span>
            @endif
        </div>

        @if(count($overdue) === 0)
            <div class="px-6 py-10 text-center">
                <p class="text-sm font-medium text-slate-600">Просроченных задач нет</p>
                <p class="text-xs text-slate-400 mt-1">Все задачи выполняются в срок</p>
            </div>
        @else
            <div class="overflow-x-auto px-2 pb-2 pt-2">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left">
                            <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200">Задача</th>
                            <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200">Компания</th>
                            <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200">Исполнитель</th>
                            <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200 text-right">Срок</th>
                            <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200 text-right">Дней</th>
                            <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200">Статус</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($overdue as $task)
                            <tr class="border-b border-slate-100 last:border-0">
                                <td class="px-4 py-3 font-medium text-slate-700">
                                    {{ $task['name'] }}
                                    @if($task['is_adhoc'])
                                        <span class="ml-1.5 inline-block text-[10px] text-slate-400 border border-slate-200 px-1.5 py-px rounded-full align-middle">вне сметы</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-slate-500">{{ $task['client_name'] }}</td>
                                <td class="px-4 py-3 text-slate-500">{{ $task['assignee'] }}</td>
                                <td class="px-4 py-3 text-right text-slate-400 tabular-nums">{{ $task['due_date']->format('d.m') }}</td>
                                <td class="px-4 py-3 text-right font-semibold text-red-600 tabular-nums">{{ $task['days'] }}</td>
                                <td class="px-4 py-3">
                                    @php [$label, $dot] = $statusDot($task['status'], true); @endphp
                                    <span class="inline-flex items-center gap-1.5 text-xs text-slate-500">
                                        <span class="w-1.5 h-1.5 rounded-full {{ $dot }}"></span>{{ $label }}
                                    </span>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Дисциплина по месяцам --}}
    @php $judged = array_sum(array_map(fn ($m) => $m['on_time'] + $m['late'] + $m['overdue'], $discipline)); @endphp
    <div class="xl:col-span-2 bg-white rounded-2xl border border-slate-200/80 overflow-hidden">
        <div class="flex flex-wrap items-baseline gap-3 px-6 pt-5">
            <h2 class="text-base font-semibold text-slate-800">Дисциплина по месяцам</h2>
            <span class="text-xs text-slate-400">закрытые и просроченные</span>
        </div>

        @if($judged === 0)
            <div class="px-6 py-10 text-center">
                <p class="text-sm font-medium text-slate-600">Пока нечего показать</p>
                <p class="text-xs text-slate-400 mt-1">Здесь появятся столбики закрытых и просроченных задач</p>
            </div>
        @else
            <div class="px-6 pt-6 pb-5">
                <div class="flex items-end justify-evenly gap-3 border-b border-slate-200 pb-0" style="height: 190px">
                    @foreach($discipline as $m)
                        @php $mTotal = $m['on_time'] + $m['late'] + $m['overdue']; @endphp
                        <div class="flex flex-col items-center justify-end gap-1.5 h-full"
                             title="{{ $m['label'] }}: вовремя {{ $m['on_time'] }}, с опозданием {{ $m['late'] }}, просрочено {{ $m['overdue'] }}">
                            <span class="text-xs {{ $mTotal > 0 ? 'text-slate-500 font-medium' : 'text-slate-300' }} tabular-nums">{{ $mTotal }}</span>
                            <div class="flex flex-col justify-end gap-[2px] w-6">
                                @foreach([['overdue', 'bg-red-600'], ['late', 'bg-amber-600'], ['on_time', 'bg-emerald-600']] as [$k, $cls])
                                    @if($m[$k] > 0)
                                        <div class="{{ $cls }} rounded-t-[3px] w-full" style="height: {{ max(4, round($m[$k] / $disciplineMax * 140)) }}px"></div>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="flex justify-evenly gap-3 mt-2">
                    @foreach($discipline as $m)
                        <span class="text-xs text-slate-400">{{ $m['label'] }}</span>
                    @endforeach
                </div>
                <div class="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                    <span class="inline-flex items-center gap-1.5 text-slate-500"><span class="w-1.5 h-1.5 rounded-full bg-emerald-600"></span>вовремя</span>
                    <span class="inline-flex items-center gap-1.5 text-slate-500"><span class="w-1.5 h-1.5 rounded-full bg-amber-600"></span>с опозданием</span>
                    <span class="inline-flex items-center gap-1.5 text-slate-500"><span class="w-1.5 h-1.5 rounded-full bg-red-600"></span>просрочено</span>
                </div>
            </div>
        @endif
    </div>

    </div>

    {{-- По сотрудникам --}}
    <div class="bg-white rounded-2xl border border-slate-200/80 overflow-hidden" x-data="{ open: null }">
        <div class="flex flex-wrap items-baseline gap-3 px-6 pt-5">
            <h2 class="text-base font-semibold text-slate-800">По сотрудникам</h2>
            <span class="text-xs text-slate-400">{{ $monthLabel }} · строка раскрывает задачи · «Просрочено» — сейчас, вне периода</span>
        </div>

        @if(count($byEmployee) === 0)
            <div class="px-6 py-10 text-center">
                <p class="text-sm font-medium text-slate-600">Нет задач в выбранном месяце</p>
                <p class="text-xs text-slate-400 mt-1">Выберите другой период</p>
            </div>
        @else
            <div class="overflow-x-auto px-2 pb-2 pt-2">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left">
                            <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200">Сотрудник</th>
                            <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200 text-right">Задач</th>
                            <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200" title="Состав задач месяца: выполнено / на проверке / в работе / не начато">Состав</th>
                            <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200 text-right">Выполнено</th>
                            <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200 text-right">Вовремя</th>
                            <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200 text-right">Просрочено</th>
                            <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200 text-right">На проверке</th>
                            <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200 text-right" title="Сколько раз главбух возвращал задачи этого месяца на доработку">Возвраты</th>
                            <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200 text-right">Время</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($byEmployee as $empId => $row)
                            @php $pct = $row['completed'] > 0 ? (int) round($row['on_time'] / $row['completed'] * 100) : null; @endphp
                            <tr class="border-b border-slate-100 cursor-pointer hover:bg-slate-50 transition-colors"
                                @click="open = open === {{ $empId }} ? null : {{ $empId }}">
                                <td class="px-4 py-3 font-medium text-slate-700">
                                    <span class="inline-block w-4 text-slate-300 transition-transform" :class="open === {{ $empId }} && 'rotate-90'">›</span>
                                    {{ $row['name'] }}
                                </td>
                                <td class="px-4 py-3 text-right text-slate-700 tabular-nums">
                                    {{ $row['total'] }}@if($row['adhoc'] > 0)<span class="text-xs text-slate-400" title="из них вне сметы"> +{{ $row['adhoc'] }}</span>@endif
                                </td>
                                <td class="px-4 py-3">
                                    @if($row['total'] > 0)
                                        <div class="flex h-1.5 gap-px w-28"
                                             title="выполнено {{ $row['completed'] }} · на проверке {{ $row['review'] }} · в работе {{ $row['in_progress'] }} · не начато {{ $row['pending'] }}">
                                            @foreach([['completed', 'bg-emerald-600'], ['review', 'bg-amber-600'], ['in_progress', 'bg-indigo-600'], ['pending', 'bg-slate-300']] as [$k, $cls])
                                                @if($row[$k] > 0)
                                                    <div class="{{ $cls }} rounded-full min-w-[3px]" style="width: {{ round($row[$k] / $row['total'] * 100, 1) }}%"></div>
                                                @endif
                                            @endforeach
                                        </div>
                                    @else
                                        <span class="text-slate-300">—</span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right text-slate-500 tabular-nums">{{ $row['completed'] }}</td>
                                <td class="px-4 py-3 text-right">
                                    @if($pct === null)
                                        <span class="text-slate-300">—</span>
                                    @else
                                        <span class="inline-flex items-center justify-end gap-2">
                                            <span class="w-10 h-1 rounded-full bg-slate-100 overflow-hidden">
                                                <span class="block h-full rounded-full {{ $meterColor($pct) }}" style="width: {{ $pct }}%"></span>
                                            </span>
                                            <span class="font-medium text-slate-700 tabular-nums w-9">{{ $pct }}%</span>
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums {{ $row['overdue'] > 0 ? 'text-red-600' : 'text-slate-300 font-normal' }}">
                                    {{ $row['overdue'] > 0 ? $row['overdue'] : '—' }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums {{ $row['review'] > 0 ? 'text-slate-700' : 'text-slate-300' }}">
                                    {{ $row['review'] > 0 ? $row['review'] : '—' }}
                                </td>
                                <td class="px-4 py-3 text-right tabular-nums {{ $row['rework'] > 0 ? 'text-slate-700' : 'text-slate-300' }}">
                                    {{ $row['rework'] > 0 ? $row['rework'] : '—' }}
                                </td>
                                <td class="px-4 py-3 text-right text-slate-500 tabular-nums">{{ $row['time'] ?? '—' }}</td>
                            </tr>
                            <tr x-show="open === {{ $empId }}" x-cloak>
                                <td colspan="9" class="px-4 pb-4 pt-1 bg-slate-50/60">
                                    @if(count($row['tasks']) === 0)
                                        <p class="px-4 py-3 text-xs text-slate-400">В этом месяце задач нет — у сотрудника только просрочка за прошлые месяцы (см. «Просрочено сейчас»)</p>
                                    @else
                                        <table class="w-full text-xs">
                                            <thead>
                                                <tr class="text-left text-[11px] text-slate-400">
                                                    <th class="px-4 py-2 font-medium">Задача</th>
                                                    <th class="px-4 py-2 font-medium">Компания</th>
                                                    <th class="px-4 py-2 font-medium text-right">Срок</th>
                                                    <th class="px-4 py-2 font-medium">Статус</th>
                                                    <th class="px-4 py-2 font-medium text-right">Возвраты</th>
                                                    <th class="px-4 py-2 font-medium text-right">Время</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                @foreach($row['tasks'] as $t)
                                                    <tr class="border-t border-slate-200/60">
                                                        <td class="px-4 py-2 text-slate-700">
                                                            {{ $t['name'] }}
                                                            @if($t['is_adhoc'])
                                                                <span class="ml-1.5 inline-block text-[10px] text-slate-400 border border-slate-200 px-1.5 py-px rounded-full align-middle">вне сметы</span>
                                                            @endif
                                                        </td>
                                                        <td class="px-4 py-2 text-slate-500">{{ $t['client_name'] }}</td>
                                                        <td class="px-4 py-2 text-right tabular-nums {{ $t['late'] ? 'text-red-600 font-semibold' : 'text-slate-400' }}">{{ $t['due_date'] ?? '—' }}</td>
                                                        <td class="px-4 py-2">
                                                            @php [$label, $dot] = $statusDot($t['status'], $t['late']); @endphp
                                                            <span class="inline-flex items-center gap-1.5 text-slate-500">
                                                                <span class="w-1.5 h-1.5 rounded-full {{ $dot }}"></span>{{ $label }}
                                                            </span>
                                                        </td>
                                                        <td class="px-4 py-2 text-right tabular-nums {{ $t['rework'] > 0 ? 'text-slate-700' : 'text-slate-300' }}">{{ $t['rework'] > 0 ? $t['rework'] : '—' }}</td>
                                                        <td class="px-4 py-2 text-right text-slate-500 tabular-nums">{{ $t['time'] ?? '—' }}</td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- По компаниям --}}
    <div class="bg-white rounded-2xl border border-slate-200/80 overflow-hidden">
        <div class="flex flex-wrap items-baseline gap-3 px-6 pt-5">
            <h2 class="text-base font-semibold text-slate-800">По компаниям</h2>
            <span class="text-xs text-slate-400">{{ $monthLabel }} · сом/час = смета ÷ время за месяц: чем ниже, тем дороже клиент обходится</span>
        </div>

        @if(count($byCompany) === 0)
            <div class="px-6 py-10 text-center">
                <p class="text-sm font-medium text-slate-600">Нет данных за выбранный месяц</p>
                <p class="text-xs text-slate-400 mt-1">Выберите другой период</p>
            </div>
        @else
            <div class="overflow-x-auto px-2 pb-2 pt-2">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left">
                            <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200">Компания</th>
                            <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200 text-right">Задач</th>
                            <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200 text-right">Выполнено</th>
                            <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200 text-right">Вовремя</th>
                            <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200 text-right">Просрочено</th>
                            <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200 text-right">Время</th>
                            <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200 text-right">Смета, сом</th>
                            <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200 text-right">сом/час</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($byCompany as $row)
                            @php $pct = $row['completed'] > 0 ? (int) round($row['on_time'] / $row['completed'] * 100) : null; @endphp
                            <tr class="border-b border-slate-100 last:border-0">
                                <td class="px-4 py-3 font-medium text-slate-700">{{ $row['name'] }}</td>
                                <td class="px-4 py-3 text-right text-slate-700 tabular-nums">
                                    {{ $row['total'] }}@if($row['adhoc'] > 0)<span class="text-xs text-slate-400" title="из них вне сметы"> +{{ $row['adhoc'] }}</span>@endif
                                </td>
                                <td class="px-4 py-3 text-right text-slate-500 tabular-nums">{{ $row['completed'] }}</td>
                                <td class="px-4 py-3 text-right">
                                    @if($pct === null)
                                        <span class="text-slate-300">—</span>
                                    @else
                                        <span class="inline-flex items-center justify-end gap-2">
                                            <span class="w-10 h-1 rounded-full bg-slate-100 overflow-hidden">
                                                <span class="block h-full rounded-full {{ $meterColor($pct) }}" style="width: {{ $pct }}%"></span>
                                            </span>
                                            <span class="font-medium text-slate-700 tabular-nums w-9">{{ $pct }}%</span>
                                        </span>
                                    @endif
                                </td>
                                <td class="px-4 py-3 text-right font-semibold tabular-nums {{ $row['overdue'] > 0 ? 'text-red-600' : 'text-slate-300 font-normal' }}">
                                    {{ $row['overdue'] > 0 ? $row['overdue'] : '—' }}
                                </td>
                                <td class="px-4 py-3 text-right text-slate-500 tabular-nums">{{ $row['time'] ?? '—' }}</td>
                                <td class="px-4 py-3 text-right text-slate-500 tabular-nums">
                                    {{ $row['estimate'] !== null ? number_format($row['estimate'], 0, ',', ' ') : '—' }}
                                </td>
                                <td class="px-4 py-3 text-right font-medium tabular-nums {{ $row['rate'] !== null ? 'text-slate-700' : 'text-slate-300' }}">
                                    {{ $row['rate'] !== null ? number_format($row['rate'], 0, ',', ' ') : '—' }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>

    {{-- Куда уходит время --}}
    <div class="bg-white rounded-2xl border border-slate-200/80 overflow-hidden">
        <div class="flex flex-wrap items-baseline gap-3 px-6 pt-5">
            <h2 class="text-base font-semibold text-slate-800">Куда уходит время</h2>
            <span class="text-xs text-slate-400">{{ $monthLabel }} · компании по затраченному времени</span>
        </div>

        @if(count($timeTop) === 0)
            <div class="px-6 py-10 text-center">
                <p class="text-sm font-medium text-slate-600">Таймеры в этом месяце не запускались</p>
                <p class="text-xs text-slate-400 mt-1">Бары появятся, когда сотрудники начнут отмечать время</p>
            </div>
        @else
            <div class="px-6 pt-4 pb-5 space-y-2.5">
                @foreach($timeTop as $r)
                    <div class="flex items-center gap-3" title="{{ $r['name'] }}: {{ $r['time'] }}">
                        <span class="w-44 shrink-0 truncate text-sm text-slate-600">{{ $r['name'] }}</span>
                        <div class="flex-1">
                            <div class="h-4 bg-indigo-600 rounded-r-[4px]" style="width: {{ max(1, round($r['elapsed'] / $timeMax * 100, 1)) }}%"></div>
                        </div>
                        <span class="w-16 shrink-0 text-right text-xs text-slate-500 tabular-nums">{{ $r['time'] }}</span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

</div>
@endsection
