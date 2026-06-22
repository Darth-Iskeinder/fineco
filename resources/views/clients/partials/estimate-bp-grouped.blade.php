{{-- Сгруппированные строки БП: сфера → строки БП.
     Срок выполнения показан в самой строке БП, поэтому подгруппы по датам нет (дата не дублируется).
     $groups — имя Alpine-выражения с массивом [{sphere, bps:[]}]. --}}
<template x-for="sg in {{ $groups }}" :key="sg.sphere">
    <div>
        {{-- Заголовок сферы --}}
        <div class="px-6 py-2 bg-slate-100/70 border-b border-slate-200 flex items-center gap-2">
            <svg class="w-3.5 h-3.5 text-slate-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3.055 11H5a2 2 0 012 2v1a2 2 0 002 2 2 2 0 012 2v2.945M8 3.935V5.5A2.5 2.5 0 0010.5 8h.5a2 2 0 012 2 2 2 0 104 0 2 2 0 012-2h1.064M15 20.488V18a2 2 0 012-2h3.064"/></svg>
            <span class="text-xs font-semibold text-slate-600 uppercase tracking-wide" x-text="sg.sphere"></span>
        </div>

        <div class="divide-y divide-slate-100">
            @include('clients.partials.estimate-bp-rows', ['list' => 'sg.bps'])
        </div>
    </div>
</template>
