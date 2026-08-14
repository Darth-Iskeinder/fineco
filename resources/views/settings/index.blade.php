@extends('layouts.app')

@section('title', 'Настройки - Kubik')
@section('page-title', 'Настройки')

@section('content')
<div x-data="settingsPage()">
    <!-- Табы -->
    <div class="border-b border-slate-200 mb-6">
        <nav class="-mb-px flex gap-6">
            <button @click="activeTab = 'tax_systems'" :class="activeTab === 'tax_systems' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'" class="py-3 px-1 border-b-2 font-medium text-sm transition-colors">
                Системы налогообложения
            </button>
            <button @click="activeTab = 'activity_types'" :class="activeTab === 'activity_types' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'" class="py-3 px-1 border-b-2 font-medium text-sm transition-colors">
                Виды деятельности
            </button>
            <button @click="activeTab = 'tariffs'" :class="activeTab === 'tariffs' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'" class="py-3 px-1 border-b-2 font-medium text-sm transition-colors">
                Тарифы
            </button>
            <button @click="activeTab = 'services'" :class="activeTab === 'services' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'" class="py-3 px-1 border-b-2 font-medium text-sm transition-colors">
                Бизнес процессы
            </button>
            <button @click="activeTab = 'rates'" :class="activeTab === 'rates' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'" class="py-3 px-1 border-b-2 font-medium text-sm transition-colors">
                Справочник ставок
            </button>
        </nav>
    </div>

    <!-- Системы налогообложения -->
    <div x-show="activeTab === 'tax_systems'" x-cloak>
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-slate-800">Системы налогообложения</h2>
                <button @click="openCreateModal('tax_systems')" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                    Добавить
                </button>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Название</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Код</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Описание</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Порядок</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Статус</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Действия</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-200">
                        <template x-for="item in taxSystems" :key="item.id">
                            <tr class="hover:bg-slate-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900" x-text="item.name"></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500 font-mono" x-text="item.code"></td>
                                <td class="px-6 py-4 text-sm text-slate-500 max-w-xs truncate" x-text="item.description || '—'"></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500" x-text="item.sort_order"></td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span x-show="item.is_active" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">Активен</span>
                                    <span x-show="!item.is_active" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600">Неактивен</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                    <button @click="openEditModal('tax_systems', item)" class="text-indigo-600 hover:text-indigo-900 mr-3">Изменить</button>
                                    <button @click="openDeleteModal('tax_systems', item)" class="text-red-600 hover:text-red-900">Удалить</button>
                                </td>
                            </tr>
                        </template>
                        <template x-if="taxSystems.length === 0">
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-slate-500">Нет данных</td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Виды деятельности -->
    <div x-show="activeTab === 'activity_types'" x-cloak>
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-slate-800">Виды деятельности</h2>
                <button @click="openCreateModal('activity_types')" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                    Добавить
                </button>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Название</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Код</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Описание</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Порядок</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Статус</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Действия</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-200">
                        <template x-for="item in activityTypes" :key="item.id">
                            <tr class="hover:bg-slate-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900" x-text="item.name"></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500 font-mono" x-text="item.code"></td>
                                <td class="px-6 py-4 text-sm text-slate-500 max-w-xs truncate" x-text="item.description || '—'"></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500" x-text="item.sort_order"></td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span x-show="item.is_active" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">Активен</span>
                                    <span x-show="!item.is_active" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600">Неактивен</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                    <button @click="openEditModal('activity_types', item)" class="text-indigo-600 hover:text-indigo-900 mr-3">Изменить</button>
                                    <button @click="openDeleteModal('activity_types', item)" class="text-red-600 hover:text-red-900">Удалить</button>
                                </td>
                            </tr>
                        </template>
                        <template x-if="activityTypes.length === 0">
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-slate-500">Нет данных</td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Тарифы -->
    <div x-show="activeTab === 'tariffs'" x-cloak>
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-slate-800">Тарифы</h2>
                <button @click="openCreateModal('tariffs')" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                    Добавить
                </button>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Название</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Описание</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Статус</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Действия</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-200">
                        <template x-for="item in tariffs" :key="item.id">
                            <tr class="hover:bg-slate-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900" x-text="item.name"></td>
                                <td class="px-6 py-4 text-sm text-slate-500 max-w-xs truncate" x-text="item.description || '—'"></td>
                                <td class="px-6 py-4 whitespace-nowrap">
                                    <span x-show="item.is_active" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">Активен</span>
                                    <span x-show="!item.is_active" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600">Неактивен</span>
                                </td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                    <button @click="openEditModal('tariffs', item)" class="text-indigo-600 hover:text-indigo-900 mr-3">Изменить</button>
                                    <button @click="openDeleteModal('tariffs', item)" class="text-red-600 hover:text-red-900">Удалить</button>
                                </td>
                            </tr>
                        </template>
                        <template x-if="tariffs.length === 0">
                            <tr>
                                <td colspan="6" class="px-6 py-8 text-center text-slate-500">Нет данных</td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Бизнес процессы (услуги) -->
    <div x-show="activeTab === 'services'" x-cloak>
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-slate-800">Бизнес-процессы</h2>
                <button @click="openServiceModal()" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                    Добавить БП
                </button>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Название</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Режим налогообложения</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Тарифы</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Периодичность</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Срок</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Стоимость</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Действия</th>
                        </tr>
                    </thead>
                    <template x-for="svc in services" :key="svc.id">
                        <tbody class="bg-white divide-y divide-slate-100">
                            <!-- Родительский БП -->
                            <tr class="hover:bg-slate-50">
                                <td class="px-6 py-3 text-sm font-semibold text-slate-900">
                                    <span x-text="svc.name"></span>
                                    <span x-show="svc.allows_quantity" class="ml-2 inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700">кол-во</span>
                                </td>
                                <td class="px-6 py-3 text-sm text-slate-600">
                                    <div class="flex flex-wrap gap-1">
                                        <template x-if="svc.tax_systems && svc.tax_systems.length > 0">
                                            <template x-for="ts in svc.tax_systems" :key="ts.id">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-700" x-text="ts.name"></span>
                                            </template>
                                        </template>
                                        <template x-if="!svc.tax_systems || svc.tax_systems.length === 0">
                                            <span class="text-slate-400">—</span>
                                        </template>
                                    </div>
                                </td>
                                <td class="px-6 py-3 text-sm">
                                    <div class="flex flex-wrap gap-1">
                                        <template x-if="svc.tariffs && svc.tariffs.length > 0">
                                            <template x-for="t in svc.tariffs" :key="t.id">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-violet-100 text-violet-700" x-text="t.name"></span>
                                            </template>
                                        </template>
                                        <template x-if="!svc.tariffs || svc.tariffs.length === 0">
                                            <span class="text-slate-400">—</span>
                                        </template>
                                    </div>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-sm text-slate-500" x-text="svc.periodicity || '—'"></td>
                                <td class="px-6 py-3 whitespace-nowrap text-sm text-slate-500">
                                    <template x-if="svc.due_day">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-rose-100 text-rose-700">
                                            до <span x-text="svc.due_day" class="mx-0.5"></span> числа
                                        </span>
                                    </template>
                                    <template x-if="!svc.due_day"><span class="text-slate-300">—</span></template>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-sm font-semibold text-slate-900">
                                    <template x-if="svc.children && svc.children.length > 0">
                                        <span class="text-slate-400 font-normal text-xs">из подпунктов: <span class="font-semibold text-slate-700" x-text="formatPrice(svc.children.reduce((s, c) => s + Number(c.cost), 0))"></span></span>
                                    </template>
                                    <template x-if="!svc.children || svc.children.length === 0">
                                        <span x-text="formatPrice(svc.cost)"></span>
                                    </template>
                                </td>
                                <td class="px-6 py-3 whitespace-nowrap text-right text-sm">
                                    <button @click="openServiceModal(svc)" class="text-indigo-600 hover:text-indigo-900 mr-3">Изменить</button>
                                    <button @click="openDeleteServiceModal(svc)" class="text-red-600 hover:text-red-900">Удалить</button>
                                </td>
                            </tr>
                            <!-- Дочерние подпункты -->
                            <template x-for="child in (svc.children || [])" :key="child.id">
                                <tr class="bg-slate-50/50 hover:bg-slate-50">
                                    <td class="pl-12 pr-6 py-2.5 text-sm text-slate-600">
                                        <div class="flex items-center gap-1.5">
                                            <svg class="w-3 h-3 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                            <span x-text="child.name"></span>
                                            <span x-show="child.allows_quantity" class="ml-1 inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700">кол-во</span>
                                        </div>
                                    </td>
                                    <td class="px-6 py-2.5 text-sm text-slate-400">—</td>
                                    <td class="px-6 py-2.5 text-sm text-slate-400">—</td>
                                    <td class="px-6 py-2.5 whitespace-nowrap text-sm text-slate-400" x-text="child.periodicity || '—'"></td>
                                    <td class="px-6 py-2.5 text-sm text-slate-300">—</td>
                                    <td class="px-6 py-2.5 whitespace-nowrap text-sm text-slate-600" x-text="formatPrice(child.cost)"></td>
                                    <td class="px-6 py-2.5 whitespace-nowrap text-right text-sm">
                                        <button @click="openServiceModal(child)" class="text-indigo-600 hover:text-indigo-900 mr-3">Изменить</button>
                                        <button @click="openDeleteServiceModal(child, svc.id)" class="text-red-600 hover:text-red-900">Удалить</button>
                                    </td>
                                </tr>
                            </template>
                        </tbody>
                    </template>
                    <tbody x-show="services.length === 0">
                        <tr>
                            <td colspan="6" class="px-6 py-8 text-center text-slate-500">Нет бизнес-процессов. Нажмите «Добавить БП» чтобы создать первый.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Справочник ставок -->
    <div x-show="activeTab === 'rates'" x-cloak>
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                <h2 class="text-lg font-semibold text-slate-800">Справочник ставок</h2>
                <button @click="openRateModal()" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                    Создать ставку
                </button>
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Название</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Единица</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Цена</th>
                            <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Условия</th>
                            <th class="px-6 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Действия</th>
                        </tr>
                    </thead>
                    <tbody class="bg-white divide-y divide-slate-200">
                        <template x-for="rate in rates" :key="rate.id">
                            <tr class="hover:bg-slate-50">
                                <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900" x-text="rate.name"></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500" x-text="rate.unit || '—'"></td>
                                <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-900" x-text="formatPrice(rate.price)"></td>
                                <td class="px-6 py-4 text-sm text-slate-500 max-w-xs truncate" x-text="rate.conditions || '—'"></td>
                                <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                    <div class="flex items-center justify-end gap-2">
                                        <button @click="openRateModal(rate)" class="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors" title="Редактировать">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                                        </button>
                                        <button @click="openDeleteRateModal(rate)" class="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Удалить">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </template>
                        <template x-if="rates.length === 0">
                            <tr>
                                <td colspan="5" class="px-6 py-8 text-center text-slate-500">Нет ставок. Нажмите «Создать ставку» чтобы добавить первую.</td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Модальное окно создания/редактирования (налоги, виды деятельности, тарифы) -->
    <template x-teleport="body">
        <div x-show="showModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
            <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
                <div x-show="showModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-slate-500/75 transition-opacity" @click="closeModal()"></div>

                <div x-show="showModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" class="relative inline-block w-full max-w-lg p-6 my-8 text-left align-middle bg-white rounded-2xl shadow-xl transform transition-all">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-semibold text-slate-900" x-text="modalTitle"></h3>
                        <button @click="closeModal()" class="p-2 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100 transition-colors">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <form @submit.prevent="submitForm()">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Название <span class="text-red-500">*</span></label>
                                <input type="text" x-model="form.name" required class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                            </div>

                            <template x-if="modalType !== 'tariffs'">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Код <span class="text-red-500">*</span></label>
                                    <input type="text" x-model="form.code" required pattern="[a-z0-9_]+" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500" placeholder="например: trade">
                                    <p class="mt-1 text-xs text-slate-500">Только латинские буквы, цифры и подчёркивание</p>
                                </div>
                            </template>

                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Описание</label>
                                <textarea x-model="form.description" rows="2" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 resize-none"></textarea>
                            </div>

                            <div class="grid grid-cols-2 gap-4">
                                <template x-if="modalType !== 'tariffs'">
                                    <div>
                                        <label class="block text-sm font-medium text-slate-700 mb-1">Порядок сортировки</label>
                                        <input type="number" x-model="form.sort_order" min="0" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                    </div>
                                </template>
                                <div class="flex items-end pb-2">
                                    <label class="flex items-center">
                                        <input type="checkbox" x-model="form.is_active" class="w-4 h-4 text-indigo-600 border-slate-300 rounded focus:ring-indigo-500">
                                        <span class="ml-2 text-sm font-medium text-slate-700">Активен</span>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="flex justify-end gap-3 mt-6 pt-6 border-t border-slate-100">
                            <button type="button" @click="closeModal()" class="px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
                                Отмена
                            </button>
                            <button type="submit" :disabled="saving" class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-colors">
                                <svg x-show="saving" class="w-4 h-4 mr-2 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                <span x-text="isEditing ? 'Сохранить' : 'Создать'"></span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </template>

    <!-- Модальное окно услуги (Бизнес процессы) -->
    <template x-teleport="body">
        <div x-show="showServiceModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
            <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
                <div x-show="showServiceModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-slate-500/75 transition-opacity" @click="showServiceModal = false"></div>

                <div x-show="showServiceModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" class="relative inline-block w-full max-w-2xl p-6 my-8 text-left align-middle bg-white rounded-2xl shadow-xl transform transition-all">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-semibold text-slate-900" x-text="serviceForm.id ? (serviceForm.parent_id ? 'Редактирование подпункта' : 'Редактирование БП') : 'Новый бизнес-процесс'"></h3>
                        <button @click="showServiceModal = false" class="p-2 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100 transition-colors">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
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

                            <div class="grid grid-cols-2 gap-4">
                                <template x-if="serviceForm.children.length === 0">
                                    <div>
                                        <label class="block text-sm font-medium text-slate-700 mb-1">
                                            Стоимость (сом)
                                            <span x-show="!serviceForm.use_tiered_pricing" class="text-red-500">*</span>
                                        </label>
                                        <input type="number" x-model="serviceForm.cost"
                                               :required="!serviceForm.use_tiered_pricing"
                                               :disabled="serviceForm.use_tiered_pricing"
                                               min="0"
                                               class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 disabled:bg-slate-100 disabled:text-slate-400 disabled:cursor-not-allowed">
                                    </div>
                                </template>
                                <template x-if="serviceForm.children.length > 0">
                                    <div class="flex items-center gap-2 px-3 py-2 bg-amber-50 border border-amber-200 rounded-lg">
                                        <svg class="w-4 h-4 text-amber-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
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
                                        {{-- Триггер --}}
                                        <button type="button"
                                                @click="showDueDayPicker = !showDueDayPicker"
                                                class="w-full flex items-center justify-between px-3 py-2 border border-slate-200 rounded-lg text-sm bg-white hover:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-colors">
                                            <span :class="serviceForm.due_day ? 'text-slate-800' : 'text-slate-400'"
                                                  x-text="serviceForm.due_day ? 'До ' + serviceForm.due_day + ' числа каждого месяца' : '— не указан —'"></span>
                                            <svg class="w-4 h-4 text-slate-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/>
                                            </svg>
                                        </button>

                                        {{-- Пикер --}}
                                        <div x-show="showDueDayPicker"
                                             @click.away="showDueDayPicker = false"
                                             x-transition:enter="transition ease-out duration-150"
                                             x-transition:enter-start="opacity-0 translate-y-1"
                                             x-transition:enter-end="opacity-100 translate-y-0"
                                             x-transition:leave="transition ease-in duration-100"
                                             x-transition:leave-start="opacity-100 translate-y-0"
                                             x-transition:leave-end="opacity-0 translate-y-1"
                                             class="absolute z-50 mt-1 w-full bg-white border border-slate-200 rounded-xl shadow-xl p-3"
                                             style="display:none">
                                            <div class="flex items-center justify-between mb-2 px-1">
                                                <span class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Выберите число</span>
                                                <button type="button"
                                                        @click="serviceForm.due_day = null; showDueDayPicker = false"
                                                        class="text-xs text-slate-400 hover:text-red-500 transition-colors">
                                                    Очистить
                                                </button>
                                            </div>

                                            {{-- Заголовки дней недели --}}
                                            <div class="grid grid-cols-7 mb-1">
                                                <template x-for="d in ['Пн','Вт','Ср','Чт','Пт','Сб','Вс']" :key="d">
                                                    <div class="text-center text-xs text-slate-400 font-medium py-1" x-text="d"></div>
                                                </template>
                                            </div>

                                            {{-- Сетка дней 1–31 --}}
                                            <div class="grid grid-cols-7 gap-0.5">
                                                <template x-for="day in Array.from({length: 31}, (_, i) => i + 1)" :key="day">
                                                    <button type="button"
                                                            @click="serviceForm.due_day = day; showDueDayPicker = false"
                                                            :class="serviceForm.due_day === day
                                                                ? 'bg-indigo-600 text-white font-semibold'
                                                                : 'text-slate-700 hover:bg-indigo-50 hover:text-indigo-600'"
                                                            class="h-8 w-full rounded-lg text-sm transition-colors"
                                                            x-text="day">
                                                    </button>
                                                </template>
                                                {{-- Заглушки для выравнивания (31 % 7 = 3, нужно 4 пустых) --}}
                                                <template x-for="_ in [1,2,3,4]" :key="'e' + _">
                                                    <div></div>
                                                </template>
                                            </div>
                                        </div>
                                    </div>
                                    <p class="mt-1 text-xs text-slate-400">Сотрудник получит напоминание за 2–3 дня до этой даты</p>
                                </div>
                            </template>

                            <template x-if="serviceForm.children.length === 0">
                                <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox" x-model="serviceForm.allows_quantity"
                                               class="w-4 h-4 text-indigo-600 border-slate-300 rounded focus:ring-indigo-500">
                                        <span class="text-sm font-medium text-slate-700">
                                            Можно указывать количество
                                            <span class="text-slate-400 font-normal">(например: кол-во банковских операций)</span>
                                        </span>
                                    </label>
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox" x-model="serviceForm.use_tiered_pricing"
                                               class="w-4 h-4 text-indigo-600 border-slate-300 rounded focus:ring-indigo-500">
                                        <span class="text-sm font-medium text-slate-700">Ступенчатая цена</span>
                                    </label>
                                </div>
                            </template>

                            <!-- Ступени цен -->
                            <template x-if="serviceForm.use_tiered_pricing">
                                <div class="border border-indigo-200 rounded-xl p-3 bg-indigo-50/30 space-y-2">
                                    <div class="flex items-center justify-between">
                                        <span class="text-xs font-semibold text-slate-600 uppercase tracking-wide">Ступени цен</span>
                                        <button type="button" @click="addPricingRule()"
                                                class="inline-flex items-center text-xs text-indigo-600 hover:text-indigo-800 font-medium">
                                            <svg class="w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                            Добавить ступень
                                        </button>
                                    </div>
                                    <template x-if="serviceForm.pricing_rules.length === 0">
                                        <p class="text-xs text-slate-400 py-1">Добавьте хотя бы одну ступень цены</p>
                                    </template>
                                    <template x-for="(rule, ridx) in serviceForm.pricing_rules" :key="ridx">
                                        <div class="flex items-center gap-2">
                                            <span class="text-xs text-slate-500 whitespace-nowrap">До</span>
                                            <input type="number" x-model.number="rule.max_qty" min="1" placeholder="кол-во"
                                                   class="w-24 px-2 py-1.5 border border-slate-200 rounded-lg text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                            <span class="text-xs text-slate-500 whitespace-nowrap">шт. =</span>
                                            <input type="number" x-model.number="rule.price" min="0" placeholder="цена"
                                                   class="flex-1 px-2 py-1.5 border border-slate-200 rounded-lg text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                            <span class="text-xs text-slate-500">сом</span>
                                            <button type="button" @click="serviceForm.pricing_rules.splice(ridx, 1)"
                                                    class="p-1 text-slate-300 hover:text-red-500 transition-colors rounded">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                            </button>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            <!-- Режим налогообложения (только для родительских БП) -->
                            <template x-if="!serviceForm.parent_id">
                                <div class="space-y-2">
                                    <label class="block text-sm font-medium text-slate-700">Режим налогообложения <span class="text-red-500">*</span></label>
                                    <p x-show="serviceFormErrors.tax_systems" class="text-xs text-red-500">Выберите хотя бы один режим</p>
                                    <div class="flex flex-wrap gap-2">
                                        <template x-for="ts in taxSystems" :key="ts.id">
                                            <label class="flex items-center gap-2 cursor-pointer px-3 py-2 rounded-xl border transition-colors"
                                                   :class="isTaxSystemSelected(ts.id) ? 'border-amber-300 bg-amber-50' : 'border-slate-200 hover:bg-slate-50'">
                                                <input type="checkbox"
                                                       :checked="isTaxSystemSelected(ts.id)"
                                                       @change="toggleTaxSystem(ts.id)"
                                                       class="w-3.5 h-3.5 text-amber-500 border-slate-300 rounded focus:ring-amber-400">
                                                <span class="text-sm font-medium text-slate-700" x-text="ts.name"></span>
                                            </label>
                                        </template>
                                    </div>
                                </div>
                            </template>

                            <!-- Тарифы с pivot-полями (только для родительских БП) -->
                            <template x-if="!serviceForm.parent_id">
                                <div class="space-y-2">
                                    <label class="block text-sm font-medium text-slate-700">Тарифы <span class="text-red-500">*</span></label>
                                    <p x-show="serviceFormErrors.tariffs" class="text-xs text-red-500">Выберите хотя бы один тариф</p>
                                    <template x-for="tariff in tariffs" :key="tariff.id">
                                        <div class="border rounded-xl overflow-hidden transition-colors"
                                             :class="isTariffSelected(tariff.id) ? 'border-indigo-300' : 'border-slate-200'">
                                            <label class="flex items-center gap-2 cursor-pointer px-3 py-2 transition-colors"
                                                   :class="isTariffSelected(tariff.id) ? 'bg-indigo-50 hover:bg-indigo-100' : 'hover:bg-slate-50'">
                                                <input type="checkbox"
                                                       :checked="isTariffSelected(tariff.id)"
                                                       @change="toggleTariff(tariff)"
                                                       class="w-3.5 h-3.5 text-indigo-600 border-slate-300 rounded focus:ring-indigo-500">
                                                <span class="text-sm font-medium text-slate-700" x-text="tariff.name"></span>
                                            </label>
                                            <template x-if="isTariffSelected(tariff.id)">
                                                <div class="px-3 pb-3 pt-2 bg-indigo-50/50 border-t border-indigo-100 grid grid-cols-2 gap-3">
                                                    <div>
                                                        <label class="block text-xs font-medium text-slate-500 mb-1">Бесплатный лимит (шт.)</label>
                                                        <input type="number"
                                                               :value="getTariffPivot(tariff.id).free_limit"
                                                               @input="updateTariffPivot(tariff.id, 'free_limit', $event.target.value)"
                                                               min="0" placeholder="0"
                                                               class="w-full px-2 py-1.5 border border-slate-200 rounded-lg text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                                    </div>
                                                    <div>
                                                        <label class="block text-xs font-medium text-slate-500 mb-1">Цена сверх лимита (сом)</label>
                                                        <input type="number"
                                                               :value="getTariffPivot(tariff.id).price_override ?? ''"
                                                               @input="updateTariffPivot(tariff.id, 'price_override', $event.target.value)"
                                                               min="0" placeholder="— как у услуги —"
                                                               class="w-full px-2 py-1.5 border border-slate-200 rounded-lg text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                                    </div>
                                                </div>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </template>

                            <!-- Подпункты (только для родительских БП) -->
                            <template x-if="!serviceForm.parent_id">
                                <div class="border-t border-slate-100 pt-4">
                                    <div class="flex items-center justify-between mb-3">
                                        <label class="text-sm font-semibold text-slate-700">Подпункты</label>
                                        <button type="button" @click="addChildForm()"
                                                class="inline-flex items-center text-xs text-indigo-600 hover:text-indigo-800 font-medium">
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
                                                        <input type="text" x-model="child.name" placeholder="Название подпункта *" required
                                                               class="block w-full px-2.5 py-1.5 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white">
                                                    </div>
                                                    <input type="number" x-model.number="child.cost" min="0" placeholder="Стоимость"
                                                           class="px-2.5 py-1.5 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white">
                                                    <select x-model="child.periodicity"
                                                            class="px-2.5 py-1.5 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white">
                                                        <option value="">— периодичность —</option>
                                                        <option value="Ежемесячный">Ежемесячный</option>
                                                        <option value="Ежеквартальный">Ежеквартальный</option>
                                                        <option value="Ежегодный">Ежегодный</option>
                                                        <option value="Разовый">Разовый</option>
                                                    </select>
                                                    <label class="col-span-2 flex items-center gap-1.5 cursor-pointer">
                                                        <input type="checkbox" x-model="child.allows_quantity"
                                                               class="w-3.5 h-3.5 text-indigo-600 border-slate-300 rounded focus:ring-indigo-500">
                                                        <span class="text-xs text-slate-600">Можно указывать количество</span>
                                                    </label>
                                                </div>
                                                <button type="button" @click="removeChildForm(cidx)"
                                                        class="p-1 text-slate-300 hover:text-red-500 transition-colors rounded mt-0.5">
                                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                                </button>
                                            </div>
                                        </template>
                                    </div>
                                </div>
                            </template>
                        </div>

                        <div class="flex justify-end gap-3 mt-6 pt-4 border-t border-slate-100">
                            <button type="button" @click="showServiceModal = false" class="px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
                                Отмена
                            </button>
                            <button type="submit" :disabled="savingService" class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-colors">
                                <svg x-show="savingService" class="w-4 h-4 mr-2 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                <span x-text="serviceForm.id ? 'Сохранить' : 'Создать'"></span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </template>

    <!-- Модальное окно ставки -->
    <template x-teleport="body">
        <div x-show="showRateModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
            <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
                <div x-show="showRateModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-slate-500/75 transition-opacity" @click="showRateModal = false"></div>

                <div x-show="showRateModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" class="relative inline-block w-full max-w-lg p-6 my-8 text-left align-middle bg-white rounded-2xl shadow-xl transform transition-all">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-semibold text-slate-900" x-text="rateForm.id ? 'Редактирование ставки' : 'Новая ставка'"></h3>
                        <button @click="showRateModal = false" class="p-2 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100 transition-colors">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <form @submit.prevent="submitRateForm()">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Название <span class="text-red-500">*</span></label>
                                <input type="text" x-model="rateForm.name" required class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Единица</label>
                                    <input type="text" x-model="rateForm.unit" placeholder="час, шт., услуга..." class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Цена <span class="text-red-500">*</span></label>
                                    <input type="number" x-model="rateForm.price" required min="0" step="0.01" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Условия</label>
                                <textarea x-model="rateForm.conditions" rows="3" placeholder="Опишите условия применения ставки..." class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 resize-none"></textarea>
                            </div>
                        </div>

                        <div class="flex justify-end gap-3 mt-6 pt-6 border-t border-slate-100">
                            <button type="button" @click="showRateModal = false" class="px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
                                Отмена
                            </button>
                            <button type="submit" :disabled="savingRate" class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-colors">
                                <svg x-show="savingRate" class="w-4 h-4 mr-2 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                <span x-text="rateForm.id ? 'Сохранить' : 'Создать'"></span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </template>

    <!-- Модальное окно удаления -->
    <template x-teleport="body">
        <div x-show="showDeleteModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
            <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
                <div x-show="showDeleteModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-slate-500/75 transition-opacity" @click="showDeleteModal = false"></div>

                <div x-show="showDeleteModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" class="relative inline-block w-full max-w-md p-6 my-8 text-left align-middle bg-white rounded-2xl shadow-xl transform transition-all">
                    <div class="flex items-center gap-4 mb-4">
                        <div class="flex-shrink-0 w-12 h-12 bg-red-100 rounded-full flex items-center justify-center">
                            <svg class="w-6 h-6 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" /></svg>
                        </div>
                        <div>
                            <h3 class="text-lg font-semibold text-slate-900">Удаление</h3>
                            <p class="text-sm text-slate-500">Это действие нельзя отменить</p>
                        </div>
                    </div>

                    <p class="text-slate-700 mb-6">Вы уверены, что хотите удалить «<span class="font-medium" x-text="deleteItem?.name"></span>»?</p>

                    <div class="flex justify-end gap-3">
                        <button @click="showDeleteModal = false" class="px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
                            Отмена
                        </button>
                        <button @click="confirmDelete()" :disabled="deleting" class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-red-600 rounded-lg hover:bg-red-700 disabled:opacity-50 transition-colors">
                            <svg x-show="deleting" class="w-4 h-4 mr-2 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            Удалить
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>

    <!-- Toast уведомления -->
    <template x-teleport="body">
        <div x-show="toast.show" x-cloak x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 translate-y-2" class="fixed bottom-4 right-4 z-50">
            <div :class="toast.type === 'success' ? 'bg-emerald-500' : 'bg-red-500'" class="px-4 py-3 rounded-lg text-white text-sm font-medium shadow-lg flex items-center gap-2">
                <svg x-show="toast.type === 'success'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                <svg x-show="toast.type === 'error'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                <span x-text="toast.message"></span>
            </div>
        </div>
    </template>
