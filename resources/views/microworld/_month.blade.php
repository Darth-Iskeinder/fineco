{{-- Процент месяца, полоса и четыре числа. Ждёт: $m (счётчики с pct), $isCurrent, $empty (текст без задач). --}}
@php
    use App\Http\Controllers\MicroworldController as Page;

    $tasks        = ['задача', 'задачи', 'задач'];
    $notSubmitted = $m['overdue'] + $m['open'];
    $tone         = Page::tone($m['pct']);
@endphp

<div class="rounded-2xl border border-slate-200 bg-white p-5" data-month-card>
    @if ($m['total'] === 0)
        <p class="text-sm text-slate-500">{{ $empty }}</p>
    @else
        <div class="flex flex-wrap items-end gap-x-6 gap-y-2">
            <div>
                <p class="text-sm text-slate-500">Сдано</p>
                <p class="text-5xl font-bold leading-tight {{ $tone['text'] }}" data-pct>{{ $m['pct'] }}%</p>
            </div>
            <p class="pb-2 text-sm text-slate-600">
                {{ $m['done'] }} из {{ Page::plural($m['total'], $tasks) }}@if ($m['late'] > 0), из них {{ $m['late'] }} с опозданием@endif.
                @if ($notSubmitted > 0)
                    Не сдано: {{ $notSubmitted }}@if ($m['overdue'] > 0), из них просрочено {{ $m['overdue'] }}@endif.
                @else
                    Всё сдано.
                @endif
                @if ($isCurrent && $m['open'] > 0)
                    <br>Ещё можно успеть: {{ Page::plural($m['open'], $tasks) }}, срок не наступил.
                @endif
            </p>
        </div>

        <div class="mt-4 flex h-3 w-full overflow-hidden rounded-full bg-slate-100"
             title="В срок {{ $m['on_time'] }}, с опозданием {{ $m['late'] }}, просрочено {{ $m['overdue'] }}, срок не наступил {{ $m['open'] }}">
            <div class="{{ $tone['bar'] }}" style="width: {{ $m['done'] / $m['total'] * 100 }}%"></div>
        </div>
        <div class="mt-1 flex justify-between text-xs text-slate-400"><span>0%</span><span>100%</span></div>

        <div class="mt-4 grid grid-cols-2 gap-3 sm:grid-cols-4" data-counters>
            <div class="rounded-xl bg-emerald-50 px-3 py-2">
                <p class="text-xs text-emerald-700">В срок</p>
                <p class="text-xl font-semibold text-emerald-700">{{ $m['on_time'] }}</p>
            </div>
            <div class="rounded-xl bg-amber-50 px-3 py-2">
                <p class="text-xs text-amber-700">Сдано с опозданием</p>
                <p class="text-xl font-semibold text-amber-700">{{ $m['late'] }}</p>
            </div>
            <div class="rounded-xl bg-red-50 px-3 py-2">
                <p class="text-xs text-red-700">Просрочено</p>
                <p class="text-xl font-semibold text-red-700">{{ $m['overdue'] }}</p>
            </div>
            <div class="rounded-xl bg-slate-50 px-3 py-2">
                <p class="text-xs text-slate-600">Срок не наступил</p>
                <p class="text-xl font-semibold text-slate-700">{{ $m['open'] }}</p>
            </div>
        </div>
    @endif
</div>
