@extends('layouts.app')

@section('title', 'Сотрудники - ERP Fineco')
@section('page-title', 'Сотрудники')

@section('content')
<div x-data="{
    showCreateModal: false,
    showDeleteModal: false,
    deleteEmployee: null,
    selectedRowId: null,
    allModules: @js($modules->map(fn($m) => ['id' => $m->id, 'name' => $m->display_name])),
    createSelectedIds: [],
    adminRoleId: 1,

    searchQuery: '',
    employees: @js($employees->map(fn($e) => [
        'id' => $e->id,
        'full_name' => $e->full_name,
        'position' => $e->position,
        'email' => $e->email,
        'phone' => $e->phone,
        'role_name' => $e->role->display_name,
        'employment_status' => $e->employment_status ?? 'employed',
    ])),
    loading: false,
    searchTimeout: null,

    async searchEmployees() {
        this.loading = true;
        try {
            const response = await fetch('/employees/search?q=' + encodeURIComponent(this.searchQuery));
            this.employees = await response.json();
        } catch (error) {
            console.error('Search error:', error);
        }
        this.loading = false;
    },

    onSearchInput() {
        clearTimeout(this.searchTimeout);
        this.searchTimeout = setTimeout(() => this.searchEmployees(), 300);
    },

    clearSearch() {
        this.searchQuery = '';
        this.searchEmployees();
    },

    createAdd(id) {
        if (!this.createSelectedIds.includes(id)) this.createSelectedIds.push(id);
    },
    createRemove(id) {
        this.createSelectedIds = this.createSelectedIds.filter(i => i !== id);
    },
    createGetSelected() {
        return this.allModules.filter(m => this.createSelectedIds.includes(m.id));
    },
    createGetAvailable() {
        return this.allModules.filter(m => !this.createSelectedIds.includes(m.id));
    },
    resetCreateForm() {
        this.createSelectedIds = [];
    },
}">
    <div class="sm:flex sm:items-center sm:justify-between mb-8">
        <div>
            <p class="text-slate-500">Управление сотрудниками компании</p>
        </div>
        <div class="mt-4 sm:mt-0">
            <button @click="showCreateModal = true; resetCreateForm()"
                    type="button"
                    class="inline-flex items-center px-5 py-2.5 bg-gradient-to-r from-violet-500 to-indigo-600 text-white text-sm font-medium rounded-xl shadow-lg shadow-indigo-500/25 hover:shadow-xl hover:shadow-indigo-500/30 hover:-translate-y-0.5 transition-all duration-200">
                <svg class="-ml-1 mr-2 h-5 w-5" fill="none" viewBox="0 0 20 20" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" />
                </svg>
                Добавить сотрудника
            </button>
        </div>
    </div>

    <!-- Поиск -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 mb-6">
        <div class="px-6 py-5">
            <div class="sm:flex sm:items-center sm:gap-4">
                <div class="flex-1">
                    <label for="search" class="sr-only">Поиск</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <svg x-show="!loading" class="h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                            <svg x-show="loading" class="h-5 w-5 text-indigo-500 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                            </svg>
                        </div>
                        <input type="text"
                               id="search"
                               x-model="searchQuery"
                               @input="onSearchInput()"
                               class="block w-full pl-11 pr-10 py-2.5 border border-slate-200 rounded-xl bg-slate-50/50 text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 focus:bg-white transition-all duration-200 sm:text-sm"
                               placeholder="Поиск по ФИО, email, телефону...">
                        <button x-show="searchQuery.length > 0"
                                @click="clearSearch()"
                                type="button"
                                class="absolute inset-y-0 right-0 pr-4 flex items-center text-slate-400 hover:text-slate-600">
                            <svg class="h-4 w-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                            </svg>
                        </button>
                    </div>
                </div>
                <div class="mt-4 sm:mt-0">
                    <span x-show="searchQuery.length > 0" class="text-sm text-slate-500">
                        Найдено: <span class="font-medium text-slate-700" x-text="employees.length"></span>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Таблица -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead>
                    <tr class="bg-slate-50/80 border-b border-slate-200/50">
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">
                            ФИО
                        </th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">
                            Должность
                        </th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">
                            Email
                        </th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">
                            Телефон
                        </th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">
                            Роль
                        </th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">
                            Статус
                        </th>
                        <th scope="col" class="relative px-6 py-4">
                            <span class="sr-only">Действия</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="employee in employees" :key="employee.id">
                        <tr @click="selectedRowId = (selectedRowId === employee.id ? null : employee.id)"
                            :class="selectedRowId === employee.id ? 'bg-indigo-50/70 ring-1 ring-inset ring-indigo-200' : 'hover:bg-slate-50/50'"
                            class="cursor-pointer transition-colors duration-150">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="w-9 h-9 bg-gradient-to-br from-violet-100 to-indigo-100 rounded-xl flex items-center justify-center mr-3">
                                        <span class="text-sm font-semibold text-indigo-600" x-text="employee.full_name.charAt(0)"></span>
                                    </div>
                                    <div class="text-sm font-medium text-slate-800" x-text="employee.full_name"></div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-slate-600" x-text="employee.position"></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-slate-600" x-text="employee.email"></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-slate-600" x-text="employee.phone || '—'"></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-slate-600" x-text="employee.role_name"></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <template x-if="employee.employment_status === 'employed'">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-emerald-100 text-emerald-700">Активный</span>
                                </template>
                                <template x-if="employee.employment_status === 'fired'">
                                    <span class="inline-flex items-center px-2.5 py-1 rounded-lg text-xs font-medium bg-red-100 text-red-700">Уволен</span>
                                </template>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <a :href="'/employees/' + employee.id"
                                       class="inline-flex items-center p-2 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-all duration-150"
                                       title="Открыть">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" />
                                        </svg>
                                    </a>
                                    <button type="button"
                                            @click="deleteEmployee = { id: employee.id, name: employee.full_name }; showDeleteModal = true"
                                            class="inline-flex items-center p-2 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-all duration-150"
                                            title="Удалить">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                        </svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <!-- Пустое состояние -->
        <div x-show="employees.length === 0" class="px-6 py-16 text-center">
            <div class="w-16 h-16 bg-slate-100 rounded-2xl flex items-center justify-center mx-auto mb-4">
                <svg class="h-8 w-8 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M12 4.354a4 4 0 110 5.292M15 21H3v-1a6 6 0 0112 0v1zm0 0h6v-1a6 6 0 00-9-5.197m13.5-9a2.5 2.5 0 11-5 0 2.5 2.5 0 015 0z" />
                </svg>
            </div>
            <h3 class="text-sm font-medium text-slate-800 mb-1">Сотрудники не найдены</h3>
            <p class="text-sm text-slate-500 mb-6" x-text="searchQuery ? 'Попробуйте изменить параметры поиска' : 'Начните с добавления нового сотрудника'"></p>
            <button x-show="!searchQuery"
                    @click="showCreateModal = true; resetCreateForm()"
                    type="button"
                    class="inline-flex items-center px-5 py-2.5 bg-gradient-to-r from-violet-500 to-indigo-600 text-white text-sm font-medium rounded-xl shadow-lg shadow-indigo-500/25 hover:shadow-xl hover:shadow-indigo-500/30 transition-all duration-200">
                <svg class="-ml-1 mr-2 h-5 w-5" fill="none" viewBox="0 0 20 20" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 3a1 1 0 011 1v5h5a1 1 0 110 2h-5v5a1 1 0 11-2 0v-5H4a1 1 0 110-2h5V4a1 1 0 011-1z" />
                </svg>
                Добавить сотрудника
            </button>
        </div>
    </div>

    <!-- Modal: Добавить сотрудника -->
    <div x-show="showCreateModal"
         x-transition:enter="transition ease-out duration-300"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-200"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-50 overflow-y-auto"
         style="display: none;">
        <div class="fixed inset-0 bg-slate-900/50 backdrop-blur-sm" @click="showCreateModal = false"></div>

        <div class="flex min-h-full items-center justify-center p-4">
            <div x-show="showCreateModal"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0 translate-y-4 scale-95"
                 x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                 x-transition:leave="transition ease-in duration-200"
                 x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                 x-transition:leave-end="opacity-0 translate-y-4 scale-95"
                 @click.away="showCreateModal = false"
                 class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[90vh] overflow-hidden">

                <div class="flex items-center justify-between px-6 py-4 border-b border-slate-100">
                    <h3 class="text-lg font-semibold text-slate-800">Добавить сотрудника</h3>
                    <button @click="showCreateModal = false" type="button" class="p-2 text-slate-400 hover:text-slate-600 hover:bg-slate-100 rounded-lg transition-all duration-150">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                <form action="{{ route('employees.store') }}" method="POST" class="overflow-y-auto max-h-[calc(90vh-140px)]">
                    @csrf
                    <div class="px-6 py-6">
                        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
                            <div class="sm:col-span-2">
                                <label for="create_full_name" class="block text-sm font-medium text-slate-700 mb-2">ФИО <span class="text-red-500">*</span></label>
                                <input type="text" name="full_name" id="create_full_name" required class="block w-full px-4 py-2.5 border border-slate-200 rounded-xl bg-slate-50/50 text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 focus:bg-white transition-all duration-200">
                            </div>

                            <div>
                                <label for="create_position" class="block text-sm font-medium text-slate-700 mb-2">Должность <span class="text-red-500">*</span></label>
                                <input type="text" name="position" id="create_position" required class="block w-full px-4 py-2.5 border border-slate-200 rounded-xl bg-slate-50/50 text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 focus:bg-white transition-all duration-200">
                            </div>

                            <div>
                                <label for="create_email" class="block text-sm font-medium text-slate-700 mb-2">Email (логин) <span class="text-red-500">*</span></label>
                                <input type="email" name="email" id="create_email" required class="block w-full px-4 py-2.5 border border-slate-200 rounded-xl bg-slate-50/50 text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 focus:bg-white transition-all duration-200">
                            </div>

                            <div>
                                <label for="create_phone" class="block text-sm font-medium text-slate-700 mb-2">Телефон</label>
                                <input type="tel" name="phone" id="create_phone" placeholder="+996 (___) ___-___" class="block w-full px-4 py-2.5 border border-slate-200 rounded-xl bg-slate-50/50 text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 focus:bg-white transition-all duration-200">
                            </div>

                            <div x-data="{ show: false }">
                                <label for="create_password" class="block text-sm font-medium text-slate-700 mb-2">Пароль <span class="text-red-500">*</span></label>
                                <div class="relative">
                                    <input :type="show ? 'text' : 'password'" name="password" id="create_password" required minlength="8" class="block w-full px-4 py-2.5 pr-11 border border-slate-200 rounded-xl bg-slate-50/50 text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 focus:bg-white transition-all duration-200">
                                    <button type="button" @click="show = !show" class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600 transition-colors duration-150" :aria-label="show ? 'Скрыть пароль' : 'Показать пароль'">
                                        <svg x-show="!show" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                        <svg x-show="show" x-cloak class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                                        </svg>
                                    </button>
                                </div>
                                <p class="mt-1 text-xs text-slate-500">Минимум 8 символов</p>
                            </div>

                            <div x-data="{ show: false }">
                                <label for="create_password_confirmation" class="block text-sm font-medium text-slate-700 mb-2">Подтверждение пароля <span class="text-red-500">*</span></label>
                                <div class="relative">
                                    <input :type="show ? 'text' : 'password'" name="password_confirmation" id="create_password_confirmation" required class="block w-full px-4 py-2.5 pr-11 border border-slate-200 rounded-xl bg-slate-50/50 text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 focus:bg-white transition-all duration-200">
                                    <button type="button" @click="show = !show" class="absolute inset-y-0 right-0 flex items-center pr-3 text-slate-400 hover:text-slate-600 transition-colors duration-150" :aria-label="show ? 'Скрыть пароль' : 'Показать пароль'">
                                        <svg x-show="!show" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                        </svg>
                                        <svg x-show="show" x-cloak class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0A9.953 9.953 0 0112 5c4.478 0 8.268 2.943 9.543 7a10.025 10.025 0 01-4.132 5.411m0 0L21 21" />
                                        </svg>
                                    </button>
                                </div>
                            </div>

                            <div x-data="{ createRoleId: '' }">
                                <label for="create_role_id" class="block text-sm font-medium text-slate-700 mb-2">Роль <span class="text-red-500">*</span></label>
                                <select name="role_id" id="create_role_id" x-model="createRoleId" required class="block w-full px-4 py-2.5 border border-slate-200 rounded-xl bg-slate-50/50 text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 focus:bg-white transition-all duration-200">
                                    <option value="">Выберите роль</option>
                                    @foreach($roles as $role)
                                        <option value="{{ $role->id }}">{{ $role->display_name }}</option>
                                    @endforeach
                                </select>
                            </div>

                            <div class="sm:col-span-2" x-data="{ createRoleId: '' }" x-init="document.getElementById('create_role_id').addEventListener('change', e => createRoleId = e.target.value)">
                                <label class="block text-sm font-medium text-slate-700 mb-3">Доступ к модулям</label>

                                <template x-if="createRoleId == adminRoleId">
                                    <p class="text-sm text-slate-500 mb-4 p-3 bg-indigo-50 rounded-xl border border-indigo-100">
                                        <svg class="inline w-4 h-4 mr-1 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                        </svg>
                                        Администратор имеет доступ ко всем модулям
                                    </p>
                                </template>

                                <template x-if="createRoleId != adminRoleId">
                                    <div>
                                        <div class="mb-4">
                                            <p class="text-xs text-slate-500 mb-2">Выбранные модули:</p>
                                            <div class="flex flex-wrap gap-2 min-h-[42px] p-3 bg-slate-50/50 border border-slate-200 rounded-xl">
                                                <template x-for="module in createGetSelected()" :key="module.id">
                                                    <span class="inline-flex items-center px-3 py-1.5 bg-gradient-to-r from-violet-500 to-indigo-600 text-white text-sm font-medium rounded-lg shadow-sm">
                                                        <span x-text="module.name"></span>
                                                        <button type="button" @click="createRemove(module.id)" class="ml-2 hover:bg-white/20 rounded-full p-0.5 transition-colors duration-150">
                                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                                                            </svg>
                                                        </button>
                                                        <input type="hidden" name="modules[]" :value="module.id">
                                                    </span>
                                                </template>
                                                <span x-show="createGetSelected().length === 0" class="text-sm text-slate-400 italic">Нет выбранных модулей</span>
                                            </div>
                                        </div>

                                        <div>
                                            <p class="text-xs text-slate-500 mb-2">Доступные модули:</p>
                                            <div class="flex flex-wrap gap-2 min-h-[42px] p-3 bg-slate-50/50 border border-slate-200 rounded-xl">
                                                <template x-for="module in createGetAvailable()" :key="module.id">
                                                    <button type="button" @click="createAdd(module.id)" class="inline-flex items-center px-3 py-1.5 bg-white border border-slate-200 text-slate-700 text-sm font-medium rounded-lg hover:border-indigo-300 hover:bg-indigo-50 hover:text-indigo-700 transition-all duration-150">
                                                        <svg class="w-3.5 h-3.5 mr-1.5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                                                        </svg>
                                                        <span x-text="module.name"></span>
                                                    </button>
                                                </template>
                                                <span x-show="createGetAvailable().length === 0" class="text-sm text-slate-400 italic">Все модули выбраны</span>
                                            </div>
                                        </div>
                                    </div>
                                </template>
                            </div>
                        </div>
                    </div>

                    <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-end gap-3">
                        <button @click="showCreateModal = false" type="button" class="inline-flex items-center px-5 py-2.5 border border-slate-200 rounded-xl text-sm font-medium text-slate-600 bg-white hover:bg-slate-50 hover:border-slate-300 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all duration-200">
                            Отмена
                        </button>
                        <button type="submit" class="inline-flex items-center px-5 py-2.5 bg-gradient-to-r from-violet-500 to-indigo-600 text-white text-sm font-medium rounded-xl shadow-lg shadow-indigo-500/25 hover:shadow-xl hover:shadow-indigo-500/30 transition-all duration-200">
                            <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6v6m0 0v6m0-6h6m-6 0H6" />
                            </svg>
                            Создать сотрудника
                        </button>
                    </div>
                </form>
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
                        Вы уверены, что хотите удалить сотрудника <span class="font-medium text-slate-700" x-text="deleteEmployee?.name"></span>? Это действие нельзя отменить.
                    </p>
                </div>

                <div class="px-6 py-4 bg-slate-50 border-t border-slate-100 flex justify-center gap-3">
                    <button @click="showDeleteModal = false" type="button" class="inline-flex items-center px-5 py-2.5 border border-slate-200 rounded-xl text-sm font-medium text-slate-600 bg-white hover:bg-slate-50 hover:border-slate-300 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 transition-all duration-200">
                        Отмена
                    </button>
                    <form :action="'/employees/' + deleteEmployee?.id" method="POST" class="inline">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="inline-flex items-center px-5 py-2.5 bg-gradient-to-r from-red-500 to-red-600 text-white text-sm font-medium rounded-xl shadow-lg shadow-red-500/25 hover:shadow-xl hover:shadow-red-500/30 transition-all duration-200">
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
document.addEventListener('DOMContentLoaded', function() {
    function setupPhoneMask(input) {
        if (!input) return;

        input.addEventListener('input', function(e) {
            let value = e.target.value.replace(/\D/g, '');
            if (value.startsWith('996')) value = value.slice(3);

            let formatted = '+996';
            if (value.length > 0) formatted += ' (' + value.slice(0, 3);
            if (value.length > 3) formatted += ') ' + value.slice(3, 6);
            if (value.length > 6) formatted += '-' + value.slice(6, 9);

            e.target.value = formatted === '+996' ? '' : formatted;
        });

        input.addEventListener('focus', function(e) {
            if (!e.target.value) e.target.value = '+996';
        });

        input.addEventListener('blur', function(e) {
            if (e.target.value === '+996' || e.target.value === '+996 (') e.target.value = '';
        });
    }

    setupPhoneMask(document.getElementById('create_phone'));
});
</script>
@endsection
