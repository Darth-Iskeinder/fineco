{{-- Заголовок фильтруемой колонки: название плюс воронка.

     Фильтр стоит там же, где данные, и не занимает места над таблицей, пока не нужен.
     Само меню рисуется одно на страницу (см. блок с x-teleport внизу services.blade.php):
     таблица прокручивается внутри своего блока и обрезала бы выпадающий список.

     $key — ключ фасета в filters, $title — подпись колонки. --}}
<th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider whitespace-nowrap">
    <button type="button" @click.stop="toggleFilterMenu('{{ $key }}', $event)"
            class="inline-flex items-center gap-1 uppercase tracking-wider hover:text-indigo-600 transition-colors"
            :class="(filters['{{ $key }}'].length || openFilter === '{{ $key }}') ? 'text-indigo-600' : ''"
            title="Фильтр по колонке">
        <span>{{ $title }}</span>
        <svg class="w-3 h-3 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h18M6 10h12M10 16h4"/>
        </svg>
        <span x-show="filters['{{ $key }}'].length"
              class="inline-flex items-center justify-center min-w-[1rem] px-1 rounded-full bg-indigo-600 text-white text-[10px] leading-4"
              x-text="filters['{{ $key }}'].length"></span>
    </button>
</th>
