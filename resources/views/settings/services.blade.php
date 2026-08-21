@extends('settings.layout')
@section('page-title', 'Бизнес процессы')

@section('settings-content')
@php
$flagKeys = array_keys(\App\Models\Service::SPECIAL_FLAGS);
$servicesJson = $services->map(fn($s) => array_merge([
    'id'              => $s->id,
    'parent_id'       => $s->parent_id,
    'tax_systems'     => $s->taxSystems->map(fn($ts) => ['id' => $ts->id, 'name' => $ts->name])->values(),
    'name'             => $s->name,
    'description'      => $s->description,
    'sphere'           => $s->sphere,
    'service_group'    => $s->service_group,
    'business_process' => $s->business_process,
    'category'         => $s->category,
    'cost'             => $s->cost,
    'rate_id'          => $s->rate_id,
    'rate'             => $s->rate ? ['id' => $s->rate->id, 'name' => $s->rate->name, 'unit' => $s->rate->unit, 'price' => $s->rate->price] : null,
    'pricing_rules'   => $s->pricing_rules ?? [],
    'periodicity'       => $s->periodicity,
    'due_day'           => $s->due_day,
    'start_month'       => $s->start_month,
    'start_day'         => $s->start_day,
    'deadline'          => $s->deadlineLabels(),
    'deadline_days'     => $s->deadline_days,
    'execution_minutes' => $s->execution_minutes,
    'closing_rule'      => $s->closing_rule,
    'requires_document' => $s->requires_document,
    'check_type'        => $s->check_type,
    'requires_review'   => $s->requires_review,
    'billing'           => $s->billing,
    'comment'           => $s->comment,
    'is_active'         => $s->is_active,
    'archived_at'       => $s->archived_at?->toDateString(),
    'active_from'       => $s->active_from?->toDateString(),
    'allows_quantity'   => $s->allows_quantity,
    'splits_by_branch'  => $s->splits_by_branch,
    'sort_order'        => $s->sort_order,
    'children'        => $s->children->map(fn($c) => [
        'id'              => $c->id,
        'parent_id'       => $c->parent_id,
        'name'            => $c->name,
        'description'     => $c->description,
        'cost'            => $c->cost,
        'billing'         => $c->billing,
        'rate_id'         => $c->rate_id,
        'rate'            => $c->rate ? ['id' => $c->rate->id, 'name' => $c->rate->name, 'unit' => $c->rate->unit, 'price' => $c->rate->price] : null,
        'periodicity'     => $c->periodicity,
        'is_active'       => $c->is_active,
        'allows_quantity' => $c->allows_quantity,
        'sort_order'      => $c->sort_order,
        'children'        => [],
    ])->values(),
], collect($flagKeys)->mapWithKeys(fn($k) => [$k => (bool) $s->$k])->all()))->values();
@endphp

