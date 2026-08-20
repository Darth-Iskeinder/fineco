@extends('layouts.app')

@section('title', $employee->full_name . ' - Kubik')
@section('page-title', $employee->full_name)

@section('content')
<div x-data="employeeShow()" x-init="init()">

    <!-- Шапка -->
    <div class="sm:flex sm:items-center sm:justify-between mb-8">
        <div class="flex items-center gap-4">
            <a href="{{ route('employees.index') }}" class="p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-all duration-150">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 19l-7-7m0 0l7-7m-7 7h18" />
                </svg>
            </a>
            <div>
                <div class="flex items-center gap-3">
                    <h1 class="text-2xl font-bold text-slate-800" x-text="employee.full_name"></h1>
                    <template x-if="employee.employment_status === 'employed'">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-emerald-100 text-emerald-700">Активный</span>
                    </template>
                    <template x-if="employee.employment_status === 'fired'">
                        <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-red-100 text-red-700">Уволен</span>
                    </template>
                </div>
                <p class="text-slate-500 mt-1" x-text="employee.role_name || '—'"></p>
            </div>
        </div>
        <div class="mt-4 sm:mt-0">
            <button @click="showDeleteModal = true"
                    type="button"
                    class="inline-flex items-center px-4 py-2 border border-red-200 rounded-xl text-sm font-medium text-red-600 bg-white hover:bg-red-50 hover:border-red-300 transition-all duration-200">
                <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                </svg>
                Удалить
            </button>
        </div>
    </div>

    <div class="space-y-6">

        <!-- Основная информация -->
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-indigo-100 rounded-lg">
                        <svg class="w-5 h-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z" />
                        </svg>
                    </div>
                    <h2 class="text-lg font-semibold text-slate-800">Основная информация</h2>
                </div>
                <template x-if="!editing.info">
                    <button @click="startEdit('info')" class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-all" title="Редактировать">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                    </button>
                </template>
                <template x-if="editing.info">
                    <div class="flex items-center gap-2">
                        <button @click="saveSection('info')" :disabled="saving.info"
                                class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-all">
                            <svg x-show="saving.info" class="w-4 h-4 mr-1 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            <svg x-show="!saving.info" class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                            Сохранить
                        </button>
                        <button @click="cancelEdit('info')" class="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-all">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                </template>
            </div>
            <div class="px-6 py-5">
                {{-- Что ответил сервер, если сохранение не прошло: без этого
                     раздел просто оставался открытым, будто кнопка залипла. --}}
                <template x-if="errors.info.length">
                    <div class="mb-4 px-4 py-3 rounded-xl bg-red-50 border border-red-200">
                        <template x-for="message in errors.info" :key="message">
                            <p class="text-sm text-red-700" x-text="message"></p>
                        </template>
                    </div>
                </template>
                <template x-if="!editing.info">
                    <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-4">
                        <div>
                            <dt class="text-sm font-medium text-slate-500">Номер сотрудника</dt>
                            <dd class="mt-1 text-sm text-slate-900 font-mono" x-text="employee.employee_number || '—'"></dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-slate-500">ФИО</dt>
                            <dd class="mt-1 text-sm text-slate-900" x-text="employee.full_name || '—'"></dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-slate-500">Роль</dt>
                            <dd class="mt-1 text-sm text-slate-900" x-text="employee.role_name || '—'"></dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-slate-500">Email</dt>
                            <dd class="mt-1 text-sm text-slate-900" x-text="employee.email || '—'"></dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-slate-500">Телефон</dt>
                            <dd class="mt-1 text-sm text-slate-900" x-text="employee.phone || '—'"></dd>
                        </div>
                    </dl>
                </template>
                <template x-if="editing.info">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-x-6 gap-y-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Номер сотрудника</label>
                            <input type="text" x-model="form.info.employee_number" placeholder="001"
                                   class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">ФИО <span class="text-red-500">*</span></label>
                            <input type="text" x-model="form.info.full_name" required
                                   class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Роль <span class="text-red-500">*</span></label>
                            <select x-model="form.info.role_id" required
                                    class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white">
                                <template x-for="role in roles" :key="role.id">
                                    <option :value="String(role.id)" x-text="role.display_name"></option>
                                </template>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Email <span class="text-red-500">*</span></label>
                            <input type="email" x-model="form.info.email" required
                                   class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Телефон</label>
                            <x-phone-field model="form.info.phone" id="show_phone"
                                           class="block w-full pl-[100px] pr-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500" />
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- Персональные данные -->
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-violet-100 rounded-lg">
                        <svg class="w-5 h-5 text-violet-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z" />
                        </svg>
                    </div>
                    <h2 class="text-lg font-semibold text-slate-800">Персональные данные</h2>
                </div>
                <template x-if="!editing.personal">
                    <button @click="startEdit('personal')" class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-all" title="Редактировать">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                    </button>
                </template>
                <template x-if="editing.personal">
                    <div class="flex items-center gap-2">
                        <button @click="saveSection('personal')" :disabled="saving.personal"
                                class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-all">
                            <svg x-show="saving.personal" class="w-4 h-4 mr-1 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            <svg x-show="!saving.personal" class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                            Сохранить
                        </button>
                        <button @click="cancelEdit('personal')" class="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-all">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                </template>
            </div>
            <div class="px-6 py-5">
                {{-- Что ответил сервер, если сохранение не прошло: без этого
                     раздел просто оставался открытым, будто кнопка залипла. --}}
                <template x-if="errors.personal.length">
                    <div class="mb-4 px-4 py-3 rounded-xl bg-red-50 border border-red-200">
                        <template x-for="message in errors.personal" :key="message">
                            <p class="text-sm text-red-700" x-text="message"></p>
                        </template>
                    </div>
                </template>
                <template x-if="!editing.personal">
                    <dl class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-x-6 gap-y-4">
                        <div>
                            <dt class="text-sm font-medium text-slate-500">Дата рождения</dt>
                            <dd class="mt-1 text-sm text-slate-900" x-text="formatDate(employee.birth_date)"></dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-slate-500">Дата принятия на работу</dt>
                            <dd class="mt-1 text-sm text-slate-900" x-text="formatDate(employee.hired_at)"></dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-slate-500">Дата увольнения</dt>
                            <dd class="mt-1 text-sm text-slate-900" x-text="formatDate(employee.fired_at)"></dd>
                        </div>
                        <div>
                            <dt class="text-sm font-medium text-slate-500">Статус занятости</dt>
                            <dd class="mt-1">
                                <template x-if="employee.employment_status === 'employed'">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-emerald-100 text-emerald-700">Активный</span>
                                </template>
                                <template x-if="employee.employment_status === 'fired'">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-red-100 text-red-700">Уволен</span>
                                </template>
                            </dd>
                        </div>
                    </dl>
                </template>
                <template x-if="editing.personal">
                    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-x-6 gap-y-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Дата рождения</label>
                            <input type="date" x-model="form.personal.birth_date"
                                   class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Дата принятия на работу</label>
                            <input type="date" x-model="form.personal.hired_at"
                                   class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Дата увольнения</label>
                            <input type="date" x-model="form.personal.fired_at"
                                   class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1">Статус занятости <span class="text-red-500">*</span></label>
                            <select x-model="form.personal.employment_status"
                                    class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                <option value="employed">Активный</option>
                                <option value="fired">Уволен</option>
                            </select>
                        </div>
                    </div>
                </template>
            </div>
        </div>

        <!-- Доступ -->
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="p-2 bg-amber-100 rounded-lg">
                        <svg class="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                        </svg>
                    </div>
                    <h2 class="text-lg font-semibold text-slate-800">Доступ</h2>
                </div>
                <template x-if="!editing.access">
                    <button @click="startEdit('access')" class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-all" title="Редактировать">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                        </svg>
                    </button>
                </template>
                <template x-if="editing.access">
                    <div class="flex items-center gap-2">
                        <button @click="saveSection('access')" :disabled="saving.access"
                                class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-all">
                            <svg x-show="saving.access" class="w-4 h-4 mr-1 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            <svg x-show="!saving.access" class="w-4 h-4 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" /></svg>
                            Сохранить
                        </button>
                        <button @click="cancelEdit('access')" class="p-1.5 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-all">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                </template>
            </div>
            <div class="px-6 py-5">
                {{-- Что ответил сервер, если сохранение не прошло: без этого
                     раздел просто оставался открытым, будто кнопка залипла. --}}
                <template x-if="errors.access.length">
                    <div class="mb-4 px-4 py-3 rounded-xl bg-red-50 border border-red-200">
                        <template x-for="message in errors.access" :key="message">
                            <p class="text-sm text-red-700" x-text="message"></p>
                        </template>
                    </div>
                </template>
                <template x-if="!editing.access">
                    <dl class="space-y-4">
                        <div>
                            <dt class="text-sm font-medium text-slate-500 mb-2">Модули</dt>
                            <dd>
                                <template x-if="employee.role_id == adminRoleId">
                                    <span class="text-sm text-slate-500 italic">Администратор — доступ ко всем модулям</span>
                                </template>
                                <template x-if="employee.role_id != adminRoleId">
                                    <div class="flex flex-wrap gap-2">
                                        <template x-for="name in employee.module_names" :key="name">
                                            <span class="inline-flex items-center px-3 py-1.5 bg-gradient-to-r from-violet-500 to-indigo-600 text-white text-xs font-medium rounded-lg" x-text="name"></span>
                                        </template>
                                        <span x-show="!employee.module_names || employee.module_names.length === 0" class="text-sm text-slate-400 italic">Нет назначенных модулей</span>
                                    </div>
                                </template>
                            </dd>
                        </div>
                    </dl>
                </template>
                <template x-if="editing.access">
                    <div class="space-y-5">
                        <p class="text-xs text-slate-400">Роль меняется в блоке «Основная информация».</p>

                        <template x-if="employee.role_id == adminRoleId">
                            <p class="text-sm text-slate-500 p-3 bg-indigo-50 rounded-xl border border-indigo-100">
                                <svg class="inline w-4 h-4 mr-1 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                                Администратор имеет доступ ко всем модулям
                            </p>
                        </template>

                        <template x-if="employee.role_id != adminRoleId">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-3">Доступ к модулям</label>
                                <div class="mb-3">
                                    <p class="text-xs text-slate-500 mb-2">Выбранные модули:</p>
                                    <div class="flex flex-wrap gap-2 min-h-[42px] p-3 bg-slate-50/50 border border-slate-200 rounded-xl">
                                        <template x-for="module in getSelectedModules()" :key="module.id">
                                            <span class="inline-flex items-center px-3 py-1.5 bg-gradient-to-r from-violet-500 to-indigo-600 text-white text-sm font-medium rounded-lg shadow-sm">
                                                <span x-text="module.display_name"></span>
                                                <button type="button" @click="moduleRemove(module.id)" class="ml-2 hover:bg-white/20 rounded-full p-0.5 transition-colors duration-150">
                                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                                    </svg>
                                                </button>
                                            </span>
                                        </template>
                                        <span x-show="getSelectedModules().length === 0" class="text-sm text-slate-400 italic">Нет выбранных модулей</span>
                                    </div>
                                </div>
                                <div>
                                    <p class="text-xs text-slate-500 mb-2">Доступные модули:</p>
                                    <div class="flex flex-wrap gap-2 min-h-[42px] p-3 bg-slate-50/50 border border-slate-200 rounded-xl">
                                        <template x-for="module in getAvailableModules()" :key="module.id">
                                            <button type="button" @click="moduleAdd(module.id)"
                                                    class="inline-flex items-center px-3 py-1.5 bg-white border border-slate-200 text-slate-700 text-sm font-medium rounded-lg hover:border-indigo-300 hover:bg-indigo-50 hover:text-indigo-700 transition-all duration-150">
                                                <svg class="w-3.5 h-3.5 mr-1.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                                </svg>
                                                <span x-text="module.display_name"></span>
                                            </button>
                                        </template>
                                        <span x-show="getAvailableModules().length === 0" class="text-sm text-slate-400 italic">Все модули выбраны</span>
                                    </div>
                                </div>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </div>

        <!-- Компании -->
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center gap-3">
                <div class="p-2 bg-emerald-100 rounded-lg">
                    <svg class="w-5 h-5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                    </svg>
                </div>
                <h2 class="text-lg font-semibold text-slate-800">Компании</h2>
                <span class="ml-auto text-sm text-slate-500" x-text="employee.clients ? employee.clients.length + ' компани' + (employee.clients.length === 1 ? 'я' : employee.clients.length < 5 ? 'и' : 'й') : '0 компаний'"></span>
            </div>
            <div class="px-6 py-5">
                <template x-if="employee.clients && employee.clients.length > 0">
                    <div class="divide-y divide-slate-100">
                        <template x-for="client in employee.clients" :key="client.id">
                            <div class="py-3 flex items-center justify-between gap-3">
                                <div class="min-w-0">
                                    <div class="text-sm font-medium text-slate-800" x-text="client.name"></div>
                                    <div class="text-xs text-slate-500 mt-0.5" x-text="'ИНН: ' + (client.inn || '—')"></div>
                                </div>
                                <a :href="'/clients/' + client.id"
                                   class="p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-all duration-150"
                                   title="Открыть компанию">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5l7 7-7 7" />
                                    </svg>
                                </a>
                            </div>
                        </template>
                    </div>
                </template>
                <template x-if="!employee.clients || employee.clients.length === 0">
                    <div class="py-8 text-center">
                        <div class="w-12 h-12 bg-slate-100 rounded-xl flex items-center justify-center mx-auto mb-3">
                            <svg class="w-6 h-6 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                            </svg>
                        </div>
                        <p class="text-sm text-slate-500">Нет компаний</p>
                        <p class="text-xs text-slate-400 mt-1">Сотрудник не назначен ответственным, не входит в команду клиента и не ведёт задачи</p>
                    </div>
                </template>
            </div>
        </div>

    </div>

    <!-- Modal: Подтверждение удаления -->
    <div x-show="showDeleteModal"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-50 overflow-y-auto"
         style="display: none;">
        <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm" @click="showDeleteModal = false"></div>
        <div class="flex min-h-full items-center justify-center p-4">
            <div x-show="showDeleteModal"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0 translate-y-4 scale-95"
                 x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                 x-transition:leave="transition ease-in duration-200"
                 x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                 x-transition:leave-end="opacity-0 translate-y-4 scale-95"
                 @click.away="showDeleteModal = false"
                 class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md overflow-hidden">
                <div class="pt-6 pb-4 text-center">
                    <div class="mx-auto w-14 h-14 bg-red-100 rounded-full flex items-center justify-center">
                        <svg class="w-7 h-7 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    </div>
                </div>
                <div class="px-6 pb-6 text-center">
                    <h3 class="text-lg font-semibold text-slate-800 mb-2">Удалить сотрудника?</h3>
                    <p class="text-sm text-slate-500">
                        Вы уверены, что хотите удалить сотрудника <span class="font-medium text-slate-700" x-text="employee.full_name"></span>? Это действие нельзя отменить.
                    </p>
                </div>
                <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-center gap-3">
                    <button @click="showDeleteModal = false" type="button"
                            class="inline-flex items-center px-5 py-2.5 border border-slate-200 rounded-xl text-sm font-medium text-slate-600 bg-white hover:bg-slate-50 hover:border-slate-300 transition-all duration-200">
                        Отмена
                    </button>
                    <form action="{{ route('employees.destroy', $employee) }}" method="POST" class="inline">
                        @csrf
                        @method('DELETE')
                        <button type="submit"
                                class="inline-flex items-center px-5 py-2.5 bg-gradient-to-r from-red-500 to-red-600 text-white text-sm font-medium rounded-xl shadow-lg shadow-red-500/25 hover:shadow-xl hover:shadow-red-500/30 transition-all duration-200">
                            <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                            </svg>
                            Удалить
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>

