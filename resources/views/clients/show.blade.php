@extends('layouts.app')

@section('title', $client->name . ' - Kubik')
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
                    <template x-if="client.client_status">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium"
                              :class="statusBadgeClass(client.client_status.color)"
                              x-text="client.client_status.name"></span>
                    </template>
                    <template x-if="!client.client_status && client.is_active">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-emerald-100 text-emerald-700">Активен</span>
                    </template>
                    <template x-if="!client.client_status && !client.is_active">
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
                        <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-x-6 gap-y-4">
                            <div>
                                <dt class="text-sm font-medium text-slate-500">Форма организации</dt>
                                <dd class="mt-1 text-sm text-slate-900" x-text="client.organization_form?.name || '—'"></dd>
                            </div>
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
                                <dd class="mt-1 text-sm text-slate-900" x-text="taxAuthorityLabel(client.tax_office_code)"></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-slate-500">Основной вид деятельности</dt>
                                <dd class="mt-1 text-sm text-slate-900" x-text="client.activity_type?.name || '—'"></dd>
                            </div>
                        </dl>
                    </template>
                    <!-- Режим редактирования -->
                    <template x-if="editing.basic">
                        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-6 gap-x-6 gap-y-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Название <span class="text-red-500">*</span></label>
                                <input type="text" x-model="form.basic.name" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Форма организации</label>
                                <select x-model="form.basic.organization_form_id" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                    <option value="">Не указана</option>
                                    <template x-for="of in organizationForms" :key="of.id">
                                        <option :value="String(of.id)" :selected="String(of.id) === form.basic.organization_form_id" x-text="of.name"></option>
                                    </template>
                                </select>
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
                                <select x-model="form.basic.tax_office_code" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                    <option value="">Не указан</option>
                                    <template x-for="ta in taxAuthorities" :key="ta.id">
                                        <option :value="ta.code" :selected="ta.code === form.basic.tax_office_code" x-text="ta.code + ' — ' + ta.name"></option>
                                    </template>
                                </select>
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

            <!-- Статус и период обслуживания -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="p-2 bg-teal-100 rounded-lg">
                            <svg class="w-5 h-5 text-teal-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                        </div>
                        <h2 class="text-lg font-semibold text-slate-800">Статус и период обслуживания</h2>
                    </div>
                    <template x-if="!editing.status">
                        <button @click="startEdit('status')" class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-all" title="Редактировать">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                        </button>
                    </template>
                    <template x-if="editing.status">
                        <div class="flex items-center gap-2">
                            <button @click="saveSection('status')" :disabled="saving.status" class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-all">
                                <svg x-show="saving.status" class="w-4 h-4 mr-1 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                <svg x-show="!saving.status" class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                                Сохранить
                            </button>
                            <button @click="cancelEdit('status')" class="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-all">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                            </button>
                        </div>
                    </template>
                </div>
                <div class="px-6 py-5">
                    <!-- Режим просмотра -->
                    <template x-if="!editing.status">
                        <dl class="grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-4">
                            <div>
                                <dt class="text-sm font-medium text-slate-500">Статус</dt>
                                <dd class="mt-1">
                                    <template x-if="client.client_status">
                                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium"
                                              :class="statusBadgeClass(client.client_status.color)"
                                              x-text="client.client_status.name"></span>
                                    </template>
                                    <template x-if="!client.client_status">
                                        <span class="text-sm text-slate-500">—</span>
                                    </template>
                                </dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-slate-500">Дата начала обслуживания</dt>
                                <dd class="mt-1 text-sm text-slate-900" x-text="formatDate(client.service_start_date) || '—'"></dd>
                            </div>
                            <div>
                                <dt class="text-sm font-medium text-slate-500">Дата завершения</dt>
                                <dd class="mt-1 text-sm text-slate-900" x-text="formatDate(client.service_end_date) || 'Бессрочно'"></dd>
                            </div>
                        </dl>
                    </template>
                    <!-- Режим редактирования -->
                    <template x-if="editing.status">
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-x-6 gap-y-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Статус клиента</label>
                                <select x-model="form.status.client_status_id" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                    <option value="">Не выбран</option>
                                    <template x-for="cs in clientStatuses" :key="cs.id">
                                        <option :value="String(cs.id)" :selected="String(cs.id) === form.status.client_status_id" x-text="cs.name"></option>
                                    </template>
                                </select>
                                <template x-if="form.status.client_status_id && clientStatuses.find(cs => String(cs.id) === form.status.client_status_id)?.closes_service">
                                    <p class="mt-1 text-xs text-amber-600">Этот статус завершает обслуживание. is_active будет снят автоматически.</p>
                                </template>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Дата начала обслуживания</label>
                                <input type="date" x-model="form.status.service_start_date"
                                       class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Дата завершения</label>
                                <div class="flex gap-2">
                                    <input type="date" x-model="form.status.service_end_date"
                                           class="flex-1 min-w-0 px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                    <button type="button" @click="form.status.service_end_date = new Date().toISOString().split('T')[0]"
                                            class="px-3 py-2 text-xs text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors whitespace-nowrap">
                                        Сегодня
                                    </button>
                                </div>
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
                                <dd class="mt-1 text-sm text-slate-900" x-text="client.taxpayer_category_model?.name || taxpayerCategoriesLegacy[client.taxpayer_category] || '—'"></dd>
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
                                <select x-model="form.tax.taxpayer_category_id" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                    <option value="">Не указана</option>
                                    <template x-for="tc in taxpayerCategories" :key="tc.id">
                                        <option :value="String(tc.id)" :selected="String(tc.id) === form.tax.taxpayer_category_id" x-text="tc.name"></option>
                                    </template>
                                </select>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Характеристики бизнеса -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="p-2 bg-orange-100 rounded-lg">
                            <svg class="w-5 h-5 text-orange-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-6 9l2 2 4-4" />
                            </svg>
                        </div>
                        <h2 class="text-lg font-semibold text-slate-800">Характеристики бизнеса</h2>
                    </div>
                    <template x-if="!editing.flags">
                        <button @click="startEdit('flags')" class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-all" title="Редактировать">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                            </svg>
                        </button>
                    </template>
                    <template x-if="editing.flags">
                        <div class="flex items-center gap-2">
                            <button @click="saveSection('flags')" :disabled="saving.flags" class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-all">
                                <svg x-show="saving.flags" class="w-4 h-4 mr-1 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                <svg x-show="!saving.flags" class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                                Сохранить
                            </button>
                            <button @click="cancelEdit('flags')" class="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-all">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                            </button>
                        </div>
                    </template>
                </div>
                <div class="px-6 py-5">

                    <!-- Режим просмотра -->
                    <template x-if="!editing.flags">
                        <div class="space-y-4">
                            <!-- Карточки с количеством -->
                            <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                                <div :class="client.is_zero_movement ? 'bg-amber-50 border-amber-200' : 'bg-slate-50 border-slate-100'" class="flex items-center gap-3 p-3 rounded-xl border transition-colors">
                                    <div :class="client.is_zero_movement ? 'bg-amber-100 text-amber-500' : 'bg-slate-100 text-slate-300'" class="p-1.5 rounded-lg flex-shrink-0">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" /></svg>
                                    </div>
                                    <div class="min-w-0">
                                        <p :class="client.is_zero_movement ? 'text-amber-700' : 'text-slate-400'" class="text-xs font-medium leading-tight">Нулевой клиент</p>
                                        <p :class="client.is_zero_movement ? 'text-amber-500 font-semibold' : 'text-slate-400'" class="text-xs" x-text="client.is_zero_movement ? 'Да' : 'Нет'"></p>
                                    </div>
                                </div>
                                <div :class="client.has_employees ? 'bg-emerald-50 border-emerald-200' : 'bg-slate-50 border-slate-100'" class="flex items-center gap-3 p-3 rounded-xl border transition-colors">
                                    <div :class="client.has_employees ? 'bg-emerald-100 text-emerald-500' : 'bg-slate-100 text-slate-300'" class="p-1.5 rounded-lg flex-shrink-0">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
                                    </div>
                                    <div class="min-w-0">
                                        <p :class="client.has_employees ? 'text-emerald-700' : 'text-slate-400'" class="text-xs font-medium leading-tight">Сотрудники</p>
                                        <p :class="client.has_employees ? 'text-emerald-600 font-semibold' : 'text-slate-400'" class="text-xs" x-text="client.has_employees ? (client.employees_count ? client.employees_count + ' чел.' : 'Да') : 'Нет'"></p>
                                    </div>
                                </div>
                                <div :class="client.has_kkm ? 'bg-blue-50 border-blue-200' : 'bg-slate-50 border-slate-100'" class="flex items-center gap-3 p-3 rounded-xl border transition-colors">
                                    <div :class="client.has_kkm ? 'bg-blue-100 text-blue-500' : 'bg-slate-100 text-slate-300'" class="p-1.5 rounded-lg flex-shrink-0">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" /></svg>
                                    </div>
                                    <div class="min-w-0">
                                        <p :class="client.has_kkm ? 'text-blue-700' : 'text-slate-400'" class="text-xs font-medium leading-tight">ККМ / Касса</p>
                                        <p :class="client.has_kkm ? 'text-blue-600 font-semibold' : 'text-slate-400'" class="text-xs" x-text="client.has_kkm ? (client.kkm_count ? client.kkm_count + ' шт.' : 'Да') : 'Нет'"></p>
                                    </div>
                                </div>
                                <div :class="client.has_marketplaces ? 'bg-violet-50 border-violet-200' : 'bg-slate-50 border-slate-100'" class="flex items-center gap-3 p-3 rounded-xl border transition-colors">
                                    <div :class="client.has_marketplaces ? 'bg-violet-100 text-violet-500' : 'bg-slate-100 text-slate-300'" class="p-1.5 rounded-lg flex-shrink-0">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" /></svg>
                                    </div>
                                    <div class="min-w-0">
                                        <p :class="client.has_marketplaces ? 'text-violet-700' : 'text-slate-400'" class="text-xs font-medium leading-tight">Маркетплейсы</p>
                                        <p :class="client.has_marketplaces ? 'text-violet-600 font-semibold' : 'text-slate-400'" class="text-xs" x-text="client.has_marketplaces ? (client.marketplaces_count ? client.marketplaces_count + ' шт.' : 'Да') : 'Нет'"></p>
                                    </div>
                                </div>
                                <!-- Дополнительные характеристики с количеством -->
                                <template x-for="opt in countFlags" :key="opt.key">
                                    <div :class="client[opt.key] ? ('bg-' + opt.color + '-50 border-' + opt.color + '-200') : 'bg-slate-50 border-slate-100'" class="flex items-center gap-3 p-3 rounded-xl border transition-colors">
                                        <div :class="client[opt.key] ? ('bg-' + opt.color + '-100 text-' + opt.color + '-500') : 'bg-slate-100 text-slate-300'" class="p-1.5 rounded-lg flex-shrink-0">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 7h.01M7 3h5a1.99 1.99 0 011.414.586l7 7a2 2 0 010 2.828l-5 5a2 2 0 01-2.828 0l-7-7A1.99 1.99 0 013 7V4a1 1 0 011-1z" /></svg>
                                        </div>
                                        <div class="min-w-0">
                                            <p :class="client[opt.key] ? ('text-' + opt.color + '-700') : 'text-slate-400'" class="text-xs font-medium leading-tight" x-text="opt.label"></p>
                                            <p :class="client[opt.key] ? ('text-' + opt.color + '-600 font-semibold') : 'text-slate-400'" class="text-xs" x-text="client[opt.key] ? (client[opt.count] ? client[opt.count] + ' шт.' : 'Да') : 'Нет'"></p>
                                        </div>
                                    </div>
                                </template>
                            </div>
                            <!-- Филиалы — список НО, куда сдаются «филиальные» отчёты -->
                            <template x-if="client.has_branches && client.branches && client.branches.length">
                                <div class="rounded-xl border border-purple-200 bg-purple-50/40 p-3">
                                    <div class="flex items-center gap-2 mb-2">
                                        <svg class="w-4 h-4 text-purple-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 6h4" /></svg>
                                        <span class="text-xs font-semibold text-purple-700">Филиалы — отчёты сдаются в несколько НО</span>
                                    </div>
                                    <div class="flex flex-wrap gap-2">
                                        <span x-show="client.tax_office_code" class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium bg-white border border-purple-200 text-purple-700">
                                            <span class="font-semibold" x-text="taxAuthorityLabel(client.tax_office_code)"></span>
                                            <span class="text-purple-400">· основной</span>
                                        </span>
                                        <template x-for="(b, i) in client.branches" :key="i">
                                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-lg text-xs font-medium bg-white border border-purple-200 text-purple-700">
                                                <span class="font-semibold" x-text="taxAuthorityLabel(b.no_code)"></span>
                                            </span>
                                        </template>
                                    </div>
                                </div>
                            </template>
                            <!-- Режимы и особенности — чипы -->
                            <div class="flex flex-wrap gap-2 pt-1">
                                <span :class="client.import_eaeu ? 'bg-teal-50 text-teal-700 border-teal-200' : 'bg-slate-50 text-slate-400 border-slate-200'" class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium border">
                                    <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" :class="client.import_eaeu ? 'bg-teal-500' : 'bg-slate-300'"></span>
                                    Импорт ЕАЭС
                                </span>
                                <span :class="client.import_third_countries ? 'bg-teal-50 text-teal-700 border-teal-200' : 'bg-slate-50 text-slate-400 border-slate-200'" class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium border">
                                    <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" :class="client.import_third_countries ? 'bg-teal-500' : 'bg-slate-300'"></span>
                                    Импорт ГТД
                                </span>
                                <span :class="client.has_export ? 'bg-cyan-50 text-cyan-700 border-cyan-200' : 'bg-slate-50 text-slate-400 border-slate-200'" class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium border">
                                    <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" :class="client.has_export ? 'bg-cyan-500' : 'bg-slate-300'"></span>
                                    Экспорт
                                </span>
                                {{-- ПВТ/ПКИ скрыты: это режимы налогообложения, задаются через выбор РН. Возможно к удалению.
                                <span :class="client.pvt_mode ? 'bg-indigo-50 text-indigo-700 border-indigo-200' : 'bg-slate-50 text-slate-400 border-slate-200'" class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium border">
                                    <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" :class="client.pvt_mode ? 'bg-indigo-500' : 'bg-slate-300'"></span>
                                    Режим ПВТ
                                </span>
                                <span :class="client.pki_mode ? 'bg-purple-50 text-purple-700 border-purple-200' : 'bg-slate-50 text-slate-400 border-slate-200'" class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium border">
                                    <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" :class="client.pki_mode ? 'bg-purple-500' : 'bg-slate-300'"></span>
                                    Режим ПКИ
                                </span>
                                --}}
                                <span :class="client.has_alcohol ? 'bg-rose-50 text-rose-700 border-rose-200' : 'bg-slate-50 text-slate-400 border-slate-200'" class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium border">
                                    <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" :class="client.has_alcohol ? 'bg-rose-500' : 'bg-slate-300'"></span>
                                    Алкоголь / ГосАлко
                                </span>
                                <template x-for="opt in otherFlags" :key="opt.key">
                                    <span :class="client[opt.key] ? 'bg-indigo-50 text-indigo-700 border-indigo-200' : 'bg-slate-50 text-slate-400 border-slate-200'" class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full text-xs font-medium border">
                                        <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" :class="client[opt.key] ? 'bg-indigo-500' : 'bg-slate-300'"></span>
                                        <span x-text="opt.label"></span>
                                    </span>
                                </template>
                            </div>
                            {{-- Оператор ЭДО временно скрыт — пока не нужен. Возможно к удалению (колонка clients.edo_operator).
                            <template x-if="client.edo_operator">
                                <div class="pt-2 border-t border-slate-100">
                                    <span class="text-sm text-slate-500">Оператор ЭДО / ЭСФ: </span>
                                    <span class="text-sm font-medium text-slate-800" x-text="client.edo_operator"></span>
                                </div>
                            </template>
                            --}}
                        </div>
                    </template>

                    <!-- Режим редактирования -->
                    <template x-if="editing.flags">
                        <div class="space-y-5">
                            <!-- Карточки-тогглы: объём -->
                            <div>
                                <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3">Состав бизнеса</p>
                                <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-3">
                                    <!-- Нулевой клиент -->
                                    <div :class="form.flags.is_zero_movement ? 'bg-amber-50 border-amber-200' : 'bg-white border-slate-200'" class="p-3.5 rounded-xl border transition-all duration-150 cursor-pointer select-none" @click="form.flags.is_zero_movement = !form.flags.is_zero_movement">
                                        <div class="flex items-center justify-between gap-2">
                                            <div class="flex items-center gap-2.5">
                                                <div :class="form.flags.is_zero_movement ? 'bg-amber-100 text-amber-500' : 'bg-slate-100 text-slate-400'" class="p-1.5 rounded-lg flex-shrink-0 transition-colors">
                                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" /></svg>
                                                </div>
                                                <span class="text-sm font-medium text-slate-700">Нулевой клиент</span>
                                            </div>
                                            <span :class="form.flags.is_zero_movement ? 'bg-amber-400' : 'bg-slate-200'"
                                                  class="relative inline-flex h-6 w-11 flex-shrink-0 rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out">
                                                <span :class="form.flags.is_zero_movement ? 'translate-x-5' : 'translate-x-0'" class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"></span>
                                            </span>
                                        </div>
                                    </div>
                                    <!-- Сотрудники -->
                                    <div :class="form.flags.has_employees ? 'bg-emerald-50 border-emerald-200' : 'bg-white border-slate-200'" class="p-3.5 rounded-xl border transition-all duration-150">
                                        <div class="flex items-center justify-between gap-2">
                                            <div class="flex items-center gap-2.5">
                                                <div :class="form.flags.has_employees ? 'bg-emerald-100 text-emerald-500' : 'bg-slate-100 text-slate-400'" class="p-1.5 rounded-lg flex-shrink-0 transition-colors">
                                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197M13 7a4 4 0 11-8 0 4 4 0 018 0z" /></svg>
                                                </div>
                                                <span class="text-sm font-medium text-slate-700">Сотрудники</span>
                                            </div>
                                            <button type="button" @click="form.flags.has_employees = !form.flags.has_employees; if(!form.flags.has_employees) form.flags.employees_count = null"
                                                    :class="form.flags.has_employees ? 'bg-emerald-500' : 'bg-slate-200'"
                                                    class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none">
                                                <span :class="form.flags.has_employees ? 'translate-x-5' : 'translate-x-0'" class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"></span>
                                            </button>
                                        </div>
                                        <div x-show="form.flags.has_employees" x-transition class="mt-2.5">
                                            <input type="number" x-model.number="form.flags.employees_count" min="0" max="9999" placeholder="Количество"
                                                   class="block w-full px-2.5 py-1.5 bg-white border border-emerald-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500/20 focus:border-emerald-400 placeholder-slate-400">
                                        </div>
                                    </div>
                                    <!-- ККМ -->
                                    <div :class="form.flags.has_kkm ? 'bg-blue-50 border-blue-200' : 'bg-white border-slate-200'" class="p-3.5 rounded-xl border transition-all duration-150">
                                        <div class="flex items-center justify-between gap-2">
                                            <div class="flex items-center gap-2.5">
                                                <div :class="form.flags.has_kkm ? 'bg-blue-100 text-blue-500' : 'bg-slate-100 text-slate-400'" class="p-1.5 rounded-lg flex-shrink-0 transition-colors">
                                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h18M7 15h1m4 0h1m-7 4h12a3 3 0 003-3V8a3 3 0 00-3-3H6a3 3 0 00-3 3v8a3 3 0 003 3z" /></svg>
                                                </div>
                                                <span class="text-sm font-medium text-slate-700">ККМ / Касса</span>
                                            </div>
                                            <button type="button" @click="form.flags.has_kkm = !form.flags.has_kkm; if(!form.flags.has_kkm) form.flags.kkm_count = null"
                                                    :class="form.flags.has_kkm ? 'bg-blue-500' : 'bg-slate-200'"
                                                    class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none">
                                                <span :class="form.flags.has_kkm ? 'translate-x-5' : 'translate-x-0'" class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"></span>
                                            </button>
                                        </div>
                                        <div x-show="form.flags.has_kkm" x-transition class="mt-2.5">
                                            <input type="number" x-model.number="form.flags.kkm_count" min="0" max="9999" placeholder="Количество"
                                                   class="block w-full px-2.5 py-1.5 bg-white border border-blue-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-blue-500/20 focus:border-blue-400 placeholder-slate-400">
                                        </div>
                                    </div>
                                    <!-- Маркетплейсы -->
                                    <div :class="form.flags.has_marketplaces ? 'bg-violet-50 border-violet-200' : 'bg-white border-slate-200'" class="p-3.5 rounded-xl border transition-all duration-150">
                                        <div class="flex items-center justify-between gap-2">
                                            <div class="flex items-center gap-2.5">
                                                <div :class="form.flags.has_marketplaces ? 'bg-violet-100 text-violet-500' : 'bg-slate-100 text-slate-400'" class="p-1.5 rounded-lg flex-shrink-0 transition-colors">
                                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11V7a4 4 0 00-8 0v4M5 9h14l1 12H4L5 9z" /></svg>
                                                </div>
                                                <span class="text-sm font-medium text-slate-700">Маркетплейсы</span>
                                            </div>
                                            <button type="button" @click="form.flags.has_marketplaces = !form.flags.has_marketplaces; if(!form.flags.has_marketplaces) form.flags.marketplaces_count = null"
                                                    :class="form.flags.has_marketplaces ? 'bg-violet-500' : 'bg-slate-200'"
                                                    class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none">
                                                <span :class="form.flags.has_marketplaces ? 'translate-x-5' : 'translate-x-0'" class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"></span>
                                            </button>
                                        </div>
                                        <div x-show="form.flags.has_marketplaces" x-transition class="mt-2.5">
                                            <input type="number" x-model.number="form.flags.marketplaces_count" min="0" max="9999" placeholder="Количество"
                                                   class="block w-full px-2.5 py-1.5 bg-white border border-violet-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-violet-500/20 focus:border-violet-400 placeholder-slate-400">
                                        </div>
                                    </div>
                                    <!-- Дополнительные характеристики с количеством -->
                                    <template x-for="opt in countFlags" :key="opt.key">
                                        <div :class="form.flags[opt.key] ? ('bg-' + opt.color + '-50 border-' + opt.color + '-200') : 'bg-white border-slate-200'" class="p-3.5 rounded-xl border transition-all duration-150">
                                            <div class="flex items-center justify-between gap-2">
                                                <div class="flex items-center gap-2.5">
                                                    <div :class="form.flags[opt.key] ? ('bg-' + opt.color + '-100 text-' + opt.color + '-500') : 'bg-slate-100 text-slate-400'" class="p-1.5 rounded-lg flex-shrink-0 transition-colors">
                                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5a1.99 1.99 0 011.414.586l7 7a2 2 0 010 2.828l-5 5a2 2 0 01-2.828 0l-7-7A1.99 1.99 0 013 7V4a1 1 0 011-1z" /></svg>
                                                    </div>
                                                    <span class="text-sm font-medium text-slate-700" x-text="opt.label"></span>
                                                </div>
                                                <button type="button" @click="form.flags[opt.key] = !form.flags[opt.key]; if(!form.flags[opt.key]) form.flags[opt.count] = null"
                                                        :class="form.flags[opt.key] ? ('bg-' + opt.color + '-500') : 'bg-slate-200'"
                                                        class="relative inline-flex h-6 w-11 flex-shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none">
                                                    <span :class="form.flags[opt.key] ? 'translate-x-5' : 'translate-x-0'" class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out"></span>
                                                </button>
                                            </div>
                                            <div x-show="form.flags[opt.key]" x-transition class="mt-2.5">
                                                <input type="number" x-model.number="form.flags[opt.count]" min="0" max="9999" placeholder="Количество"
                                                       :class="'block w-full px-2.5 py-1.5 bg-white border border-' + opt.color + '-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-' + opt.color + '-500/20 focus:border-' + opt.color + '-400 placeholder-slate-400'">
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            <!-- Филиалы -->
                            <div>
                                <div class="flex items-center justify-between mb-3">
                                    <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider">Филиалы (сдача отчётов в разные НО)</p>
                                </div>
                                <div class="space-y-2">
                                    <p class="text-xs text-slate-500">
                                        Основной НО: <span class="font-medium text-slate-700" x-text="taxAuthorityLabel(client.tax_office_code)"></span>.
                                        Добавьте НО филиалов — отчёты «по филиалам» (НСП, 161 форма) сдаются в каждый из них.
                                    </p>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <template x-for="(b, i) in form.flags.branches" :key="b.no_code">
                                            <span class="inline-flex items-center gap-1.5 pl-2.5 pr-1.5 py-1 rounded-lg text-xs font-medium bg-white border border-purple-200 text-purple-700">
                                                <span class="font-semibold" x-text="taxAuthorityLabel(b.no_code)"></span>
                                                <button type="button" @click="form.flags.branches.splice(i, 1)" class="text-purple-300 hover:text-red-500 transition-colors">
                                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                                </button>
                                            </span>
                                        </template>
                                        <select @change="addBranch($event.target.value); $event.target.value = ''"
                                                class="w-44 px-2.5 py-1 border-2 border-dashed border-slate-200 rounded-lg text-xs text-slate-500 bg-white hover:border-purple-300 hover:text-purple-600 focus:outline-none focus:ring-2 focus:ring-purple-500/20 focus:border-purple-400 transition-colors">
                                            <option value="">+ Добавить филиал</option>
                                            <template x-for="ta in availableBranchAuthorities()" :key="ta.id">
                                                <option :value="ta.code" x-text="ta.code + ' — ' + ta.name"></option>
                                            </template>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <!-- Режимы и особенности -->
                            <div>
                                <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3">Режимы и особенности</p>
                                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                                    <!-- Импорт ЕАЭС -->
                                    <div :class="form.flags.import_eaeu ? 'bg-teal-50 border-teal-200' : 'bg-white border-slate-200'" class="p-3 rounded-xl border transition-all duration-150 cursor-pointer select-none" @click="form.flags.import_eaeu = !form.flags.import_eaeu">
                                        <div class="flex items-center justify-between mb-1.5">
                                            <div :class="form.flags.import_eaeu ? 'bg-teal-100 text-teal-500' : 'bg-slate-100 text-slate-400'" class="p-1.5 rounded-lg transition-colors">
                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                                            </div>
                                            <div :class="form.flags.import_eaeu ? 'bg-teal-400' : 'bg-slate-200'" class="w-4 h-4 rounded-full flex items-center justify-center flex-shrink-0 transition-colors">
                                                <svg x-show="form.flags.import_eaeu" class="w-2.5 h-2.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                            </div>
                                        </div>
                                        <p :class="form.flags.import_eaeu ? 'text-teal-700' : 'text-slate-500'" class="text-xs font-medium leading-tight">Импорт ЕАЭС</p>
                                    </div>
                                    <!-- Импорт ГТД -->
                                    <div :class="form.flags.import_third_countries ? 'bg-teal-50 border-teal-200' : 'bg-white border-slate-200'" class="p-3 rounded-xl border transition-all duration-150 cursor-pointer select-none" @click="form.flags.import_third_countries = !form.flags.import_third_countries">
                                        <div class="flex items-center justify-between mb-1.5">
                                            <div :class="form.flags.import_third_countries ? 'bg-teal-100 text-teal-500' : 'bg-slate-100 text-slate-400'" class="p-1.5 rounded-lg transition-colors">
                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                                            </div>
                                            <div :class="form.flags.import_third_countries ? 'bg-teal-400' : 'bg-slate-200'" class="w-4 h-4 rounded-full flex items-center justify-center flex-shrink-0 transition-colors">
                                                <svg x-show="form.flags.import_third_countries" class="w-2.5 h-2.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                            </div>
                                        </div>
                                        <p :class="form.flags.import_third_countries ? 'text-teal-700' : 'text-slate-500'" class="text-xs font-medium leading-tight">Импорт ГТД</p>
                                    </div>
                                    <!-- Экспорт -->
                                    <div :class="form.flags.has_export ? 'bg-cyan-50 border-cyan-200' : 'bg-white border-slate-200'" class="p-3 rounded-xl border transition-all duration-150 cursor-pointer select-none" @click="form.flags.has_export = !form.flags.has_export">
                                        <div class="flex items-center justify-between mb-1.5">
                                            <div :class="form.flags.has_export ? 'bg-cyan-100 text-cyan-500' : 'bg-slate-100 text-slate-400'" class="p-1.5 rounded-lg transition-colors">
                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 7.5m0 0L7.5 12M12 7.5V21" /></svg>
                                            </div>
                                            <div :class="form.flags.has_export ? 'bg-cyan-400' : 'bg-slate-200'" class="w-4 h-4 rounded-full flex items-center justify-center flex-shrink-0 transition-colors">
                                                <svg x-show="form.flags.has_export" class="w-2.5 h-2.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                            </div>
                                        </div>
                                        <p :class="form.flags.has_export ? 'text-cyan-700' : 'text-slate-500'" class="text-xs font-medium leading-tight">Экспорт</p>
                                    </div>
                                    {{-- ПВТ/ПКИ скрыты: это режимы налогообложения, задаются через выбор РН выше. Возможно к удалению.
                                    <!-- Режим ПВТ -->
                                    <div :class="form.flags.pvt_mode ? 'bg-indigo-50 border-indigo-200' : 'bg-white border-slate-200'" class="p-3 rounded-xl border transition-all duration-150 cursor-pointer select-none" @click="form.flags.pvt_mode = !form.flags.pvt_mode">
                                        <div class="flex items-center justify-between mb-1.5">
                                            <div :class="form.flags.pvt_mode ? 'bg-indigo-100 text-indigo-500' : 'bg-slate-100 text-slate-400'" class="p-1.5 rounded-lg transition-colors">
                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.428 15.428a2 2 0 00-1.022-.547l-2.387-.477a6 6 0 00-3.86.517l-.318.158a6 6 0 01-3.86.517L6.05 15.21a2 2 0 00-1.806.547M8 4h8l-1 1v5.172a2 2 0 00.586 1.414l5 5c1.26 1.26.367 3.414-1.415 3.414H4.828c-1.782 0-2.674-2.154-1.414-3.414l5-5A2 2 0 009 10.172V5L8 4z" /></svg>
                                            </div>
                                            <div :class="form.flags.pvt_mode ? 'bg-indigo-400' : 'bg-slate-200'" class="w-4 h-4 rounded-full flex items-center justify-center flex-shrink-0 transition-colors">
                                                <svg x-show="form.flags.pvt_mode" class="w-2.5 h-2.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                            </div>
                                        </div>
                                        <p :class="form.flags.pvt_mode ? 'text-indigo-700' : 'text-slate-500'" class="text-xs font-medium leading-tight">Режим ПВТ</p>
                                    </div>
                                    <!-- Режим ПКИ -->
                                    <div :class="form.flags.pki_mode ? 'bg-purple-50 border-purple-200' : 'bg-white border-slate-200'" class="p-3 rounded-xl border transition-all duration-150 cursor-pointer select-none" @click="form.flags.pki_mode = !form.flags.pki_mode">
                                        <div class="flex items-center justify-between mb-1.5">
                                            <div :class="form.flags.pki_mode ? 'bg-purple-100 text-purple-500' : 'bg-slate-100 text-slate-400'" class="p-1.5 rounded-lg transition-colors">
                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" /></svg>
                                            </div>
                                            <div :class="form.flags.pki_mode ? 'bg-purple-400' : 'bg-slate-200'" class="w-4 h-4 rounded-full flex items-center justify-center flex-shrink-0 transition-colors">
                                                <svg x-show="form.flags.pki_mode" class="w-2.5 h-2.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                            </div>
                                        </div>
                                        <p :class="form.flags.pki_mode ? 'text-purple-700' : 'text-slate-500'" class="text-xs font-medium leading-tight">Режим ПКИ</p>
                                    </div>
                                    --}}
                                    <!-- Алкоголь -->
                                    <div :class="form.flags.has_alcohol ? 'bg-rose-50 border-rose-200' : 'bg-white border-slate-200'" class="p-3 rounded-xl border transition-all duration-150 cursor-pointer select-none" @click="form.flags.has_alcohol = !form.flags.has_alcohol">
                                        <div class="flex items-center justify-between mb-1.5">
                                            <div :class="form.flags.has_alcohol ? 'bg-rose-100 text-rose-500' : 'bg-slate-100 text-slate-400'" class="p-1.5 rounded-lg transition-colors">
                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9.75 3.104v5.714a2.25 2.25 0 01-.659 1.591L5 14.5M9.75 3.104c-.251.023-.501.05-.75.082m.75-.082a24.301 24.301 0 014.5 0m0 0v5.714c0 .597.237 1.17.659 1.591L19.8 15m-1.8-.5H6M6.75 21h10.5" /></svg>
                                            </div>
                                            <div :class="form.flags.has_alcohol ? 'bg-rose-400' : 'bg-slate-200'" class="w-4 h-4 rounded-full flex items-center justify-center flex-shrink-0 transition-colors">
                                                <svg x-show="form.flags.has_alcohol" class="w-2.5 h-2.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                            </div>
                                        </div>
                                        <p :class="form.flags.has_alcohol ? 'text-rose-700' : 'text-slate-500'" class="text-xs font-medium leading-tight">Алкоголь</p>
                                    </div>
                                </div>
                            </div>

                            <!-- Прочие условия -->
                            <div>
                                <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-3">Прочие условия</p>
                                <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-6 gap-3">
                                    <template x-for="opt in otherFlags" :key="opt.key">
                                        <div :class="form.flags[opt.key] ? 'bg-indigo-50 border-indigo-200' : 'bg-white border-slate-200'" class="p-3 rounded-xl border transition-all duration-150 cursor-pointer select-none" @click="form.flags[opt.key] = !form.flags[opt.key]">
                                            <div class="flex items-center justify-between mb-1.5">
                                                <div :class="form.flags[opt.key] ? 'bg-indigo-100 text-indigo-500' : 'bg-slate-100 text-slate-400'" class="p-1.5 rounded-lg transition-colors">
                                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5a1.99 1.99 0 011.414.586l7 7a2 2 0 010 2.828l-5 5a2 2 0 01-2.828 0l-7-7A1.99 1.99 0 013 7V4a1 1 0 011-1z" /></svg>
                                                </div>
                                                <div :class="form.flags[opt.key] ? 'bg-indigo-400' : 'bg-slate-200'" class="w-4 h-4 rounded-full flex items-center justify-center flex-shrink-0 transition-colors">
                                                    <svg x-show="form.flags[opt.key]" class="w-2.5 h-2.5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                                </div>
                                            </div>
                                            <p :class="form.flags[opt.key] ? 'text-indigo-700' : 'text-slate-500'" class="text-xs font-medium leading-tight" x-text="opt.label"></p>
                                        </div>
                                    </template>
                                </div>
                            </div>

                            {{-- Оператор ЭДО временно скрыт — пока не нужен. Возможно к удалению (колонка clients.edo_operator).
                            <div class="pt-1 border-t border-slate-100">
                                <label class="block text-sm font-medium text-slate-700 mb-1">Оператор ЭДО / ЭСФ</label>
                                <input type="text" x-model="form.flags.edo_operator"
                                       class="block w-full sm:w-72 px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                                       placeholder="Название оператора">
                            </div>
                            --}}
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
                            <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-x-6 gap-y-4">
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
                                    <dt class="text-sm font-medium text-slate-500">Ответственное лицо</dt>
                                    <dd class="mt-1">
                                        <template x-if="client.responsible_employee">
                                            <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-700" x-text="client.responsible_employee.full_name"></span>
                                        </template>
                                        <template x-if="!client.responsible_employee">
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
                            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-x-6 gap-y-4">
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
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Ответственное лицо</label>
                                    <select x-model="form.contract.responsible_employee_id" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white">
                                        <option value="">— Не назначено —</option>
                                        <template x-for="emp in allEmployees" :key="emp.id">
                                            <option :value="String(emp.id)" x-text="emp.full_name"></option>
                                        </template>
                                    </select>
                                    <p class="mt-1 text-xs text-slate-400">На это лицо ассайнятся задачи по компании</p>
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

            <!-- Документы клиента -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 flex items-center gap-3">
                    <div class="p-2 bg-blue-100 rounded-lg">
                        <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                        </svg>
                    </div>
                    <h2 class="text-lg font-semibold text-slate-800">Документы</h2>
                </div>
                <div class="px-6 py-5 space-y-4">
                    <!-- Список документов -->
                    <template x-if="clientDocuments.length > 0">
                        <div class="space-y-2">
                            <template x-for="doc in clientDocuments" :key="doc.id">
                                <div class="flex items-center gap-3 p-3 bg-slate-50 rounded-xl border border-slate-100 hover:border-slate-200 transition-colors">
                                    <!-- Иконка типа -->
                                    <div :class="docIconColor(doc.mime_type)" class="p-2 rounded-lg flex-shrink-0">
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" x-html="docIcon(doc.mime_type)"></svg>
                                    </div>
                                    <!-- Имя и размер -->
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-slate-800 truncate" x-text="doc.original_name"></p>
                                        <p class="text-xs text-slate-400" x-text="formatFileSize(doc.size)"></p>
                                    </div>
                                    <!-- Кнопки действий -->
                                    <div class="flex items-center gap-1 flex-shrink-0">
                                        <button x-show="canPreview(doc)"
                                                @click="openPreview(doc)" type="button"
                                                class="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-all" title="Просмотр">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                            </svg>
                                        </button>
                                        <a x-show="isSheetDoc(doc)" :href="doc.url + '/sheet/view'" target="_blank"
                                           class="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-all" title="Открыть во вкладке">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                            </svg>
                                        </a>
                                        <a :href="doc.url" target="_blank" download
                                           class="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-all" title="Скачать">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                            </svg>
                                        </a>
                                        <button @click="deleteDocument(doc.id)" type="button"
                                                class="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-all" title="Удалить">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                            </svg>
                                        </button>
                                    </div>
                                </div>
                            </template>
                        </div>
                    </template>
                    <template x-if="clientDocuments.length === 0">
                        <p class="text-sm text-slate-400">Документы не загружены</p>
                    </template>

                    <!-- Зона загрузки -->
                    <div
                        class="relative border-2 border-dashed border-slate-200 rounded-xl p-6 text-center hover:border-indigo-300 hover:bg-indigo-50/30 transition-all duration-150"
                        :class="{'border-indigo-400 bg-indigo-50/40': uploadDragging}"
                        @dragover.prevent="uploadDragging = true"
                        @dragleave.prevent="uploadDragging = false"
                        @drop.prevent="uploadDragging = false; uploadDocuments($event.dataTransfer.files)">
                        <input type="file" multiple id="doc-upload-input"
                               class="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
                               @change="uploadDocuments($event.target.files); $event.target.value = ''">
                        <div class="flex flex-col items-center gap-2">
                            <div class="p-2.5 bg-slate-100 rounded-xl">
                                <svg class="w-6 h-6 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12" />
                                </svg>
                            </div>
                            <template x-if="!uploadingDocs">
                                <div>
                                    <p class="text-sm font-medium text-slate-600">Перетащите файлы или нажмите для выбора</p>
                                    <p class="text-xs text-slate-400 mt-0.5">PDF, DOC, XLS, PNG, JPG, ZIP и другие — до 40 МБ</p>
                                </div>
                            </template>
                            <template x-if="uploadingDocs">
                                <div class="flex items-center gap-2">
                                    <svg class="w-4 h-4 text-indigo-500 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                    <p class="text-sm text-indigo-600">Загружаем...</p>
                                </div>
                            </template>
                        </div>
                    </div>
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
                                <dd class="mt-1">
                                    <template x-if="Array.isArray(client.power_of_attorney_name) && client.power_of_attorney_name.length > 0">
                                        <div class="flex flex-wrap gap-1.5">
                                            <template x-for="poaName in client.power_of_attorney_name" :key="poaName">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-700" x-text="poaName"></span>
                                            </template>
                                        </div>
                                    </template>
                                    <template x-if="!Array.isArray(client.power_of_attorney_name) || client.power_of_attorney_name.length === 0">
                                        <span class="text-sm text-slate-900">—</span>
                                    </template>
                                </dd>
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
                                <div class="flex flex-wrap gap-1.5 mb-2" x-show="form.attorney.power_of_attorney_name.length > 0">
                                    <template x-for="poaName in form.attorney.power_of_attorney_name" :key="poaName">
                                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-700">
                                            <span x-text="poaName"></span>
                                            <button type="button" @click="form.attorney.power_of_attorney_name = form.attorney.power_of_attorney_name.filter(n => n !== poaName)" class="text-indigo-400 hover:text-indigo-700">
                                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                            </button>
                                        </span>
                                    </template>
                                </div>
                                <select @change="if($event.target.value && !form.attorney.power_of_attorney_name.includes($event.target.value)) form.attorney.power_of_attorney_name.push($event.target.value); $event.target.value = ''" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                    <option value="">+ Добавить сотрудника</option>
                                    <template x-for="emp in allEmployees" :key="emp.id">
                                        <option :value="emp.full_name" :disabled="form.attorney.power_of_attorney_name.includes(emp.full_name)" x-text="emp.full_name"></option>
                                    </template>
                                </select>
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

            <!-- Контакты и связанные лица -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="p-2 bg-sky-100 rounded-lg">
                            <svg class="w-5 h-5 text-sky-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z" />
                            </svg>
                        </div>
                        <h2 class="text-lg font-semibold text-slate-800">Контакты и связанные лица</h2>
                    </div>
                    <template x-if="!editing.contacts_info">
                        <button @click="startEdit('contacts_info')" class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-all" title="Редактировать">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                        </button>
                    </template>
                    <template x-if="editing.contacts_info">
                        <div class="flex items-center gap-2">
                            <button @click="saveSection('contacts_info')" :disabled="saving.contacts_info" class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-all">
                                <svg x-show="saving.contacts_info" class="w-4 h-4 mr-1 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                <svg x-show="!saving.contacts_info" class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                                Сохранить
                            </button>
                            <button @click="cancelEdit('contacts_info')" class="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-all">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                            </button>
                        </div>
                    </template>
                </div>
                <div class="px-6 py-5">
                    <!-- Режим просмотра -->
                    <template x-if="!editing.contacts_info">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                            <!-- Контакты -->
                            <div>
                                <h3 class="text-sm font-medium text-slate-700 mb-3">Телефоны / Email / Чат</h3>
                                <template x-if="client.contacts && client.contacts.length > 0">
                                    <div class="space-y-2">
                                        <template x-for="(contact, i) in client.contacts" :key="i">
                                            <div class="flex items-start gap-3">
                                                <span class="inline-flex items-center px-2 py-0.5 rounded text-xs font-medium bg-slate-100 text-slate-600 flex-shrink-0 mt-0.5" x-text="contactTypeLabel(contact.type)"></span>
                                                <div>
                                                    <span class="text-sm text-slate-900 font-mono" x-text="contact.value"></span>
                                                    <span x-show="contact.note" class="ml-2 text-xs text-slate-400" x-text="contact.note"></span>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                                <template x-if="!client.contacts || client.contacts.length === 0">
                                    <p class="text-sm text-slate-400">Не указаны</p>
                                </template>
                            </div>
                            <!-- Связанные лица -->
                            <div>
                                <h3 class="text-sm font-medium text-slate-700 mb-3">Связанные лица</h3>
                                <template x-if="client.related_persons && client.related_persons.length > 0">
                                    <div class="space-y-3">
                                        <template x-for="(person, i) in client.related_persons" :key="i">
                                            <div class="p-3 bg-slate-50 rounded-xl border border-slate-100">
                                                <div class="flex items-center gap-2 mb-1">
                                                    <span class="text-sm font-medium text-slate-800" x-text="person.name"></span>
                                                    <span x-show="person.role" class="text-xs text-slate-500" x-text="'· ' + person.role"></span>
                                                </div>
                                                <div class="flex flex-wrap gap-3">
                                                    <span x-show="person.inn" class="text-xs text-slate-500 font-mono">ИНН: <span x-text="person.inn"></span></span>
                                                    <span x-show="person.note" class="text-xs text-slate-400 italic" x-text="person.note"></span>
                                                </div>
                                            </div>
                                        </template>
                                    </div>
                                </template>
                                <template x-if="!client.related_persons || client.related_persons.length === 0">
                                    <p class="text-sm text-slate-400">Не указаны</p>
                                </template>
                            </div>
                        </div>
                    </template>
                    <!-- Режим редактирования -->
                    <template x-if="editing.contacts_info">
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                            <!-- Контакты -->
                            <div>
                                <h3 class="text-sm font-medium text-slate-700 mb-3">Телефоны / Email / Чат</h3>
                                <div class="space-y-2">
                                    <template x-for="(contact, i) in form.contacts_info.contacts" :key="i">
                                        <div class="flex items-start gap-2">
                                            <select x-model="contact.type" class="px-2 py-1.5 border border-slate-200 rounded-lg text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 flex-shrink-0">
                                                <option value="phone">Тел.</option>
                                                <option value="email">Email</option>
                                                <option value="telegram">TG</option>
                                                <option value="whatsapp">WA</option>
                                                <option value="viber">Viber</option>
                                                <option value="other">Другое</option>
                                            </select>
                                            <input type="text" x-model="contact.value" placeholder="Номер / адрес" class="flex-1 min-w-0 px-2.5 py-1.5 border border-slate-200 rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                            <input type="text" x-model="contact.note" placeholder="Примечание" class="w-24 px-2.5 py-1.5 border border-slate-200 rounded-lg text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                            <button type="button" @click="form.contacts_info.contacts.splice(i, 1)" class="p-1.5 text-slate-400 hover:text-red-500 hover:bg-red-50 rounded-lg transition-colors flex-shrink-0">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                            </button>
                                        </div>
                                    </template>
                                </div>
                                <button type="button" @click="form.contacts_info.contacts.push({type: 'phone', value: '', note: ''})" class="mt-2 w-full py-2 border-2 border-dashed border-slate-200 rounded-lg text-xs text-slate-500 hover:border-indigo-300 hover:text-indigo-600 transition-colors">
                                    + Добавить контакт
                                </button>
                            </div>
                            <!-- Связанные лица -->
                            <div>
                                <h3 class="text-sm font-medium text-slate-700 mb-3">Связанные лица</h3>
                                <div class="space-y-3">
                                    <template x-for="(person, i) in form.contacts_info.related_persons" :key="i">
                                        <div class="p-3 bg-slate-50 rounded-xl border border-slate-200 relative">
                                            <button type="button" @click="form.contacts_info.related_persons.splice(i, 1)" class="absolute top-2 right-2 p-1 text-slate-400 hover:text-red-500 hover:bg-red-50 rounded-lg transition-colors">
                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                            </button>
                                            <div class="grid grid-cols-2 gap-2 pr-6">
                                                <div>
                                                    <label class="block text-xs text-slate-500 mb-1">Имя <span class="text-red-500">*</span></label>
                                                    <input type="text" x-model="person.name" placeholder="ФИО" class="block w-full px-2.5 py-1.5 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                                </div>
                                                <div>
                                                    <label class="block text-xs text-slate-500 mb-1">Роль</label>
                                                    <input type="text" x-model="person.role" placeholder="Директор" class="block w-full px-2.5 py-1.5 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                                </div>
                                                <div>
                                                    <label class="block text-xs text-slate-500 mb-1">ИНН</label>
                                                    <input type="text" x-model="person.inn" maxlength="14" placeholder="ИНН" class="block w-full px-2.5 py-1.5 border border-slate-200 rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                                </div>
                                                <div>
                                                    <label class="block text-xs text-slate-500 mb-1">Примечание</label>
                                                    <input type="text" x-model="person.note" placeholder="Доп. инфо" class="block w-full px-2.5 py-1.5 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                                </div>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                                <button type="button" @click="form.contacts_info.related_persons.push({name: '', role: '', inn: '', note: ''})" class="mt-2 w-full py-2 border-2 border-dashed border-slate-200 rounded-lg text-xs text-slate-500 hover:border-indigo-300 hover:text-indigo-600 transition-colors">
                                    + Добавить связанное лицо
                                </button>
                            </div>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Дополнительно -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <div class="p-2 bg-slate-100 rounded-lg">
                            <svg class="w-5 h-5 text-slate-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 12h.01M12 12h.01M19 12h.01M6 12a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0zm7 0a1 1 0 11-2 0 1 1 0 012 0z" />
                            </svg>
                        </div>
                        <h2 class="text-lg font-semibold text-slate-800">Дополнительно</h2>
                    </div>
                    <template x-if="!editing.extras">
                        <button @click="startEdit('extras')" class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-all" title="Редактировать">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                        </button>
                    </template>
                    <template x-if="editing.extras">
                        <div class="flex items-center gap-2">
                            <button @click="saveSection('extras')" :disabled="saving.extras" class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-all">
                                <svg x-show="saving.extras" class="w-4 h-4 mr-1 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                <svg x-show="!saving.extras" class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                                Сохранить
                            </button>
                            <button @click="cancelEdit('extras')" class="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-all">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                            </button>
                        </div>
                    </template>
                </div>
                <div class="px-6 py-5">
                    <!-- Режим просмотра -->
                    <template x-if="!editing.extras">
                        <div class="space-y-4">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-6">
                                <div x-show="client.client_folder_url">
                                    <dt class="text-sm font-medium text-slate-500 mb-1">Папка клиента</dt>
                                    <dd>
                                        <a :href="client.client_folder_url" target="_blank" class="inline-flex items-center gap-1.5 text-sm text-indigo-600 hover:text-indigo-800 hover:underline">
                                            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z" /></svg>
                                            Открыть папку
                                        </a>
                                    </dd>
                                </div>
                                <div x-show="client.access_instructions">
                                    <dt class="text-sm font-medium text-slate-500 mb-1">Доступы / инструкции</dt>
                                    <dd class="text-sm text-slate-700 whitespace-pre-wrap" x-text="client.access_instructions"></dd>
                                </div>
                            </div>
                            <template x-if="client.extra_fields && client.extra_fields.length > 0">
                                <div class="pt-4 border-t border-slate-100">
                                    <h3 class="text-sm font-medium text-slate-700 mb-3">Дополнительные поля</h3>
                                    <dl class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-x-6 gap-y-3">
                                        <template x-for="(field, i) in client.extra_fields" :key="i">
                                            <div>
                                                <dt class="text-xs font-medium text-slate-500" x-text="field.label"></dt>
                                                <dd class="mt-0.5 text-sm text-slate-900" x-text="field.value || '—'"></dd>
                                            </div>
                                        </template>
                                    </dl>
                                </div>
                            </template>
                            <template x-if="!client.client_folder_url && !client.access_instructions && (!client.extra_fields || client.extra_fields.length === 0)">
                                <p class="text-sm text-slate-400">Не заполнено</p>
                            </template>
                        </div>
                    </template>
                    <!-- Режим редактирования -->
                    <template x-if="editing.extras">
                        <div class="space-y-5">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Папка клиента (ссылка)</label>
                                    <input type="url" x-model="form.extras.client_folder_url" placeholder="https://drive.google.com/..."
                                           class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Доступы / инструкции</label>
                                    <textarea x-model="form.extras.access_instructions" rows="2" placeholder="Логины, пароли, инструкции..."
                                              class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 resize-none"></textarea>
                                </div>
                            </div>
                            <div>
                                <div class="flex items-center justify-between mb-3">
                                    <label class="text-sm font-medium text-slate-700">Дополнительные поля</label>
                                </div>
                                <div class="space-y-2">
                                    <template x-for="(field, i) in form.extras.extra_fields" :key="i">
                                        <div class="flex items-center gap-2">
                                            <input type="text" x-model="field.label" placeholder="Название поля"
                                                   class="w-40 flex-shrink-0 px-2.5 py-1.5 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                            <input type="text" x-model="field.value" placeholder="Значение"
                                                   class="flex-1 min-w-0 px-2.5 py-1.5 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                            <button type="button" @click="form.extras.extra_fields.splice(i, 1)" class="p-1.5 text-slate-400 hover:text-red-500 hover:bg-red-50 rounded-lg transition-colors flex-shrink-0">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                                            </button>
                                        </div>
                                    </template>
                                </div>
                                <button type="button" @click="form.extras.extra_fields.push({label: '', value: ''})" class="mt-2 w-full py-2 border-2 border-dashed border-slate-200 rounded-lg text-xs text-slate-500 hover:border-indigo-300 hover:text-indigo-600 transition-colors">
                                    + Добавить поле
                                </button>
                            </div>
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
                        <p class="text-sm text-slate-700 whitespace-pre-wrap" x-text="client.notes || 'Нет примечаний'"></p>
                    </template>
                    <template x-if="editing.notes">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Примечания</label>
                            <textarea x-model="form.notes.notes" rows="3" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 resize-none"></textarea>
                        </div>
                    </template>
                </div>
            </div>

    </div>

    <!-- ==================== ПРЕДПРОСМОТР ДОКУМЕНТА ==================== -->
    <template x-teleport="body">
        <div x-show="showPreview" x-cloak
             class="fixed inset-0 z-[60] flex items-center justify-center p-4"
             @keydown.escape.window="closePreview()">

            <!-- Backdrop -->
            <div class="absolute inset-0 bg-slate-900/75 backdrop-blur-sm transition-opacity"
                 @click="closePreview()"></div>

            <!-- Modal -->
            <div class="relative w-full max-w-5xl bg-white rounded-2xl shadow-2xl flex flex-col z-10 overflow-hidden"
                 style="height: 90vh; max-height: 90vh;">

                <!-- Шапка -->
                <div class="flex items-center justify-between px-5 py-3.5 border-b border-slate-100 flex-shrink-0 bg-white">
                    <div class="flex items-center gap-3 min-w-0 flex-1">
                        <div :class="docIconColor(previewDoc?.mime_type)" class="p-1.5 rounded-lg flex-shrink-0">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" x-html="docIcon(previewDoc?.mime_type)"></svg>
                        </div>
                        <div class="min-w-0">
                            <p class="text-sm font-semibold text-slate-800 truncate" x-text="previewDoc?.original_name"></p>
                            <p class="text-xs text-slate-400" x-text="formatFileSize(previewDoc?.size ?? 0)"></p>
                        </div>
                    </div>
                    <div class="flex items-center gap-2 flex-shrink-0 ml-4">
                        <a x-show="isSheetDoc(previewDoc)" :href="previewDoc?.url + '/sheet/view'" target="_blank"
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                            Во вкладке
                        </a>
                        <a :href="previewDoc?.url" target="_blank" download
                           class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                            Скачать
                        </a>
                        <button @click="closePreview()" type="button"
                                class="p-1.5 text-slate-400 hover:text-slate-700 hover:bg-slate-100 rounded-lg transition-colors">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                </div>

                <!-- Контент -->
                <div class="flex-1 overflow-hidden bg-slate-100 relative">

                    <!-- PDF -->
                    <template x-if="previewDoc?.mime_type === 'application/pdf'">
                        <iframe :src="previewDoc.url + '?inline=1#toolbar=1&navpanes=0&scrollbar=1'"
                                class="w-full h-full border-0 block"
                                type="application/pdf"></iframe>
                    </template>

                    <!-- Изображения -->
                    <template x-if="previewDoc?.mime_type?.startsWith('image/')">
                        <div class="w-full h-full flex items-center justify-center p-6 overflow-auto">
                            <img :src="previewDoc.url + '?inline=1'"
                                 :alt="previewDoc.original_name"
                                 class="max-w-full max-h-full object-contain rounded-xl shadow-md select-none">
                        </div>
                    </template>

                    <!-- Excel: показать нечем, поэтому таблицу разбирает сервер -->
                    <template x-if="isSheetDoc(previewDoc)">
                        <div class="w-full h-full flex flex-col">
                            @include('partials.sheet-preview', ['state' => 'sheetView'])
                        </div>
                    </template>

                </div>
            </div>
        </div>
    </template>

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

    {{-- ==================== ИСТОРИЯ ЗАДАЧ ====================
         Отдельный Alpine-компонент, а не часть clientShow(): карточка и так большая,
         а история — самостоятельный кусок, который грузится только по раскрытию.
         Без права секции нет в разметке вообще (см. ClientController@show). --}}
    @if($canSeeTaskHistory)
    <div x-data="taskHistory({{ $client->id }})" class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden mt-6">
        <button type="button" @click="toggle()"
                class="w-full px-6 py-5 flex items-center justify-between gap-4 text-left hover:bg-slate-50/70 transition-colors">
            <div class="flex items-center gap-4">
                <div class="p-2.5 bg-emerald-100 rounded-xl">
                    <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01" />
                    </svg>
                </div>
                <div>
                    <h2 class="text-base font-semibold text-slate-800">История задач</h2>
                    <p class="text-sm text-slate-500 mt-0.5">
                        Выполненные задачи по этой компании — что сделано и какие документы приложены
                    </p>
                </div>
            </div>
            <svg class="w-5 h-5 text-slate-400 shrink-0 transition-transform duration-200"
                 :class="open && 'rotate-180'" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 9l-7 7-7-7" />
            </svg>
        </button>

        <div x-show="open" x-transition.opacity class="border-t border-slate-100" style="display:none">
            <div class="px-6 py-4 flex items-center justify-between gap-4 flex-wrap">
                <div class="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5">
                    <template x-for="opt in docFilters" :key="opt.key">
                        <button type="button" @click="setDocs(opt.key)"
                                class="px-3 py-1.5 text-sm font-medium rounded-md transition-colors"
                                :class="docs === opt.key ? 'bg-white shadow-sm text-slate-800' : 'text-slate-500 hover:text-slate-700'"
                                x-text="opt.label"></button>
                    </template>
                </div>
                <p class="text-sm text-slate-500" x-show="!loading && !error && total > 0">
                    Всего: <span class="font-semibold text-slate-700" x-text="total"></span>
                </p>
            </div>

            <div x-show="loading" class="px-6 py-10 text-center text-sm text-slate-400">Загружаем историю…</div>

            <div x-show="error && !loading" class="px-6 pb-6" style="display:none">
                <div class="rounded-xl bg-rose-50 border border-rose-200 px-4 py-3 flex items-center justify-between gap-4">
                    <p class="text-sm text-rose-700" x-text="error"></p>
                    <button type="button" @click="load()"
                            class="px-3 py-1.5 text-sm font-medium text-rose-700 bg-white border border-rose-200 rounded-lg hover:bg-rose-50">
                        Повторить
                    </button>
                </div>
            </div>

            <div x-show="!loading && !error && items.length === 0" class="px-6 py-10 text-center" style="display:none">
                <p class="text-sm text-slate-500" x-text="emptyText()"></p>
            </div>

            <div x-show="!loading && !error && items.length > 0" class="overflow-x-auto" style="display:none">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="text-left text-xs font-medium uppercase tracking-wide text-slate-400 border-b border-slate-100">
                            <th class="px-6 py-3 font-medium">Выполнена</th>
                            <th class="px-4 py-3 font-medium">Задача</th>
                            <th class="px-4 py-3 font-medium">Период</th>
                            <th class="px-4 py-3 font-medium">Исполнитель</th>
                            <th class="px-4 py-3 font-medium whitespace-nowrap">Документы</th>
                            <th class="px-6 py-3 font-medium">Отметки</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template x-for="row in items" :key="row.uid">
                            <tr @click="openTask(row)" tabindex="0" @keydown.enter="openTask(row)"
                                title="Открыть карточку задачи"
                                class="hover:bg-slate-50/70 focus:bg-slate-50 focus:outline-none cursor-pointer transition-colors">
                                <td class="px-6 py-3 whitespace-nowrap text-slate-600" x-text="formatDate(row.completed_at)"></td>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-slate-800" x-text="row.name"></div>
                                    <div class="flex flex-wrap items-center gap-1.5 mt-1">
                                        <span x-show="row.type === 'adhoc'"
                                              class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-md bg-amber-50 text-amber-700 border border-amber-200">
                                            вне сметы
                                        </span>
                                        <span x-show="row.branch_label"
                                              class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-md bg-slate-100 text-slate-600"
                                              x-text="row.branch_label"></span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-slate-500 whitespace-nowrap" x-text="row.reporting_period || '—'"></td>
                                <td class="px-4 py-3 text-slate-600" x-text="row.doer_name || '—'"></td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    <button type="button" x-show="row.documents_count > 0"
                                            @click.stop="openDocsFromRow(row)"
                                            title="Посмотреть документ"
                                            class="inline-flex items-center gap-1 text-slate-700 hover:text-indigo-600 transition-colors">
                                        <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
                                        </svg>
                                        <span x-text="row.documents_count"></span>
                                    </button>
                                    <span x-show="row.documents_count === 0" class="text-slate-300">—</span>
                                </td>
                                <td class="px-6 py-3">
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <span x-show="row.force_closed"
                                              class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-md bg-orange-50 text-orange-700 border border-orange-200">
                                            закрыта принудительно
                                        </span>
                                        <span x-show="row.rework_count > 0"
                                              class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-md bg-rose-50 text-rose-700 border border-rose-200">
                                            доработок: <span class="ml-1" x-text="row.rework_count"></span>
                                        </span>
                                        <span x-show="!row.force_closed && !row.rework_count" class="text-slate-300">—</span>
                                    </div>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <div x-show="!loading && !error && lastPage > 1" class="px-6 py-4 flex items-center justify-between gap-4 border-t border-slate-100" style="display:none">
                <button type="button" @click="goTo(page - 1)" :disabled="page <= 1"
                        class="px-3 py-1.5 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                    Назад
                </button>
                <span class="text-sm text-slate-500">
                    Страница <span class="font-semibold text-slate-700" x-text="page"></span> из <span x-text="lastPage"></span>
                </span>
                <button type="button" @click="goTo(page + 1)" :disabled="page >= lastPage"
                        class="px-3 py-1.5 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                    Вперёд
                </button>
            </div>
        </div>

        {{-- Карточка задачи. Своя, а не модалка задачника: та вварена в его Alpine-компонент
             (таймеры, загрузка документов, права на редактирование) и здесь не нужна — тут только чтение. --}}
        <div x-show="selected || detailLoading || detailError" x-transition.opacity
             class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-slate-900/50"
             @click.self="closeTask()" @keydown.escape.window="closeTask()" style="display:none">
            <div class="bg-white rounded-2xl shadow-xl w-full max-w-2xl max-h-[85vh] flex flex-col overflow-hidden">
                <div class="px-6 py-4 border-b border-slate-100 flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-base font-semibold text-slate-800" x-text="selected?.name || 'Задача'"></h3>
                        <p class="text-sm text-slate-500 mt-0.5" x-show="selected">
                            <span x-text="selected?.reporting_period || 'без отчётного периода'"></span>
                            <span class="text-slate-300 mx-1.5">·</span>
                            выполнена <span x-text="formatDate(selected?.completed_at)"></span>
                        </p>
                    </div>
                    <button type="button" @click="closeTask()" class="p-1.5 rounded-lg text-slate-400 hover:bg-slate-100 hover:text-slate-600 transition-colors">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <div class="px-6 py-5 overflow-y-auto space-y-5">
                    <p x-show="detailLoading" class="text-sm text-slate-400 text-center py-6">Загружаем карточку…</p>

                    <div x-show="detailError && !detailLoading" class="rounded-xl bg-rose-50 border border-rose-200 px-4 py-3" style="display:none">
                        <p class="text-sm text-rose-700" x-text="detailError"></p>
                    </div>

                    <template x-if="selected && !detailLoading">
                        <div class="space-y-5">
                            <div class="flex flex-wrap gap-1.5">
                                <span x-show="selected.type === 'adhoc'"
                                      class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-md bg-amber-50 text-amber-700 border border-amber-200">вне сметы</span>
                                <span x-show="selected.branch_label"
                                      class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-md bg-slate-100 text-slate-600" x-text="selected.branch_label"></span>
                                <span x-show="selected.force_closed"
                                      class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-md bg-orange-50 text-orange-700 border border-orange-200">закрыта принудительно</span>
                                <span x-show="selected.rework_count > 0"
                                      class="inline-flex items-center px-2 py-0.5 text-xs font-medium rounded-md bg-rose-50 text-rose-700 border border-rose-200">
                                    доработок: <span class="ml-1" x-text="selected.rework_count"></span>
                                </span>
                            </div>

                            <dl class="grid grid-cols-2 gap-x-6 gap-y-3 text-sm">
                                <div>
                                    <dt class="text-slate-400 text-xs uppercase tracking-wide">Исполнитель</dt>
                                    <dd class="text-slate-700 mt-0.5" x-text="selected.doer_name || '—'"></dd>
                                </div>
                                <div>
                                    <dt class="text-slate-400 text-xs uppercase tracking-wide">Принял</dt>
                                    <dd class="text-slate-700 mt-0.5" x-text="selected.reviewer_name || 'без проверки'"></dd>
                                </div>
                                <div x-show="selected.due_date">
                                    <dt class="text-slate-400 text-xs uppercase tracking-wide">Срок</dt>
                                    <dd class="text-slate-700 mt-0.5" x-text="formatDate(selected.due_date)"></dd>
                                </div>
                                <div x-show="selected.assigned_by_name">
                                    <dt class="text-slate-400 text-xs uppercase tracking-wide">Поручил</dt>
                                    <dd class="text-slate-700 mt-0.5" x-text="selected.assigned_by_name"></dd>
                                </div>
                            </dl>

                            <template x-for="block in commentBlocks()" :key="block.label">
                                <div>
                                    <p class="text-slate-400 text-xs uppercase tracking-wide" x-text="block.label"></p>
                                    <p class="text-sm text-slate-700 mt-1 whitespace-pre-line" x-text="block.text"></p>
                                </div>
                            </template>

                            <div x-show="selected.children.length > 0">
                                <p class="text-slate-400 text-xs uppercase tracking-wide mb-2">Подпункты</p>
                                <ul class="space-y-1.5">
                                    <template x-for="child in selected.children" :key="child.id">
                                        <li class="flex items-start gap-2 text-sm">
                                            <svg x-show="child.status === 'completed'" class="w-4 h-4 text-emerald-500 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                            </svg>
                                            <svg x-show="child.status !== 'completed'" class="w-4 h-4 text-slate-300 mt-0.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                <circle cx="12" cy="12" r="8" stroke-width="1.5" />
                                            </svg>
                                            <div class="min-w-0">
                                                <span class="text-slate-700" x-text="child.name"></span>
                                                <template x-for="doc in child.documents" :key="doc.id">
                                                    <div class="flex items-center gap-2">
                                                        {{-- Ссылка, а не кнопка: обычный клик открывает окно, Ctrl+клик и
                                                             колёсико — новую вкладку силами самого браузера --}}
                                                        <a x-show="canPreviewDoc(doc)" :href="docTabUrl(doc)" @click="openDocFromLink($event, doc)"
                                                           class="text-xs text-indigo-600 hover:text-indigo-700 hover:underline truncate"
                                                           x-text="doc.name"></a>
                                                        <a x-show="!canPreviewDoc(doc)" :href="doc.url"
                                                           class="text-xs text-indigo-600 hover:text-indigo-700 hover:underline truncate" x-text="doc.name"></a>
                                                        <a x-show="isSheetDoc(doc)" :href="docTabUrl(doc)" target="_blank"
                                                           title="Открыть во вкладке" class="text-slate-300 hover:text-slate-500 shrink-0">
                                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                                            </svg>
                                                        </a>
                                                        <a :href="doc.url" title="Скачать" class="text-slate-300 hover:text-slate-500 shrink-0">
                                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                                            </svg>
                                                        </a>
                                                    </div>
                                                </template>
                                            </div>
                                        </li>
                                    </template>
                                </ul>
                            </div>

                            <div>
                                <p class="text-slate-400 text-xs uppercase tracking-wide mb-2">Документы</p>
                                <ul x-show="selected.documents.length > 0" class="space-y-1.5">
                                    <template x-for="doc in selected.documents" :key="doc.id">
                                        <li class="flex items-center gap-2">
                                            {{-- PDF, картинки и таблицы открываем прямо в окне, остальное браузер скачает.
                                                 Ссылкой, а не кнопкой: Ctrl+клик и колёсико тогда откроют новую вкладку --}}
                                            <a x-show="canPreviewDoc(doc)" :href="docTabUrl(doc)" @click="openDocFromLink($event, doc)"
                                               class="inline-flex items-center gap-2 text-sm text-indigo-600 hover:text-indigo-700 hover:underline min-w-0">
                                                <svg x-show="!isImageDoc(doc) && !isSheetDoc(doc)" class="w-4 h-4 text-red-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                                                </svg>
                                                <svg x-show="isSheetDoc(doc)" class="w-4 h-4 text-emerald-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                                                </svg>
                                                <svg x-show="isImageDoc(doc)" class="w-4 h-4 text-sky-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                                                </svg>
                                                <span class="truncate" x-text="doc.name"></span>
                                            </a>
                                            <a x-show="!canPreviewDoc(doc)" :href="doc.url"
                                               class="inline-flex items-center gap-2 text-sm text-indigo-600 hover:text-indigo-700 hover:underline min-w-0">
                                                <svg class="w-4 h-4 text-slate-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13" />
                                                </svg>
                                                <span class="truncate" x-text="doc.name"></span>
                                            </a>
                                            <a x-show="isSheetDoc(doc)" :href="docTabUrl(doc)" target="_blank" title="Открыть во вкладке"
                                               class="p-1 rounded-md text-slate-300 hover:text-slate-600 hover:bg-slate-100 shrink-0">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                                                </svg>
                                            </a>
                                            <a :href="doc.url" title="Скачать" class="p-1 rounded-md text-slate-300 hover:text-slate-600 hover:bg-slate-100 shrink-0">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                                                </svg>
                                            </a>
                                        </li>
                                    </template>
                                </ul>
                                <p x-show="selected.documents.length === 0" class="text-sm"
                                   :class="selected.requires_document ? 'text-orange-600' : 'text-slate-400'"
                                   x-text="selected.requires_document ? 'Документ по этому БП требуется, но не приложен' : 'Документов нет'"></p>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>

        {{-- Просмотрщик документа: поверх карточки задачи (z выше), чтобы, закрыв его,
             вернуться к деталям. Чем рисуем — зависит от типа: PDF отдаём встроенному
             просмотрщику браузера в iframe, картинку — <img>, а Excel браузер не умеет
             вовсе, поэтому таблицу разбирает сервер и мы рисуем её сами. --}}
        <div x-show="docViewer.show" x-transition.opacity
             class="fixed inset-0 z-[60] flex flex-col bg-black/70 p-3 sm:p-6"
             @click.self="closeDocViewer()" @keydown.escape.window="closeDocViewer()" style="display:none">
            <div class="w-full max-w-5xl mx-auto flex flex-col flex-1 min-h-0 bg-white rounded-2xl shadow-2xl overflow-hidden">
                <div class="flex items-center gap-3 px-4 py-3 border-b border-slate-100 shrink-0">
                    <svg x-show="!docViewer.image && !docViewer.sheet" class="w-5 h-5 text-red-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />
                    </svg>
                    <svg x-show="docViewer.sheet" class="w-5 h-5 text-emerald-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
                    </svg>
                    <svg x-show="docViewer.image" class="w-5 h-5 text-sky-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />
                    </svg>
                    <p class="text-sm font-medium text-slate-700 truncate flex-1" x-text="docViewer.name"></p>
                    <a :href="docViewer.url.replace('?inline=1', '')" title="Скачать"
                       class="shrink-0 p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
                        </svg>
                    </a>
                    <a :href="docViewer.tabUrl" target="_blank" title="Открыть в новой вкладке"
                       class="shrink-0 p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14" />
                        </svg>
                    </a>
                    <button type="button" @click="closeDocViewer()" title="Закрыть"
                            class="shrink-0 p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>
                {{-- Содержимое создаём только когда просмотрщик открыт: иначе браузер тянет файл заранее --}}
                <template x-if="docViewer.show && !docViewer.image && !docViewer.sheet">
                    <iframe :src="docViewer.url" :title="docViewer.name" class="flex-1 w-full min-h-0 border-0"></iframe>
                </template>
                <template x-if="docViewer.show && docViewer.image">
                    <div class="flex-1 min-h-0 overflow-auto bg-slate-100 flex items-center justify-center p-4">
                        <img :src="docViewer.url" :alt="docViewer.name"
                             class="max-w-full max-h-full object-contain rounded-lg shadow-md select-none">
                    </div>
                </template>
                <template x-if="docViewer.show && docViewer.sheet">
                    @include('partials.sheet-preview', ['state' => 'sheetView'])
                </template>
            </div>
        </div>
    </div>
    @endif
