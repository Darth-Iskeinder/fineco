{{-- Команда на клиентах главбуха: кольцо, таблица людей, справа просрочки выбранного.
     Ждёт: $team (members, ring, month). --}}
@php
    use App\Http\Controllers\MicroworldController as Page;

    $members = $team['members'];
    $ring    = $team['ring'];
    $total   = $team['month']['total'];
    $maxLoss = max(0.1, ...array_map(fn ($r) => $r['loss'], $members ?: [['loss' => 0]]));
    $fmt     = fn (float $v) => number_format($v, 1, ',', '');

    $ringR    = 46;
    $ringC    = 2 * M_PI * $ringR;
    $ringGap  = count($ring) > 1 ? 5 : 0;
    $ringFree = $ringC - $ringGap * count($ring);
    $ringAt   = 0;
@endphp

<div class="grid items-start gap-4 lg:grid-cols-3" x-data="{ sel: {{ $members[0]['id'] ?? 'null' }} }" data-team>
    <div class="rounded-2xl border border-slate-200 bg-white p-5 lg:col-span-2">
        <div class="flex flex-col gap-5 sm:flex-row sm:items-center">
            {{-- Кольцо: кто сколько задач месяца тянет на ваших клиентах --}}
            <div class="relative mx-auto h-28 w-28 shrink-0 sm:mx-0">
                <svg viewBox="0 0 120 120" class="absolute inset-0 h-full w-full -rotate-90" aria-hidden="true">
                    <circle cx="60" cy="60" r="{{ $ringR }}" fill="none" stroke-width="16" stroke="#f1f5f9" />
                    @foreach ($ring as $part)
                        @php $len = $total > 0 ? $ringFree * $part['total'] / $total : 0; @endphp
                        <circle cx="60" cy="60" r="{{ $ringR }}" fill="none" stroke-width="16" stroke="{{ $part['color'] }}"
                                stroke-dasharray="{{ round($len, 2) }} {{ round($ringC - $len, 2) }}"
                                stroke-dashoffset="{{ round(-$ringAt, 2) }}" />
                        @php $ringAt += $len + $ringGap; @endphp
                    @endforeach
                </svg>
                <div class="absolute inset-0 flex flex-col items-center justify-center leading-none">
                    <span class="text-xl font-semibold tabular-nums text-slate-800">{{ $total }}</span>
                    <span class="mt-1 text-[11px] text-slate-400">задач</span>
                </div>
            </div>
            <div>
                <p class="text-base font-semibold text-slate-800">Команда на моих клиентах</p>
                <p class="mt-1 text-sm text-slate-500">
                    Все задачи ваших клиентов за месяц и кто их делает. Нажмите на человека, справа появятся его просрочки.
                </p>
                <div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600">
                    @foreach ($ring as $part)
                        <span class="inline-flex items-center gap-1.5">
                            <span class="h-2.5 w-2.5 rounded-full" style="background: {{ $part['color'] }}"></span>
                            {{ $part['name'] }} · {{ $part['total'] }}
                        </span>
                    @endforeach
                </div>
            </div>
        </div>

        <div class="mt-5 overflow-x-auto">
            <table class="w-full text-sm" data-members>
                <thead>
                    <tr class="text-left text-xs text-slate-400">
                        <th class="py-2 pr-3 font-medium">Сотрудник</th>
                        <th class="py-2 pr-3 text-right font-medium">Задач</th>
                        <th class="py-2 pr-3 text-right font-medium">В срок</th>
                        <th class="py-2 pr-3 text-right font-medium">Поздно</th>
                        <th class="py-2 pr-3 text-right font-medium">Просрочено</th>
                        <th class="py-2 pr-3 text-right font-medium">%</th>
                        <th class="py-2 font-medium">Вклад в недобор</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($members as $r)
                        @php $tone = Page::tone($r['pct']); @endphp
                        <tr class="cursor-pointer hover:bg-slate-50" @click="sel = {{ $r['id'] }}"
                            :class="sel === {{ $r['id'] }} ? 'bg-indigo-50/60' : ''" data-member="{{ $r['id'] }}">
                            <td class="py-2.5 pr-3">
                                <div class="flex items-center gap-2">
                                    <span class="h-2.5 w-2.5 shrink-0 rounded-full" style="background: {{ $r['color'] }}"></span>
                                    <div class="min-w-0">
                                        <p class="font-medium text-slate-800">
                                            {{ $r['name'] }}@if ($r['is_me']) <span class="font-normal text-slate-400">(я)</span>@endif
                                            @if ($r['note'])
                                                <span class="ml-1 rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-normal text-slate-500">{{ $r['note'] }}</span>
                                            @endif
                                        </p>
                                        @if ($r['role'])
                                            <p class="text-xs text-slate-400">{{ $r['role'] }}</p>
                                        @endif
                                    </div>
                                </div>
                            </td>
                            <td class="py-2.5 pr-3 text-right tabular-nums text-slate-700">{{ $r['total'] }}</td>
                            <td class="py-2.5 pr-3 text-right tabular-nums text-slate-700">{{ $r['on_time'] }}</td>
                            <td class="py-2.5 pr-3 text-right tabular-nums text-slate-700">{{ $r['late'] }}</td>
                            <td class="py-2.5 pr-3 text-right tabular-nums font-semibold text-slate-800">{{ $r['overdue'] }}</td>
                            <td class="py-2.5 pr-3 text-right tabular-nums font-semibold {{ $tone['text'] }}">{{ $r['pct'] === null ? '—' : $r['pct'] . '%' }}</td>
                            <td class="py-2.5">
                                @if ($r['loss'] > 0)
                                    <div class="flex items-center gap-2">
                                        <div class="h-1.5 w-20 overflow-hidden rounded-full bg-slate-100">
                                            <div class="h-full rounded-full bg-red-500" style="width: {{ $r['loss'] / $maxLoss * 100 }}%"></div>
                                        </div>
                                        <span class="whitespace-nowrap text-xs font-semibold tabular-nums text-red-600">−{{ $fmt($r['loss']) }} п.п.</span>
                                    </div>
                                @else
                                    <span class="text-xs text-slate-300">—</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        <p class="mt-3 text-xs text-slate-400">
            Процент: сданные задачи, вовремя и с опозданием. Вклад в недобор: сколько процентов команды
            забрали просроченные задачи человека.
        </p>
    </div>

    {{-- Справа просрочки выбранного человека на ваших клиентах, за все месяцы --}}
    <div class="rounded-2xl border border-slate-200 bg-white p-5" data-member-overdue>
        <p class="text-xs font-medium uppercase tracking-wider text-slate-400">Просрочено сейчас</p>
        @foreach ($members as $r)
            <div x-show="sel === {{ $r['id'] }}" @if (!$loop->first) x-cloak @endif x-data="{ all: false }">
                <p class="mt-1 text-lg font-semibold text-slate-800">{{ $r['name'] }}</p>
                @if (count($r['overdue_now']) === 0)
                    <p class="mt-2 text-sm text-slate-500">Просроченных задач нет.</p>
                @else
                    <div class="mt-3 space-y-2">
                        @foreach ($r['overdue_now'] as $o)
                            <div class="rounded-xl bg-slate-50 px-3 py-2" @if ($loop->index >= 8) x-show="all" x-cloak @endif>
                                <div class="flex items-start justify-between gap-2">
                                    <p class="text-sm font-medium text-slate-800">{{ $o['name'] }}</p>
                                    <span class="whitespace-nowrap text-sm font-semibold text-red-600">+{{ Page::plural($o['days'], ['день', 'дня', 'дней']) }}</span>
                                </div>
                                <p class="text-xs text-slate-500">
                                    {{ $o['client'] }} · срок {{ $o['due']->format('d.m.Y') }}
                                    @if ($o['status'] === 'rework')
                                        <span class="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-amber-700">на доработке</span>
                                    @endif
                                </p>
                            </div>
                        @endforeach
                    </div>
                    @if (count($r['overdue_now']) > 8)
                        <button type="button" class="mt-2 text-sm text-indigo-600 hover:text-indigo-800"
                                x-show="!all" @click="all = true">Показать все {{ count($r['overdue_now']) }}</button>
                    @endif
                @endif
            </div>
        @endforeach
    </div>
</div>
