@extends('layouts.app')

@section('title', 'БухСмета - ERP Fineco')
@section('page-title', 'БухСмета')
<style>[x-cloak]{display:none!important}</style>

@section('content')
<div x-data="{
    clients: @js($clients->map(fn($c) => [
        'id' => $c->id,
        'name' => $c->name,
        'inn' => $c->inn,
        'ownership_form_label' => $c->ownership_form_label,
        'tax_system_name' => $c->taxSystem?->name ?? '—',
        'tariff_name' => $c->tariff?->name ?? '—',
        'responsible' => $c->responsibleEmployee?->full_name ?? '—',
    ])),
    searchQuery: '',

    get filteredClients() {
        if (!this.searchQuery) return this.clients;
        const query = this.searchQuery.toLowerCase();
        return this.clients.filter(c =>
            c.name.toLowerCase().includes(query) ||
            c.inn.includes(query)
        );
    },
}">
    <!-- Поиск -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 mb-6">
        <div class="px-6 py-5">
            <div class="sm:flex sm:items-center sm:gap-4">
                <div class="flex-1">
                    <label class="sr-only">Поиск</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                            <svg class="h-5 w-5 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                            </svg>
                        </div>
                        <input type="text"
                               x-model="searchQuery"
                               class="block w-full pl-11 pr-10 py-2.5 border border-slate-200 rounded-xl bg-slate-50/50 text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 focus:bg-white transition-all duration-200 sm:text-sm"
                               placeholder="Поиск по названию или ИНН...">
                        <button x-show="searchQuery.length > 0"
                                @click="searchQuery = ''"
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
                        Найдено: <span class="font-medium text-slate-700" x-text="filteredClients.length"></span>
                    </span>
                </div>
            </div>
        </div>
    </div>

    <!-- Таблица клиентов -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full">
                <thead>
                    <tr class="bg-slate-50/80 border-b border-slate-200/50">
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Название</th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">ИНН</th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">СНО</th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Тарифный план</th>
                        <th scope="col" class="px-6 py-4 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">Ответственный</th>
                        <th scope="col" class="relative px-6 py-4"><span class="sr-only">Перейти</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="client in filteredClients" :key="client.id">
                        <tr class="hover:bg-slate-50/50 transition-colors duration-150 cursor-pointer"
                            @click="window.location.href = '/clients/' + client.id">
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="flex items-center">
                                    <div class="w-9 h-9 bg-gradient-to-br from-emerald-100 to-teal-100 rounded-xl flex items-center justify-center mr-3">
                                        <span class="text-sm font-semibold text-teal-600" x-text="client.name.charAt(0)"></span>
                                    </div>
                                    <div class="text-sm font-medium text-slate-800" x-text="client.name"></div>
                                </div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-slate-600 font-mono" x-text="client.inn"></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-slate-600" x-text="client.tax_system_name"></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <div class="text-sm text-slate-600" x-text="client.tariff_name"></div>
                            </td>
                            <td class="px-6 py-4">
                                <div class="text-sm text-slate-600 max-w-[200px] truncate" x-text="client.responsible" :title="client.responsible"></div>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-right">
                                <svg class="w-5 h-5 text-slate-400 ml-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5l7 7-7 7" />
                                </svg>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        <!-- Пустое состояние -->
        <div x-show="filteredClients.length === 0" class="px-6 py-16 text-center">
            <div class="w-16 h-16 bg-slate-100 rounded-2xl flex items-center justify-center mx-auto mb-4">
                <svg class="h-8 w-8 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
                </svg>
            </div>
            <h3 class="text-sm font-medium text-slate-800 mb-1">Компании не найдены</h3>
            <p class="text-sm text-slate-500" x-text="searchQuery ? 'Попробуйте изменить параметры поиска' : 'Нет активных клиентов'"></p>
        </div>
    </div>
</div>
@endsection
