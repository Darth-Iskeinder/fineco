{{-- Меню воронки: одно на страницу, позиционируется по нажатой кнопке.
     Через teleport в body, иначе его обрезал бы прокручиваемый блок таблицы. --}}
<template x-teleport="body">
    {{-- style="display:none" (а не x-cloak): правила [x-cloak] может не быть в макете,
         и подложка на весь экран висела бы поверх страницы до старта Alpine. --}}
    <div x-show="openFilter" style="display: none;" class="fixed inset-0 z-40"
         @keydown.escape.window="closeFilterMenu()"
         @resize.window="positionFilterMenu()"
         @scroll.window="onScrollWhileFiltering()">
        {{-- Прозрачная подложка: клик мимо меню закрывает его, как в любой таблице. --}}
        <div class="absolute inset-0" @click="closeFilterMenu()"></div>

        <div class="absolute w-[260px] bg-white rounded-xl shadow-xl border border-slate-200 overflow-hidden"
             :style="'top:' + filterMenu.top + 'px; left:' + filterMenu.left + 'px'"
             @click.stop>
            <div class="px-3 pt-3 pb-2 flex items-center justify-between gap-2">
                <span class="text-xs font-semibold text-slate-700 uppercase tracking-wider"
                      x-text="openFilter ? facetByKey(openFilter).title : ''"></span>
                <button type="button" x-show="openFilter && filters[openFilter].length"
                        @click="clearFacet(openFilter)"
                        class="text-xs text-slate-400 hover:text-indigo-600">Сбросить</button>
            </div>

            {{-- Строка поиска нужна там, где значений много (группы, компании, режимы НО).
                 Показываем по ПОЛНОМУ числу значений: если считать по найденным,
                 поле пропадало бы от первой же введённой буквы. --}}
            <div class="px-3 pb-2" x-show="menuTotal > 7">
                <input type="text" x-model="filterSearch" x-ref="filterSearchInput"
                       placeholder="Найти значение…" @keydown.enter.prevent="closeFilterMenu()"
                       class="w-full px-2 py-1 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
            </div>

            {{-- «Выбрать все» работает по тому, что сейчас в списке: отобрал поиском
                 нужное — отметил одним нажатием, как в фильтре таблицы Excel. --}}
            <label x-show="menuOptions.length > 1"
                   class="flex items-center gap-2 px-3 py-1.5 text-sm text-slate-600 cursor-pointer hover:bg-slate-50 border-t border-slate-100">
                <input type="checkbox" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500/20"
                       :checked="menuAllChecked" @change="toggleMenuAll()">
                <span x-text="menuAllChecked ? 'Снять все' : 'Выбрать все'"></span>
                <span class="ml-auto text-xs text-slate-400" x-text="menuOptions.length"></span>
            </label>

            <div class="max-h-72 overflow-y-auto border-t border-slate-100">
                <template x-for="opt in menuOptions" :key="opt.value">
                    {{-- Значение с нулём оставляем видимым, но приглушаем: под текущим
                         отбором оно ничего не добавит, и это видно до нажатия. --}}
                    <label class="flex items-center gap-2 px-3 py-1.5 text-sm cursor-pointer hover:bg-slate-50"
                           :class="opt.count === 0 ? 'text-slate-400' : 'text-slate-700'">
                        <input type="checkbox" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500/20"
                               :checked="isFacetChecked(openFilter, opt.value)"
                               @change="toggleFacetValue(openFilter, opt.value)">
                        <span class="flex-1 truncate" :title="opt.label" x-text="opt.label"></span>
                        <span class="text-xs text-slate-400" x-text="opt.count"></span>
                    </label>
                </template>
                <div x-show="menuOptions.length === 0"
                     class="px-3 py-4 text-sm text-slate-400 text-center">Ничего не нашлось</div>
            </div>

            <div class="px-3 py-2 border-t border-slate-100 bg-slate-50/60 flex justify-end">
                <button type="button" @click="closeFilterMenu()"
                        class="px-3 py-1 text-xs font-medium text-slate-600 bg-white border border-slate-200 rounded-lg hover:bg-slate-50">
                    Готово
                </button>
            </div>
        </div>
    </div>
</template>
