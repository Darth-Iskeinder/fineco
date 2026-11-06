{{--
    Окупаемость клиентов за месяц (ClientPayback). Только при включённых расчётах с клиентами.
    Ширины полосок и положение риски среднего задаются style: классов под проценты нет.
    Новые классы Tailwind не вводить без npm run build, сборка лежит в public/build.
--}}
@php
    $s     = $payback['summary'];
    $money = fn ($v) => \App\Models\ClientInvoice::money($v);
    $tierBar = ['worst' => 'bg-red-600', 'below' => 'bg-amber-500', 'above' => 'bg-indigo-600'];
    $dim   = $isCurrent ? 'opacity-60' : '';
@endphp

<div class="bg-white rounded-2xl border border-slate-200/80 overflow-hidden">
    <div class="flex flex-wrap items-baseline gap-3 px-6 pt-5">
        <h2 class="text-base font-semibold text-slate-800">Окупаемость</h2>
        <span class="text-xs text-slate-400">
            {{ $monthLabel }} · деньги из счетов, часы из таймеров{{ $s['timedShare'] !== null ? ', таймер у ' . $s['timedShare'] . '% начатых задач' : '' }}
        </span>
    </div>

    @if($isCurrent)
        <div class="mx-6 mt-3 rounded-xl bg-amber-50 border border-amber-200 px-4 py-2 text-xs text-amber-800">
            Месяц ещё идёт: счёт выставлен целиком, а часы только копятся. Сом за час к концу месяца упадёт, сравнивать лучше по закрытым месяцам.
        </div>
    @endif

    <div class="mx-6 mt-4 rounded-xl border border-slate-200 overflow-hidden">
        <div class="flex flex-wrap gap-px bg-slate-100">
            <div class="flex-1 bg-white px-5 py-4" style="min-width: 150px">
                <div class="text-[13px] text-slate-500">Выставлено за месяц</div>
                <div class="mt-2 text-2xl font-semibold text-slate-900 tabular-nums">{{ $money($s['billed']) }} <span class="text-sm font-medium text-slate-400">сом</span></div>
                <div class="mt-1 text-xs text-slate-400">{{ $s['billedCount'] }} {{ $plural($s['billedCount'], ['счёт', 'счёта', 'счетов']) }}</div>
            </div>
            <div class="flex-1 bg-white px-5 py-4" style="min-width: 150px">
                <div class="text-[13px] text-slate-500">Дебиторка сейчас</div>
                <div class="mt-2 text-2xl font-semibold text-slate-900 tabular-nums">{{ $money($s['debt']) }} <span class="text-sm font-medium text-slate-400">сом</span></div>
                <div class="mt-1 text-xs text-slate-400">{{ $s['debtors'] }} {{ $plural($s['debtors'], ['должник', 'должника', 'должников']) }}</div>
            </div>
            <div class="flex-1 bg-white px-5 py-4" style="min-width: 150px">
                <div class="text-[13px] text-slate-500">Просрочено больше 30 дней</div>
                <div class="mt-2 text-2xl font-semibold tabular-nums {{ $s['overdue'] > 0 ? 'text-red-600' : 'text-slate-900' }}">{{ $money($s['overdue']) }} <span class="text-sm font-medium text-slate-400">сом</span></div>
                <div class="mt-1 text-xs text-slate-400">
                    @if($s['debt'] > 0) {{ (int) round($s['overdue'] / $s['debt'] * 100) }}% дебиторки @else долгов нет @endif
                </div>
            </div>
            <div class="flex-1 bg-white px-5 py-4" style="min-width: 150px">
                <div class="text-[13px] text-slate-500">Часов по таймерам</div>
                <div class="mt-2 text-2xl font-semibold text-slate-900 tabular-nums {{ $dim }}">{{ $s['time'] }}</div>
                <div class="mt-1 text-xs text-slate-400">все задачи клиентов</div>
            </div>
            <div class="flex-1 bg-white px-5 py-4" style="min-width: 150px">
                <div class="text-[13px] text-slate-500">Средний сом за час</div>
                <div class="mt-2 text-2xl font-semibold tabular-nums {{ $s['avg'] === null ? 'text-slate-300' : 'text-slate-900' }} {{ $dim }}">{{ $s['avg'] === null ? '—' : $money($s['avg']) }}</div>
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
            $shareCell = function (array $r) {
                if ($r['share'] === null) {
                    return '<span class="text-slate-300">—</span>';
                }

                return '<span class="inline-flex items-center justify-end gap-2" title="Таймер у ' . $r['timed'] . ' из ' . $r['started'] . ' начатых задач">'
                    . '<span class="w-10 h-1 rounded-full bg-slate-100 overflow-hidden"><span class="block h-full rounded-full bg-slate-400" style="width: ' . $r['share'] . '%"></span></span>'
                    . '<span class="text-slate-500 tabular-nums w-9">' . $r['share'] . '%</span></span>';
            };
        @endphp
        <div class="overflow-x-auto px-2 pb-2 pt-2">
            <table class="w-full text-sm" style="min-width: 820px">
                <thead>
                    <tr class="text-left">
                        <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200">Компания</th>
                        <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200 text-right">Выставлено, сом</th>
                        <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200 text-right" title="Сколько клиент должен сегодня по всем счетам, от месяца не зависит">Долг сейчас</th>
                        <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200 text-right">Часов</th>
                        <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200 text-right" title="Из начатых задач месяца у скольких запускали таймер">Задач с таймером</th>
                        <th class="px-4 py-2.5 text-xs font-medium text-slate-400 border-b border-slate-200">Сом за час</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($payback['rated'] as $r)
                        <tr class="border-b border-slate-100 last:border-0">
                            <td class="px-4 py-3 font-medium text-slate-700">{{ $r['name'] }}</td>
                            <td class="px-4 py-3 text-right text-slate-700 tabular-nums whitespace-nowrap">{{ $money($r['sum']) }}</td>
                            @include('partials.dashboard-payback-debt', ['r' => $r, 'money' => $money])
                            <td class="px-4 py-3 text-right text-slate-500 tabular-nums whitespace-nowrap">{{ $r['time'] }}</td>
                            <td class="px-4 py-3 text-right">{!! $shareCell($r) !!}</td>
                            <td class="px-4 py-3 w-64 {{ $dim }}">
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

                    @foreach([['weak', 'Мало данных: таймер меньше чем у половины начатых задач', 'мало данных'], ['unbilled', 'Без счёта за месяц', 'нет счёта']] as [$group, $caption, $chip])
                        @if(count($payback[$group]) > 0)
                            <tr>
                                <td colspan="6" class="px-4 pt-4 pb-2 text-xs text-slate-400">{{ $caption }}</td>
                            </tr>
                            @foreach($payback[$group] as $r)
                                <tr class="border-b border-slate-100 last:border-0">
                                    <td class="px-4 py-3 text-slate-400">{{ $r['name'] }}</td>
                                    <td class="px-4 py-3 text-right text-slate-400 tabular-nums whitespace-nowrap">{{ $r['sum'] === null ? '—' : $money($r['sum']) }}</td>
                                    @include('partials.dashboard-payback-debt', ['r' => $r, 'money' => $money])
                                    <td class="px-4 py-3 text-right text-slate-400 tabular-nums whitespace-nowrap">{{ $r['time'] }}</td>
                                    <td class="px-4 py-3 text-right">{!! $shareCell($r) !!}</td>
                                    <td class="px-4 py-3">
                                        <span class="inline-block rounded-full px-2 text-[11px] whitespace-nowrap {{ $group === 'weak' ? 'bg-slate-100 text-slate-500' : 'bg-indigo-50 text-indigo-700' }}">{{ $chip }}</span>
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