<div x-data="servicesPage()" class="space-y-4">

    {{-- Карточка ростом во весь экран: шапка с кнопкой и фильтры закреплены, прокручивается
         только таблица. Иначе при длинном списке страница уезжала вниз вместе с «Добавить БП». --}}
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden flex flex-col max-h-[calc(100vh-7rem)]">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between flex-shrink-0">
            <div>
                <h2 class="text-lg font-semibold text-slate-800">Бизнес-процессы</h2>
                <p class="text-sm text-slate-500 mt-0.5">Перечень услуг, оказываемых клиентам</p>
            </div>
            <button @click="openServiceModal()" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Добавить БП
            </button>
        </div>
        <div class="px-6 py-3 border-b border-slate-100 flex flex-wrap items-center gap-4 bg-slate-50/40 flex-shrink-0">
            <div class="relative">
                <span class="absolute inset-y-0 left-0 pl-3 flex items-center text-slate-400 pointer-events-none">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M11 19a8 8 0 100-16 8 8 0 000 16z"/></svg>
                </span>
                <input type="text" x-model="searchQuery" placeholder="Поиск по бизнес-процессам…"
                       class="w-64 pl-9 pr-8 py-1.5 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                <button x-show="searchQuery" @click="searchQuery = ''" class="absolute inset-y-0 right-0 pr-2.5 flex items-center text-slate-400 hover:text-slate-600">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            <div class="flex items-center gap-2">
                <label class="text-sm text-slate-600">Сфера:</label>
                <select x-model="sphereFilter" class="px-3 py-1.5 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                    <option value="">Все сферы</option>
                    <template x-for="sph in sphereOptions" :key="sph">
                        <option :value="sph" x-text="sph"></option>
                    </template>
                </select>
            </div>
            <label class="inline-flex items-center gap-2 text-sm text-slate-600 cursor-pointer select-none">
                <input type="checkbox" x-model="groupBySphere" class="rounded border-slate-300 text-indigo-600 focus:ring-indigo-500/20">
                Группировать по сферам
            </label>
            <span class="text-xs text-slate-400" x-show="sphereFilter || searchQuery.trim()" x-text="visibleServices.length + ' из ' + services.length"></span>
        </div>
        <div class="flex-1 min-h-0 overflow-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50 sticky top-0 z-20 [&_th]:bg-slate-50">
                    <tr>
                        {{-- Название закреплено слева: при прокрутке вправо видно, о каком БП строка --}}
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider whitespace-nowrap sticky left-0 z-30 border-r border-slate-200">Бизнес процесс</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider whitespace-nowrap">Сфера</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider whitespace-nowrap">Группа</th>
                        {{-- TODO: колонка "Бизнес процесс" (поле business_process) удалена — дублировала "Название", рассмотреть удаление поля из таблицы --}}
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider whitespace-nowrap">Категория</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider whitespace-nowrap">Режим НО</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider whitespace-nowrap">Периодичность</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider whitespace-nowrap">Дедлайн</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider whitespace-nowrap">План (мин.)</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider whitespace-nowrap">Правило закрытия</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider whitespace-nowrap">Проверка</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider whitespace-nowrap">Биллинг</th>
                        {{-- TODO: колонка "Стоимость" скрыта — возможно не нужна в таблице БП, рассмотреть удаление поля cost из services --}}
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider whitespace-nowrap">Комментарий</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider whitespace-nowrap">Действия</th>
                    </tr>
                </thead>
                <template x-for="row in displayRows" :key="row.key">
                    <tbody class="bg-white divide-y divide-slate-100">
                        <tr x-show="row.type === 'header'" class="bg-slate-100">
                            <td colspan="13" class="px-4 py-2 text-sm font-semibold text-slate-700">
                                {{-- Название сферы прижато к левому краю окна: при прокрутке вправо
                                     остаётся видно, в какой группе находишься. --}}
                                <div class="sticky left-0 w-fit">
                                    <span x-text="row.sphere"></span>
                                    <span class="ml-1 font-normal text-slate-400" x-text="'(' + row.count + ')'"></span>
                                </div>
                            </td>
                        </tr>

                        <tr x-show="row.type === 'service'"
                            @click="selectedRowId = (selectedRowId === 's' + row.svc.id ? null : 's' + row.svc.id)"
                            @dblclick="openServiceModal(row.svc)"
                            :class="selectedRowId === 's' + row.svc.id ? 'bg-indigo-50/70 ring-1 ring-inset ring-indigo-200' : 'hover:bg-slate-50'"
                            class="group cursor-pointer select-none">
                            {{-- Закреплённая колонка: фон обязателен и должен быть непрозрачным,
                                 иначе под неё будут просвечивать уезжающие ячейки. --}}
                            {{-- Ширина фиксирована: колонка закреплена и не должна съедать экран.
                                 Длинное название обрезается (полное — в подсказке), метки условий
                                 переносятся на следующую строку. --}}
                            <td class="px-4 py-3 text-sm font-semibold text-slate-900 sticky left-0 z-10 border-r border-slate-200"
                                :class="selectedRowId === 's' + row.svc.id ? 'bg-indigo-50' : 'bg-white group-hover:bg-slate-50'">
                                <div class="flex flex-wrap items-center gap-1 w-64">
                                    {{-- Подпункты свёрнуты: у БП их бывает по пять штук, и раскрытыми
                                         они раздували список так, что сами БП терялись. Цифра у стрелки
                                         показывает, сколько внутри, чтобы не раскрывать ради проверки. --}}
                                    <button type="button" x-show="(row.svc.children || []).length > 0"
                                            @click.stop="toggleChildren(row.svc.id)"
                                            :title="childrenVisible(row.svc) ? 'Свернуть подпункты' : 'Показать подпункты'"
                                            class="flex items-center gap-0.5 -ml-1 px-1 py-0.5 rounded text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 transition-colors flex-shrink-0">
                                        <svg class="w-3.5 h-3.5 transition-transform" :class="childrenVisible(row.svc) ? 'rotate-90' : ''"
                                             fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                        <span class="text-xs font-medium" x-text="(row.svc.children || []).length"></span>
                                    </button>
                                    {{-- У БП без подпунктов место под стрелку остаётся: иначе названия
                                         в колонке скакали бы влево-вправо от строки к строке. --}}
                                    <span x-show="(row.svc.children || []).length === 0" class="w-3.5 flex-shrink-0"></span>
                                    <span class="truncate max-w-full" :class="row.svc.archived_at ? 'text-slate-400' : ''" :title="row.svc.name" x-text="row.svc.name"></span>
                                    {{-- Архивный БП остаётся в списке: он часть истории, по нему
                                         доделывают незакрытое. Отличаем пометкой, а не пряча. --}}
                                    <span x-show="row.svc.archived_at" class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-slate-200 text-slate-600">архивный</span>
                                    <span x-show="row.svc.active_from" class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-emerald-50 text-emerald-700"
                                          :title="'Задачи по нему пойдут с ' + formatDate(row.svc.active_from)"
                                          x-text="'с ' + formatDate(row.svc.active_from)"></span>
                                    <span x-show="row.svc.allows_quantity" class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700">кол-во</span>
                                    <span x-show="row.svc.splits_by_branch" class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-700">
                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 6h4"/></svg>
                                        по филиалам
                                    </span>
                                    <template x-for="f in specialFlags" :key="f.key">
                                        <span x-show="row.svc[f.key]" class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium" :class="'bg-' + f.color + '-100 text-' + f.color + '-700'" x-text="f.label"></span>
                                    </template>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-500 whitespace-nowrap" x-text="row.svc.sphere || '—'"></td>
                            <td class="px-4 py-3 text-sm text-slate-500 whitespace-nowrap" x-text="row.svc.service_group || '—'"></td>
                            {{-- TODO: ячейка "Бизнес процесс" (поле business_process) удалена — дублировала "Название", рассмотреть удаление поля из таблицы --}}
                            <td class="px-4 py-3 text-sm text-slate-500 whitespace-nowrap" x-text="row.svc.category || '—'"></td>
                            <td class="px-4 py-3 text-sm text-slate-600">
                                <div class="flex flex-wrap gap-1">
                                    <template x-if="row.svc.tax_systems && row.svc.tax_systems.length > 0">
                                        <template x-for="ts in row.svc.tax_systems" :key="ts.id">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700" x-text="ts.name"></span>
                                        </template>
                                    </template>
                                    <template x-if="!row.svc.tax_systems || row.svc.tax_systems.length === 0"><span class="text-slate-400">—</span></template>
                                </div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-slate-500" x-text="row.svc.periodicity || '—'"></td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm">
                                <div class="flex flex-wrap gap-1">
                                    <template x-for="(d, di) in (row.svc.deadline || [])" :key="di">
                                        <span class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-slate-100 text-slate-600" x-text="d"></span>
                                    </template>
                                    <span x-show="!row.svc.deadline || row.svc.deadline.length === 0" class="text-slate-400">—</span>
                                </div>
                            </td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-slate-500" x-text="row.svc.execution_minutes ? row.svc.execution_minutes + ' мин.' : '—'"></td>
                            <td class="px-4 py-3 text-sm whitespace-nowrap">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                                      :class="row.svc.requires_document ? 'bg-blue-100 text-blue-700' : 'bg-slate-100 text-slate-500'"
                                      x-text="row.svc.requires_document ? 'С документом' : 'Без документа'"></span>
                            </td>
                            <td class="px-4 py-3 text-sm whitespace-nowrap">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                                      :class="row.svc.requires_review ? 'bg-amber-100 text-amber-700' : 'bg-emerald-100 text-emerald-700'"
                                      x-text="row.svc.requires_review ? 'Проверка' : 'Самоконтроль'"></span>
                            </td>
                            <td class="px-4 py-3 text-sm text-slate-500 whitespace-nowrap" x-text="row.svc.billing || '—'"></td>
                            {{-- TODO: ячейка "Стоимость" скрыта — возможно не нужна в таблице БП, рассмотреть удаление поля cost из services --}}
                            <td class="px-4 py-3 text-sm text-slate-500 max-w-[160px] truncate" x-text="row.svc.comment || '—'"></td>
                            <td class="px-4 py-3 whitespace-nowrap text-right">
                                <div class="flex items-center justify-end gap-1">
                                    {{-- Архивный не редактируем: расписание читается живьём, и правка
                                         задним числом сдвинула бы уже посчитанные задачи. --}}
                                    <button x-show="!row.svc.archived_at" @click="openServiceModal(row.svc)" class="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors" title="Редактировать">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                    </button>
                                    <button x-show="!row.svc.archived_at" @click="openArchiveModal(row.svc)" class="p-1.5 text-slate-400 hover:text-amber-600 hover:bg-amber-50 rounded-lg transition-colors" title="Архивировать">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                                    </button>
                                    <button x-show="row.svc.archived_at" @click="openRestoreModal(row.svc)" class="p-1.5 text-slate-400 hover:text-emerald-600 hover:bg-emerald-50 rounded-lg transition-colors" title="Вернуть в работу">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                    </button>
                                    <button @click="openDeleteModal(row.svc)" class="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Удалить">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>

                        <template x-for="child in (row.type === 'service' && childrenVisible(row.svc) ? (row.svc.children || []) : [])" :key="child.id">
                            <tr @click="selectedRowId = (selectedRowId === 'c' + child.id ? null : 'c' + child.id)"
                                @dblclick="openServiceModal(child)"
                                :class="selectedRowId === 'c' + child.id ? 'bg-indigo-100/70 ring-1 ring-inset ring-indigo-200' : 'bg-slate-50/50 hover:bg-slate-50'"
                                class="group cursor-pointer select-none">
                                <td class="pl-10 pr-4 py-2.5 text-sm text-slate-600 sticky left-0 z-10 border-r border-slate-200"
                                    :class="selectedRowId === 'c' + child.id ? 'bg-indigo-100' : 'bg-slate-50'">
                                    <div class="flex items-center gap-1.5 w-56">
                                        <svg class="w-3 h-3 text-slate-300 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                        <span class="truncate" :title="child.name" x-text="child.name"></span>
                                        <span x-show="child.allows_quantity" class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700 flex-shrink-0">кол-во</span>
                                    </div>
                                </td>
                                <td class="px-4 py-2.5 text-sm text-slate-300">—</td>
                                <td class="px-4 py-2.5 text-sm text-slate-300">—</td>
                                {{-- TODO: ячейка "Бизнес процесс" удалена — см. комментарий в thead --}}
                                <td class="px-4 py-2.5 text-sm text-slate-300">—</td>
                                <td class="px-4 py-2.5 text-sm text-slate-300">—</td>
                                    <td class="px-4 py-2.5 whitespace-nowrap text-sm text-slate-400" x-text="child.periodicity || '—'"></td>
                                <td class="px-4 py-2.5 text-sm text-slate-300">—</td>
                                <td class="px-4 py-2.5 text-sm text-slate-300">—</td>
                                <td class="px-4 py-2.5 text-sm text-slate-300">—</td>
                                <td class="px-4 py-2.5 text-sm text-slate-300">—</td>
                                <td class="px-4 py-2.5 text-sm text-slate-300">—</td>
                                {{-- TODO: ячейка "Стоимость" скрыта — см. комментарий в thead --}}
                                <td class="px-4 py-2.5 text-sm text-slate-300">—</td>
                                <td class="px-4 py-2.5 whitespace-nowrap text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <button @click="openServiceModal(child)" class="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors" title="Редактировать">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                        </button>
                                        <button @click="openDeleteModal(child, row.svc.id)" class="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Удалить">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </template>
                <tbody x-show="displayRows.length === 0">
                    <tr><td colspan="13" class="px-6 py-10 text-center text-slate-400" x-text="services.length === 0 ? 'Нет бизнес-процессов. Нажмите «Добавить БП» чтобы создать первый.' : 'Нет бизнес-процессов в выбранной сфере.'"></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    {{-- Service modal --}}
    <template x-teleport="body">
        <div x-show="showServiceModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20">
                <div class="fixed inset-0 bg-slate-500/75" @click="showServiceModal = false"></div>
                <div class="relative w-full max-w-2xl bg-white rounded-2xl shadow-xl p-6 z-10">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-semibold text-slate-900" x-text="serviceForm.id ? (serviceForm.parent_id ? 'Редактирование подпункта' : 'Редактирование БП') : 'Новый бизнес-процесс'"></h3>
                        <button @click="showServiceModal = false" class="p-2 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <form @submit.prevent="submitServiceForm()">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Название <span class="text-red-500">*</span></label>
                                <input type="text" x-model="serviceForm.name" required class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Описание</label>
                                <textarea x-model="serviceForm.description" rows="2" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 resize-none"></textarea>
                            </div>

                            {{-- Дополнительные классификационные поля --}}
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Сфера</label>
                                    <select x-model="serviceForm.sphere" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white">
                                        <option value="">— не указана —</option>
                                        <template x-if="serviceForm.sphere && !spheres.includes(serviceForm.sphere)">
                                            <option :value="serviceForm.sphere" x-text="serviceForm.sphere"></option>
                                        </template>
                                        <template x-for="sph in spheres" :key="sph">
                                            <option :value="sph" x-text="sph"></option>
                                        </template>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Группа</label>
                                    <select x-model="serviceForm.service_group" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white">
                                        <option value="">— не указана —</option>
                                        <template x-if="serviceForm.service_group && !groups.includes(serviceForm.service_group)">
                                            <option :value="serviceForm.service_group" x-text="serviceForm.service_group"></option>
                                        </template>
                                        <template x-for="g in groups" :key="g">
                                            <option :value="g" x-text="g"></option>
                                        </template>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Категория</label>
                                    <select x-model="serviceForm.category" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white">
                                        <option value="">— не указана —</option>
                                        <template x-if="serviceForm.category && !categories.includes(serviceForm.category)">
                                            <option :value="serviceForm.category" x-text="serviceForm.category"></option>
                                        </template>
                                        <template x-for="c in categories" :key="c">
                                            <option :value="c" x-text="c"></option>
                                        </template>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">План выполнения (мин.)</label>
                                    <input type="number" x-model="serviceForm.execution_minutes" min="0" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Правило закрытия</label>
                                    <div class="flex items-center gap-2">
                                        <button type="button" @click="serviceForm.requires_document = !serviceForm.requires_document"
                                                :class="serviceForm.requires_document ? 'bg-indigo-600' : 'bg-slate-300'"
                                                class="relative inline-flex h-6 w-11 flex-shrink-0 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
                                            <span :class="serviceForm.requires_document ? 'translate-x-6' : 'translate-x-1'" class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform"></span>
                                        </button>
                                        <span class="text-sm text-slate-700" x-text="serviceForm.requires_document ? 'С документом' : 'Без документа'"></span>
                                    </div>
                                    <p class="mt-1 text-xs text-slate-400" x-text="serviceForm.requires_document ? 'Для закрытия нужно прикрепить документ' : 'Можно закрыть без документа'"></p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Проверка</label>
                                    <div class="flex items-center gap-2">
                                        <button type="button" @click="serviceForm.requires_review = !serviceForm.requires_review"
                                                :class="serviceForm.requires_review ? 'bg-indigo-600' : 'bg-slate-300'"
                                                class="relative inline-flex h-6 w-11 flex-shrink-0 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
                                            <span :class="serviceForm.requires_review ? 'translate-x-6' : 'translate-x-1'" class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform"></span>
                                        </button>
                                        <span class="text-sm text-slate-700" x-text="serviceForm.requires_review ? 'Обязательная проверка' : 'Самоконтроль'"></span>
                                    </div>
                                    <p class="mt-1 text-xs text-slate-400" x-text="serviceForm.requires_review ? 'Задачу закрывает аудитор после проверки' : 'Задача закрывается сразу после «выполнено»'"></p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Биллинг</label>
                                    {{-- Биллинг задаётся только на основном БП; подпункты наследуют режим и ставку --}}
                                    <template x-if="serviceForm.parent_id">
                                        <div class="px-3 py-2 rounded-lg bg-slate-50 border border-slate-200 text-xs text-slate-500">Наследуется от основного БП</div>
                                    </template>
                                    <template x-if="!serviceForm.parent_id">
                                        <select x-model="serviceForm.billing" @change="onBillingChange()" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white">
                                            <option value="">— не указан —</option>
                                            <template x-if="serviceForm.billing && !billings.some(b => b.name === serviceForm.billing)">
                                                <option :value="serviceForm.billing" x-text="serviceForm.billing"></option>
                                            </template>
                                            <template x-for="b in billings" :key="b.name">
                                                <option :value="b.name" x-text="b.name"></option>
                                            </template>
                                        </select>
                                    </template>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Количество</label>
                                    {{-- Количество, как и биллинг, задаётся только на основном БП --}}
                                    <template x-if="serviceForm.parent_id">
                                        <div class="px-3 py-2 rounded-lg bg-slate-50 border border-slate-200 text-xs text-slate-500">Наследуется от основного БП</div>
                                    </template>
                                    <template x-if="!serviceForm.parent_id">
                                        <div>
                                            <div class="flex items-center gap-2">
                                                <button type="button" @click="serviceForm.allows_quantity = !serviceForm.allows_quantity"
                                                        :class="serviceForm.allows_quantity ? 'bg-indigo-600' : 'bg-slate-300'"
                                                        class="relative inline-flex h-6 w-11 flex-shrink-0 items-center rounded-full transition-colors focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
                                                    <span :class="serviceForm.allows_quantity ? 'translate-x-6' : 'translate-x-1'" class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform"></span>
                                                </button>
                                                <span class="text-sm text-slate-700" x-text="serviceForm.allows_quantity ? 'Можно указывать количество' : 'Без количества'"></span>
                                            </div>
                                            <p class="mt-1 text-xs text-slate-400" x-text="serviceForm.allows_quantity ? 'В смете вводится кол-во (напр. операций)' : 'В смете количество не вводится'"></p>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            {{-- Тарификация основного БП: ставка распространяется и на его подпункты --}}
                            <template x-if="isPaidBilling && !serviceForm.parent_id">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Ставка <span class="text-red-500">*</span></label>
                                    <select x-model="serviceForm.rate_id" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white">
                                        <option value="">— выберите ставку —</option>
                                        <template x-for="r in rates" :key="r.id">
                                            <option :value="r.id" x-text="rateLabel(r)"></option>
                                        </template>
                                    </select>
                                    <p class="mt-1 text-xs text-slate-500" x-show="selectedRate" x-text="selectedRate ? ('Цена в смете: ' + formatPrice(selectedRate.price) + (selectedRate.unit ? ' / ' + selectedRate.unit : '') + ' × кол-во') : ''"></p>
                                    <p class="mt-1 text-xs text-amber-600" x-show="!serviceForm.rate_id">Выберите ставку — из неё берётся цена и единица измерения.</p>
                                    <p class="mt-1 text-xs text-slate-400" x-show="serviceForm.children.length > 0">Ставка применяется и к подпунктам этого БП.</p>
                                </div>
                            </template>
                            <template x-if="isFreeBilling && !serviceForm.parent_id">
                                <div class="rounded-lg bg-slate-50 border border-slate-200 px-3 py-2 text-xs text-slate-500">
                                    <span x-show="selectedBillingCode === 'included'">Цена 0 — услуга входит в абонентскую плату.</span>
                                    <span x-show="selectedBillingCode === 'none'">Не тарифицируется — цена 0 (контрольная задача).</span>
                                </div>
                            </template>

                            <div class="grid grid-cols-2 gap-4">
                                <div class="col-span-2">
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Периодичность</label>
                                    <select x-model="serviceForm.periodicity" @change="onPeriodicityChange()" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white">
                                        <option value="">— не указана —</option>
                                        <template x-for="p in periodicities" :key="p.name">
                                            <option :value="p.name" x-text="p.name"></option>
                                        </template>
                                    </select>
                                </div>

                                {{-- Месяц: теги месяцев для ежеквартально/ежегодно, иначе недоступно --}}
                                <div class="col-span-2">
                                    <label class="block text-sm font-medium text-slate-700 mb-1">
                                        Месяц
                                        <span x-show="monthFieldEnabled && monthMultiple" class="text-slate-400 font-normal">(можно выбрать несколько)</span>
                                    </label>
                                    <template x-if="monthFieldEnabled">
                                        <div class="flex flex-wrap gap-1.5">
                                            <template x-for="(m, i) in months" :key="i">
                                                <button type="button" @click="toggleMonth(i + 1)"
                                                        :class="isMonthSelected(i + 1) ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50'"
                                                        class="px-2.5 py-1 rounded-lg border text-xs font-medium transition-colors" x-text="m"></button>
                                            </template>
                                        </div>
                                    </template>
                                    <template x-if="!monthFieldEnabled">
                                        <div class="flex items-center gap-2 px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-sm text-slate-400">
                                            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                                            <span x-text="monthDisabledHint"></span>
                                        </div>
                                    </template>
                                </div>

                                {{-- День: дни недели тегами для еженедельно, иначе день месяца --}}
                                <div class="col-span-2">
                                    <label class="block text-sm font-medium text-slate-700 mb-1">
                                        День
                                        <span x-show="dayIsWeekday" class="text-slate-400 font-normal">(дни недели, можно несколько)</span>
                                    </label>
                                    <template x-if="dayIsWeekday">
                                        <div class="flex flex-wrap gap-1.5">
                                            <template x-for="w in weekdays" :key="w.n">
                                                <button type="button" @click="toggleWeekday(w.n)"
                                                        :class="isWeekdaySelected(w.n) ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50'"
                                                        class="px-3 py-1 rounded-lg border text-xs font-medium transition-colors" x-text="w.label"></button>
                                            </template>
                                        </div>
                                    </template>
                                    <template x-if="!dayIsWeekday">
                                        <div class="relative">
                                            <button type="button" @click="showStartDayPicker = !showStartDayPicker"
                                                    class="w-full flex items-center justify-between px-3 py-2 border border-slate-200 rounded-lg text-sm bg-white hover:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-colors">
                                                <span :class="dayOfMonth ? 'text-slate-800' : 'text-slate-400'" x-text="dayOfMonth ? dayOfMonth + ' число' : '— выберите число —'"></span>
                                                <svg class="w-4 h-4 text-slate-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                            </button>
                                            <div x-show="showStartDayPicker" @click.away="showStartDayPicker = false"
                                                 class="absolute z-50 mt-1 w-full bg-white border border-slate-200 rounded-xl shadow-xl p-3" style="display:none">
                                                <div class="flex items-center justify-between mb-2 px-1">
                                                    <span class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Выберите число</span>
                                                    <button type="button" @click="dayOfMonth = ''; showStartDayPicker = false" class="text-xs text-slate-400 hover:text-red-500 transition-colors">Очистить</button>
                                                </div>
                                                <div class="grid grid-cols-7 gap-0.5">
                                                    <template x-for="day in Array.from({length: 31}, (_, i) => i + 1)" :key="day">
                                                        <button type="button" @click="dayOfMonth = day; showStartDayPicker = false"
                                                                :class="dayOfMonth === day ? 'bg-indigo-600 text-white font-semibold' : 'text-slate-700 hover:bg-indigo-50 hover:text-indigo-600'"
                                                                class="h-8 w-full rounded-lg text-sm transition-colors" x-text="day"></button>
                                                    </template>
                                                    <template x-for="_ in [1,2,3,4]" :key="'e' + _"><div></div></template>
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <template x-if="!serviceForm.parent_id">
                                <div>
                                    <label class="block text-xs font-semibold text-slate-500 uppercase tracking-wide mb-2">Особые условия</label>
                                    <div class="grid grid-cols-2 sm:grid-cols-3 gap-1.5">
                                        <template x-for="f in specialFlags" :key="f.key">
                                            <label class="flex items-center gap-2 cursor-pointer px-2.5 py-1.5 rounded-lg border text-sm transition-colors select-none"
                                                   :class="serviceForm.flags[f.key] ? ('bg-' + f.color + '-50 border-' + f.color + '-200') : 'bg-white border-slate-200 hover:bg-slate-50'">
                                                <input type="checkbox" x-model="serviceForm.flags[f.key]" class="w-4 h-4 border-slate-300 rounded" :class="'text-' + f.color + '-600 focus:ring-' + f.color + '-500'">
                                                <span class="text-slate-700 leading-tight" x-text="f.label"></span>
                                            </label>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            <template x-if="!serviceForm.parent_id">
                                <label class="flex items-start gap-3 cursor-pointer px-3 py-2.5 rounded-xl border transition-colors select-none"
                                       :class="serviceForm.splits_by_branch ? 'bg-purple-50 border-purple-200' : 'bg-white border-slate-200 hover:bg-slate-50'">
                                    <input type="checkbox" x-model="serviceForm.splits_by_branch" class="mt-0.5 w-4 h-4 text-purple-600 border-slate-300 rounded focus:ring-purple-500">
                                    <span class="leading-tight">
                                        <span class="text-sm font-medium text-slate-800">Дублируется по филиалам</span>
                                        <span class="block text-xs text-slate-500 mt-0.5">Если у клиента есть филиалы, в смете этот БП размножится по каждому НО (основной + филиалы) — отдельная задача на каждый.</span>
                                    </span>
                                </label>
                            </template>

                            <template x-if="!serviceForm.parent_id">
                                <div class="space-y-2">
                                    <label class="block text-sm font-medium text-slate-700">Режим налогообложения</label>
                                    <div class="flex flex-wrap gap-2">
                                        <template x-for="ts in taxSystems" :key="ts.id">
                                            <label class="flex items-center gap-2 cursor-pointer px-3 py-2 rounded-xl border transition-colors"
                                                   :class="isTaxSystemSelected(ts.id) ? 'border-amber-300 bg-amber-50' : 'border-slate-200 hover:bg-slate-50'">
                                                <input type="checkbox" :checked="isTaxSystemSelected(ts.id)" @change="toggleTaxSystem(ts.id)" class="w-3.5 h-3.5 text-amber-500 border-slate-300 rounded focus:ring-amber-400">
                                                <span class="text-sm font-medium text-slate-700" x-text="ts.name"></span>
                                            </label>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            <template x-if="!serviceForm.parent_id">
                                <div class="border-t border-slate-100 pt-4">
                                    <div class="flex items-center justify-between mb-3">
                                        <label class="text-sm font-semibold text-slate-700">Подпункты</label>
                                        <button type="button" @click="addChildForm()" class="inline-flex items-center text-xs text-indigo-600 hover:text-indigo-800 font-medium">
                                            <svg class="w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                            Добавить подпункт
                                        </button>
                                    </div>
                                    <template x-if="serviceForm.children.length === 0">
                                        <p class="text-xs text-slate-400 py-2">Нет подпунктов. Подпункты — это дочерние услуги, которые можно включить отдельно в смете.</p>
                                    </template>
                                    {{-- Биллинг, ставка и количество у подпунктов не задаются — они наследуются от основного БП --}}
                                    <p class="text-xs text-slate-500 mb-2" x-show="serviceForm.children.length > 0"
                                       x-text="isPaidBilling
                                            ? (selectedRate
                                                ? 'Биллинг из основного БП: ' + formatPrice(selectedRate.price) + (selectedRate.unit ? ' / ' + selectedRate.unit : '') + ' × кол-во по каждому подпункту'
                                                : 'Биллинг из основного БП — выберите ставку выше')
                                            : (isFreeBilling
                                                ? (selectedBillingCode === 'included' ? 'Биллинг из основного БП: входит в абонентку — 0' : 'Биллинг из основного БП: не тарифицируется — 0')
                                                : 'Биллинг наследуется от основного БП')"></p>
                                    <p class="text-xs text-slate-400 mb-2" x-show="serviceForm.children.length > 0"
                                       x-text="serviceForm.allows_quantity ? 'Количество можно указывать по каждому подпункту' : 'Количество в смете не вводится'"></p>
                                    <div class="space-y-2">
                                        <template x-for="(child, cidx) in serviceForm.children" :key="cidx">
                                            <div class="flex items-start gap-2 p-3 bg-slate-50 rounded-xl border border-slate-200">
                                                <div class="flex-1 space-y-2">
                                                    <input type="text" x-model="child.name" placeholder="Название подпункта *" required class="block w-full px-2.5 py-1.5 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white">
                                                </div>
                                                <button type="button" @click="removeChildForm(cidx)" class="p-1 text-slate-300 hover:text-red-500 transition-colors rounded mt-0.5">
                                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                                </button>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>
                        <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-slate-100">
                            <button type="button" @click="showServiceModal = false" class="px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50">Отмена</button>
                            <button type="submit" :disabled="savingService" class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 disabled:opacity-50">
                                <svg x-show="savingService" class="w-4 h-4 mr-2 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                <span x-text="serviceForm.id ? 'Сохранить' : 'Создать'"></span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </template>

    {{-- Каждой модалке — свой x-teleport: Alpine переносит в body только ПЕРВЫЙ
         элемент такого шаблона, и соседи в нём просто не попадают в DOM. --}}
    <template x-teleport="body">
        {{-- Архивация: последствия наступают у всех клиентов сразу, поэтому проговариваем их
             до нажатия и показываем, скольких это коснётся. --}}
        <div x-show="showArchiveModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-slate-500/75" @click="showArchiveModal = false"></div>
                <div class="relative w-full max-w-md bg-white rounded-2xl shadow-xl p-6 z-10">
                    <div class="flex items-center gap-4 mb-4">
                        <div class="w-12 h-12 bg-amber-100 rounded-full flex items-center justify-center flex-shrink-0">
                            <svg class="w-6 h-6 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900" x-text="archiveBlockedDelete ? 'Удалить нельзя — процесс в работе' : 'Архивировать бизнес-процесс?'"></h3>
                            <p class="text-sm text-slate-500" x-text="archiveItem?.name"></p>
                        </div>
                    </div>
                    <div class="text-sm text-slate-600 space-y-2 mb-6">
                        <p x-show="archiveBlockedDelete">
                            Процесс уже ведут, поэтому удалить его нельзя: вместе с ним исчезло бы расписание,
                            по которому считаются задачи у клиентов. Вместо удаления — архив.
                        </p>
                        <p x-show="archiveUsage === null">Считаем, скольких клиентов это коснётся…</p>
                        <p x-show="archiveUsage !== null && archiveUsage > 0">
                            Процесс ведут у <span class="font-medium text-slate-800" x-text="archiveUsage"></span> клиентов.
                            Текущий месяц они доработают, со следующего задачи по нему заводиться не будут.
                        </p>
                        <p x-show="archiveUsage === 0">Сейчас его никто не ведёт — работу это не затронет.</p>
                        <p>Незакрытые задачи внутри текущего месяца останутся: их всё равно сдавать.</p>
                        <p class="text-xs text-slate-400">Архивный процесс остаётся в списке и не редактируется. Вернуть в работу можно в любой момент.</p>
                    </div>
                    <p x-show="archiveParentId" class="text-sm text-amber-700 bg-amber-50 rounded-lg px-3 py-2 mb-4">
                        Это подпункт. Он живёт вместе со своим процессом — архивировать нужно процесс целиком.
                    </p>
                    <div class="flex justify-end gap-3">
                        <button @click="showArchiveModal = false" class="px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50">
                            <span x-text="archiveParentId ? 'Понятно' : 'Отмена'"></span>
                        </button>
                        <button x-show="!archiveParentId" @click="confirmArchive()" :disabled="archiving" class="px-4 py-2 text-sm font-medium text-white bg-amber-600 rounded-lg hover:bg-amber-700 disabled:opacity-50">
                            Архивировать
                        </button>
                    </div>
                </div>
            </div>
        </div>

    </template>

    <template x-teleport="body">
        {{-- Возврат в работу: со следующего месяца, иначе расписание досчитало бы
             слоты за время простоя и высыпало бы просрочку задним числом. --}}
        <div x-show="showRestoreModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-slate-500/75" @click="showRestoreModal = false"></div>
                <div class="relative w-full max-w-md bg-white rounded-2xl shadow-xl p-6 z-10">
                    <div class="flex items-center gap-4 mb-4">
                        <div class="w-12 h-12 bg-emerald-100 rounded-full flex items-center justify-center flex-shrink-0">
                            <svg class="w-6 h-6 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900">Вернуть в работу?</h3>
                            <p class="text-sm text-slate-500" x-text="restoreItem?.name"></p>
                        </div>
                    </div>
                    <div class="text-sm text-slate-600 space-y-2 mb-6">
                        <p>
                            Процесс снова станет активным, но задачи по нему пойдут
                            <span class="font-medium text-slate-800" x-text="'с ' + formatDate(nextMonthStart())"></span>.
                        </p>
                        <p>За время, пока он был в архиве, задачи задним числом не появятся.</p>
                    </div>
                    <div class="flex justify-end gap-3">
                        <button @click="showRestoreModal = false" class="px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50">Отмена</button>
                        <button @click="confirmRestore()" :disabled="restoring" class="px-4 py-2 text-sm font-medium text-white bg-emerald-600 rounded-lg hover:bg-emerald-700 disabled:opacity-50">
                            Вернуть в работу
                        </button>
                    </div>
                </div>
            </div>
        </div>

    </template>

    {{-- Delete modal --}}
    <template x-teleport="body">
        <div x-show="showDeleteModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-slate-500/75" @click="showDeleteModal = false"></div>
                <div class="relative w-full max-w-md bg-white rounded-2xl shadow-xl p-6 z-10">
                    <div class="flex items-center gap-4 mb-4">
                        <div class="w-12 h-12 rounded-full flex items-center justify-center flex-shrink-0"
                             :class="deleteUsage?.in_use ? 'bg-amber-100' : 'bg-red-100'">
                            <svg x-show="!deleteUsage?.in_use" class="w-6 h-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                            <svg x-show="deleteUsage?.in_use" x-cloak class="w-6 h-6 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4"/></svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900" x-text="deleteUsage?.in_use ? 'Удалить нельзя — процесс в работе' : 'Удаление'"></h3>
                            <p class="text-sm text-slate-500" x-text="deleteItem?.name"></p>
                        </div>
                    </div>

                    {{-- Три состояния: проверяем — можно удалить — нельзя удалить.
                         Окно открывается сразу, поэтому первое видно доли секунды,
                         но экран не выглядит мёртвым, пока идёт запрос. --}}
                    <div class="text-sm text-slate-600 space-y-2 mb-6">
                        <p x-show="deleteUsage === null" class="flex items-center gap-2 text-slate-500">
                            <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            Проверяем, ведут ли этот процесс…
                        </p>
                        <template x-if="deleteUsage && !deleteUsage.in_use">
                            <p class="text-slate-700">Процесс нигде не используется — удаление безопасно. Это действие нельзя отменить.</p>
                        </template>
                        <template x-if="deleteUsage?.in_use">
                            <div class="space-y-2">
                                <p>Процесс уже ведут, поэтому удалить его нельзя: вместе с ним исчезло бы расписание, по которому считаются задачи у клиентов.</p>
                                <p x-show="deleteUsage.clients > 0">
                                    Сейчас он стоит у <span class="font-medium text-slate-800" x-text="deleteUsage.clients"></span> клиентов.
                                </p>
                                <p>Вместо удаления — архив: текущий месяц клиенты доработают, со следующего задачи по нему заводиться не будут.</p>
                            </div>
                        </template>
                    </div>

                    <div class="flex justify-end gap-3">
                        <button @click="showDeleteModal = false" class="px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50">Отмена</button>
                        <button x-show="deleteUsage?.in_use && !deleteParentId" x-cloak @click="archiveFromDelete()"
                                class="px-4 py-2 text-sm font-medium text-white bg-amber-600 rounded-lg hover:bg-amber-700">
                            Архивировать
                        </button>
                        <button x-show="!deleteUsage?.in_use" @click="confirmDelete()" :disabled="deleting || deleteUsage === null" class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 disabled:opacity-50">
                            <svg x-show="deleting" class="w-4 h-4 mr-2 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            Удалить
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>

    {{-- Toast --}}
    <template x-teleport="body">
        <div x-show="toast.show" x-cloak x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 translate-y-2" class="fixed bottom-4 right-4 z-[60]">
            <div :class="toast.type === 'success' ? 'bg-emerald-500' : 'bg-red-500'" class="px-4 py-3 rounded-lg text-white text-sm font-medium shadow-lg flex items-center gap-2">
                <svg x-show="toast.type === 'success'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                <svg x-show="toast.type === 'error'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                <span x-text="toast.message"></span>
            </div>
        </div>
    </template>
