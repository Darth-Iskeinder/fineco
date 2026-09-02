{{-- Чипы отобранного: по колонкам видно только значок воронки, а тут одной строкой
     видно всё, что сейчас включено, и каждое снимается на месте.

     $class — классы контейнера (у каждой страницы своя рамка и отступы). --}}
<div x-show="filterChips.length" class="{{ $class ?? '' }}">
    <template x-for="chip in visibleChips" :key="chip.key + ':' + chip.value">
        <button type="button" @click="toggleFacetValue(chip.key, chip.value)"
                class="inline-flex items-center gap-1 pl-2 pr-1.5 py-0.5 rounded-full text-xs font-medium bg-indigo-50 text-indigo-700 border border-indigo-100 hover:bg-indigo-100 transition-colors">
            <span class="text-indigo-400" x-text="chip.title + ':'"></span>
            <span x-text="chip.label"></span>
            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
        </button>
    </template>

    {{-- Остальные прячем за счётчиком: выбрать можно десятки значений, и полный
         список чипов уводил бы таблицу за нижний край экрана. --}}
    <button type="button" x-show="hiddenChipsCount" @click="chipsExpanded = true"
            class="text-xs text-slate-500 hover:text-indigo-600 underline decoration-dotted underline-offset-2">
        и ещё <span x-text="hiddenChipsCount"></span>
    </button>
    <button type="button" x-show="chipsExpanded && filterChips.length > chipsLimit" @click="chipsExpanded = false"
            class="text-xs text-slate-500 hover:text-indigo-600 underline decoration-dotted underline-offset-2">
        свернуть
    </button>
</div>
