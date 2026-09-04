{{--
    Раскрытие строки сотрудника в список его компаний.

    Один и тот же кусок у главбуха и у бухгалтера, но смысл чисел разный: у
    главбуха это весь объём компании, у бухгалтера — только его часть в ней.
    Ждёт снаружи x-data с полем `open`.
--}}
<div class="pt-2 border-t border-slate-100">
    <button type="button" @click="open = !open"
            class="inline-flex items-center gap-1.5 text-xs text-slate-500 hover:text-slate-700 transition-colors">
        <span class="text-slate-300 transition-transform" :class="open && 'rotate-90'">›</span>
        {{ count($row['companies']) }} {{ $coWord(count($row['companies'])) }}
    </button>

    @php
        // Компании отсортированы от слабых к сильным. Показываем начало списка:
        // у главбуха их бывает больше сотни, и хвост со 100% читать незачем.
        $shown = array_slice($row['companies'], 0, 12);
        $rest  = count($row['companies']) - count($shown);
    @endphp

    <div x-show="open" x-cloak class="mt-2 grid gap-x-8 gap-y-1 sm:grid-cols-2 lg:grid-cols-3">
        @foreach($shown as $co)
            <div class="flex items-baseline gap-3 text-xs">
                <span class="flex-1 truncate text-slate-600">{{ $co['name'] }}</span>
                <span class="text-slate-400 tabular-nums">{{ $co['completed'] }} / {{ $co['total'] }}</span>
                <span class="w-9 text-right text-slate-700 tabular-nums">{{ $co['pct'] }}%</span>
            </div>
        @endforeach
    </div>

    @if($rest > 0)
        <p x-show="open" x-cloak class="mt-2 text-xs text-slate-400">
            Показаны 12 самых слабых, ещё {{ $rest }} {{ $coWord($rest) }} выше по проценту
        </p>
    @endif
</div>
