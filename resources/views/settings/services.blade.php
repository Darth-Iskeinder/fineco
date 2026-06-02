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
    'pricing_rules'   => $s->pricing_rules ?? [],
    'periodicity'       => $s->periodicity,
    'due_day'           => $s->due_day,
    'deadline_days'     => $s->deadline_days,
    'execution_minutes' => $s->execution_minutes,
    'closing_rule'      => $s->closing_rule,
    'check_type'        => $s->check_type,
    'billing'           => $s->billing,
    'comment'           => $s->comment,
    'is_active'         => $s->is_active,
    'allows_quantity'   => $s->allows_quantity,
    'sort_order'        => $s->sort_order,
    'children'        => $s->children->map(fn($c) => [
        'id'              => $c->id,
        'parent_id'       => $c->parent_id,
        'name'            => $c->name,
        'description'     => $c->description,
        'cost'            => $c->cost,
        'periodicity'     => $c->periodicity,
        'is_active'       => $c->is_active,
        'allows_quantity' => $c->allows_quantity,
        'sort_order'      => $c->sort_order,
        'children'        => [],
    ])->values(),
], collect($flagKeys)->mapWithKeys(fn($k) => [$k => (bool) $s->$k])->all()))->values();
@endphp

<div x-data="servicesPage()" class="space-y-4">

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <div>
                <h2 class="text-lg font-semibold text-slate-800">Бизнес-процессы</h2>
                <p class="text-sm text-slate-500 mt-0.5">Перечень услуг, оказываемых клиентам</p>
            </div>
            <button @click="openServiceModal()" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Добавить БП
            </button>
        </div>
        <div class="px-6 py-3 border-b border-slate-100 flex flex-wrap items-center gap-4 bg-slate-50/40">
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
        <div class="overflow-auto" style="max-height: calc(100vh - 13rem);">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50 sticky top-0 z-10 [&_th]:bg-slate-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider whitespace-nowrap">Бизнес процесс</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider whitespace-nowrap">Сфера</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider whitespace-nowrap">Группа</th>
                        {{-- TODO: колонка "Бизнес процесс" (поле business_process) удалена — дублировала "Название", рассмотреть удаление поля из таблицы --}}
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider whitespace-nowrap">Категория</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider whitespace-nowrap">Режим НО</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider whitespace-nowrap">Периодичность</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider whitespace-nowrap">Дедлайн (дн.)</th>
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
                        <tr x-show="row.type === 'header'" class="bg-slate-100/70">
                            <td colspan="13" class="px-4 py-2 text-sm font-semibold text-slate-700">
                                <span x-text="row.sphere"></span>
                                <span class="ml-1 font-normal text-slate-400" x-text="'(' + row.count + ')'"></span>
                            </td>
                        </tr>

                        <tr x-show="row.type === 'service'"
                            @click="selectedRowId = (selectedRowId === 's' + row.svc.id ? null : 's' + row.svc.id)"
                            :class="selectedRowId === 's' + row.svc.id ? 'bg-indigo-50/70 ring-1 ring-inset ring-indigo-200' : 'hover:bg-slate-50'"
                            class="cursor-pointer">
                            <td class="px-4 py-3 text-sm font-semibold text-slate-900 whitespace-nowrap">
                                <span x-text="row.svc.name"></span>
                                <span x-show="row.svc.allows_quantity" class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700">кол-во</span>
                                <template x-for="f in specialFlags" :key="f.key">
                                    <span x-show="row.svc[f.key]" class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium" :class="'bg-' + f.color + '-100 text-' + f.color + '-700'" x-text="f.label"></span>
                                </template>
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
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-slate-500" x-text="row.svc.deadline_days ? row.svc.deadline_days + ' дн.' : '—'"></td>
                            <td class="px-4 py-3 whitespace-nowrap text-sm text-slate-500" x-text="row.svc.execution_minutes ? row.svc.execution_minutes + ' мин.' : '—'"></td>
                            <td class="px-4 py-3 text-sm text-slate-500 whitespace-nowrap" x-text="row.svc.closing_rule || '—'"></td>
                            <td class="px-4 py-3 text-sm text-slate-500 whitespace-nowrap" x-text="row.svc.check_type || '—'"></td>
                            <td class="px-4 py-3 text-sm text-slate-500 whitespace-nowrap" x-text="row.svc.billing || '—'"></td>
                            {{-- TODO: ячейка "Стоимость" скрыта — возможно не нужна в таблице БП, рассмотреть удаление поля cost из services --}}
                            <td class="px-4 py-3 text-sm text-slate-500 max-w-[160px] truncate" x-text="row.svc.comment || '—'"></td>
                            <td class="px-4 py-3 whitespace-nowrap text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <button @click="openServiceModal(row.svc)" class="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors" title="Редактировать">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                    </button>
                                    <button @click="openDeleteModal(row.svc)" class="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Удалить">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>

                        <template x-for="child in (row.type === 'service' ? (row.svc.children || []) : [])" :key="child.id">
                            <tr @click="selectedRowId = (selectedRowId === 'c' + child.id ? null : 'c' + child.id)"
                                :class="selectedRowId === 'c' + child.id ? 'bg-indigo-100/70 ring-1 ring-inset ring-indigo-200' : 'bg-slate-50/50 hover:bg-slate-50'"
                                class="cursor-pointer">
                                <td class="pl-10 pr-4 py-2.5 text-sm text-slate-600 whitespace-nowrap">
                                    <div class="flex items-center gap-1.5">
                                        <svg class="w-3 h-3 text-slate-300 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                        <span x-text="child.name"></span>
                                        <span x-show="child.allows_quantity" class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700">кол-во</span>
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
                                    <input type="text" x-model="serviceForm.sphere" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Группа</label>
                                    <input type="text" x-model="serviceForm.service_group" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Бизнес процесс</label>
                                    <input type="text" x-model="serviceForm.business_process" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Категория</label>
                                    <input type="text" x-model="serviceForm.category" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Дедлайн (дней)</label>
                                    <input type="number" x-model="serviceForm.deadline_days" min="0" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">План выполнения (мин.)</label>
                                    <input type="number" x-model="serviceForm.execution_minutes" min="0" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Правило закрытия</label>
                                    <input type="text" x-model="serviceForm.closing_rule" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Проверка</label>
                                    <input type="text" x-model="serviceForm.check_type" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Биллинг</label>
                                    <input type="text" x-model="serviceForm.billing" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Комментарий</label>
                                    <input type="text" x-model="serviceForm.comment" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                </div>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <template x-if="serviceForm.children.length === 0">
                                    <div>
                                        <label class="block text-sm font-medium text-slate-700 mb-1">
                                            Стоимость (сом)
                                            <span class="text-red-500">*</span>
                                        </label>
                                        <input type="number" x-model="serviceForm.cost" required min="0"
                                               class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                    </div>
                                </template>
                                <template x-if="serviceForm.children.length > 0">
                                    <div class="flex items-center gap-2 px-3 py-2 bg-amber-50 border border-amber-200 rounded-lg">
                                        <svg class="w-4 h-4 text-amber-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        <span class="text-xs text-amber-700">Стоимость формируется из подпунктов</span>
                                    </div>
                                </template>
                                <div :class="serviceForm.children.length > 0 ? 'col-span-2' : ''">
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Периодичность</label>
                                    <select x-model="serviceForm.periodicity" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white">
                                        <option value="">— не указана —</option>
                                        <option value="Ежемесячный">Ежемесячный</option>
                                        <option value="Ежеквартальный">Ежеквартальный</option>
                                        <option value="Ежегодный">Ежегодный</option>
                                        <option value="Разовый">Разовый</option>
                                    </select>
                                </div>
                            </div>

                            <template x-if="!serviceForm.parent_id">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Срок выполнения</label>
                                    <div class="relative">
                                        <button type="button" @click="showDueDayPicker = !showDueDayPicker"
                                                class="w-full flex items-center justify-between px-3 py-2 border border-slate-200 rounded-lg text-sm bg-white hover:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-colors">
                                            <span :class="serviceForm.due_day ? 'text-slate-800' : 'text-slate-400'" x-text="serviceForm.due_day ? 'До ' + serviceForm.due_day + ' числа каждого месяца' : '— не указан —'"></span>
                                            <svg class="w-4 h-4 text-slate-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                                        </button>
                                        <div x-show="showDueDayPicker" @click.away="showDueDayPicker = false"
                                             class="absolute z-50 mt-1 w-full bg-white border border-slate-200 rounded-xl shadow-xl p-3" style="display:none">
                                            <div class="flex items-center justify-between mb-2 px-1">
                                                <span class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Выберите число</span>
                                                <button type="button" @click="serviceForm.due_day = null; showDueDayPicker = false" class="text-xs text-slate-400 hover:text-red-500 transition-colors">Очистить</button>
                                            </div>
                                            <div class="grid grid-cols-7 mb-1">
                                                <template x-for="d in ['Пн','Вт','Ср','Чт','Пт','Сб','Вс']" :key="d">
                                                    <div class="text-center text-xs text-slate-400 font-medium py-1" x-text="d"></div>
                                                </template>
                                            </div>
                                            <div class="grid grid-cols-7 gap-0.5">
                                                <template x-for="day in Array.from({length: 31}, (_, i) => i + 1)" :key="day">
                                                    <button type="button" @click="serviceForm.due_day = day; showDueDayPicker = false"
                                                            :class="serviceForm.due_day === day ? 'bg-indigo-600 text-white font-semibold' : 'text-slate-700 hover:bg-indigo-50 hover:text-indigo-600'"
                                                            class="h-8 w-full rounded-lg text-sm transition-colors" x-text="day"></button>
                                                </template>
                                                <template x-for="_ in [1,2,3,4]" :key="'e' + _"><div></div></template>
                                            </div>
                                        </div>
                                    </div>
                                    <p class="mt-1 text-xs text-slate-400">Сотрудник получит напоминание за 2–3 дня до этой даты</p>
                                </div>
                            </template>

                            <template x-if="serviceForm.children.length === 0">
                                <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox" x-model="serviceForm.allows_quantity" class="w-4 h-4 text-indigo-600 border-slate-300 rounded focus:ring-indigo-500">
                                        <span class="text-sm font-medium text-slate-700">Можно указывать количество <span class="text-slate-400 font-normal">(напр. кол-во операций)</span></span>
                                    </label>
                                </div>
                            </template>

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
                                <div class="space-y-2">
                                    <label class="block text-sm font-medium text-slate-700">Режим налогообложения <span class="text-red-500">*</span></label>
                                    <p x-show="serviceFormErrors.tax_systems" class="text-xs text-red-500">Выберите хотя бы один режим</p>
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
                                    <div class="space-y-2">
                                        <template x-for="(child, cidx) in serviceForm.children" :key="cidx">
                                            <div class="flex items-start gap-2 p-3 bg-slate-50 rounded-xl border border-slate-200">
                                                <div class="flex-1 grid grid-cols-2 gap-2">
                                                    <div class="col-span-2">
                                                        <input type="text" x-model="child.name" placeholder="Название подпункта *" required class="block w-full px-2.5 py-1.5 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white">
                                                    </div>
                                                    <input type="number" x-model.number="child.cost" min="0" placeholder="Стоимость" class="px-2.5 py-1.5 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white">
                                                    <select x-model="child.periodicity" class="px-2.5 py-1.5 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white">
                                                        <option value="">— периодичность —</option>
                                                        <option value="Ежемесячный">Ежемесячный</option>
                                                        <option value="Ежеквартальный">Ежеквартальный</option>
                                                        <option value="Ежегодный">Ежегодный</option>
                                                        <option value="Разовый">Разовый</option>
                                                    </select>
                                                    <label class="col-span-2 flex items-center gap-1.5 cursor-pointer">
                                                        <input type="checkbox" x-model="child.allows_quantity" class="w-3.5 h-3.5 text-indigo-600 border-slate-300 rounded focus:ring-indigo-500">
                                                        <span class="text-xs text-slate-600">Можно указывать количество</span>
                                                    </label>
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

    {{-- Delete modal --}}
    <template x-teleport="body">
        <div x-show="showDeleteModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-slate-500/75" @click="showDeleteModal = false"></div>
                <div class="relative w-full max-w-md bg-white rounded-2xl shadow-xl p-6 z-10">
                    <div class="flex items-center gap-4 mb-4">
                        <div class="w-12 h-12 bg-red-100 rounded-full flex items-center justify-center flex-shrink-0">
                            <svg class="w-6 h-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900">Удаление</h3>
                            <p class="text-sm text-slate-500">Это действие нельзя отменить</p>
                        </div>
                    </div>
                    <p class="text-slate-700 mb-6">Вы уверены, что хотите удалить «<span class="font-medium" x-text="deleteItem?.name"></span>»?</p>
                    <div class="flex justify-end gap-3">
                        <button @click="showDeleteModal = false" class="px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50">Отмена</button>
                        <button @click="confirmDelete()" :disabled="deleting" class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 disabled:opacity-50">
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
        <div x-show="toast.show" x-cloak x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 translate-y-2" class="fixed bottom-4 right-4 z-50">
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
        selectedRowId: null,

        sphereFilter: '',
        searchQuery: '',
        groupBySphere: false,

        get sphereOptions() {
            return [...new Set(this.services.map(s => s.sphere).filter(Boolean))]
                .sort((a, b) => a.localeCompare(b, 'ru'));
        },
        serviceMatchesSearch(s, q) {
            const fields = [s.name, s.sphere, s.service_group, s.category, s.business_process];
            if (fields.some(f => (f || '').toLowerCase().includes(q))) return true;
            return (s.children || []).some(c => (c.name || '').toLowerCase().includes(q));
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
        serviceFormErrors: { tax_systems: false },
        serviceForm: {
            id: null, parent_id: null, tax_systems: [], name: '', description: '',
            sphere: '', service_group: '', business_process: '', category: '',
            cost: 0, pricing_rules: [], use_tiered_pricing: false,
            periodicity: '', due_day: null, deadline_days: null, execution_minutes: null,
            closing_rule: '', check_type: '', billing: '', comment: '',
            allows_quantity: false, flags: {}, children: [],
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
        deleteItem: null,
        deleteParentId: null,
        deleting: false,
        toast: { show: false, message: '', type: 'success' },

        openServiceModal(svc = null) {
            this.serviceFormErrors = { tax_systems: false };
            if (svc) {
                const rules = svc.pricing_rules || [];
                this.serviceForm = {
                    id: svc.id, parent_id: svc.parent_id || null,
                    tax_systems: (svc.tax_systems || []).map(ts => ts.id),
                    name: svc.name, description: svc.description || '',
                    sphere: svc.sphere || '', service_group: svc.service_group || '',
                    business_process: svc.business_process || '', category: svc.category || '',
                    cost: svc.cost, pricing_rules: rules, use_tiered_pricing: rules.length > 0,
                    periodicity: svc.periodicity || '', due_day: svc.due_day || null,
                    deadline_days: svc.deadline_days || null, execution_minutes: svc.execution_minutes || null,
                    closing_rule: svc.closing_rule || '', check_type: svc.check_type || '',
                    billing: svc.billing || '', comment: svc.comment || '',
                    allows_quantity: svc.allows_quantity || false,
                    flags: this.flagsFromSvc(svc),
                    children: (svc.children || []).map(c => ({ id: c.id, name: c.name, cost: c.cost, periodicity: c.periodicity || '', allows_quantity: c.allows_quantity || false })),
                };
            } else {
                this.serviceForm = {
                    id: null, parent_id: null, tax_systems: [], name: '', description: '',
                    sphere: '', service_group: '', business_process: '', category: '',
                    cost: 0, pricing_rules: [], use_tiered_pricing: false,
                    periodicity: '', due_day: null, deadline_days: null, execution_minutes: null,
                    closing_rule: '', check_type: '', billing: '', comment: '',
                    allows_quantity: false, flags: this.blankFlags(), children: [],
                };
            }
            this.showServiceModal = true;
        },

        openDeleteModal(svc, parentId = null) {
            this.deleteItem = svc;
            this.deleteParentId = parentId;
            this.showDeleteModal = true;
        },

        addChildForm() { this.serviceForm.children.push({ id: null, name: '', cost: 0, periodicity: '', allows_quantity: false }); },
        removeChildForm(cidx) { this.serviceForm.children.splice(cidx, 1); },

        isTaxSystemSelected(id) { return this.serviceForm.tax_systems.includes(id); },
        toggleTaxSystem(id) {
            if (this.isTaxSystemSelected(id)) this.serviceForm.tax_systems = this.serviceForm.tax_systems.filter(x => x !== id);
            else this.serviceForm.tax_systems.push(id);
            this.serviceFormErrors.tax_systems = false;
        },

        addPricingRule() { this.serviceForm.pricing_rules.push({ max_qty: '', price: '' }); },

        async submitServiceForm() {
            if (!this.serviceForm.parent_id) {
                this.serviceFormErrors.tax_systems = this.serviceForm.tax_systems.length === 0;
                if (this.serviceFormErrors.tax_systems) return;
            }
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
                            if (parent) { if (!parent.children) parent.children = []; parent.children.push(item); }
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