</div>

<script>
function servicesPage() {
    return {
        services: @json($servicesJson),
        taxSystems: @json($taxSystems),
        specialFlags: @json($specialFlags),
        periodicities: @json($periodicities),
        categories: @json($categories),
        spheres: @json($spheres),
        groups: @json($groups),
        billings: @json($billings),
        rates: @json($rates),
        selectedRowId: null,

        sphereFilter: '',
        searchQuery: '',
        groupBySphere: false,

        months: ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'],
        weekdays: [{n:1,label:'Пн'},{n:2,label:'Вт'},{n:3,label:'Ср'},{n:4,label:'Чт'},{n:5,label:'Пт'},{n:6,label:'Сб'},{n:7,label:'Вс'}],

        // Тип выбранной периодичности (kind) определяет поведение полей «Месяц»/«День»
        get selectedKind() {
            const p = this.periodicities.find(x => x.name === this.serviceForm.periodicity);
            return p ? p.kind : null;
        },
        get monthFieldEnabled() { return ['quarterly','yearly'].includes(this.selectedKind); },

        // Тарификация: код режима выбранного биллинга определяет, нужна ли ставка
        get selectedBillingCode() {
            const b = this.billings.find(x => x.name === this.serviceForm.billing);
            return b ? b.code : null;
        },
        get isPaidBilling() { return ['by_quantity', 'addon'].includes(this.selectedBillingCode); },
        get isFreeBilling() { return ['included', 'none'].includes(this.selectedBillingCode); },
        get selectedRate() { return this.rates.find(r => r.id == this.serviceForm.rate_id) || null; },
        rateLabel(r) { return r.name + ' · ' + this.formatPrice(r.price) + (r.unit ? ' / ' + r.unit : ''); },
        onBillingChange() { if (!this.isPaidBilling) this.serviceForm.rate_id = null; },

        get monthMultiple() { return this.selectedKind === 'quarterly'; }, // ежегодно — только один месяц
        get dayIsWeekday() { return this.selectedKind === 'weekly'; },
        get monthDisabledHint() {
            if (!this.serviceForm.periodicity) return 'Сначала выберите периодичность';
            if (this.selectedKind === 'monthly') return 'Недоступно для «Ежемесячно»';
            if (this.selectedKind === 'weekly') return 'Недоступно для «Еженедельно»';
            return 'Недоступно для этой периодичности';
        },
        isMonthSelected(n) { return (this.serviceForm.start_month || []).includes(n); },
        toggleMonth(n) {
            const arr = this.serviceForm.start_month || [];
            if (this.monthMultiple) {
                this.serviceForm.start_month = arr.includes(n) ? arr.filter(x => x !== n) : [...arr, n].sort((a, b) => a - b);
            } else {
                this.serviceForm.start_month = arr.includes(n) ? [] : [n]; // ежегодно: один месяц
            }
        },
        isWeekdaySelected(n) { return (this.serviceForm.start_day || []).includes(n); },
        toggleWeekday(n) {
            const arr = this.serviceForm.start_day || [];
            this.serviceForm.start_day = arr.includes(n) ? arr.filter(x => x !== n) : [...arr, n].sort((a, b) => a - b);
        },
        get dayOfMonth() { return (this.serviceForm.start_day && this.serviceForm.start_day.length) ? this.serviceForm.start_day[0] : ''; },
        set dayOfMonth(v) { this.serviceForm.start_day = v ? [parseInt(v)] : []; },
        // При смене периодичности типы месяца/дня меняются — сбрасываем прежний выбор
        onPeriodicityChange() { this.serviceForm.start_month = []; this.serviceForm.start_day = []; },

        get sphereOptions() {
            return [...new Set(this.services.map(s => s.sphere).filter(Boolean))]
                .sort((a, b) => a.localeCompare(b, 'ru'));
        },
        childMatchesSearch(s, q) {
            return (s.children || []).some(c => (c.name || '').toLowerCase().includes(q));
        },
        serviceMatchesSearch(s, q) {
            const fields = [s.name, s.sphere, s.service_group, s.category, s.business_process];
            if (fields.some(f => (f || '').toLowerCase().includes(q))) return true;
            return this.childMatchesSearch(s, q);
        },

        // --- Раскрытие подпунктов ---
        // По умолчанию свёрнуты все: список из полутора сотен БП с раскрытыми
        // подпунктами не читается. Состояние живёт до перезагрузки страницы.
        expandedServices: {},

        toggleChildren(serviceId) {
            this.expandedServices = {
                ...this.expandedServices,
                [serviceId]: !this.expandedServices[serviceId],
            };
        },

        /**
         * Показывать ли подпункты этого БП.
         *
         * Во время поиска БП находится и по названию подпункта — такой раскрываем
         * сами, иначе непонятно, почему строка вообще нашлась.
         */
        childrenVisible(svc) {
            const q = this.searchQuery.trim().toLowerCase();

            if (q && this.childMatchesSearch(svc, q)) {
                return true;
            }

            return !!this.expandedServices[svc.id];
        },
        get visibleServices() {
            let list = this.services;
            if (this.sphereFilter) {
                list = list.filter(s => (s.sphere || '') === this.sphereFilter);
            }
            const q = this.searchQuery.trim().toLowerCase();
            if (q) {
                list = list.filter(s => this.serviceMatchesSearch(s, q));
            }
            return list;
        },
        get displayRows() {
            const list = this.visibleServices;
            if (!this.groupBySphere) {
                return list.map(svc => ({ type: 'service', svc, key: 's' + svc.id }));
            }
            const groups = {}, order = [];
            list.forEach(svc => {
                const key = svc.sphere || 'Без сферы';
                if (!groups[key]) { groups[key] = []; order.push(key); }
                groups[key].push(svc);
            });
            const rows = [];
            order.forEach(sphere => {
                rows.push({ type: 'header', sphere, count: groups[sphere].length, key: 'h:' + sphere });
                groups[sphere].forEach(svc => rows.push({ type: 'service', svc, key: 's' + svc.id }));
            });
            return rows;
        },

        showServiceModal: false,
        savingService: false,
        showDueDayPicker: false,
        showStartDayPicker: false,
        serviceFormErrors: { tax_systems: false },
        serviceForm: {
            id: null, parent_id: null, tax_systems: [], name: '', description: '',
            sphere: '', service_group: '', business_process: '', category: '',
            cost: 0, rate_id: null, pricing_rules: [], use_tiered_pricing: false,
            periodicity: '', due_day: null, start_month: [], start_day: [], deadline_days: null, execution_minutes: null,
            closing_rule: '', requires_document: false, check_type: '', requires_review: false, billing: '', comment: '',
            allows_quantity: false, splits_by_branch: false, flags: {}, children: [],
        },

        blankFlags() {
            const o = {};
            this.specialFlags.forEach(f => o[f.key] = false);
            return o;
        },
        flagsFromSvc(svc) {
            const o = {};
            this.specialFlags.forEach(f => o[f.key] = !!svc[f.key]);
            return o;
        },

        showDeleteModal: false,
        showArchiveModal: false,
        showRestoreModal: false,
        archiveItem: null,
        restoreItem: null,
        archiveUsage: null,   // null — счётчик ещё едет с сервера
        archiveParentId: null,
        deleteUsage: null,   // null — проверка ещё идёт
        archiveBlockedDelete: false,   // окно открыто из-за отказа в удалении
        archiving: false,
        restoring: false,
        deleteItem: null,
        deleteParentId: null,
        deleting: false,
        toast: { show: false, message: '', type: 'success' },

        openServiceModal(svc = null) {
            // Архивный открывается только на чтение — правка расписания сдвинула бы
            // уже посчитанные задачи. Двойной клик по строке сюда тоже приходит.
            if (svc?.archived_at) {
                this.showToast('Процесс в архиве. Чтобы менять его, верните в работу', 'error');
                return;
            }

            this.serviceFormErrors = { tax_systems: false };
            if (svc) {
                const rules = svc.pricing_rules || [];
                this.serviceForm = {
                    id: svc.id, parent_id: svc.parent_id || null,
                    tax_systems: (svc.tax_systems || []).map(ts => ts.id),
                    name: svc.name, description: svc.description || '',
                    sphere: svc.sphere || '', service_group: svc.service_group || '',
                    business_process: svc.business_process || '', category: svc.category || '',
                    cost: svc.cost, rate_id: svc.rate_id ?? null, pricing_rules: rules, use_tiered_pricing: rules.length > 0,
                    periodicity: svc.periodicity || '', due_day: svc.due_day || null,
                    start_month: Array.isArray(svc.start_month) ? svc.start_month : [], start_day: Array.isArray(svc.start_day) ? svc.start_day : [],
                    deadline_days: svc.deadline_days || null, execution_minutes: svc.execution_minutes || null,
                    closing_rule: svc.closing_rule || '', requires_document: !!svc.requires_document, check_type: svc.check_type || '', requires_review: !!svc.requires_review,
                    billing: svc.billing || '', comment: svc.comment || '',
                    allows_quantity: svc.allows_quantity || false,
                    splits_by_branch: svc.splits_by_branch || false,
                    flags: this.flagsFromSvc(svc),
                    children: (svc.children || []).map(c => ({ id: c.id, name: c.name, cost: c.cost, periodicity: c.periodicity || '' })),
                };
            } else {
                this.serviceForm = {
                    id: null, parent_id: null, tax_systems: [], name: '', description: '',
                    sphere: '', service_group: '', business_process: '', category: '',
                    cost: 0, rate_id: null, pricing_rules: [], use_tiered_pricing: false,
                    periodicity: '', due_day: null, start_month: [], start_day: [], deadline_days: null, execution_minutes: null,
                    closing_rule: '', requires_document: false, check_type: '', requires_review: false, billing: '', comment: '',
                    allows_quantity: false, splits_by_branch: false, flags: this.blankFlags(), children: [],
                };
            }
            this.showServiceModal = true;
        },

        /**
         * Удаление начинается с вопроса «а его уже ведут?».
         *
         * Если ведут — удалять нельзя, и вместо окна удаления сразу показываем
         * окно архивации с объяснением. Раньше отказ прилетал уже после нажатия
         * «Удалить», окно оставалось открытым и выглядело неработающим.
         */
        async openDeleteModal(svc, parentId = null) {
            this.deleteItem = svc;
            this.deleteParentId = parentId;
            this.deleteUsage = null;   // ещё не знаем, ведут ли процесс
            this.showDeleteModal = true;

            // Окно открываем сразу, а «ведут ли его» выясняем следом: ждать ответа
            // молча — значит показывать мёртвый экран, будто кнопка не сработала.
            try {
                const r = await fetch(`/settings/services/${svc.id}/usage`, { headers: { 'Accept': 'application/json' } });
                const usage = await r.json();

                // Пока ходили на сервер, окно могли закрыть или открыть для другого БП.
                if (!this.showDeleteModal || this.deleteItem?.id !== svc.id) return;

                this.deleteUsage = usage;
            } catch {
                // Не достучались — оставляем обычное удаление: отказ придёт с сервера.
                this.deleteUsage = { in_use: false, clients: 0 };
            }
        },

        /** Из окна удаления — сразу в архивацию, без лишних переходов. */
        archiveFromDelete() {
            const svc = this.deleteItem;
            const usage = this.deleteUsage;

            this.showDeleteModal = false;
            this.archiveItem = svc;
            this.archiveParentId = this.deleteParentId;
            this.archiveUsage = usage?.clients ?? 0;
            this.archiveBlockedDelete = true;
            this.showArchiveModal = true;
        },

        /**
         * Первое число следующего месяца — с него архивный процесс вернётся в работу.
         *
         * Строку собираем сами: toISOString() переводит время в UTC, и у нас (+06)
         * полночь первого числа уезжает на последний день предыдущего месяца.
         */
        nextMonthStart() {
            const now = new Date();
            const year = now.getMonth() === 11 ? now.getFullYear() + 1 : now.getFullYear();
            const month = (now.getMonth() + 1) % 12 + 1;

            return `${year}-${String(month).padStart(2, '0')}-01`;
        },

        formatDate(value) {
            if (!value) return '';

            // С сервера дата может прийти полным ISO («2026-09-01T00:00:00.000000Z»),
            // из вёрстки — короткой строкой. Берём первые десять символов: и там, и там
            // это YYYY-MM-DD.
            const [y, m, d] = String(value).slice(0, 10).split('-');

            return `${d}.${m}.${y}`;
        },

        async openArchiveModal(svc, parentId = null) {
            this.archiveItem = svc;
            this.archiveParentId = parentId;
            this.archiveBlockedDelete = false;
            this.archiveUsage = null;
            this.showArchiveModal = true;

            try {
                const r = await fetch(`/settings/services/${svc.id}/usage`, { headers: { 'Accept': 'application/json' } });
                const d = await r.json();
                this.archiveUsage = d.clients ?? 0;
            } catch {
                this.archiveUsage = 0;
            }
        },

        async confirmArchive() {
            this.archiving = true;
            try {
                const r = await fetch(`/settings/services/${this.archiveItem.id}/archive`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' },
                });
                const d = await r.json();
                if (d.success) {
                    const svc = this.services.find(s => s.id === this.archiveItem.id);
                    if (svc) { svc.archived_at = d.item.archived_at; svc.active_from = null; svc.is_active = false; }
                    this.showToast(d.message, 'success');
                    this.showArchiveModal = false;
                } else { this.showToast(d.message || 'Ошибка', 'error'); }
            } catch { this.showToast('Не удалось заархивировать', 'error'); }
            this.archiving = false;
        },

        openRestoreModal(svc) {
            this.restoreItem = svc;
            this.showRestoreModal = true;
        },

        async confirmRestore() {
            this.restoring = true;
            try {
                const r = await fetch(`/settings/services/${this.restoreItem.id}/restore`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' },
                });
                const d = await r.json();
                if (d.success) {
                    const svc = this.services.find(s => s.id === this.restoreItem.id);
                    if (svc) { svc.archived_at = null; svc.active_from = d.item.active_from; svc.is_active = true; }
                    this.showToast(d.message, 'success');
                    this.showRestoreModal = false;
                } else { this.showToast(d.message || 'Ошибка', 'error'); }
            } catch { this.showToast('Не удалось вернуть в работу', 'error'); }
            this.restoring = false;
        },

        addChildForm() { this.serviceForm.children.push({ id: null, name: '', cost: 0, periodicity: '' }); },
        removeChildForm(cidx) { this.serviceForm.children.splice(cidx, 1); },

        isTaxSystemSelected(id) { return this.serviceForm.tax_systems.includes(id); },
        toggleTaxSystem(id) {
            if (this.isTaxSystemSelected(id)) this.serviceForm.tax_systems = this.serviceForm.tax_systems.filter(x => x !== id);
            else this.serviceForm.tax_systems.push(id);
            this.serviceFormErrors.tax_systems = false;
        },

        addPricingRule() { this.serviceForm.pricing_rules.push({ max_qty: '', price: '' }); },

        async submitServiceForm() {
            this.savingService = true;
            const url = this.serviceForm.id ? `/settings/services/${this.serviceForm.id}` : '/settings/services';
            // Флаги условий выравниваем в плоский payload (is_pvt, is_marketplaces, ...)
            const { flags, ...rest } = this.serviceForm;
            const payload = { ...rest, ...flags };
            try {
                const r = await fetch(url, {
                    method: this.serviceForm.id ? 'PUT' : 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' },
                    body: JSON.stringify(payload),
                });
                const d = await r.json();
                if (d.success) {
                    this.showToast(d.message, 'success');
                    const item = d.item;
                    if (this.serviceForm.id) {
                        if (item.parent_id) {
                            const parent = this.services.find(s => s.id === item.parent_id);
                            if (parent) { const ci = parent.children.findIndex(c => c.id === item.id); if (ci !== -1) parent.children[ci] = item; }
                        } else {
                            const i = this.services.findIndex(s => s.id === item.id); if (i !== -1) this.services[i] = item;
                        }
                    } else {
                        if (item.parent_id) {
                            const parent = this.services.find(s => s.id === item.parent_id);
                            if (parent) {
                                if (!parent.children) parent.children = [];
                                parent.children.push(item);
                                // Только что добавленный подпункт должен быть виден,
                                // иначе кажется, что сохранение ничего не сделало.
                                this.expandedServices = { ...this.expandedServices, [parent.id]: true };
                            }
                        } else { this.services.unshift(item); }
                    }
                    this.showServiceModal = false; this.showDueDayPicker = false;
                } else { this.showToast(d.message || 'Ошибка сохранения', 'error'); }
            } catch { this.showToast('Ошибка сохранения', 'error'); }
            this.savingService = false;
        },

        async confirmDelete() {
            this.deleting = true;
            try {
                const r = await fetch(`/settings/services/${this.deleteItem.id}`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' } });
                const d = await r.json();
                if (d.success) {
                    this.showToast(d.message, 'success');
                    if (this.deleteParentId) {
                        const parent = this.services.find(s => s.id === this.deleteParentId);
                        if (parent) parent.children = parent.children.filter(c => c.id !== this.deleteItem.id);
                    } else {
                        this.services = this.services.filter(s => s.id !== this.deleteItem.id);
                    }
                    this.showDeleteModal = false;
                } else { this.showToast(d.message || 'Ошибка', 'error'); }
            } catch { this.showToast('Ошибка удаления', 'error'); }
            this.deleting = false;
        },

        formatPrice(price) { return new Intl.NumberFormat('ru-RU').format(price) + ' сом'; },
        showToast(message, type = 'success') { this.toast = { show: true, message, type }; setTimeout(() => { this.toast.show = false; }, 3000); },
    };
}
</script>
@endsection