</div>

@php
$servicesJson = $services->map(fn($s) => [
    'id'              => $s->id,
    'parent_id'       => $s->parent_id,
    'tax_systems'     => $s->taxSystems->map(fn($ts) => ['id' => $ts->id, 'name' => $ts->name])->values(),
    'name'            => $s->name,
    'description'     => $s->description,
    'cost'            => $s->cost,
    'pricing_rules'   => $s->pricing_rules ?? [],
    'periodicity'     => $s->periodicity,
    'due_day'         => $s->due_day,
    'is_active'       => $s->is_active,
    'allows_quantity' => $s->allows_quantity,
    'sort_order'      => $s->sort_order,
    'tariffs'         => $s->tariffs->map(fn($t) => [
        'id'             => $t->id,
        'name'           => $t->name,
        'free_limit'     => (int) $t->pivot->free_limit,
        'price_override' => $t->pivot->price_override !== null ? (float) $t->pivot->price_override : null,
    ])->values(),
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
        'tariffs'         => [],
        'children'        => [],
    ])->values(),
]);
@endphp
<script>
function settingsPage() {
    return {
        activeTab: 'tax_systems',
        taxSystems: @json($taxSystems),
        activityTypes: @json($activityTypes),
        tariffs: @json($tariffs),
        rates: @json($rates),
        services: @json($servicesJson),

        // Общий модал (налоги, виды деят., тарифы)
        showModal: false,
        showDeleteModal: false,
        modalType: '',
        isEditing: false,
        editingId: null,
        saving: false,
        deleting: false,
        deleteType: '',
        deleteItem: null,
        deleteIsService: false,
        deleteServiceParentId: null,

        form: {
            name: '',
            code: '',
            price: 0,
            description: '',
            sort_order: 0,
            is_active: true,
        },

        // Модал ставки
        showRateModal: false,
        savingRate: false,
        rateForm: { id: null, name: '', unit: '', price: 0, conditions: '' },

        // Модал услуги
        showServiceModal: false,
        savingService: false,
        showDueDayPicker: false,
        serviceFormErrors: {
            tax_systems: false,
            tariffs: false,
        },
        serviceForm: {
            id: null,
            parent_id: null,
            tax_systems: [],
            name: '',
            description: '',
            cost: 0,
            pricing_rules: [],
            use_tiered_pricing: false,
            periodicity: '',
            due_day: null,
            allows_quantity: false,
            tariffs: [],
            children: [],
        },

        toast: {
            show: false,
            message: '',
            type: 'success',
        },

        get modalTitle() {
            const titles = {
                tax_systems: 'Система налогообложения',
                activity_types: 'Вид деятельности',
                tariffs: 'Тариф',
            };
            const prefix = this.isEditing ? 'Редактирование: ' : 'Новый: ';
            return prefix + (titles[this.modalType] || '');
        },

        openCreateModal(type) {
            this.modalType = type;
            this.isEditing = false;
            this.editingId = null;
            this.resetForm();
            this.showModal = true;
        },

        openEditModal(type, item) {
            this.modalType = type;
            this.isEditing = true;
            this.editingId = item.id;
            this.form = {
                name: item.name,
                code: item.code,
                price: item.price || 0,
                description: item.description || '',
                sort_order: item.sort_order || 0,
                is_active: item.is_active,
            };
            this.showModal = true;
        },

        openDeleteModal(type, item) {
            this.deleteType = type;
            this.deleteItem = item;
            this.deleteIsService = false;
            this.showDeleteModal = true;
        },

        openDeleteServiceModal(svc, parentId = null) {
            this.deleteType = 'services';
            this.deleteItem = svc;
            this.deleteIsService = true;
            this.deleteServiceParentId = parentId;
            this.showDeleteModal = true;
        },

        closeModal() {
            this.showModal = false;
            this.resetForm();
        },

        resetForm() {
            this.form = {
                name: '',
                code: '',
                price: 0,
                description: '',
                sort_order: 0,
                is_active: true,
            };
        },

        openRateModal(rate = null) {
            this.rateForm = rate
                ? { id: rate.id, name: rate.name, unit: rate.unit || '', price: rate.price, conditions: rate.conditions || '' }
                : { id: null, name: '', unit: '', price: 0, conditions: '' };
            this.showRateModal = true;
        },

        openDeleteRateModal(rate) {
            this.deleteType = 'rates';
            this.deleteItem = rate;
            this.deleteIsService = false;
            this.showDeleteModal = true;
        },

        async submitRateForm() {
            this.savingRate = true;
            const url = this.rateForm.id ? `/settings/rates/${this.rateForm.id}` : '/settings/rates';
            const method = this.rateForm.id ? 'PUT' : 'POST';
            try {
                const response = await fetch(url, {
                    method,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(this.rateForm),
                });
                const data = await response.json();
                if (data.success) {
                    this.showToast(data.message, 'success');
                    if (this.rateForm.id) {
                        const idx = this.rates.findIndex(r => r.id === this.rateForm.id);
                        if (idx !== -1) this.rates[idx] = data.item;
                    } else {
                        this.rates.push(data.item);
                    }
                    this.showRateModal = false;
                } else {
                    this.showToast(data.message || 'Ошибка сохранения', 'error');
                }
            } catch (e) {
                this.showToast('Ошибка сохранения', 'error');
            }
            this.savingRate = false;
        },

        openServiceModal(svc = null) {
            this.serviceFormErrors = { tax_systems: false, tariffs: false };
            if (svc) {
                const pricingRules = svc.pricing_rules || [];
                this.serviceForm = {
                    id: svc.id,
                    parent_id: svc.parent_id || null,
                    tax_systems: (svc.tax_systems || []).map(ts => ts.id),
                    name: svc.name,
                    description: svc.description || '',
                    cost: svc.cost,
                    pricing_rules: pricingRules,
                    use_tiered_pricing: pricingRules.length > 0,
                    periodicity: svc.periodicity || '',
                    due_day: svc.due_day || null,
                    allows_quantity: svc.allows_quantity || false,
                    tariffs: (svc.tariffs || []).map(t => ({
                        id: t.id,
                        free_limit: t.free_limit ?? 0,
                        price_override: t.price_override ?? null,
                    })),
                    children: (svc.children || []).map(c => ({
                        id: c.id,
                        name: c.name,
                        cost: c.cost,
                        periodicity: c.periodicity || '',
                        allows_quantity: c.allows_quantity || false,
                    })),
                };
            } else {
                this.serviceForm = {
                    id: null,
                    parent_id: null,
                    tax_systems: [],
                    name: '',
                    description: '',
                    cost: 0,
                    pricing_rules: [],
                    use_tiered_pricing: false,
                    periodicity: '',
                    due_day: null,
                    allows_quantity: false,
                    tariffs: [],
                    children: [],
                };
            }
            this.showServiceModal = true;
        },

        addChildForm() {
            this.serviceForm.children.push({ id: null, name: '', cost: 0, periodicity: '', allows_quantity: false });
        },

        removeChildForm(cidx) {
            this.serviceForm.children.splice(cidx, 1);
        },

        isTaxSystemSelected(taxSystemId) {
            return this.serviceForm.tax_systems.includes(taxSystemId);
        },

        toggleTaxSystem(taxSystemId) {
            if (this.isTaxSystemSelected(taxSystemId)) {
                this.serviceForm.tax_systems = this.serviceForm.tax_systems.filter(id => id !== taxSystemId);
            } else {
                this.serviceForm.tax_systems.push(taxSystemId);
            }
            this.serviceFormErrors.tax_systems = false;
        },

        isTariffSelected(tariffId) {
            return this.serviceForm.tariffs.some(t => t.id === tariffId);
        },

        getTariffPivot(tariffId) {
            return this.serviceForm.tariffs.find(t => t.id === tariffId)
                || { free_limit: 0, price_override: null };
        },

        toggleTariff(tariff) {
            if (this.isTariffSelected(tariff.id)) {
                this.serviceForm.tariffs = this.serviceForm.tariffs.filter(t => t.id !== tariff.id);
            } else {
                this.serviceForm.tariffs.push({ id: tariff.id, free_limit: 0, price_override: null });
            }
            this.serviceFormErrors.tariffs = false;
        },

        updateTariffPivot(tariffId, field, value) {
            const entry = this.serviceForm.tariffs.find(t => t.id === tariffId);
            if (!entry) return;
            if (field === 'price_override') {
                entry.price_override = value === '' ? null : parseFloat(value);
            } else {
                entry[field] = parseInt(value) || 0;
            }
        },

        addPricingRule() {
            this.serviceForm.pricing_rules.push({ max_qty: '', price: '' });
        },

        async submitServiceForm() {
            // Валидация для родительских БП
            if (!this.serviceForm.parent_id) {
                this.serviceFormErrors.tax_systems = this.serviceForm.tax_systems.length === 0;
                this.serviceFormErrors.tariffs = this.serviceForm.tariffs.length === 0;
                if (this.serviceFormErrors.tax_systems || this.serviceFormErrors.tariffs) {
                    return;
                }
            }

            this.savingService = true;

            const url = this.serviceForm.id
                ? `/settings/services/${this.serviceForm.id}`
                : '/settings/services';
            const method = this.serviceForm.id ? 'PUT' : 'POST';

            try {
                const response = await fetch(url, {
                    method,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(this.serviceForm),
                });

                const data = await response.json();

                if (data.success) {
                    this.showToast(data.message, 'success');
                    const item = data.item;
                    if (this.serviceForm.id) {
                        if (item.parent_id) {
                            // Обновляем дочерний элемент в родителе
                            const parent = this.services.find(s => s.id === item.parent_id);
                            if (parent) {
                                const cidx = parent.children.findIndex(c => c.id === item.id);
                                if (cidx !== -1) parent.children[cidx] = item;
                            }
                        } else {
                            const idx = this.services.findIndex(s => s.id === item.id);
                            if (idx !== -1) this.services[idx] = item;
                        }
                    } else {
                        if (item.parent_id) {
                            // Добавляем подпункт к родителю
                            const parent = this.services.find(s => s.id === item.parent_id);
                            if (parent) {
                                if (!parent.children) parent.children = [];
                                parent.children.push(item);
                            }
                        } else {
                            this.services.push(item);
                        }
                    }
                    this.showServiceModal = false;
                    this.showDueDayPicker = false;
                } else {
                    this.showToast(data.message || 'Ошибка сохранения', 'error');
                }
            } catch (e) {
                this.showToast('Ошибка сохранения', 'error');
            }

            this.savingService = false;
        },

        async submitForm() {
            this.saving = true;

            const urls = {
                tax_systems: '/settings/tax-systems',
                activity_types: '/settings/activity-types',
                tariffs: '/settings/tariffs',
            };

            const url = this.isEditing
                ? `${urls[this.modalType]}/${this.editingId}`
                : urls[this.modalType];

            const method = this.isEditing ? 'PUT' : 'POST';

            try {
                const response = await fetch(url, {
                    method: method,
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(this.form),
                });

                const data = await response.json();

                if (data.success) {
                    this.showToast(data.message, 'success');

                    if (this.isEditing) {
                        const list = this.getList(this.modalType);
                        const index = list.findIndex(i => i.id === this.editingId);
                        if (index !== -1) {
                            list[index] = data.item;
                        }
                    } else {
                        this.getList(this.modalType).push(data.item);
                    }

                    this.closeModal();
                } else {
                    this.showToast(data.message || 'Ошибка сохранения', 'error');
                }
            } catch (error) {
                this.showToast('Ошибка сохранения', 'error');
            }

            this.saving = false;
        },

        async confirmDelete() {
            this.deleting = true;

            const urls = {
                tax_systems: '/settings/tax-systems',
                activity_types: '/settings/activity-types',
                tariffs: '/settings/tariffs',
                rates: '/settings/rates',
                services: '/settings/services',
            };

            try {
                const response = await fetch(`${urls[this.deleteType]}/${this.deleteItem.id}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });

                const data = await response.json();

                if (data.success) {
                    this.showToast(data.message, 'success');
                    if (this.deleteIsService) {
                        if (this.deleteServiceParentId) {
                            const parent = this.services.find(s => s.id === this.deleteServiceParentId);
                            if (parent) parent.children = parent.children.filter(c => c.id !== this.deleteItem.id);
                        } else {
                            this.services = this.services.filter(s => s.id !== this.deleteItem.id);
                        }
                    } else {
                        const list = this.getList(this.deleteType);
                        const index = list.findIndex(i => i.id === this.deleteItem.id);
                        if (index !== -1) list.splice(index, 1);
                    }
                    this.showDeleteModal = false;
                } else {
                    this.showToast(data.message || 'Ошибка удаления', 'error');
                }
            } catch (error) {
                this.showToast('Ошибка удаления', 'error');
            }

            this.deleting = false;
        },

        getList(type) {
            const lists = {
                tax_systems: this.taxSystems,
                activity_types: this.activityTypes,
                tariffs: this.tariffs,
                rates: this.rates,
            };
            return lists[type];
        },

        formatPrice(price) {
            return new Intl.NumberFormat('ru-RU').format(price) + ' сом';
        },

        showToast(message, type = 'success') {
            this.toast = { show: true, message, type };
            setTimeout(() => {
                this.toast.show = false;
            }, 3000);
        },
    };
}
</script>
@endsection
