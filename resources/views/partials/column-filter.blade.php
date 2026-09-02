{{-- Заголовок фильтруемой колонки: название плюс воронка.

     Фильтр стоит там же, где данные, и не занимает места над таблицей, пока не нужен.
     Само меню рисуется одно на страницу (см. partials.column-filter-menu): таблица
     прокручивается внутри своего блока и обрезала бы выпадающий список.

     Где есть и сортировка, и воронка, они разведены: между ними отступ и тонкая черта,
     у каждой кнопки своя площадь нажатия с запасом. Иначе стрелки сортировки и значок
     фильтра стоят вплотную, и промах по соседней кнопке делает не то, что человек ждал.

     $key   — ключ фасета в filters;
     $title — подпись колонки;
     $sort  — ключ сортировки (необязательно): рядом с названием появятся стрелки,
              как было до появления воронки, и оба действия живут в одном заголовке;
     $align — 'right' для колонки действий (необязательно);
     $thClass — дополнительные классы ячейки заголовка (необязательно). --}}
@php
    $sort    = $sort    ?? null;
    $align   = $align   ?? 'left';
    $thClass = $thClass ?? '';
@endphp
{{-- Класс пишем целиком, а не склейкой «text-» с переменной: сканер Tailwind
     читает исходники текстом и склеенного имени в сборке бы не оказалось. --}}
<th class="px-4 py-3 {{ $align === 'right' ? 'text-right' : 'text-left' }} text-xs font-medium text-slate-500 uppercase tracking-wider whitespace-nowrap {{ $thClass }}">
    <div class="inline-flex items-center">
        @if ($sort)
            <button type="button" @click="toggleSort('{{ $sort }}')"
                    class="group inline-flex items-center gap-1 px-1 py-1 -my-1 rounded uppercase tracking-wider hover:text-slate-700 hover:bg-slate-100 transition-colors"
                    title="Сортировать">
                <span>{{ $title }}</span>
                <span class="inline-flex flex-col leading-[0]">
                    <svg class="w-2 h-2" :class="sortBy === '{{ $sort }}' && sortDir === 'asc' ? 'text-indigo-600' : 'text-slate-300 group-hover:text-slate-400'" viewBox="0 0 8 8" fill="currentColor"><path d="M4 0l4 5H0z"/></svg>
                    <svg class="w-2 h-2 mt-0.5" :class="sortBy === '{{ $sort }}' && sortDir === 'desc' ? 'text-indigo-600' : 'text-slate-300 group-hover:text-slate-400'" viewBox="0 0 8 8" fill="currentColor"><path d="M4 8L0 3h8z"/></svg>
                </span>
            </button>

            {{-- Черта между двумя действиями: видно, что это разные кнопки, а не одна. --}}
            <span class="mx-1.5 h-3.5 w-px bg-slate-200" aria-hidden="true"></span>
        @endif

        {{-- Площадь нажатия крупнее самого значка: 28 на 28 против 14 на 14 у иконки.
             Мелкая мишень рядом с сортировкой — это промахи на каждом втором клике. --}}
        <button type="button" @click.stop="toggleFilterMenu('{{ $key }}', $event)"
                class="inline-flex items-center gap-1 px-1.5 py-1.5 -my-1.5 rounded uppercase tracking-wider hover:text-indigo-600 hover:bg-slate-100 transition-colors"
                :class="(filters['{{ $key }}'].length || openFilter === '{{ $key }}') ? 'text-indigo-600' : ''"
                title="Фильтр по колонке">
            @unless ($sort)
                <span>{{ $title }}</span>
            @endunless
            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h18M6 10h12M10 16h4"/>
            </svg>
            <span x-show="filters['{{ $key }}'].length"
                  class="inline-flex items-center justify-center min-w-[1rem] px-1 rounded-full bg-indigo-600 text-white text-[10px] leading-4"
                  x-text="filters['{{ $key }}'].length"></span>
        </button>
    </div>
</th>
