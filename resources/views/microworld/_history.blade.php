{{-- Столбики за полгода. Ждёт: $history, $focus, $url (замыкание ссылки с месяцем), $title. --}}
@php
    use App\Http\Controllers\MicroworldController as Page;
@endphp

<div class="rounded-2xl border border-slate-200 bg-white p-5" data-history>
    <p class="text-sm font-medium text-slate-700">{{ $title }}</p>
    <div class="mt-4 flex items-end gap-3 sm:gap-6">
        @foreach ($history as $h)
            @php
                $isFocus = $h['year'] === $focus->year && $h['month'] === $focus->month;
                $tone    = Page::tone($h['pct']);
                $tip     = $h['total'] === 0
                    ? 'Задач не было'
                    : "Сдано {$h['done']} из {$h['total']} (с опозданием {$h['late']}), просрочено {$h['overdue']}, срок не наступил {$h['open']}";
            @endphp
            <a href="{{ $url(['month' => sprintf('%04d-%02d', $h['year'], $h['month'])]) }}"
               class="group flex flex-1 flex-col items-center" title="{{ $tip }}">
                <span class="text-xs font-semibold {{ $tone['text'] }}">{{ $h['pct'] === null ? '—' : $h['pct'] . '%' }}</span>
                <div class="mt-1 flex h-32 w-full max-w-12 items-end rounded-md bg-slate-50">
                    <div class="w-full rounded-md {{ $tone['bar'] }} group-hover:opacity-80"
                         style="height: {{ max(2, (int) ($h['pct'] ?? 0)) }}%"></div>
                </div>
                <span @class(['mt-1 text-xs', 'font-semibold text-slate-900' => $isFocus, 'text-slate-500' => !$isFocus])>
                    {{ Page::MONTHS_SHORT[$h['month']] }}
                </span>
            </a>
        @endforeach
    </div>
    <p class="mt-3 text-xs text-slate-400">Текущий месяц ещё идёт, его процент растёт по мере сдачи задач.</p>
</div>
