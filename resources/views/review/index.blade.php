@extends('layouts.app')

@section('title', 'Проверка')
@section('page-title', 'Проверка')

@section('content')

<div x-data="reviewList({{ json_encode($logs) }}, {{ json_encode($reviewed) }}, {{ $slaDays }}, {{ $historyDays }})" x-cloak>

    <div class="bg-white rounded-xl border border-slate-200 shadow-sm overflow-hidden">
        <div class="px-5 py-4 border-b border-slate-100 flex items-start justify-between gap-4 flex-wrap">
            <div class="min-w-0">
                <h2 class="text-base font-bold text-slate-900">Задачи на проверке</h2>
                <p class="text-sm text-slate-500 mt-0.5">Закрытые сотрудниками задачи по БП с обязательной проверкой</p>
                <p class="text-xs text-slate-400 mt-1 flex items-center gap-1">
                    <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    <span x-show="viewMode === 'review'">Двойной клик по строке — детали задачи</span>
                    <span x-show="viewMode === 'reviewed'" x-text="'Показаны проверенные за последние ' + historyDays + ' дней. Двойной клик — детали.'"></span>
                </p>
            </div>

            {{-- Переключатель вкладок --}}
            <div class="flex items-center bg-slate-100 rounded-lg p-1 gap-1 flex-shrink-0">
                <button @click="viewMode = 'review'"
                        :class="viewMode === 'review' ? 'bg-white shadow-sm text-indigo-600' : 'text-slate-500 hover:text-slate-700'"
                        class="px-3 py-1.5 rounded-md text-sm font-medium transition-all whitespace-nowrap">
                    На проверке
                    <span x-show="items.length > 0" class="text-xs text-slate-400" x-text="'(' + items.length + ')'"></span>
                </button>
                <button @click="viewMode = 'reviewed'"
                        :class="viewMode === 'reviewed' ? 'bg-white shadow-sm text-indigo-600' : 'text-slate-500 hover:text-slate-700'"
                        class="px-3 py-1.5 rounded-md text-sm font-medium transition-all whitespace-nowrap">
                    Проверенные
                    <span x-show="reviewedItems.length > 0" class="text-xs text-slate-400" x-text="'(' + reviewedItems.length + ')'"></span>
                </button>
            </div>
        </div>

        {{-- ===== ВКЛАДКА «НА ПРОВЕРКЕ» ===== --}}
        <div x-show="viewMode === 'review'">
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
                                <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">
                                    <button type="button" @click="toggleSort()"
                                            class="group inline-flex items-center gap-1 uppercase tracking-wider hover:text-slate-700 transition-colors"
                                            title="Сортировать по дате отправки">
                                        Отправлено
                                        <span class="inline-flex flex-col leading-[0]">
                                            <svg class="w-2 h-2" :class="sortDir === 'asc' ? 'text-indigo-600' : 'text-slate-300 group-hover:text-slate-400'" viewBox="0 0 8 8" fill="currentColor"><path d="M4 0l4 5H0z"/></svg>
                                            <svg class="w-2 h-2 mt-0.5" :class="sortDir === 'desc' ? 'text-indigo-600' : 'text-slate-300 group-hover:text-slate-400'" viewBox="0 0 8 8" fill="currentColor"><path d="M4 8L0 3h8z"/></svg>
                                        </span>
                                    </button>
                                </th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Срок проверки</th>
                                <th class="px-4 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Действия</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template x-for="(item, idx) in items" :key="item.id">
                                <tr class="hover:bg-slate-50/50 cursor-pointer" @dblclick="openDetail(item, true)">
                                    <td class="px-4 py-3.5 text-sm text-slate-800" x-text="item.employee_name"></td>
                                    <td class="px-4 py-3.5 text-sm text-slate-600" x-text="item.client_name"></td>
                                    <td class="px-4 py-3.5 text-sm text-slate-600" x-text="item.service_name"></td>
                                    <td class="px-4 py-3.5 text-sm font-mono text-slate-700" x-text="formatTime(item.elapsed_seconds)"></td>
                                    <td class="px-4 py-3.5 text-sm text-slate-500" x-text="item.submitted_at"></td>
                                    <td class="px-4 py-3.5 whitespace-nowrap">
                                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium"
                                              :class="reviewStatus(item).cls">
                                            <span class="w-1.5 h-1.5 rounded-full" :class="reviewStatus(item).dot"></span>
                                            <span x-text="reviewStatus(item).label"></span>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3.5 text-right whitespace-nowrap" @dblclick.stop>
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

        {{-- ===== ВКЛАДКА «ПРОВЕРЕННЫЕ» (история за 30 дней, read-only) ===== --}}
        <div x-show="viewMode === 'reviewed'">
            <template x-if="reviewedItems.length === 0">
                <div class="px-5 py-10 text-center text-slate-400" x-text="'За последние ' + historyDays + ' дней нет проверенных задач'"></div>
            </template>

            <template x-if="reviewedItems.length > 0">
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-slate-200">
                        <thead class="bg-slate-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Сотрудник</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Компания</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">БП</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Затрачено</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Проверил</th>
                                <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Когда</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                            <template x-for="item in reviewedItems" :key="item.id">
                                <tr class="hover:bg-slate-50/50 cursor-pointer" @dblclick="openDetail(item, false)">
                                    <td class="px-4 py-3.5 text-sm text-slate-800" x-text="item.employee_name"></td>
                                    <td class="px-4 py-3.5 text-sm text-slate-600" x-text="item.client_name"></td>
                                    <td class="px-4 py-3.5 text-sm text-slate-600" x-text="item.service_name"></td>
                                    <td class="px-4 py-3.5 text-sm font-mono text-slate-700" x-text="formatTime(item.elapsed_seconds)"></td>
                                    <td class="px-4 py-3.5 text-sm">
                                        <span class="inline-flex items-center gap-1.5 text-emerald-700">
                                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                            <span x-text="item.reviewed_by_name"></span>
                                        </span>
                                    </td>
                                    <td class="px-4 py-3.5 text-sm text-slate-500" x-text="item.reviewed_at"></td>
                                </tr>
                            </template>
                        </tbody>
                    </table>
                </div>
            </template>
        </div>
    </div>

    {{-- Попап деталей задачи (двойной клик по строке) --}}
    <div x-show="detailItem !== null" x-cloak
         class="fixed inset-0 z-50 flex items-center justify-center bg-slate-900/40 px-4"
         @click.self="closeDetail()"
         @keydown.escape.window="closeDetail()">
        <template x-if="detailItem !== null">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg p-6 max-h-[85vh] overflow-y-auto">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h3 class="text-base font-semibold text-slate-800" x-text="detailItem.service_name"></h3>
                        <p class="text-sm text-slate-500 mt-0.5">
                            <span x-text="detailItem.client_name"></span>
                            <span class="text-slate-300"> · </span>
                            <span x-text="detailItem.employee_name"></span>
                        </p>
                    </div>
                    <button @click="closeDetail()" class="text-slate-300 hover:text-slate-500 transition-colors flex-shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                {{-- Плашка «проверено» (для вкладки Проверенные) --}}
                <template x-if="!detailActionable && detailItem.reviewed_by_name">
                    <div class="mt-4 flex items-center gap-2 bg-emerald-50 border border-emerald-100 rounded-xl px-4 py-2.5 text-sm text-emerald-700">
                        <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                        <span>Проверено · <span class="font-medium" x-text="detailItem.reviewed_by_name"></span> · <span x-text="detailItem.reviewed_at"></span></span>
                    </div>
                </template>

                {{-- Затрачено + отправлено --}}
                <div class="mt-4 flex items-center gap-6 bg-slate-50 rounded-xl px-4 py-3">
                    <div>
                        <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-0.5">Затрачено</p>
                        <p class="font-mono text-base font-semibold text-slate-700" x-text="formatTime(detailItem.elapsed_seconds)"></p>
                    </div>
                    <div>
                        <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-0.5">Отправлено</p>
                        <p class="text-sm text-slate-600" x-text="detailItem.submitted_at"></p>
                    </div>
                    <template x-if="detailItem.periodicity">
                        <div>
                            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-0.5">Периодичность</p>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600" x-text="detailItem.periodicity"></span>
                        </div>
                    </template>
                </div>

                <div class="mt-4 space-y-3">
                    <template x-if="detailItem.description">
                        <div>
                            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-0.5">Описание</p>
                            <p class="text-sm text-slate-600 whitespace-pre-line" x-text="detailItem.description"></p>
                        </div>
                    </template>
                    <template x-if="detailItem.comment">
                        <div>
                            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-0.5">Комментарий</p>
                            <p class="text-sm text-slate-600 whitespace-pre-line" x-text="detailItem.comment"></p>
                        </div>
                    </template>
                    <template x-if="!detailItem.description && !detailItem.comment">
                        <p class="text-sm text-slate-400 italic">Описание и комментарий не заполнены</p>
                    </template>
                </div>

                {{-- Количество (план / факт) --}}
                <template x-if="detailItem.allows_quantity">
                    <div class="mt-4 pt-4 border-t border-slate-100 flex items-center gap-8">
                        <div>
                            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-0.5">План</p>
                            <p class="text-sm font-medium text-slate-800" x-text="detailItem.quantity"></p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-0.5">Факт</p>
                            <p class="text-sm font-medium text-slate-800" x-text="detailItem.actual_quantity ?? '—'"></p>
                        </div>
                    </div>
                </template>

                {{-- Документ --}}
                <template x-if="detailItem.requires_document">
                    <div class="mt-4 pt-4 border-t border-slate-100">
                        <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5">Прикреплённый документ</p>
                        <template x-if="detailItem.document_name">
                            <a :href="'/storage/' + detailItem.document_path" target="_blank"
                               class="inline-flex items-center gap-1.5 text-sm text-indigo-600 hover:text-indigo-800 underline truncate max-w-[320px]">
                                <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                <span x-text="detailItem.document_name"></span>
                            </a>
                        </template>
                        <template x-if="!detailItem.document_name">
                            <span class="text-sm text-slate-400 italic">Документ не прикреплён</span>
                        </template>
                    </div>
                </template>

                {{-- Подпункты (read-only) --}}
                <template x-if="detailItem.children && detailItem.children.length > 0">
                    <div class="mt-5 pt-4 border-t border-slate-100">
                        <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Подпункты</p>
                        <div class="space-y-1.5">
                            <template x-for="child in detailItem.children" :key="child.id">
                                <div class="py-1">
                                    <div class="flex items-center gap-2.5">
                                        {{-- Статус-иконка --}}
                                        <span class="flex-shrink-0 w-4 h-4 inline-flex items-center justify-center rounded-full"
                                              :class="child.status === 'completed' ? 'bg-emerald-100 text-emerald-600' : (child.status === 'review' ? 'bg-sky-100 text-sky-600' : (child.status === 'rework' ? 'bg-rose-100 text-rose-600' : 'border border-slate-300'))">
                                            <svg x-show="child.status === 'completed'" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                        </span>
                                        <span class="text-sm flex-1"
                                              :class="child.status === 'completed' ? 'line-through text-slate-400' : 'text-slate-700'"
                                              x-text="child.name"></span>
                                        <span x-show="child.status === 'review'" class="text-xs text-sky-600 font-medium">на проверке</span>
                                        <span x-show="child.status === 'rework'" class="text-xs text-rose-600 font-medium">на доработку</span>
                                        <span x-show="child.allows_quantity" class="text-xs text-slate-400 whitespace-nowrap"
                                              x-text="'факт: ' + (child.actual_quantity ?? '—') + ' / ' + child.quantity"></span>
                                    </div>
                                    {{-- Документ подпункта --}}
                                    <template x-if="child.requires_document">
                                        <div class="flex items-center gap-2 mt-1 text-xs" style="margin-left:1.625rem">
                                            <template x-if="child.document_name">
                                                <a :href="'/storage/' + child.document_path" target="_blank"
                                                   class="inline-flex items-center gap-1 text-indigo-600 hover:text-indigo-800 underline truncate max-w-[260px]">
                                                    <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                                    <span x-text="child.document_name"></span>
                                                </a>
                                            </template>
                                            <template x-if="!child.document_name">
                                                <span class="text-slate-400 italic">Документ не прикреплён</span>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                {{-- Действия проверки прямо из попапа — только для вкладки «На проверке» --}}
                <template x-if="detailActionable">
                    <div class="mt-6 pt-4 border-t border-slate-100 flex items-center justify-end gap-2">
                        <button @click.prevent="rejectFromDetail()"
                                :disabled="detailItem.loading"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-rose-50 text-rose-600 border border-rose-200 text-xs font-medium rounded-lg hover:bg-rose-100 disabled:opacity-50 transition-colors">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            На доработку
                        </button>
                        <button @click.prevent="approveFromDetail()"
                                :disabled="detailItem.loading"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600 text-white text-xs font-medium rounded-lg hover:bg-emerald-700 disabled:opacity-50 transition-colors">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                            Проверено
                        </button>
                    </div>
                </template>
            </div>
        </template>
    </div>

    {{-- Модал отказа с комментарием --}}
    <div x-show="rejectIdx !== null" x-cloak class="fixed inset-0 z-[60] flex items-center justify-center bg-slate-900/40 px-4" @click.self="closeReject()">
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
function reviewList(initial, reviewedInitial, slaDays, historyDays) {
    return {
        items: initial.map((i, idx) => ({ ...i, loading: false, _seq: idx })),
        reviewedItems: reviewedInitial || [],
        slaDays: slaDays,
        historyDays: historyDays,
        viewMode: 'review',
        sortDir: null, // null = исходный порядок (по сроку) | 'desc' новее сначала | 'asc' старее сначала
        detailItem: null,
        detailActionable: false,
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

        plural(n, one, few, many) {
            const m10 = n % 10, m100 = n % 100;
            if (m10 === 1 && m100 !== 11) return one;
            if (m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20)) return few;
            return many;
        },

        // Сколько календарных дней осталось до конца срока проверки (отриц. = просрочено)
        daysLeft(item) {
            if (!item.review_started_date) return null;
            const deadline = new Date(item.review_started_date + 'T00:00:00');
            deadline.setDate(deadline.getDate() + this.slaDays);
            const today = new Date(); today.setHours(0, 0, 0, 0);
            return Math.round((deadline - today) / 86400000);
        },

        // Бейдж статуса срока: цвет + текст
        reviewStatus(item) {
            const d = this.daysLeft(item);
            if (d === null) {
                return { label: '—', cls: 'bg-slate-100 text-slate-500', dot: 'bg-slate-300' };
            }
            if (d < 0) {
                const n = Math.abs(d);
                return {
                    label: 'Просрочено на ' + n + ' ' + this.plural(n, 'день', 'дня', 'дней'),
                    cls: 'bg-red-100 text-red-700', dot: 'bg-red-500',
                };
            }
            if (d === 0) {
                return { label: 'Истекает сегодня', cls: 'bg-orange-100 text-orange-700', dot: 'bg-orange-500' };
            }
            if (d === 1) {
                return { label: 'Остался 1 день', cls: 'bg-amber-100 text-amber-700', dot: 'bg-amber-500' };
            }
            return {
                label: 'Осталось ' + d + ' ' + this.plural(d, 'день', 'дня', 'дней'),
                cls: 'bg-emerald-50 text-emerald-700', dot: 'bg-emerald-500',
            };
        },

        // Сортировка по дате отправки: клик переключает «новее → старее → исходный порядок»
        toggleSort() {
            this.sortDir = this.sortDir === null ? 'desc' : (this.sortDir === 'desc' ? 'asc' : null);
            this.applySort();
        },
        applySort() {
            if (!this.sortDir) {
                this.items = [...this.items].sort((a, b) => a._seq - b._seq);
                return;
            }
            const mult = this.sortDir === 'asc' ? 1 : -1;
            this.items = [...this.items].sort((a, b) => ((a.submitted_ts ?? 0) - (b.submitted_ts ?? 0)) * mult);
        },

        openDetail(item, actionable) {
            this.detailItem = item;
            this.detailActionable = !!actionable;
        },

        closeDetail() {
            this.detailItem = null;
        },

        approveFromDetail() {
            const idx = this.items.findIndex(i => i.id === this.detailItem.id);
            if (idx !== -1) this.approve(idx);
        },

        rejectFromDetail() {
            const idx = this.items.findIndex(i => i.id === this.detailItem.id);
            if (idx === -1) return;
            this.closeDetail();
            this.openReject(idx);
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
                this.detailItem = null;
                // Сразу добавляем в «Проверенные» (наверх) — без перезагрузки страницы
                if (data.item) this.reviewedItems.unshift({ ...data.item, loading: false });
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
                this.detailItem = null;
            } else {
                this.rejectError = data.message || 'Не удалось отправить на доработку';
            }
        },
    };
}
</script>

@endsection
