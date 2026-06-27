@extends('settings.layout')
@section('page-title', 'Справочник ставок')

@section('settings-content')
<div x-data="ratesPage()" class="space-y-4">

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between">
            <div>
                <h2 class="text-lg font-semibold text-slate-800">Справочник ставок</h2>
                <p class="text-sm text-slate-500 mt-0.5">Базовые ставки с единицами и условиями применения</p>
            </div>
            <button @click="openCreate()" class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
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
                    <template x-for="item in items" :key="item.id">
                        <tr class="hover:bg-slate-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900" x-text="item.name"></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500" x-text="item.unit || '—'"></td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-900" x-text="formatPrice(item.price)"></td>
                            <td class="px-6 py-4 text-sm text-slate-500 max-w-xs truncate" x-text="item.conditions || '—'"></td>
                            <td class="px-6 py-4 whitespace-nowrap text-right">
                                <div class="flex items-center justify-end gap-1">
                                    <button @click="openEdit(item)" class="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors" title="Редактировать">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
                                    </button>
                                    <button @click="openDelete(item)" class="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Удалить">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </template>
                    <template x-if="items.length === 0">
                        <tr><td colspan="5" class="px-6 py-10 text-center text-slate-400">Нет ставок. Нажмите «Создать ставку» чтобы добавить первую.</td></tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>

    {{-- Create/Edit modal --}}
    <template x-teleport="body">
        <div x-show="showModal" x-cloak class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-slate-500/75" @click="closeModal()"></div>
                <div class="relative w-full max-w-lg bg-white rounded-2xl shadow-xl p-6 z-10">
                    <div class="flex items-center justify-between mb-6">
                        <h3 class="text-lg font-semibold text-slate-900" x-text="isEditing ? 'Редактирование ставки' : 'Новая ставка'"></h3>
                        <button @click="closeModal()" class="p-2 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                    <form @submit.prevent="submit()">
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Название <span class="text-red-500">*</span></label>
                                <input type="text" x-model="form.name" required class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                            </div>
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Единица</label>
                                    <input type="text" x-model="form.unit" list="rate-units" placeholder="за час, за раз, за сотрудника..." class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                    <datalist id="rate-units">
                                        @foreach ($units as $u)
                                            <option value="{{ $u }}"></option>
                                        @endforeach
                                    </datalist>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Цена <span class="text-red-500">*</span></label>
                                    <input type="number" x-model="form.price" required min="0" step="0.01" class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-slate-700 mb-1">Условия</label>
                                <textarea x-model="form.conditions" rows="3" placeholder="Условия применения ставки..." class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 resize-none"></textarea>
                            </div>
                        </div>
                        <div class="flex justify-end gap-3 mt-6 pt-6 border-t border-slate-100">
                            <button type="button" @click="closeModal()" class="px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50">Отмена</button>
                            <button type="submit" :disabled="saving" class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 disabled:opacity-50">
                                <svg x-show="saving" class="w-4 h-4 mr-2 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                <span x-text="isEditing ? 'Сохранить' : 'Создать'"></span>
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
function ratesPage() {
    return {
        items: @json($rates),
        showModal: false, isEditing: false, editingId: null, saving: false,
        form: { name: '', unit: '', price: 0, conditions: '' },
        showDeleteModal: false, deleteItem: null, deleting: false,
        toast: { show: false, message: '', type: 'success' },

        openCreate() { this.isEditing = false; this.editingId = null; this.resetForm(); this.showModal = true; },
        openEdit(item) {
            this.isEditing = true; this.editingId = item.id;
            this.form = { name: item.name, unit: item.unit || '', price: item.price, conditions: item.conditions || '' };
            this.showModal = true;
        },
        openDelete(item) { this.deleteItem = item; this.showDeleteModal = true; },
        closeModal() { this.showModal = false; this.resetForm(); },
        resetForm() { this.form = { name: '', unit: '', price: 0, conditions: '' }; },

        formatPrice(price) { return new Intl.NumberFormat('ru-RU').format(price) + ' сом'; },

        async submit() {
            this.saving = true;
            const url = this.isEditing ? `/settings/rates/${this.editingId}` : '/settings/rates';
            try {
                const r = await fetch(url, { method: this.isEditing ? 'PUT' : 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' }, body: JSON.stringify(this.form) });
                const d = await r.json();
                if (d.success) {
                    this.showToast(d.message, 'success');
                    if (this.isEditing) { const i = this.items.findIndex(x => x.id === this.editingId); if (i !== -1) this.items[i] = d.item; }
                    else { this.items.push(d.item); }
                    this.closeModal();
                } else { this.showToast(d.message || 'Ошибка', 'error'); }
            } catch { this.showToast('Ошибка сохранения', 'error'); }
            this.saving = false;
        },

        async confirmDelete() {
            this.deleting = true;
            try {
                const r = await fetch(`/settings/rates/${this.deleteItem.id}`, { method: 'DELETE', headers: { 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content, 'Accept': 'application/json' } });
                const d = await r.json();
                if (d.success) { this.showToast(d.message, 'success'); this.items = this.items.filter(i => i.id !== this.deleteItem.id); this.showDeleteModal = false; }
                else { this.showToast(d.message || 'Ошибка', 'error'); }
            } catch { this.showToast('Ошибка удаления', 'error'); }
            this.deleting = false;
        },

        showToast(message, type = 'success') { this.toast = { show: true, message, type }; setTimeout(() => { this.toast.show = false; }, 3000); },
    };
}
</script>
@endsection
