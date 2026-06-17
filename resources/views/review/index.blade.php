@extends('layouts.app')

@section('title', 'Проверка')
@section('page-title', 'Проверка')

@section('content')

<div x-data="reviewList({{ json_encode($logs) }})" x-cloak>

    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100">
            <h2 class="text-base font-bold text-slate-900">Задачи на проверке</h2>
            <p class="text-sm text-slate-500 mt-0.5">Закрытые сотрудниками задачи по БП с обязательной проверкой</p>
        </div>

        <template x-if="items.length === 0">
            <div class="px-5 py-10 text-center text-slate-400">Нет задач, ожидающих проверки</div>
        </template>

        <template x-if="items.length > 0">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-200">
                    <thead class="bg-slate-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Сотрудник</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Компания</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">БП</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Затрачено</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Отправлено</th>
                            <th class="px-4 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Действия</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100">
                        <template x-for="(item, idx) in items" :key="item.id">
                            <tr class="hover:bg-slate-50/50">
                                <td class="px-4 py-3.5 text-sm text-slate-800" x-text="item.employee_name"></td>
                                <td class="px-4 py-3.5 text-sm text-slate-600" x-text="item.client_name"></td>
                                <td class="px-4 py-3.5 text-sm text-slate-600" x-text="item.service_name"></td>
                                <td class="px-4 py-3.5 text-sm font-mono text-slate-700" x-text="formatTime(item.elapsed_seconds)"></td>
                                <td class="px-4 py-3.5 text-sm text-slate-500" x-text="item.submitted_at"></td>
                                <td class="px-4 py-3.5 text-right whitespace-nowrap">
                                    <span class="inline-flex items-center gap-1.5">
                                        <button @click.prevent="approve(idx)"
                                                :disabled="item.loading"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600 text-white text-xs font-medium rounded-lg hover:bg-emerald-700 disabled:opacity-50 transition-colors">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                            Проверено
                                        </button>
                                        <button @click.prevent="openReject(idx)"
                                                :disabled="item.loading"
                                                class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-rose-50 text-rose-600 border border-rose-200 text-xs font-medium rounded-lg hover:bg-rose-100 disabled:opacity-50 transition-colors">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                            На доработку
                                        </button>
                                    </span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </template>
    </div>

    {{-- Модал отказа с комментарием --}}
    <div x-show="rejectIdx !== null" x-cloak class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4" @click.self="closeReject()">
        <div class="bg-white rounded-xl shadow-xl w-full max-w-md p-5">
            <h3 class="text-base font-bold text-slate-900 mb-1">На доработку</h3>
            <p class="text-sm text-slate-500 mb-3">Опишите, что нужно исправить — сотрудник увидит этот комментарий</p>
            <textarea x-model="rejectComment" rows="4"
                      class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:ring-2 focus:ring-rose-500 focus:border-rose-500"
                      placeholder="Комментарий..."></textarea>
            <p x-show="rejectError" class="text-xs text-rose-600 mt-1" x-text="rejectError"></p>
            <div class="flex items-center justify-end gap-2 mt-4">
                <button @click.prevent="closeReject()" class="px-3 py-1.5 text-sm font-medium text-slate-600 hover:text-slate-800">Отмена</button>
                <button @click.prevent="confirmReject()"
                        :disabled="rejectLoading"
                        class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-rose-600 text-white text-xs font-medium rounded-lg hover:bg-rose-700 disabled:opacity-50 transition-colors">
                    Отправить на доработку
                </button>
            </div>
        </div>
    </div>

</div>

<script>
function reviewList(initial) {
    return {
        items: initial.map(i => ({ ...i, loading: false })),
        rejectIdx: null,
        rejectComment: '',
        rejectError: '',
        rejectLoading: false,

        csrf() {
            return document.querySelector('meta[name="csrf-token"]').content;
        },

        formatTime(seconds) {
            if (!seconds) return '00:00:00';
            const h = Math.floor(seconds / 3600);
            const m = Math.floor((seconds % 3600) / 60);
            const s = seconds % 60;
            return String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
        },

        async approve(idx) {
            this.items[idx] = { ...this.items[idx], loading: true };
            const r = await fetch(`/review/${this.items[idx].id}/approve`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' },
            });
            const data = await r.json();
            if (data.success) {
                this.items.splice(idx, 1);
            } else {
                this.items[idx] = { ...this.items[idx], loading: false };
            }
        },

        openReject(idx) {
            this.rejectIdx = idx;
            this.rejectComment = '';
            this.rejectError = '';
        },

        closeReject() {
            this.rejectIdx = null;
        },

        async confirmReject() {
            if (!this.rejectComment.trim()) {
                this.rejectError = 'Укажите, что нужно исправить';
                return;
            }
            this.rejectLoading = true;
            const idx = this.rejectIdx;
            const r = await fetch(`/review/${this.items[idx].id}/reject`, {
                method: 'POST',
                headers: {
                    'X-CSRF-TOKEN': this.csrf(),
                    'Accept': 'application/json',
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({ comment: this.rejectComment }),
            });
            const data = await r.json();
            this.rejectLoading = false;
            if (data.success) {
                this.items.splice(idx, 1);
                this.rejectIdx = null;
            } else {
                this.rejectError = data.message || 'Не удалось отправить на доработку';
            }
        },
    };
}
</script>

@endsection
