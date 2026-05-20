@extends('layouts.app')

@section('title', $client->name . ' - ERP Fineco')
@section('page-title', $client->name)

@section('content')
<div x-data="clientShow()" x-init="init()">
    <!-- Шапка -->
    <div class="sm:flex sm:items-center sm:justify-between mb-8">
        <div class="flex items-center gap-4">
            <a href="{{ route('clients.index') }}" class="p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-all duration-150">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
            </a>
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-bold text-slate-800" x-text="client.name">{{ $client->name }}</h1>
                    <template x-if="client.is_active">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-emerald-100 text-emerald-700">Активен</span>
                    </template>
                    <template x-if="!client.is_active">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-slate-100 text-slate-600">Неактивен</span>
                    </template>
                </div>
                <p class="text-slate-500 mt-1">ИНН: <span x-text="client.inn">{{ $client->inn }}</span></p>
            </div>
        </div>
    </div>

    <div class="space-y-6">

            <!-- Основная информация -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="p-2 bg-indigo-100 rounded-lg">
                            <svg class="w-5 h-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <h2 class="text-lg font-semibold text-slate-800">Основная информация</h2>
                    </div>
                    <template x-if="!editing.basic">
                        <button @click="startEdit('basic')" class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-all" title="Редактировать">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                        </button>
                    </template>
                    <template x-if="editing.basic">
                        <div class="flex items-center gap-2">
                            <button @click="saveSection('basic')" :disabled="saving.basic" class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-all">
                                <svg x-show="saving.basic" class="w-4 h-4 mr-1 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                <svg x-show="!saving.basic" class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                                Сохранить
                            </button>
                            <button @click="cancelEdit('basic')" class="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-all">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                            </button>
                        </div>
                    </template>
                </div>
                <div class="px-6 py-5">
                    <!-- Режим просмотра -->
                    <template x-if="!editing.basic">
                        <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-x-6 gap-y-4">
                            <div>
                                <dt class="text-sm font-medium text-slate-500">ИНН</dt>
                                <dd class="mt-1 text-sm text-slate-900 font-mono" x-text="client.inn || '—'"></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-slate-500">ИНН руководителя</dt>
                                <dd class="mt-1 text-sm text-slate-900 font-mono" x-text="client.director_inn || '—'"></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-slate-500">Код НО</dt>
                                <dd class="mt-1 text-sm text-slate-900" x-text="client.tax_office_code || '—'"></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-slate-500">Основной вид деятельности</dt>
                                <dd class="mt-1 text-sm text-slate-900" x-text="client.activity_type?.name || '—'"></dd>
                            </div>
                        </dl>
                    </template>
                    <!-- Режим редактирования -->
                    <template x-if="editing.basic">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-5 gap-x-6 gap-y-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Название <span class="text-red-500">*</span></label>
                                <input type="text" x-model="form.basic.name" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">ИНН <span class="text-red-500">*</span></label>
                                <input type="text" x-model="form.basic.inn" maxlength="14" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">ИНН руководителя</label>
                                <input type="text" x-model="form.basic.director_inn" maxlength="14" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Код НО</label>
                                <input type="text" x-model="form.basic.tax_office_code" maxlength="10" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Основной вид деятельности</label>
                                <select x-model="form.basic.activity_type_id" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                    <option value="">Не указан</option>
                                    <template x-for="at in activityTypes" :key="at.id">
                                        <option :value="String(at.id)" :selected="String(at.id) === form.basic.activity_type_id" x-text="at.name"></option>
                                    </template>
                                </select>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Налоговые данные -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="p-2 bg-emerald-100 rounded-lg">
                            <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <h2 class="text-lg font-semibold text-slate-800">Налоговые данные</h2>
                    </div>
                    <template x-if="!editing.tax">
                        <button @click="startEdit('tax')" class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-all" title="Редактировать">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                        </button>
                    </template>
                    <template x-if="editing.tax">
                        <div class="flex items-center gap-2">
                            <button @click="saveSection('tax')" :disabled="saving.tax" class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-all">
                                <svg x-show="saving.tax" class="w-4 h-4 mr-1 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                <svg x-show="!saving.tax" class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                                Сохранить
                            </button>
                            <button @click="cancelEdit('tax')" class="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-all">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                            </button>
                        </div>
                    </template>
                </div>
                <div class="px-6 py-5">
                    <template x-if="!editing.tax">
                        <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-4">
                            <div>
                                <dt class="text-sm font-medium text-slate-500">Система налогообложения</dt>
                                <dd class="mt-1 text-sm text-slate-900" x-text="client.tax_system?.name || '—'"></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-slate-500">Метод учёта ДиР</dt>
                                <dd class="mt-1 text-sm text-slate-900" x-text="accountingMethods[client.accounting_method] || '—'"></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-slate-500">Категория налогоплательщика</dt>
                                <dd class="mt-1 text-sm text-slate-900" x-text="taxpayerCategories[client.taxpayer_category] || '—'"></dd>
                            </div>
                        </dl>
                    </template>
                    <template x-if="editing.tax">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Система налогообложения</label>
                                <select x-model="form.tax.tax_system_id" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                    <option value="">Не указана</option>
                                    <template x-for="ts in taxSystems" :key="ts.id">
                                        <option :value="String(ts.id)" :selected="String(ts.id) === form.tax.tax_system_id" x-text="ts.name"></option>
                                    </template>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Метод учёта ДиР</label>
                                <select x-model="form.tax.accounting_method" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                    <option value="">Не указан</option>
                                    <template x-for="(label, key) in accountingMethods" :key="key">
                                        <option :value="key" :selected="key === form.tax.accounting_method" x-text="label"></option>
                                    </template>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Категория налогоплательщика</label>
                                <select x-model="form.tax.taxpayer_category" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                    <option value="">Не указана</option>
                                    <template x-for="(label, key) in taxpayerCategories" :key="key">
                                        <option :value="key" :selected="key === form.tax.taxpayer_category" x-text="label"></option>
                                    </template>
                                </select>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Договор и обслуживание -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="p-2 bg-amber-100 rounded-lg">
                            <svg class="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                            </svg>
                        </div>
                        <h2 class="text-lg font-semibold text-slate-800">Договор и обслуживание</h2>
                    </div>
                    <template x-if="!editing.contract">
                        <button @click="startEdit('contract')" class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-all" title="Редактировать">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                        </button>
                    </template>
                    <template x-if="editing.contract">
                        <div class="flex items-center gap-2">
                            <button @click="saveSection('contract')" :disabled="saving.contract" class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-all">
                                <svg x-show="saving.contract" class="w-4 h-4 mr-1 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                <svg x-show="!saving.contract" class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                                Сохранить
                            </button>
                            <button @click="cancelEdit('contract')" class="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-all">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                            </button>
                        </div>
                    </template>
                </div>
                <div class="px-6 py-5">
                    <template x-if="!editing.contract">
                        <div>
                            <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-x-6 gap-y-4">
                                <div>
                                    <dt class="text-sm font-medium text-slate-500">Тип обслуживания</dt>
                                    <dd class="mt-1 text-sm text-slate-900" x-text="serviceTypes[client.service_type] || '—'"></dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-slate-500">Тариф</dt>
                                    <dd class="mt-1 text-sm text-slate-900 font-semibold" x-text="client.tariff ? client.tariff.name : '—'"></dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-slate-500">С кем составлен договор</dt>
                                    <dd class="mt-1 text-sm text-slate-900" x-text="client.contract_with || '—'"></dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-slate-500">Дата начала обслуживания</dt>
                                    <dd class="mt-1 text-sm text-slate-900" x-text="formatDate(client.service_start_date) || '—'"></dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-slate-500">Дата завершения</dt>
                                    <dd class="mt-1 text-sm text-slate-900" x-text="formatDate(client.service_end_date) || 'Бессрочно'"></dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-slate-500">Ответственные лица</dt>
                                    <dd class="mt-1 flex flex-wrap gap-1">
                                        <template x-if="client.employees && client.employees.length > 0">
                                            <template x-for="emp in client.employees" :key="emp.id">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-700" x-text="emp.full_name"></span>
                                            </template>
                                        </template>
                                        <template x-if="!client.employees || client.employees.length === 0">
                                            <span class="text-sm text-slate-500">—</span>
                                        </template>
                                    </dd>
                                </div>
                            </dl>
                            <div class="mt-6 pt-6 border-t border-slate-100">
                                <h3 class="text-sm font-medium text-slate-700 mb-3">Документы</h3>
                                <div class="flex flex-wrap gap-3">
                                    <template x-if="client.contract_url">
                                        <a :href="client.contract_url" target="_blank" class="inline-flex items-center px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm rounded-lg transition-colors">
                                            <svg class="w-4 h-4 mr-2 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" /></svg>
                                            Договор
                                        </a>
                                    </template>
                                    <template x-if="client.requisites_url">
                                        <a :href="client.requisites_url" target="_blank" class="inline-flex items-center px-3 py-2 bg-slate-100 hover:bg-slate-200 text-slate-700 text-sm rounded-lg transition-colors">
                                            <svg class="w-4 h-4 mr-2 text-slate-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.828 10.172a4 4 0 00-5.656 0l-4 4a4 4 0 105.656 5.656l1.102-1.101m-.758-4.899a4 4 0 005.656 0l4-4a4 4 0 00-5.656-5.656l-1.1 1.1" /></svg>
                                            Реквизиты
                                        </a>
                                    </template>
                                    <template x-if="!client.contract_url && !client.requisites_url">
                                        <span class="text-sm text-slate-500">Документы не прикреплены</span>
                                    </template>
                                </div>
                            </div>
                        </div>
                    </template>
                    <template x-if="editing.contract">
                        <div class="space-y-4">
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-x-6 gap-y-4">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Тип обслуживания</label>
                                    <select x-model="form.contract.service_type" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                        <option value="">Не указан</option>
                                        <template x-for="(label, key) in serviceTypes" :key="key">
                                            <option :value="key" :selected="key === form.contract.service_type" x-text="label"></option>
                                        </template>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Тариф</label>
                                    <select x-model="form.contract.tariff_id" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                        <option value="">Не указан</option>
                                        <template x-for="t in tariffs" :key="t.id">
                                            <option :value="String(t.id)" :selected="String(t.id) === form.contract.tariff_id" x-text="t.name"></option>
                                        </template>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">С кем составлен договор</label>
                                    <input type="text" x-model="form.contract.contract_with" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Дата начала</label>
                                    <input type="date" x-model="form.contract.service_start_date" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Дата завершения</label>
                                    <input type="date" x-model="form.contract.service_end_date" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Ответственные лица</label>
                                    <select x-model="form.contract.employees" multiple class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 h-[42px]">
                                        <template x-for="emp in allEmployees" :key="emp.id">
                                            <option :value="String(emp.id)" :selected="form.contract.employees.includes(String(emp.id))" x-text="emp.full_name"></option>
                                        </template>
                                    </select>
                                </div>
                            </div>
                            <div class="pt-4 border-t border-slate-100">
                                <h4 class="text-sm font-medium text-slate-700 mb-3">Документы</h4>
                                <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-sm font-medium text-slate-700 mb-1">Ссылка на договор</label>
                                        <input type="url" x-model="form.contract.contract_url" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500" placeholder="https://...">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-slate-700 mb-1">Ссылка на реквизиты</label>
                                        <input type="url" x-model="form.contract.requisites_url" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500" placeholder="https://...">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Доверенность -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="p-2 bg-violet-100 rounded-lg">
                            <svg class="w-5 h-5 text-violet-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <h2 class="text-lg font-semibold text-slate-800">Доверенность</h2>
                    </div>
                    <template x-if="!editing.attorney">
                        <button @click="startEdit('attorney')" class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-all" title="Редактировать">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                        </button>
                    </template>
                    <template x-if="editing.attorney">
                        <div class="flex items-center gap-2">
                            <button @click="saveSection('attorney')" :disabled="saving.attorney" class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-all">
                                <svg x-show="!saving.attorney" class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                                Сохранить
                            </button>
                            <button @click="cancelEdit('attorney')" class="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-all">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                            </button>
                        </div>
                    </template>
                </div>
                <div class="px-6 py-5">
                    <template x-if="!editing.attorney">
                        <dl class="grid grid-cols-2 gap-x-6 gap-y-4">
                            <div>
                                <dt class="text-sm font-medium text-slate-500">Доверенность на имя</dt>
                                <dd class="mt-1 text-sm text-slate-900" x-text="client.power_of_attorney_name || '—'"></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-slate-500">Срок действия</dt>
                                <dd class="mt-1 text-sm text-slate-900" x-text="formatDate(client.power_of_attorney_expires) || '—'"></dd>
                            </div>
                        </dl>
                    </template>
                    <template x-if="editing.attorney">
                        <div class="grid grid-cols-2 gap-x-6 gap-y-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Доверенность на имя</label>
                                <input type="text" x-model="form.attorney.power_of_attorney_name" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Срок действия</label>
                                <input type="date" x-model="form.attorney.power_of_attorney_expires" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- ИТС (1С) -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="p-2 bg-cyan-100 rounded-lg">
                            <svg class="w-5 h-5 text-cyan-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.75 17L9 20l-1 1h8l-1-1-.75-3M3 13h18M5 17h14a2 2 0 002-2V5a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
                            </svg>
                        </div>
                        <h2 class="text-lg font-semibold text-slate-800">ИТС (1С)</h2>
                        <template x-if="client.its_enabled">
                            <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-emerald-100 text-emerald-700">Подключено</span>
                        </template>
                    </div>
                    <template x-if="!editing.its">
                        <button @click="startEdit('its')" class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-all" title="Редактировать">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                        </button>
                    </template>
                    <template x-if="editing.its">
                        <div class="flex items-center gap-2">
                            <button @click="saveSection('its')" :disabled="saving.its" class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-all">
                                <svg x-show="!saving.its" class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                                Сохранить
                            </button>
                            <button @click="cancelEdit('its')" class="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-all">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                            </button>
                        </div>
                    </template>
                </div>
                <div class="px-6 py-5">
                    <template x-if="!editing.its">
                        <template x-if="client.its_enabled">
                            <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-x-6 gap-y-4">
                                <div>
                                    <dt class="text-sm font-medium text-slate-500">Способ подключения</dt>
                                    <dd class="mt-1 text-sm text-slate-900" x-text="connectionTypes[client.connection_type] || '—'"></dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-slate-500">Контактное лицо</dt>
                                    <dd class="mt-1 text-sm text-slate-900" x-text="client.its_contact || '—'"></dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-slate-500">Путь к базе</dt>
                                    <dd class="mt-1 text-sm text-slate-900 break-all" x-text="client.database_path || '—'"></dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-slate-500">Логин ИТС</dt>
                                    <dd class="mt-1 text-sm font-mono" :class="showPasswords ? 'text-slate-900' : 'text-slate-400'" x-text="showPasswords ? (client.its_credentials?.login || '—') : '••••••••'"></dd>
                                </div>
                                <div>
                                    <dt class="text-sm font-medium text-slate-500">Пароль ИТС</dt>
                                    <dd class="mt-1 text-sm font-mono" :class="showPasswords ? 'text-slate-900' : 'text-slate-400'" x-text="showPasswords ? (client.its_credentials?.password || '—') : '••••••••'"></dd>
                                </div>
                            </dl>
                        </template>
                        <template x-if="!client.its_enabled">
                            <p class="text-sm text-slate-500">ИТС не подключено</p>
                        </template>
                    </template>
                    <template x-if="editing.its">
                        <div class="space-y-4">
                            <div class="flex items-center">
                                <input type="checkbox" x-model="form.its.its_enabled" id="its_enabled" class="w-4 h-4 text-indigo-600 border-slate-300 rounded focus:ring-indigo-500">
                                <label for="its_enabled" class="ml-2 text-sm font-medium text-slate-700">Обслуживание ИТС</label>
                            </div>
                            <template x-if="form.its.its_enabled">
                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-5 gap-x-6 gap-y-4">
                                    <div>
                                        <label class="block text-sm font-medium text-slate-700 mb-1">Способ подключения</label>
                                        <select x-model="form.its.connection_type" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                            <option value="">Не указан</option>
                                            <template x-for="(label, key) in connectionTypes" :key="key">
                                                <option :value="key" :selected="key === form.its.connection_type" x-text="label"></option>
                                            </template>
                                        </select>
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-slate-700 mb-1">Контактное лицо</label>
                                        <input type="text" x-model="form.its.its_contact" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-slate-700 mb-1">Путь к базе</label>
                                        <input type="text" x-model="form.its.database_path" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-slate-700 mb-1">Логин ИТС</label>
                                        <input type="text" x-model="form.its.its_credentials.login" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                    </div>
                                    <div>
                                        <label class="block text-sm font-medium text-slate-700 mb-1">Пароль ИТС</label>
                                        <input type="text" x-model="form.its.its_credentials.password" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </div>

            <!-- ЭЦП и доступы -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="p-2 bg-rose-100 rounded-lg">
                            <svg class="w-5 h-5 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 7a2 2 0 012 2m4 0a6 6 0 01-7.743 5.743L11 17H9v2H7v2H4a1 1 0 01-1-1v-2.586a1 1 0 01.293-.707l5.964-5.964A6 6 0 1121 9z" />
                            </svg>
                        </div>
                        <h2 class="text-lg font-semibold text-slate-800">ЭЦП и доступы</h2>
                    </div>
                    <div class="flex items-center gap-2">
                        <button @click="showPasswords = !showPasswords" type="button" class="p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-all" title="Показать/скрыть пароли">
                            <svg x-show="!showPasswords" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                            </svg>
                            <svg x-show="showPasswords" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                            </svg>
                        </button>
                        <template x-if="!editing.eds">
                            <button @click="startEdit('eds')" class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-all" title="Редактировать">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                </svg>
                            </button>
                        </template>
                        <template x-if="editing.eds">
                            <div class="flex items-center gap-2">
                                <button @click="saveSection('eds')" :disabled="saving.eds" class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-all">
                                    <svg x-show="!saving.eds" class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                                    Сохранить
                                </button>
                                <button @click="cancelEdit('eds')" class="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-all">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                </button>
                            </div>
                        </template>
                    </div>
                </div>
                <div class="px-6 py-5 space-y-5">
                    <template x-if="!editing.eds">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                            <div>
                                <h4 class="text-sm font-medium text-slate-700 mb-3">ЭЦП / Тундук ЭСИ</h4>
                                <dl class="grid grid-cols-2 gap-4">
                                    <div>
                                        <dt class="text-xs font-medium text-slate-500">Пароль ЭЦП</dt>
                                        <dd class="mt-1 text-sm font-mono" :class="showPasswords ? 'text-slate-900' : 'text-slate-400'" x-text="showPasswords ? (client.eds_password || '—') : '••••••••'"></dd>
                                    </div>
                                    <div>
                                        <dt class="text-xs font-medium text-slate-500">Срок действия</dt>
                                        <dd class="mt-1 text-sm" :class="{'text-red-600': isEdsExpired(), 'text-amber-600': isEdsExpiringSoon(), 'text-slate-900': !isEdsExpired() && !isEdsExpiringSoon()}" x-text="formatDate(client.eds_expires) || '—'"></dd>
                                    </div>
                                </dl>
                            </div>
                            <template x-if="client.cabinet_credentials?.login">
                                <div class="sm:border-l sm:border-slate-100 sm:pl-6">
                                    <h4 class="text-sm font-medium text-slate-700 mb-3">Кабинет (без ЭЦП)</h4>
                                    <dl class="grid grid-cols-2 gap-4">
                                        <div>
                                            <dt class="text-xs font-medium text-slate-500">Логин</dt>
                                            <dd class="mt-1 text-sm font-mono" :class="showPasswords ? 'text-slate-900' : 'text-slate-400'" x-text="showPasswords ? client.cabinet_credentials?.login : '••••••••'"></dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs font-medium text-slate-500">Пароль</dt>
                                            <dd class="mt-1 text-sm font-mono" :class="showPasswords ? 'text-slate-900' : 'text-slate-400'" x-text="showPasswords ? client.cabinet_credentials?.password : '••••••••'"></dd>
                                        </div>
                                    </dl>
                                </div>
                            </template>
                            <template x-if="client.esf_user_credentials?.login">
                                <div class="sm:border-l sm:border-slate-100 sm:pl-6">
                                    <h4 class="text-sm font-medium text-slate-700 mb-3">Доп. пользователь ЭСФ</h4>
                                    <dl class="grid grid-cols-2 gap-4">
                                        <div>
                                            <dt class="text-xs font-medium text-slate-500">Логин</dt>
                                            <dd class="mt-1 text-sm font-mono" :class="showPasswords ? 'text-slate-900' : 'text-slate-400'" x-text="showPasswords ? client.esf_user_credentials?.login : '••••••••'"></dd>
                                        </div>
                                        <div>
                                            <dt class="text-xs font-medium text-slate-500">Пароль</dt>
                                            <dd class="mt-1 text-sm font-mono" :class="showPasswords ? 'text-slate-900' : 'text-slate-400'" x-text="showPasswords ? client.esf_user_credentials?.password : '••••••••'"></dd>
                                        </div>
                                    </dl>
                                </div>
                            </template>
                        </div>
                    </template>
                    <template x-if="editing.eds">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                            <div>
                                <h4 class="text-sm font-medium text-slate-700 mb-3">ЭЦП / Тундук ЭСИ</h4>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-medium text-slate-500 mb-1">Пароль ЭЦП</label>
                                        <input type="text" x-model="form.eds.eds_password" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-slate-500 mb-1">Срок действия</label>
                                        <input type="date" x-model="form.eds.eds_expires" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                    </div>
                                </div>
                            </div>
                            <div class="sm:border-l sm:border-slate-100 sm:pl-6">
                                <h4 class="text-sm font-medium text-slate-700 mb-3">Кабинет (без ЭЦП)</h4>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-medium text-slate-500 mb-1">Логин</label>
                                        <input type="text" x-model="form.eds.cabinet_credentials.login" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-slate-500 mb-1">Пароль</label>
                                        <input type="text" x-model="form.eds.cabinet_credentials.password" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                    </div>
                                </div>
                            </div>
                            <div class="sm:border-l sm:border-slate-100 sm:pl-6">
                                <h4 class="text-sm font-medium text-slate-700 mb-3">Доп. пользователь ЭСФ</h4>
                                <div class="grid grid-cols-2 gap-4">
                                    <div>
                                        <label class="block text-xs font-medium text-slate-500 mb-1">Логин</label>
                                        <input type="text" x-model="form.eds.esf_user_credentials.login" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-slate-500 mb-1">Пароль</label>
                                        <input type="text" x-model="form.eds.esf_user_credentials.password" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Банки -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="p-2 bg-blue-100 rounded-lg">
                            <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 14v3m4-3v3m4-3v3M3 21h18M3 10h18M3 7l9-4 9 4M4 10h16v11H4V10z" />
                            </svg>
                        </div>
                        <h2 class="text-lg font-semibold text-slate-800">Интернет-банкинг</h2>
                    </div>
                    <template x-if="!editing.banks">
                        <button @click="startEdit('banks')" class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-all" title="Редактировать">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                        </button>
                    </template>
                    <template x-if="editing.banks">
                        <div class="flex items-center gap-2">
                            <button @click="saveSection('banks')" :disabled="saving.banks" class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-all">
                                <svg x-show="!saving.banks" class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                                Сохранить
                            </button>
                            <button @click="cancelEdit('banks')" class="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-all">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                            </button>
                        </div>
                    </template>
                </div>
                <div class="px-6 py-5">
                    <template x-if="!editing.banks">
                        <template x-if="client.bank_credentials && client.bank_credentials.length > 0">
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                                <template x-for="(bank, index) in client.bank_credentials" :key="index">
                                    <div :class="{'sm:border-l sm:border-slate-100 sm:pl-6': index > 0}">
                                        <h4 class="text-sm font-medium text-slate-700 mb-3" x-text="bank.bank || 'Банк ' + (index + 1)"></h4>
                                        <dl class="grid grid-cols-2 gap-4">
                                            <div>
                                                <dt class="text-xs font-medium text-slate-500">Логин</dt>
                                                <dd class="mt-1 text-sm font-mono" :class="showPasswords ? 'text-slate-900' : 'text-slate-400'" x-text="showPasswords ? (bank.login || '—') : '••••••••'"></dd>
                                            </div>
                                            <div>
                                                <dt class="text-xs font-medium text-slate-500">Пароль</dt>
                                                <dd class="mt-1 text-sm font-mono" :class="showPasswords ? 'text-slate-900' : 'text-slate-400'" x-text="showPasswords ? (bank.password || '—') : '••••••••'"></dd>
                                            </div>
                                        </dl>
                                    </div>
                                </template>
                            </div>
                        </template>
                        <template x-if="!client.bank_credentials || client.bank_credentials.length === 0">
                            <p class="text-sm text-slate-500">Банки не добавлены</p>
                        </template>
                    </template>
                    <template x-if="editing.banks">
                        <div>
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-6">
                                <template x-for="(bank, index) in form.banks.bank_credentials" :key="index">
                                    <div :class="{'sm:border-l sm:border-slate-100 sm:pl-6': index > 0}" class="relative">
                                        <button @click="removeBank(index)" class="absolute top-0 right-0 p-1 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded transition-all" title="Удалить">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" /></svg>
                                        </button>
                                        <div class="grid grid-cols-1 gap-3 pr-8">
                                            <div>
                                                <label class="block text-xs font-medium text-slate-500 mb-1">Название банка</label>
                                                <input type="text" x-model="bank.bank" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-slate-500 mb-1">Логин</label>
                                                <input type="text" x-model="bank.login" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                            </div>
                                            <div>
                                                <label class="block text-xs font-medium text-slate-500 mb-1">Пароль</label>
                                                <input type="text" x-model="bank.password" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                            <template x-if="form.banks.bank_credentials.length < 3">
                                <button @click="addBank()" type="button" class="mt-4 w-full py-2 border-2 border-dashed border-slate-200 rounded-lg text-sm text-slate-500 hover:border-indigo-300 hover:text-indigo-600 transition-colors">
                                    + Добавить банк
                                </button>
                            </template>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Примечания -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="p-2 bg-slate-100 rounded-lg">
                            <svg class="w-5 h-5 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 8h10M7 12h4m1 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z" />
                            </svg>
                        </div>
                        <h2 class="text-lg font-semibold text-slate-800">Примечания</h2>
                    </div>
                    <template x-if="!editing.notes">
                        <button @click="startEdit('notes')" class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-all" title="Редактировать">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                        </button>
                    </template>
                    <template x-if="editing.notes">
                        <div class="flex items-center gap-2">
                            <button @click="saveSection('notes')" :disabled="saving.notes" class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-all">
                                <svg x-show="!saving.notes" class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                                Сохранить
                            </button>
                            <button @click="cancelEdit('notes')" class="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-all">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                            </button>
                        </div>
                    </template>
                </div>
                <div class="px-6 py-5">
                    <template x-if="!editing.notes">
                        <div class="flex items-start gap-6">
                            <div class="flex-1">
                                <p class="text-sm text-slate-700 whitespace-pre-wrap" x-text="client.notes || 'Нет примечаний'"></p>
                            </div>
                            <div class="flex items-center shrink-0">
                                <input type="checkbox" :checked="client.is_active" disabled class="w-4 h-4 text-indigo-600 border-slate-300 rounded">
                                <span class="ml-2 text-sm text-slate-600">Активный клиент</span>
                            </div>
                        </div>
                    </template>
                    <template x-if="editing.notes">
                        <div class="flex items-start gap-6">
                            <div class="flex-1">
                                <label class="block text-sm font-medium text-slate-700 mb-1">Примечания</label>
                                <textarea x-model="form.notes.notes" rows="3" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 resize-none"></textarea>
                            </div>
                            <div class="flex items-center shrink-0 pt-7">
                                <input type="checkbox" x-model="form.notes.is_active" id="is_active" class="w-4 h-4 text-indigo-600 border-slate-300 rounded focus:ring-indigo-500">
                                <label for="is_active" class="ml-2 text-sm font-medium text-slate-700">Активный клиент</label>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

    </div>

    <!-- ==================== СМЕТА (превью) ==================== -->
    @php $estimate = \App\Models\Estimate::where('client_id', $client->id)->first(); @endphp
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden mt-6">
        <div class="px-6 py-5 flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="p-2.5 bg-violet-100 rounded-xl">
                    <svg class="w-5 h-5 text-violet-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                    </svg>
                </div>
                <div>
                    <h2 class="text-base font-semibold text-slate-800">Смета</h2>
                    @if($estimate)
                        <p class="text-sm text-slate-500 mt-0.5">
                            Итого: <span class="font-semibold text-slate-800">{{ number_format($estimate->total, 0, ',', ' ') }} сом</span>
                            <span class="text-slate-400 mx-2">·</span>
                            Обновлена: {{ $estimate->updated_at->format('d.m.Y') }}
                            @if($estimate->items()->count())
                                <span class="text-slate-400 mx-2">·</span>
                                {{ $estimate->items()->count() }} {{ trans_choice('позиция|позиции|позиций', $estimate->items()->count()) }}
                            @endif
                        </p>
                    @else
                        <p class="text-sm text-slate-400 mt-0.5">Смета ещё не создана</p>
                    @endif
                </div>
            </div>
            <div class="flex items-center gap-2">
                @if($estimate)
                    <a href="/clients/{{ $client->id }}/estimate/pdf" target="_blank"
                       class="inline-flex items-center px-3 py-1.5 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
                        <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                        </svg>
                        Скачать PDF
                    </a>
                @endif
                <a href="/clients/{{ $client->id }}/estimate/edit"
                   class="inline-flex items-center px-3 py-1.5 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 transition-colors">
                    <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                    </svg>
                    {{ $estimate ? 'Открыть' : 'Создать смету' }}
                </a>
            </div>
        </div>
    </div>
