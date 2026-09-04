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
            {{-- Высота ограничена: длинный список скроллится внутри карточки и не растягивает страницу --}}
            <div class="overflow-x-auto overflow-y-auto max-h-[420px] px-2 pb-2 pt-2">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left">
                            <th class="sticky top-0 bg-white px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200">Задача</th>
                            <th class="sticky top-0 bg-white px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200">Компания</th>
                            <th class="sticky top-0 bg-white px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200">Исполнитель</th>
                            <th class="sticky top-0 bg-white px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200 text-right">Срок</th>
                            <th class="sticky top-0 bg-white px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200 text-right">Дней</th>
                            <th class="sticky top-0 bg-white px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200">Статус</th>
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
            <span class="text-xs text-slate-400">доля задач, закрытых в срок</span>
        </div>

        @if($judged === 0)
            <div class="px-6 py-10 text-center">
                <p class="text-sm font-medium text-slate-600">Пока нечего показать</p>
                <p class="text-xs text-slate-400 mt-1">Здесь появится помесячная сводка: сколько задач закрыто вовремя</p>
            </div>
        @else
            <div class="px-6 pt-5 pb-5">
                <div class="space-y-3">
                    @foreach($discipline as $m)
                        @php
                            $mTotal = $m['on_time'] + $m['late'] + $m['overdue'];
                            $mPct   = $mTotal > 0 ? (int) round($m['on_time'] / $mTotal * 100) : null;
                        @endphp
                        <div class="flex items-center gap-3"
                             title="{{ $m['label'] }}: вовремя {{ $m['on_time'] }}, с опозданием {{ $m['late'] }}, просрочено {{ $m['overdue'] }}">
                            <span class="w-14 shrink-0 text-xs text-slate-500">{{ $m['label'] }}</span>
                            <div class="flex-1">
                                @if($mTotal > 0)
                                    <div class="flex h-3 gap-px" style="width: {{ round($mTotal / $disciplineMax * 100, 1) }}%">
                                        @foreach([['on_time', 'bg-emerald-600'], ['late', 'bg-amber-600'], ['overdue', 'bg-red-600']] as [$k, $cls])
                                            @if($m[$k] > 0)
                                                <div class="{{ $cls }} rounded-sm min-w-[4px]" style="width: {{ round($m[$k] / $mTotal * 100, 1) }}%"></div>
                                            @endif
                                        @endforeach
                                    </div>
                                @else
                                    <span class="text-xs text-slate-300">задач с итогом нет</span>
                                @endif
                            </div>
                            <span class="w-32 shrink-0 text-right text-xs tabular-nums">
                                @if($mPct !== null)
                                    <span class="font-semibold text-slate-700">{{ $mPct }}% вовремя</span>
                                    <span class="text-slate-400"> · {{ $mTotal }}</span>
                                @else
                                    <span class="text-slate-300">—</span>
                                @endif
                            </span>
                        </div>
                    @endforeach
                </div>
                <div class="mt-4 flex flex-wrap gap-x-4 gap-y-1 text-xs">
                    <span class="inline-flex items-center gap-1.5 text-slate-500"><span class="w-1.5 h-1.5 rounded-full bg-emerald-600"></span>закрыто вовремя</span>
                    <span class="inline-flex items-center gap-1.5 text-slate-500"><span class="w-1.5 h-1.5 rounded-full bg-amber-600"></span>закрыто с опозданием</span>
                    <span class="inline-flex items-center gap-1.5 text-slate-500"><span class="w-1.5 h-1.5 rounded-full bg-red-600"></span>просрочено, не сдано</span>
                </div>
                <p class="mt-2.5 text-xs text-slate-400">Длина полоски — число задач месяца. Задачи в работе, у которых срок ещё не наступил, не учитываются.</p>
            </div>
        @endif
    </div>

    </div>

    {{-- По сотрудникам --}}
    @php
        // Доли кольца. Порядок и оттенки взяты из проверенной палитры (см. dataviz):
        // соседние доли различимы и при дальтонизме. Порядок не менять и седьмой
        // цвет не добавлять — хвост сворачивается в «Другие» ещё в контроллере.
        $ringColors = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300'];
        $otherColor = '#94a3b8';

        $memberColor = fn (array $m, int $i) => !empty($m['other']) ? $otherColor : ($ringColors[$i] ?? $otherColor);

        // Просрочка в полосе: заливка плюс штриховка, чтобы читалось не одним цветом
        $overdueFill = 'background:#f0b429;background-image:repeating-linear-gradient(135deg,rgba(255,255,255,.55) 0 2px,transparent 2px 5px)';

        $tasksWord = fn (int $n) => $plural($n, ['задача', 'задачи', 'задач']);
        $coWord    = fn (int $n) => $plural($n, ['компания', 'компании', 'компаний']);
        $lateWord  = fn (int $n) => $plural($n, ['просрочена', 'просрочены', 'просрочено']);
    @endphp

    <div class="bg-white rounded-2xl border border-slate-200/80 overflow-hidden">
        <div class="flex flex-wrap items-baseline gap-3 px-6 pt-5 pb-4">
            <h2 class="text-base font-semibold text-slate-800">По сотрудникам</h2>
            <span class="text-xs text-slate-400">{{ $monthLabel }} · только сметные задачи · строка раскрывает компании</span>
        </div>

        @if(count($leads) === 0 && count($accountants) === 0)
            <div class="px-6 py-10 text-center">
                <p class="text-sm font-medium text-slate-600">Нет сметных задач в выбранном месяце</p>
                <p class="text-xs text-slate-400 mt-1">Выберите другой период</p>
            </div>
        @else
            @if(count($leads) > 0)
                <div class="flex flex-wrap items-baseline gap-x-3 px-6 py-2 bg-slate-50 border-y border-slate-200/70">
                    <span class="text-[11px] font-medium uppercase tracking-wider text-slate-400">Главные бухгалтеры</span>
                    <span class="text-xs text-slate-400">объём по своим компаниям, вместе с розданным</span>
                </div>

                @foreach($leads as $row)
                    <div class="px-6 py-5 border-b border-slate-100 flex flex-col sm:flex-row sm:items-start gap-5" x-data="{ open: false }">
                        {{-- Кольцо: доли исполнителей в объёме компаний главбуха --}}
                        <div class="relative w-28 h-28 shrink-0 mx-auto sm:mx-0">
                            @php
                                $ringR    = 46;
                                $ringC    = 2 * M_PI * $ringR;
                                $ringGap  = count($row['members']) > 1 ? 5 : 0;
                                $ringFree = $ringC - $ringGap * count($row['members']);
                                $ringAt   = 0;
                            @endphp
                            <svg viewBox="0 0 120 120" class="absolute inset-0 w-full h-full -rotate-90" aria-hidden="true">
                                <circle cx="60" cy="60" r="{{ $ringR }}" fill="none" stroke-width="16" stroke="#f1f5f9" />
                                @foreach($row['members'] as $i => $m)
                                    @php
                                        $len = $row['total'] > 0 ? $ringFree * $m['total'] / $row['total'] : 0;
                                    @endphp
                                    <circle cx="60" cy="60" r="{{ $ringR }}" fill="none" stroke-width="16"
                                            stroke="{{ $memberColor($m, $i) }}"
                                            stroke-dasharray="{{ round($len, 2) }} {{ round($ringC - $len, 2) }}"
                                            stroke-dashoffset="{{ round(-$ringAt, 2) }}" />
                                    @php $ringAt += $len + $ringGap; @endphp
                                @endforeach
                            </svg>
                            <div class="absolute inset-0 flex flex-col items-center justify-center leading-none">
                                <span class="text-xl font-semibold text-slate-800 tabular-nums">{{ $row['total'] }}</span>
                                <span class="text-[11px] text-slate-400 mt-1">{{ $tasksWord($row['total']) }}</span>
                            </div>
                        </div>

                        <div class="flex-1 min-w-0 space-y-3">
                            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1">
                                <span class="text-base font-semibold text-slate-800">{{ $row['name'] }}</span>
                                <span class="text-[11px] px-2 py-0.5 rounded-full bg-indigo-50 text-indigo-600">{{ $row['role'] }}</span>
                                <span class="text-xs text-slate-400 tabular-nums">
                                    {{ count($row['companies']) }} {{ $coWord(count($row['companies'])) }}
                                    @if($row['overdue'] > 0)
                                        · <span class="text-red-600">{{ $row['overdue'] }} {{ $lateWord($row['overdue']) }}</span>
                                    @endif
                                </span>
                                <span class="sm:ml-auto text-sm text-slate-500 tabular-nums whitespace-nowrap">
                                    закрыто <span class="font-medium text-slate-700">{{ $row['completed'] }}</span> ·
                                    <span class="text-lg font-semibold text-slate-800">{{ $row['pct'] }}%</span>
                                </span>
                            </div>

                            <div>
                                @foreach($row['members'] as $i => $m)
                                    @php
                                        $donePct = $m['total'] > 0 ? round($m['completed'] / $m['total'] * 100, 1) : 0;
                                        $latePct = $m['total'] > 0 ? round($m['overdue'] / $m['total'] * 100, 1) : 0;
                                    @endphp
                                    <div class="grid grid-cols-[minmax(0,1fr)_44px] sm:grid-cols-[minmax(0,1fr)_88px_128px_44px] gap-3 items-center py-1.5 border-t border-slate-100 first:border-t-0">
                                        <div class="flex items-center gap-2 min-w-0 text-sm">
                                            <span class="w-2.5 h-2.5 rounded-sm shrink-0" style="background: {{ $memberColor($m, $i) }}"></span>
                                            <span class="truncate text-slate-700">{{ $m['name'] }}</span>
                                            @unless(!empty($m['other']))
                                                <span class="text-xs text-slate-400 shrink-0">{{ $m['self'] ? 'свои задачи' : 'бухгалтер' }}</span>
                                            @endunless
                                        </div>
                                        <div class="hidden sm:block text-right text-xs text-slate-500 tabular-nums">{{ $m['total'] }} {{ $tasksWord($m['total']) }}</div>
                                        <div class="hidden sm:block">
                                            <div class="relative h-2 rounded-full bg-slate-100 overflow-hidden"
                                                 title="закрыто {{ $m['completed'] }} из {{ $m['total'] }}@if($m['overdue'] > 0), просрочено {{ $m['overdue'] }}@endif">
                                                <span class="absolute inset-y-0 left-0 rounded-l-full" style="width: {{ $donePct }}%; background: {{ $memberColor($m, $i) }}"></span>
                                                @if($m['overdue'] > 0)
                                                    <span class="absolute inset-y-0" style="left: {{ $donePct }}%; width: {{ $latePct }}%; {{ $overdueFill }}"></span>
                                                @endif
                                            </div>
                                        </div>
                                        <div class="text-right text-sm text-slate-700 tabular-nums">{{ $m['pct'] }}%</div>
                                    </div>
                                @endforeach
                            </div>

                            @include('partials.dashboard-companies', ['row' => $row, 'coWord' => $coWord])
                        </div>
                    </div>
                @endforeach
            @endif

            @if(count($accountants) > 0)
                <div class="flex flex-wrap items-baseline gap-x-3 px-6 py-2 bg-slate-50 border-y border-slate-200/70">
                    <span class="text-[11px] font-medium uppercase tracking-wider text-slate-400">Бухгалтеры</span>
                    <span class="text-xs text-slate-400">только свои задачи, все компании сразу</span>
                </div>

                @foreach($accountants as $row)
                    @php
                        $donePct = $row['total'] > 0 ? round($row['completed'] / $row['total'] * 100, 1) : 0;
                        $latePct = $row['total'] > 0 ? round($row['overdue'] / $row['total'] * 100, 1) : 0;
                    @endphp
                    <div class="px-6 py-4 border-b border-slate-100" x-data="{ open: false }">
                        <div class="grid grid-cols-[minmax(0,1fr)_44px] sm:grid-cols-[minmax(0,1fr)_88px_128px_44px] gap-3 items-center">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                                    <span class="font-medium text-slate-700">{{ $row['name'] }}</span>
                                    <span class="text-[11px] px-2 py-0.5 rounded-full border border-slate-200 text-slate-500">{{ $row['role'] }}</span>
                                </div>
                                <div class="text-xs text-slate-400 mt-0.5">
                                    {{ count($row['companies']) }} {{ $coWord(count($row['companies'])) }}
                                    @if($row['overdue'] > 0)
                                        · <span class="text-red-600">{{ $row['overdue'] }} {{ $lateWord($row['overdue']) }}</span>
                                    @endif
                                    @if(count($row['leads']) > 0)
                                        {{-- Именительный падеж намеренно: «у Айдай Сыдыковой» требует склонения имени,
                                             а оно бывает любым. Двоеточие решает это без грамматики. --}}
                                        · {{ $plural(count($row['leads']), ['главбух', 'главбухи', 'главбухи']) }}: {{ implode(', ', $row['leads']) }}
                                    @endif
                                </div>
                            </div>
                            <div class="hidden sm:block text-right text-xs text-slate-500 tabular-nums">{{ $row['total'] }} {{ $tasksWord($row['total']) }}</div>
                            <div class="hidden sm:block">
                                <div class="relative h-2 rounded-full bg-slate-100 overflow-hidden"
                                     title="закрыто {{ $row['completed'] }} из {{ $row['total'] }}@if($row['overdue'] > 0), просрочено {{ $row['overdue'] }}@endif">
                                    <span class="absolute inset-y-0 left-0 rounded-l-full bg-indigo-600" style="width: {{ $donePct }}%"></span>
                                    @if($row['overdue'] > 0)
                                        <span class="absolute inset-y-0" style="left: {{ $donePct }}%; width: {{ $latePct }}%; {{ $overdueFill }}"></span>
                                    @endif
                                </div>
                            </div>
                            <div class="text-right text-sm font-semibold text-slate-800 tabular-nums">{{ $row['pct'] }}%</div>
                        </div>

                        <div class="mt-2">
                            @include('partials.dashboard-companies', ['row' => $row, 'coWord' => $coWord])
                        </div>
                    </div>
                @endforeach
            @endif

            <div class="flex flex-wrap gap-x-5 gap-y-2 px-6 py-3 bg-slate-50 text-xs text-slate-500">
                <span class="inline-flex items-center gap-2"><span class="w-5 h-2 rounded-full bg-indigo-600"></span> закрыто</span>
                <span class="inline-flex items-center gap-2"><span class="w-5 h-2 rounded-full" style="{{ $overdueFill }}"></span> срок прошёл, не закрыто</span>
                <span class="inline-flex items-center gap-2"><span class="w-5 h-2 rounded-full bg-slate-100"></span> в работе, срок не наступил</span>
                <span>Цвета кольца работают внутри строки главбуха, а не сквозь страницу</span>
            </div>
        @endif
    </div>

    {{-- По компаниям --}}
    <div class="bg-white rounded-2xl border border-slate-200/80 overflow-hidden">
        <div class="flex flex-wrap items-baseline gap-3 px-6 pt-5">
            <h2 class="text-base font-semibold text-slate-800">По компаниям</h2>
            <span class="text-xs text-slate-400">{{ $monthLabel }} · «Просрочено» — сейчас, вне периода</span>
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
            <span class="text-xs text-slate-400">{{ $monthLabel }} · рабочее время по таймерам задач, по компаниям</span>
        </div>

        @if(count($timeTop) === 0)
            <div class="px-6 py-10 text-center">
                <p class="text-sm font-medium text-slate-600">Таймеры в этом месяце не запускались</p>
                <p class="text-xs text-slate-400 mt-1">Бары появятся, когда сотрудники начнут отмечать время</p>
            </div>
        @else
            @php $totalElapsed = max(1, $stats['elapsed']); @endphp
            <div class="px-6 pt-4 pb-5">
                <p class="text-xs text-slate-400 pb-3">Всего за месяц по таймерам: <span class="font-medium text-slate-600">{{ $totalTime }}</span></p>
                <div class="space-y-2.5">
                    @foreach($timeTop as $r)
                        @php $share = (int) round($r['elapsed'] / $totalElapsed * 100); @endphp
                        <div class="flex items-center gap-3" title="{{ $r['name'] }}: {{ $r['time'] }} — {{ $share }}% времени месяца">
                            <span class="w-44 shrink-0 truncate text-sm text-slate-600">{{ $r['name'] }}</span>
                            <div class="flex-1">
                                <div class="h-4 bg-indigo-600 rounded-r-[4px]" style="width: {{ max(1, round($r['elapsed'] / $timeMax * 100, 1)) }}%"></div>
                            </div>
                            <span class="w-28 shrink-0 text-right text-xs text-slate-500 tabular-nums">{{ $r['time'] }} <span class="text-slate-400">· {{ $share }}%</span></span>
                        </div>
                    @endforeach
                    @if($timeRest)
                        @php $share = (int) round($timeRest['elapsed'] / $totalElapsed * 100); @endphp
                        <div class="flex items-center gap-3" title="Остальные {{ $timeRest['count'] }} {{ $plural($timeRest['count'], ['компания', 'компании', 'компаний']) }}: {{ $timeRest['time'] }}">
                            <span class="w-44 shrink-0 truncate text-sm text-slate-400">Остальные · {{ $timeRest['count'] }} {{ $plural($timeRest['count'], ['компания', 'компании', 'компаний']) }}</span>
                            <div class="flex-1">
                                <div class="h-4 bg-slate-300 rounded-r-[4px]" style="width: {{ max(1, round($timeRest['elapsed'] / $timeMax * 100, 1)) }}%"></div>
                            </div>
                            <span class="w-28 shrink-0 text-right text-xs text-slate-500 tabular-nums">{{ $timeRest['time'] }} <span class="text-slate-400">· {{ $share }}%</span></span>
                        </div>
                    @endif
                </div>
            </div>
        @endif
    </div>

</div>
@endsection
