@extends('layouts.app')

@section('title', 'БухСмета - ERP')
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
        'total' => (float) ($c->estimate?->total ?? 0),
    ])),
    searchQuery: '',

    formatTotal(value) {
        return new Intl.NumberFormat('ru-RU').format(value) + ' сом';
    },

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

    <!-- Сметы компаний -->
    <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
        <template x-for="client in filteredClients" :key="client.id">
            <div class="group bg-white rounded-2xl shadow-sm border border-slate-200/50 p-5 cursor-pointer hover:shadow-md hover:border-indigo-200 transition-all duration-150 flex flex-col"
                 @click="window.location.href = '/clients/' + client.id + '/estimate/edit'">
                <!-- Шапка карточки -->
                <div class="flex items-start gap-3 mb-4">
                    <div class="w-11 h-11 bg-gradient-to-br from-emerald-100 to-teal-100 rounded-xl flex items-center justify-center flex-shrink-0">
                        <span class="text-base font-semibold text-teal-600" x-text="client.name.charAt(0)"></span>
                    </div>
                    <div class="min-w-0 flex-1">
                        <div class="text-sm font-semibold text-slate-800 truncate" x-text="client.name" :title="client.name"></div>
                        <div class="text-xs text-slate-500 font-mono mt-0.5" x-text="'ИНН ' + client.inn"></div>
                    </div>
                    <svg class="w-5 h-5 text-slate-300 group-hover:text-indigo-400 flex-shrink-0 transition-colors" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5l7 7-7 7" />
                    </svg>
                </div>

                <!-- Параметры -->
                <div class="space-y-1.5 text-xs mb-4">
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-slate-400">СНО</span>
                        <span class="text-slate-600 font-medium truncate" x-text="client.tax_system_name"></span>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-slate-400">Тариф</span>
                        <span class="text-slate-600 font-medium truncate" x-text="client.tariff_name"></span>
                    </div>
                    <div class="flex items-center justify-between gap-2">
                        <span class="text-slate-400">Ответственный</span>
                        <span class="text-slate-600 font-medium truncate" x-text="client.responsible" :title="client.responsible"></span>
                    </div>
                </div>

                <!-- Сумма сметы -->
                <div class="mt-auto pt-3 border-t border-slate-100 flex items-baseline justify-between">
                    <span class="text-xs text-slate-400">Сумма сметы</span>
                    <span class="text-base font-bold text-slate-800" x-text="formatTotal(client.total)"></span>
                </div>
            </div>
        </template>
    </div>

    <!-- Пустое состояние -->
    <div x-show="filteredClients.length === 0" class="bg-white rounded-2xl shadow-sm border border-slate-200/50 px-6 py-16 text-center">
        <div class="w-16 h-16 bg-slate-100 rounded-2xl flex items-center justify-center mx-auto mb-4">
            <svg class="h-8 w-8 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 10v-5a1 1 0 011-1h2a1 1 0 011 1v5m-4 0h4" />
            </svg>
        </div>
        <h3 class="text-sm font-medium text-slate-800 mb-1">Компании не найдены</h3>
        <p class="text-sm text-slate-500" x-text="searchQuery ? 'Попробуйте изменить параметры поиска' : 'Нет активных клиентов'"></p>
    </div>
</div>
@endsection