</div>

<script>
function clientShow() {
    return {
        client: @json($client),
        taxSystems: @json(\App\Models\TaxSystem::active()->ordered()->get()),
        activityTypes: @json(\App\Models\ActivityType::active()->ordered()->get()),
        tariffs: @json(\App\Models\Tariff::active()->ordered()->get()),
        allEmployees: @json(\App\Models\Employee::active()->orderBy('full_name')->get()),
        serviceTypes: @json(\App\Models\Client::$serviceTypes),
        accountingMethods: @json(\App\Models\Client::$accountingMethods),
        taxpayerCategories: @json(\App\Models\Client::$taxpayerCategories),
        connectionTypes: @json(\App\Models\Client::$connectionTypes),

        showPasswords: false,

        editing: {
            basic: false,
            tax: false,
            contract: false,
            attorney: false,
            eds: false,
            its: false,
            banks: false,
            notes: false,
        },

        saving: {
            basic: false,
            tax: false,
            contract: false,
            attorney: false,
            eds: false,
            its: false,
            banks: false,
            notes: false,
        },

        form: {
            basic: {},
            tax: {},
            contract: {},
            attorney: {},
            eds: {},
            its: {},
            banks: {},
            notes: {},
        },

        init() {
            this.resetForms();
        },

        resetForms() {
            this.form.basic = {
                name: this.client.name,
                inn: this.client.inn,
                director_inn: this.client.director_inn,
                tax_office_code: this.client.tax_office_code,
                activity_type_id: this.client.activity_type_id ? String(this.client.activity_type_id) : '',
            };
            this.form.tax = {
                tax_system_id: this.client.tax_system_id ? String(this.client.tax_system_id) : '',
                accounting_method: this.client.accounting_method || '',
                taxpayer_category: this.client.taxpayer_category || '',
            };
            this.form.contract = {
                service_type: this.client.service_type || '',
                tariff_id: this.client.tariff_id ? String(this.client.tariff_id) : '',
                contract_with: this.client.contract_with,
                service_start_date: this.client.service_start_date?.split('T')[0],
                service_end_date: this.client.service_end_date?.split('T')[0],
                contract_url: this.client.contract_url,
                requisites_url: this.client.requisites_url,
                employees: this.client.employees?.map(e => String(e.id)) || [],
            };
            this.form.attorney = {
                power_of_attorney_name: this.client.power_of_attorney_name,
                power_of_attorney_expires: this.client.power_of_attorney_expires?.split('T')[0],
            };
            this.form.eds = {
                eds_password: this.client.eds_password,
                eds_expires: this.client.eds_expires?.split('T')[0],
                cabinet_credentials: this.client.cabinet_credentials || { login: '', password: '' },
                esf_user_credentials: this.client.esf_user_credentials || { login: '', password: '' },
                ettn_user_credentials: this.client.ettn_user_credentials || { login: '', password: '' },
            };
            this.form.its = {
                its_enabled: this.client.its_enabled,
                connection_type: this.client.connection_type || '',
                its_contact: this.client.its_contact,
                database_path: this.client.database_path,
                its_credentials: this.client.its_credentials || { login: '', password: '' },
                onec_connect_credentials: this.client.onec_connect_credentials || { login: '', password: '' },
            };
            this.form.banks = {
                bank_credentials: JSON.parse(JSON.stringify(this.client.bank_credentials || [])),
            };
            this.form.notes = {
                notes: this.client.notes,
                is_active: this.client.is_active,
            };
        },

        startEdit(section) {
            this.resetForms();
            this.editing[section] = true;
        },

        cancelEdit(section) {
            this.editing[section] = false;
            this.resetForms();
        },

        async saveSection(section) {
            this.saving[section] = true;

            try {
                const response = await fetch(`/clients/${this.client.id}`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        section: section,
                        ...this.form[section],
                    }),
                });

                const data = await response.json();

                if (data.success) {
                    this.client = data.client;
                    this.editing[section] = false;
                    this.resetForms();
                } else {
                    alert(data.message || 'Ошибка сохранения');
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Ошибка сохранения');
            }

            this.saving[section] = false;
        },

        addBank() {
            if (this.form.banks.bank_credentials.length < 3) {
                this.form.banks.bank_credentials.push({ bank: '', login: '', password: '' });
            }
        },

        removeBank(index) {
            this.form.banks.bank_credentials.splice(index, 1);
        },

        formatDate(dateStr) {
            if (!dateStr) return null;
            const date = new Date(dateStr);
            return date.toLocaleDateString('ru-RU');
        },

        formatPrice(price) {
            return new Intl.NumberFormat('ru-RU').format(price) + ' сом';
        },

        isEdsExpired() {
            if (!this.client.eds_expires) return false;
            return new Date(this.client.eds_expires) < new Date();
        },

        isEdsExpiringSoon() {
            if (!this.client.eds_expires) return false;
            const expires = new Date(this.client.eds_expires);
            const now = new Date();
            const daysUntil = (expires - now) / (1000 * 60 * 60 * 24);
            return daysUntil > 0 && daysUntil <= 30;
        },
    };
}
</script>
@endsection