</div>

<script>
/**
 * История выполненных задач клиента. Данные не грузятся вместе с карточкой —
 * только при первом раскрытии секции, дальше фильтр и страницы ходят тем же запросом.
 */
function taskHistory(clientId) {
    return {
        clientId,
        open: false,
        loaded: false,
        loading: false,
        error: '',
        items: [],
        page: 1,
        lastPage: 1,
        total: 0,
        docs: 'all',
        docFilters: [
            { key: 'all', label: 'Все' },
            { key: 'with', label: 'С документами' },
            { key: 'without', label: 'Без документов' },
        ],

        // Карточка задачи (попап): грузится по клику, в списке этих данных нет
        selected: null,
        detailLoading: false,
        detailError: '',

        // Просмотр документа прямо в окне: PDF и картинку DocumentController отдаёт
        // с ?inline=1 — дальше их рисует браузер (iframe и <img>). Excel — особый
        // случай: браузеру его не отдать, таблицу разбирает сервер.
        docViewer: { show: false, name: '', url: '', tabUrl: '', image: false, sheet: false },
        sheetView: sheetPreview(),

        toggle() {
            this.open = !this.open;
            if (this.open && !this.loaded) {
                this.load();
            }
        },

        setDocs(key) {
            if (this.docs === key || this.loading) return;
            this.docs = key;
            this.page = 1;
            this.load();
        },

        goTo(page) {
            if (page < 1 || page > this.lastPage || this.loading) return;
            this.page = page;
            this.load();
        },

        async load() {
            this.loading = true;
            this.error = '';

            try {
                const params = new URLSearchParams({ docs: this.docs, page: this.page });
                const response = await fetch(`/clients/${this.clientId}/task-history?${params}`, {
                    headers: { 'Accept': 'application/json' },
                });

                if (!response.ok) {
                    throw new Error('Не удалось загрузить историю задач');
                }

                const data = await response.json();
                this.items = data.items;
                this.page = data.page;
                this.lastPage = data.last_page;
                this.total = data.total;
                this.loaded = true;
            } catch (e) {
                this.error = e.message || 'Не удалось загрузить историю задач';
            } finally {
                this.loading = false;
            }
        },

        // Пустой список значит разное в зависимости от фильтра — подсказываем, что именно.
        emptyText() {
            if (this.docs === 'with') return 'Нет выполненных задач с приложенными документами';
            if (this.docs === 'without') return 'У всех выполненных задач есть документы';
            return 'По этой компании ещё нет выполненных задач';
        },

        async openTask(row) {
            this.selected = null;
            this.detailError = '';
            this.detailLoading = true;

            try {
                const response = await fetch(`/clients/${this.clientId}/task-history/${row.type}/${row.id}`, {
                    headers: { 'Accept': 'application/json' },
                });

                if (!response.ok) {
                    throw new Error('Не удалось открыть карточку задачи');
                }

                this.selected = await response.json();
            } catch (e) {
                this.detailError = e.message || 'Не удалось открыть карточку задачи';
            } finally {
                this.detailLoading = false;
            }
        },

        closeTask() {
            // Просмотрщик поверх карточки: пока он открыт, Escape и клик по фону
            // должны закрывать сначала его, а не всю карточку.
            if (this.docViewer.show) return;

            this.selected = null;
            this.detailError = '';
            this.detailLoading = false;
        },

        /**
         * В окне показываем PDF и растровые картинки (их рисует сам браузер) и
         * таблицы Excel (их разбирает сервер). Остальное — ссылкой на скачивание;
         * SVG исключён намеренно: это XML, внутри может быть скрипт. Тип определяем
         * по расширению — с сервера приходят только id, имя и ссылка. Контроллер
         * всё равно перепроверяет реальный mime и отдаёт inline только безопасное.
         */
        isImageDoc(doc) {
            return /\.(jpe?g|png|gif|webp|bmp)$/i.test(doc?.name || '');
        },

        isSheetDoc(doc) {
            return /\.(xlsx?|xlsm|ods)$/i.test(doc?.name || '');
        },

        canPreviewDoc(doc) {
            return /\.pdf$/i.test(doc?.name || '') || this.isImageDoc(doc) || this.isSheetDoc(doc);
        },

        /**
         * Куда ведёт «открыть во вкладке». PDF и картинку рисует сам браузер, а
         * таблице нужна наша страница: .xlsx во вкладке он просто скачает.
         */
        docTabUrl(doc) {
            return this.isSheetDoc(doc) ? doc.url + '/sheet/view' : doc.url + '?inline=1';
        },

        /**
         * Клик по имени документа. Ctrl/Cmd/Shift и средняя кнопка не перехватываем —
         * пусть браузер откроет вкладку сам: ради этого имя и сделано ссылкой.
         */
        openDocFromLink(event, doc) {
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) return;

            event.preventDefault();
            this.openDocViewer(doc);
        },

        openDocViewer(doc) {
            this.docViewer = {
                show: true,
                name: doc.name,
                url: doc.url + '?inline=1',
                tabUrl: this.docTabUrl(doc),
                image: this.isImageDoc(doc),
                sheet: this.isSheetDoc(doc),
            };

            if (this.docViewer.sheet) {
                this.sheetView.load(doc);
            }
        },

        closeDocViewer() {
            this.docViewer = { show: false, name: '', url: '', tabUrl: '', image: false, sheet: false };
            this.sheetView.reset();
        },

        /**
         * Клик по скрепке в строке списка. Один просматриваемый файл — открываем
         * сразу, ради этого скрепка и кликабельная. Иначе показываем карточку задачи:
         * там видны имена файлов и можно выбрать нужный.
         */
        async openDocsFromRow(row) {
            if (row.documents_count === 0) return;

            await this.openTask(row);

            const docs = this.selected?.documents ?? [];
            if (docs.length === 1 && this.canPreviewDoc(docs[0])) {
                this.openDocViewer(docs[0]);
            }
        },

        // Комментарии показываем только заполненные — пустые заголовки в карточке лишние.
        commentBlocks() {
            if (!this.selected) return [];

            return [
                { label: 'Описание', text: this.selected.description },
                { label: 'Пояснение', text: this.selected.comment },
                { label: 'Комментарий исполнителя', text: this.selected.employee_comment },
                { label: 'Комментарий проверяющего', text: this.selected.review_comment },
                { label: 'Причина принудительного закрытия', text: this.selected.force_close_comment },
            ].filter(block => block.text);
        },

        formatDate(value) {
            if (!value) return '—';
            const date = new Date(value.replace(' ', 'T'));
            if (isNaN(date)) return '—';

            return date.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' });
        },
    };
}
function clientShow() {
    return {
        client: @json($client),
        taxSystems: @json(\App\Models\TaxSystem::active()->ordered()->get()),
        activityTypes: @json(\App\Models\ActivityType::active()->ordered()->get()),
        tariffs: @json(\App\Models\Tariff::active()->ordered()->get()),
        allEmployees: @json(\App\Models\Employee::active()->orderBy('full_name')->get()),
        serviceTypes: @json(\App\Models\Client::$serviceTypes),
        accountingMethods: @json(\App\Models\Client::$accountingMethods),
        taxpayerCategoriesLegacy: @json(\App\Models\Client::$taxpayerCategories),
        taxpayerCategories: @json(\App\Models\TaxpayerCategory::orderBy('name')->get()),
        connectionTypes: @json(\App\Models\Client::$connectionTypes),
        organizationForms: @json(\App\Models\OrganizationForm::orderBy('name')->get()),
        clientStatuses: @json(\App\Models\ClientStatus::orderBy('sort_order')->orderBy('name')->get()),
        taxAuthorities: @json(\App\Models\TaxAuthority::orderBy('code')->get()),
        clientDocuments: @json($client->documents ?? []),

        // Характеристики бизнеса с количеством (карточка-тоггл + поле числа)
        countFlags: [
            { key: 'has_fixed_assets', count: 'fixed_assets_count', label: 'ОС', color: 'blue' },
            { key: 'has_fuel', count: 'fuel_count', label: 'Учёт ГСМ', color: 'yellow' },
            { key: 'has_loans', count: 'loans_count', label: 'Кредиты / депозиты', color: 'green' },
        ],
        // Прочие условия — переключатели (чип в просмотре, карточка в редактировании)
        otherFlags: [
            { key: 'has_insurance_policy', label: 'ИП страховой полис' },
            { key: 'has_mbt', label: 'МБТ' },
            { key: 'has_crypto_exchange', label: 'Криптообменник' },
            { key: 'has_payment_aggregators', label: 'Платёжные агрегаторы' },
            { key: 'has_production', label: 'Производство' },
            { key: 'has_management_report', label: 'Управленческий отчёт' },
            { key: 'has_excise', label: 'Акциз / ЭТТН' },
            { key: 'has_nonresident_services', label: 'Нерезиденты ДИО / эл.услуги' },
            { key: 'has_property', label: 'Имущество / транспорт / земля' },
            { key: 'has_bank_client', label: 'Банк-клиент (платёжки)' },
            { key: 'has_separate_books', label: 'Раздельные базы УУ/ТК/НУ/УТ' },
            { key: 'has_nonstandard_contracts', label: 'Нестандартные договоры' },
            { key: 'has_foreign_trade', label: 'Внешнеторговая деятельность' },
            { key: 'has_vat_refund', label: 'Возмещение НДС' },
            { key: 'has_special_reporting', label: 'Спец. отчётность' },
            { key: 'has_currency_operations', label: 'Валютные операции (услуги)' },
        ],

        showPasswords: false,
        uploadDragging: false,
        uploadingDocs: false,
        showPreview: false,
        sheetView: sheetPreview(),
        previewDoc: null,

        editing: {
            basic: false,
            status: false,
            tax: false,
            flags: false,
            contract: false,
            attorney: false,
            eds: false,
            its: false,
            banks: false,
            contacts_info: false,
            extras: false,
            notes: false,
        },

        saving: {
            basic: false,
            status: false,
            tax: false,
            flags: false,
            contract: false,
            attorney: false,
            eds: false,
            its: false,
            banks: false,
            contacts_info: false,
            extras: false,
            notes: false,
        },

        form: {
            basic: {},
            status: {},
            tax: {},
            flags: {},
            contract: {},
            attorney: {},
            eds: {},
            its: {},
            banks: {},
            contacts_info: {},
            extras: {},
            notes: {},
        },

        init() {
            this.resetForms();
        },

        resetForms() {
            this.form.basic = {
                name: this.client.name,
                organization_form_id: this.client.organization_form_id ? String(this.client.organization_form_id) : '',
                inn: this.client.inn,
                director_inn: this.client.director_inn,
                tax_office_code: this.client.tax_office_code,
                activity_type_id: this.client.activity_type_id ? String(this.client.activity_type_id) : '',
            };
            this.form.status = {
                client_status_id: this.client.client_status_id ? String(this.client.client_status_id) : '',
                service_start_date: this.client.service_start_date
                    ? this.client.service_start_date.split('T')[0]
                    : (this.client.created_at ? this.client.created_at.split('T')[0] : ''),
                service_end_date: this.client.service_end_date ? this.client.service_end_date.split('T')[0] : '',
            };
            this.form.flags = {
                is_zero_movement: this.client.is_zero_movement || false,
                has_employees: this.client.has_employees || false,
                employees_count: this.client.employees_count ?? null,
                has_kkm: this.client.has_kkm || false,
                kkm_count: this.client.kkm_count ?? null,
                has_marketplaces: this.client.has_marketplaces || false,
                marketplaces_count: this.client.marketplaces_count ?? null,
                import_eaeu: this.client.import_eaeu || false,
                import_third_countries: this.client.import_third_countries || false,
                has_export: this.client.has_export || false,
                pvt_mode: this.client.pvt_mode || false,
                pki_mode: this.client.pki_mode || false,
                has_alcohol: this.client.has_alcohol || false,
                has_insurance_policy: this.client.has_insurance_policy || false,
                has_mbt: this.client.has_mbt || false,
                has_crypto_exchange: this.client.has_crypto_exchange || false,
                has_payment_aggregators: this.client.has_payment_aggregators || false,
                has_production: this.client.has_production || false,
                has_management_report: this.client.has_management_report || false,
                has_fixed_assets: this.client.has_fixed_assets || false,
                fixed_assets_count: this.client.fixed_assets_count ?? null,
                has_fuel: this.client.has_fuel || false,
                fuel_count: this.client.fuel_count ?? null,
                has_loans: this.client.has_loans || false,
                loans_count: this.client.loans_count ?? null,
                has_branches: this.client.has_branches || false,
                branches: JSON.parse(JSON.stringify(this.client.branches || [])),
                has_excise: this.client.has_excise || false,
                has_nonresident_services: this.client.has_nonresident_services || false,
                has_property: this.client.has_property || false,
                has_bank_client: this.client.has_bank_client || false,
                has_separate_books: this.client.has_separate_books || false,
                has_nonstandard_contracts: this.client.has_nonstandard_contracts || false,
                has_foreign_trade: this.client.has_foreign_trade || false,
                has_vat_refund: this.client.has_vat_refund || false,
                has_special_reporting: this.client.has_special_reporting || false,
                has_currency_operations: this.client.has_currency_operations || false,
                edo_operator: this.client.edo_operator || '',
            };
            this.form.contacts_info = {
                contacts: JSON.parse(JSON.stringify(this.client.contacts || [])),
                related_persons: JSON.parse(JSON.stringify(this.client.related_persons || [])),
            };
            this.form.extras = {
                client_folder_url: this.client.client_folder_url || '',
                access_instructions: this.client.access_instructions || '',
                extra_fields: JSON.parse(JSON.stringify(this.client.extra_fields || [])),
            };
            this.form.tax = {
                tax_system_id: this.client.tax_system_id ? String(this.client.tax_system_id) : '',
                accounting_method: this.client.accounting_method || '',
                taxpayer_category: this.client.taxpayer_category || '',
                taxpayer_category_id: this.client.taxpayer_category_id ? String(this.client.taxpayer_category_id) : '',
            };
            this.form.contract = {
                service_type: this.client.service_type || '',
                tariff_id: this.client.tariff_id ? String(this.client.tariff_id) : '',
                contract_with: this.client.contract_with,
                contract_url: this.client.contract_url,
                requisites_url: this.client.requisites_url,
                responsible_employee_id: this.client.responsible_employee_id ? String(this.client.responsible_employee_id) : '',
            };
            this.form.attorney = {
                power_of_attorney_name: Array.isArray(this.client.power_of_attorney_name)
                    ? [...this.client.power_of_attorney_name]
                    : (this.client.power_of_attorney_name ? [this.client.power_of_attorney_name] : []),
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

            // Отбрасываем пустые строки филиалов (без кода НО), чтобы не падала валидация
            const payload = { section, ...this.form[section] };
            if (section === 'flags' && Array.isArray(payload.branches)) {
                payload.branches = payload.branches.filter(b => b.no_code && String(b.no_code).trim());
                payload.has_branches = payload.branches.length > 0;
            }

            try {
                const response = await fetch(`/clients/${this.client.id}`, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(payload),
                });

                const data = await response.json();

                if (data.success) {
                    this.client = data.client;
                    if (data.client.documents) {
                        this.clientDocuments = data.client.documents;
                    }
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

        taxAuthorityLabel(code) {
            if (!code) return '—';
            const ta = this.taxAuthorities.find(t => t.code === code);
            return ta ? ta.code + ' — ' + ta.name : code;
        },

        // НО, доступные для добавления как филиал: без уже выбранных и без основного НО
        availableBranchAuthorities() {
            const taken = this.form.flags.branches.map(b => b.no_code);
            return this.taxAuthorities.filter(t => t.code !== this.client.tax_office_code && !taken.includes(t.code));
        },

        addBranch(code) {
            if (!code) return;
            if (this.form.flags.branches.some(b => b.no_code === code)) return;
            const ta = this.taxAuthorities.find(t => t.code === code);
            this.form.flags.branches.push({ no_code: code, city: ta ? ta.name : '' });
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

        /**
         * Excel определяем по расширению, а не по mime: браузеры присылают .xlsx то
         * как таблицу, то как zip, и у старых загрузок в базе лежит что попало.
         */
        isSheetDoc(doc) {
            return /\.(xlsx?|xlsm|ods)$/i.test(doc?.original_name || doc?.name || '');
        },

        canPreview(doc) {
            const mimeType = doc?.mime_type;

            return this.isSheetDoc(doc)
                || mimeType === 'application/pdf'
                || !!mimeType?.startsWith('image/');
        },

        openPreview(doc) {
            this.previewDoc = doc;
            this.showPreview = true;

            if (this.isSheetDoc(doc)) {
                this.sheetView.load(doc);
            }
        },

        closePreview() {
            this.showPreview = false;
            this.previewDoc = null;
            this.sheetView.reset();
        },

        contactTypeLabel(type) {
            const labels = {
                phone: 'Телефон', email: 'Email', telegram: 'Telegram',
                whatsapp: 'WhatsApp', viber: 'Viber', other: 'Другое',
            };
            return labels[type] || type;
        },

        statusBadgeClass(color) {
            const map = {
                emerald: 'bg-emerald-100 text-emerald-700',
                red: 'bg-red-100 text-red-700',
                amber: 'bg-amber-100 text-amber-700',
                blue: 'bg-blue-100 text-blue-700',
                violet: 'bg-violet-100 text-violet-700',
                indigo: 'bg-indigo-100 text-indigo-700',
                teal: 'bg-teal-100 text-teal-700',
                rose: 'bg-rose-100 text-rose-700',
                orange: 'bg-orange-100 text-orange-700',
                cyan: 'bg-cyan-100 text-cyan-700',
                slate: 'bg-slate-100 text-slate-600',
            };
            return map[color] || 'bg-slate-100 text-slate-600';
        },

        async uploadDocuments(files) {
            if (!files || files.length === 0) return;

            // Проверка размера до отправки — чтобы не ждать заведомо отклонённую загрузку
            const MAX_BYTES = 40 * 1024 * 1024; // 40 МБ
            const tooBig = [...files].filter(f => f.size > MAX_BYTES);
            if (tooBig.length > 0) {
                const names = tooBig.map(f => `«${f.name}» — ${this.formatFileSize(f.size)}`).join('\n');
                alert(`Файл слишком большой (максимум 40 МБ):\n${names}`);
                return;
            }

            this.uploadingDocs = true;
            const formData = new FormData();
            for (const file of files) {
                formData.append('files[]', file);
            }
            formData.append('_token', document.querySelector('meta[name="csrf-token"]').content);
            try {
                const response = await fetch(`/clients/${this.client.id}/documents`, {
                    method: 'POST',
                    headers: { 'Accept': 'application/json' },
                    body: formData,
                });

                if (!response.ok) {
                    let msg = 'Не удалось загрузить файлы.';
                    if (response.status === 413) {
                        msg = 'Файлы слишком большие — суммарный размер превышает лимит сервера.';
                    } else {
                        try {
                            const err = await response.json();
                            if (err.errors) {
                                msg = Object.values(err.errors).flat().join('\n');
                            } else if (err.message) {
                                msg = err.message;
                            }
                        } catch (_) { /* тело не JSON (напр. при превышении post_max_size) */ }
                    }
                    alert(msg);
                    return;
                }

                const data = await response.json();
                if (data.success) {
                    this.clientDocuments = data.documents;
                } else {
                    alert(data.error || 'Ошибка загрузки');
                }
            } catch (e) {
                console.error(e);
                alert('Не удалось загрузить файлы. Вероятная причина — размер превышает лимит сервера. Обратитесь к администратору, чтобы поднять лимит загрузки.');
            } finally {
                this.uploadingDocs = false;
            }
        },

        async deleteDocument(docId) {
            if (!confirm('Удалить документ?')) return;
            try {
                const response = await fetch(`/clients/${this.client.id}/documents/${docId}`, {
                    method: 'DELETE',
                    headers: {
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                    },
                });
                const data = await response.json();
                if (data.success) {
                    this.clientDocuments = this.clientDocuments.filter(d => d.id !== docId);
                }
            } catch (e) {
                console.error(e);
            }
        },

        formatFileSize(bytes) {
            if (!bytes) return '0 Б';
            const k = 1024;
            const sizes = ['Б', 'КБ', 'МБ', 'ГБ'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
        },

        docIcon(mimeType) {
            if (!mimeType) return '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />';
            if (mimeType === 'application/pdf') return '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />';
            if (mimeType.startsWith('image/')) return '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16l4.586-4.586a2 2 0 012.828 0L16 16m-2-2l1.586-1.586a2 2 0 012.828 0L20 14m-6-6h.01M6 20h12a2 2 0 002-2V6a2 2 0 00-2-2H6a2 2 0 00-2 2v12a2 2 0 002 2z" />';
            if (mimeType.includes('spreadsheet') || mimeType.includes('excel') || mimeType.includes('csv')) return '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />';
            if (mimeType.includes('word') || mimeType.includes('document')) return '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />';
            if (mimeType.includes('zip') || mimeType.includes('rar') || mimeType.includes('archive')) return '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M5 8h14M5 8a2 2 0 110-4h14a2 2 0 110 4M5 8v10a2 2 0 002 2h10a2 2 0 002-2V8m-9 4h4" />';
            return '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z" />';
        },

        docIconColor(mimeType) {
            if (!mimeType) return 'bg-slate-100 text-slate-500';
            if (mimeType === 'application/pdf') return 'bg-red-100 text-red-500';
            if (mimeType.startsWith('image/')) return 'bg-violet-100 text-violet-500';
            if (mimeType.includes('spreadsheet') || mimeType.includes('excel') || mimeType.includes('csv')) return 'bg-green-100 text-green-600';
            if (mimeType.includes('word') || mimeType.includes('document')) return 'bg-blue-100 text-blue-500';
            if (mimeType.includes('zip') || mimeType.includes('rar') || mimeType.includes('archive')) return 'bg-amber-100 text-amber-600';
            return 'bg-slate-100 text-slate-500';
        },
    };
}
</script>
@endsection