</div>

<script>
function employeeShow() {
    return {
        employee: {},
        roles: @js($roles),
        modules: @js($modules->map(fn($m) => ['id' => $m->id, 'display_name' => $m->display_name])),
        adminRoleId: 1,
        editing: { info: false, personal: false, access: false },
        saving: { info: false, personal: false, access: false },
        // Что сказал сервер, когда сохранение не прошло — по разделам.
        errors: { info: [], personal: [], access: [] },
        form: {
            info: {},
            personal: {},
            access: { role_id: '', module_ids: [] },
        },
        showDeleteModal: false,

        init() {
            this.employee = @js([
                'id' => $employee->id,
                'full_name' => $employee->full_name,
                'position' => $employee->position,
                'email' => $employee->email,
                'phone' => $employee->phone,
                'employee_number' => $employee->employee_number,
                'birth_date' => $employee->birth_date?->format('Y-m-d'),
                'hired_at' => $employee->hired_at?->format('Y-m-d'),
                'fired_at' => $employee->fired_at?->format('Y-m-d'),
                'employment_status' => $employee->employment_status ?? 'employed',
                'role_id' => $employee->role_id,
                'role_name' => $employee->role?->display_name,
                'module_ids' => $employee->modules->pluck('id')->toArray(),
                'module_names' => $employee->modules->pluck('display_name')->toArray(),
                'clients' => $clients->toArray(),
            ]);
            this.resetForm();
        },

        resetForm() {
            this.form.info = {
                full_name: this.employee.full_name || '',
                role_id: String(this.employee.role_id || ''),
                email: this.employee.email || '',
                phone: this.employee.phone || '',
                employee_number: this.employee.employee_number || '',
            };
            this.form.personal = {
                birth_date: this.employee.birth_date || '',
                hired_at: this.employee.hired_at || '',
                fired_at: this.employee.fired_at || '',
                employment_status: this.employee.employment_status || 'employed',
            };
            this.form.access = {
                role_id: String(this.employee.role_id || ''),
                module_ids: [...(this.employee.module_ids || [])],
            };
        },

        startEdit(section) {
            this.editing[section] = true;
            this.errors[section] = [];
        },

        cancelEdit(section) {
            this.editing[section] = false;
            this.errors[section] = [];
            this.resetForm();
        },

        async saveSection(section) {
            this.saving[section] = true;
            this.errors[section] = [];
            try {
                const data = { section };
                if (section === 'info') Object.assign(data, this.form.info);
                if (section === 'personal') Object.assign(data, this.form.personal);
                if (section === 'access') {
                    data.modules = this.form.access.module_ids;
                }

                const resp = await fetch('/employees/' + this.employee.id, {
                    method: 'PATCH',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(data),
                });
                const result = await resp.json();
                if (result.success) {
                    this.employee = result.employee;
                    this.resetForm();
                    this.editing[section] = false;
                } else if (resp.status === 422) {
                    // Раньше здесь молчали: раздел оставался открытым без единого
                    // слова, и это выглядело как залипшая кнопка «Сохранить».
                    this.errors[section] = Object.values(result.errors ?? {}).flat();
                } else {
                    this.errors[section] = [result.message || 'Не удалось сохранить, попробуйте ещё раз'];
                }
            } catch (e) {
                console.error(e);
                this.errors[section] = ['Не удалось сохранить: нет связи с сервером'];
            }
            this.saving[section] = false;
        },

        moduleAdd(id) {
            if (!this.form.access.module_ids.includes(id)) this.form.access.module_ids.push(id);
        },
        moduleRemove(id) {
            this.form.access.module_ids = this.form.access.module_ids.filter(i => i !== id);
        },
        getSelectedModules() {
            return this.modules.filter(m => this.form.access.module_ids.includes(m.id));
        },
        getAvailableModules() {
            return this.modules.filter(m => !this.form.access.module_ids.includes(m.id));
        },

        formatDate(dateStr) {
            if (!dateStr) return '—';
            const [y, m, d] = dateStr.split('-');
            return `${d}.${m}.${y}`;
        },
    };
}
</script>
@endsection
