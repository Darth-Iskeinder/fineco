@extends('layouts.app')

@section('title', $tariff->name . ' — Услуги')
@section('page-title', 'Настройки')

@section('content')
<div x-data="tariffPage()" x-init="init()">
    <!-- Хлебные крошки -->
    <div class="flex items-center gap-2 mb-6 text-sm text-slate-500">
        <a href="/settings?tab=tariffs" class="hover:text-slate-700 transition-colors">Настройки</a>
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
        <a href="/settings?tab=tariffs" class="hover:text-slate-700 transition-colors">Тарифы</a>
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7" /></svg>
        <span class="text-slate-800 font-medium">{{ $tariff->name }}</span>
    </div>

    <!-- Карточка тарифа -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 p-6 mb-6">
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-2xl font-bold text-slate-900">{{ $tariff->name }}</h1>
                @if($tariff->description)
                    <p class="mt-1 text-slate-500">{{ $tariff->description }}</p>
                @endif
            </div>
            <div class="text-right">
                <div class="text-2xl font-bold text-indigo-600">{{ $tariff->formatted_price }}</div>
                <div class="mt-1">
                    @if($tariff->is_active)
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-800">Активен</span>
                    @else
                        <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600">Неактивен</span>
                    @endif
                </div>
            </div>
        </div>
    </div>

    <!-- Таблица услуг тарифа -->
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <h2 class="text-lg font-semibold text-slate-800">Услуги тарифа</h2>
            <button @click="openAddModal()" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                Добавить услугу
            </button>
        </div>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Название</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Описание</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Периодичность</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Стоимость</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Действия</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-slate-200">
                    <template x-for="svc in tariffServices" :key="svc.id">
                        <tr class="hover:bg-slate-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900" x-text="svc.name"></td>
                            <td class="px-6 py-4 text-sm text-slate-500 max-w-xs truncate" x-text="svc.description || '—'"></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500" x-text="svc.periodicity || '—'"></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-slate-900" x-text="formatPrice(svc.cost)"></td>
                            <td class="px-6 py-4 whitespace-nowrap text-right text-sm">
                                <button @click="detachService(svc)" class="text-red-600 hover:text-red-900">Убрать</button>
                            </td>
                        </tr>
                    </template>
                    <template x-if="tariffServices.length === 0">
                        <tr>
                            <td colspan="5" class="px-6 py-10 text-center text-slate-500">
                                <p class="text-sm">Нет услуг в этом тарифе</p>
                                <p class="text-xs text-slate-400 mt-1">Нажмите «Добавить услугу» чтобы привязать услугу</p>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Модал добавления услуги в тариф -->
    <template x-teleport="body">
        <div x-show="showAddModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto" aria-modal="true">
            <div class="flex items-center justify-center min-h-screen px-4 pt-4 pb-20 text-center sm:p-0">
                <div x-show="showAddModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0" class="fixed inset-0 bg-slate-500/75 transition-opacity" @click="showAddModal = false"></div>

                <div x-show="showAddModal" x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100" x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100" x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95" class="relative inline-block w-full max-w-lg p-6 my-8 text-left align-middle bg-white rounded-2xl shadow-xl transform transition-all">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-semibold text-slate-900">Добавить услугу в тариф</h3>
                        <button @click="showAddModal = false" class="p-2 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100 transition-colors">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <div class="mb-4">
                        <input type="text" x-model="serviceSearch" placeholder="Поиск услуги..." class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                    </div>

                    <div class="space-y-1 max-h-80 overflow-y-auto">
                        <template x-for="svc in filteredAvailableServices" :key="svc.id">
                            <button type="button"
                                    @click="attachService(svc)"
                                    class="w-full flex items-center justify-between px-4 py-3 rounded-xl hover:bg-slate-50 border border-slate-100 text-left transition-colors"
                                    :class="isAlreadyAttached(svc.id) ? 'opacity-40 cursor-not-allowed' : ''"
                                    :disabled="isAlreadyAttached(svc.id)">
                                <div>
                                    <p class="text-sm font-medium text-slate-800" x-text="svc.name"></p>
                                    <p class="text-xs text-slate-500" x-text="svc.periodicity || ''"></p>
                                </div>
                                <div class="flex items-center gap-3">
                                    <span class="text-sm font-semibold text-slate-700" x-text="formatPrice(svc.cost)"></span>
                                    <template x-if="isAlreadyAttached(svc.id)">
                                        <span class="text-xs text-emerald-600 font-medium">Добавлено</span>
                                    </template>
                                    <template x-if="!isAlreadyAttached(svc.id)">
                                        <svg class="w-5 h-5 text-indigo-500" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4" /></svg>
                                    </template>
                                </div>
                            </button>
                        </template>
                        <template x-if="filteredAvailableServices.length === 0">
                            <p class="text-center text-sm text-slate-500 py-6">Услуги не найдены</p>
                        </template>
                    </div>

                    <div class="mt-4 pt-4 border-t border-slate-100">
                        <button @click="showAddModal = false" class="w-full px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
                            Закрыть
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </template>

    <!-- Toast -->
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

<script>
function tariffPage() {
    return {
        tariffId: {{ $tariff->id }},
        tariffServices: @json($tariff->services->map(fn($s) => [
            'id' => $s->id,
            'name' => $s->name,
            'description' => $s->description,
            'cost' => $s->cost,
            'periodicity' => $s->periodicity,
        ])),
        allServices: @json($allServices->map(fn($s) => [
            'id' => $s->id,
            'name' => $s->name,
            'description' => $s->description,
            'cost' => $s->cost,
            'periodicity' => $s->periodicity,
        ])),

        showAddModal: false,
        serviceSearch: '',
        toast: { show: false, message: '', type: 'success' },

        init() {},

        get filteredAvailableServices() {
            const q = this.serviceSearch.toLowerCase();
            return this.allServices.filter(s =>
                !q || s.name.toLowerCase().includes(q) || (s.description || '').toLowerCase().includes(q)
            );
        },

        isAlreadyAttached(serviceId) {
            return this.tariffServices.some(s => s.id === serviceId);
        },

        openAddModal() {
            this.serviceSearch = '';
            this.showAddModal = true;
        },

        async attachService(svc) {
            if (this.isAlreadyAttached(svc.id)) return;

            try {
                const response = await fetch(`/settings/tariffs/${this.tariffId}/services`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ service_id: svc.id }),
                });

                const data = await response.json();
                if (data.success) {
                    this.tariffServices.push(svc);
                    this.showToast('Услуга добавлена', 'success');
                } else {
                    this.showToast(data.message || 'Ошибка', 'error');
                }
            } catch (e) {
                this.showToast('Ошибка', 'error');
            }
        },

        async detachService(svc) {
            try {
                const response = await fetch(`/settings/tariffs/${this.tariffId}/services/${svc.id}`, {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                        'Accept': 'application/json',
                    },
                });

                const data = await response.json();
                if (data.success) {
                    this.tariffServices = this.tariffServices.filter(s => s.id !== svc.id);
                    this.showToast('Услуга убрана из тарифа', 'success');
                } else {
                    this.showToast(data.message || 'Ошибка', 'error');
                }
            } catch (e) {
                this.showToast('Ошибка', 'error');
            }
        },

        formatPrice(price) {
            return new Intl.NumberFormat('ru-RU').format(price) + ' сом';
        },

        showToast(message, type = 'success') {
            this.toast = { show: true, message, type };
            setTimeout(() => { this.toast.show = false; }, 3000);
        },
    };
}
</script>
@endsection
