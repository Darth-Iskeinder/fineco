{{--
    Окупаемость клиентов за месяц (ClientPayback). Только при включённых расчётах с клиентами.
    Месяц свой, в $payback['monthLabel']: в текущем месяце блок показывает прошлый, закрытый
    ($payback['shifted']). Переменные страницы $monthLabel/$isCurrent сюда видны, но про другой
    месяц, не путать.
    Долга здесь нет намеренно (09.11.2026): он на сегодня, а блок про месяц.
    Ширины полосок и положение риски среднего задаются style: классов под проценты нет.
    Новые классы Tailwind не вводить без npm run build, сборка лежит в public/build.
--}}
@php
    $s     = $payback['summary'];
    $money = fn ($v) => \App\Models\ClientInvoice::money($v);
    $tierBar = ['worst' => 'bg-red-600', 'below' => 'bg-amber-500', 'above' => 'bg-indigo-600'];
@endphp

<div class="bg-white rounded-2xl border border-slate-200/80 overflow-hidden">
    <div class="flex flex-wrap items-baseline gap-3 px-6 pt-5">
        <h2 class="text-base font-semibold text-slate-800">Окупаемость</h2>
        <span class="text-xs text-slate-400">
            {{ $payback['monthLabel'] }} · деньги из счетов, часы из таймеров{{ $s['timedShare'] !== null ? ', таймер у ' . $s['timedShare'] . '% начатых задач' : '' }}
        </span>
    </div>

    @if($payback['shifted'])
        <p class="px-6 mt-1 text-xs text-slate-500">
            Текущий месяц ещё идёт, часы в нём только копятся. Поэтому здесь прошлый, закрытый месяц.
        </p>
    @endif

    <div class="mx-6 mt-4 rounded-xl border border-slate-200 overflow-hidden">
        <div class="flex flex-wrap gap-px bg-slate-100">
            <div class="flex-1 bg-white px-5 py-4" style="min-width: 150px">
                <div class="text-[13px] text-slate-500">Выставлено за месяц</div>
                <div class="mt-2 text-2xl font-semibold text-slate-900 tabular-nums">{{ $money($s['billed']) }} <span class="text-sm font-medium text-slate-400">сом</span></div>
                <div class="mt-1 text-xs text-slate-400">{{ $s['billedCount'] }} {{ $plural($s['billedCount'], ['счёт', 'счёта', 'счетов']) }}</div>
            </div>
            <div class="flex-1 bg-white px-5 py-4" style="min-width: 150px">
                <div class="text-[13px] text-slate-500">Средний сом за час</div>
                <div class="mt-2 text-2xl font-semibold tabular-nums {{ $s['avg'] === null ? 'text-slate-300' : 'text-slate-900' }}">{{ $s['avg'] === null ? '—' : $money($s['avg']) }}</div>
                <div class="mt-1 text-xs text-slate-400">
                    @if($s['avg'] === null) не хватает данных @else по {{ $s['ratedCount'] }} {{ $plural($s['ratedCount'], ['клиенту', 'клиентам', 'клиентам']) }} с данными @endif
                </div>
            </div>
        </div>
    </div>

    @if(count($payback['rated']) + count($payback['weak']) + count($payback['unbilled']) === 0)
        <div class="px-6 py-10 text-center">
            <p class="text-sm font-medium text-slate-600">За этот месяц нет ни счетов, ни часов</p>
            <p class="text-xs text-slate-400 mt-1">Выставьте счета в «Расчётах с клиентами» или выберите другой месяц</p>
        </div>
    @else
        @php
            // Почему сом за час не посчитан: вместо метки прямо пишем, сколько задач с таймером
            $weakNote = fn (array $r) => $r['started'] > 0
                ? 'таймер у ' . $r['timed'] . ' из ' . $r['started'] . ' ' . $plural($r['started'], ['задачи', 'задач', 'задач'])
                : 'задачи не начинали';
        @endphp
        <div class="overflow-x-auto px-2 pb-2 pt-2">
            <table class="w-full text-sm" style="min-width: 560px">
                <thead>
                    <tr class="text-left">
                        <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200">Компания</th>
                        <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200 text-right">Выставлено, сом</th>
                        <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200 text-right">Часов</th>
                        <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200">Сом за час</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($payback['rated'] as $r)
                        <tr class="border-b border-slate-100 last:border-0">
                            <td class="px-4 py-3 font-medium text-slate-700">{{ $r['name'] }}</td>
                            <td class="px-4 py-3 text-right text-slate-700 tabular-nums whitespace-nowrap">{{ $money($r['sum']) }}</td>
                            <td class="px-4 py-3 text-right text-slate-500 tabular-nums whitespace-nowrap">{{ $r['time'] }}</td>
                            <td class="px-4 py-3 w-64">
                                <div class="flex items-center gap-3" title="Средний по фирме: {{ $money($s['avg']) }} сом за час">
                                    <div class="relative flex-1 h-2 rounded-full bg-slate-100">
                                        <div class="absolute inset-y-0 left-0 rounded-full {{ $tierBar[$r['tier']] }}" style="width: {{ round($r['rate'] / $s['max'] * 100, 1) }}%"></div>
                                        <div class="absolute bg-slate-700 rounded-full" style="left: {{ round($s['avg'] / $s['max'] * 100, 1) }}%; top: -4px; bottom: -4px; width: 2px"></div>
                                    </div>
                                    <span class="w-16 text-right font-semibold tabular-nums {{ $r['tier'] === 'worst' ? 'text-red-600' : 'text-slate-700' }}">{{ $money($r['rate']) }}</span>
                                </div>
                            </td>
                        </tr>
                    @endforeach

                    @foreach([['weak', 'Мало данных: таймер меньше чем у половины начатых задач, сом за час не считаем'], ['unbilled', 'Работали, а счёта за месяц нет']] as [$group, $caption])
                        @if(count($payback[$group]) > 0)
                            <tr>
                                <td colspan="4" class="px-4 pt-4 pb-2 text-xs text-slate-400">{{ $caption }}</td>
                            </tr>
                            @foreach($payback[$group] as $r)
                                <tr class="border-b border-slate-100 last:border-0">
                                    <td class="px-4 py-3 text-slate-400">{{ $r['name'] }}</td>
                                    <td class="px-4 py-3 text-right text-slate-400 tabular-nums whitespace-nowrap">{{ $r['sum'] === null ? '—' : $money($r['sum']) }}</td>
                                    <td class="px-4 py-3 text-right text-slate-400 tabular-nums whitespace-nowrap">{{ $r['time'] }}</td>
                                    <td class="px-4 py-3">
                                        @if($group === 'weak')
                                            <span class="text-xs text-slate-500 whitespace-nowrap">{{ $weakNote($r) }}</span>
                                        @else
                                            <span class="inline-block rounded-full px-2 text-[11px] whitespace-nowrap bg-indigo-50 text-indigo-700">нет счёта</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>

        @if(count($payback['rated']) > 0)
            <div class="flex flex-wrap gap-x-4 gap-y-1 px-6 pb-5 pt-1 text-xs text-slate-500">
                <span class="inline-flex items-center gap-1.5"><span class="bg-slate-700 rounded-full" style="width: 2px; height: 12px"></span>средний по фирме</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-red-600"></span>меньше половины среднего</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>ниже среднего</span>
                <span class="inline-flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-indigo-600"></span>выше среднего</span>
            </div>
        @endif
    @endif
</div>
