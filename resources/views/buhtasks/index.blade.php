@extends('layouts.app')

@section('title', 'БухЗадачник')
@section('page-title', 'БухЗадачник')

@section('content')
<div x-data="buhTasks({{ json_encode($tasks) }}, {{ $year }}, {{ $month }}, {{ json_encode($allClients) }}, {{ json_encode($services) }})" x-cloak>

    {{-- Шапка --}}
    <div class="flex items-center justify-between mb-6">
        <div class="flex items-center gap-2 text-sm text-slate-500">
            <span x-text="totalCompleted + ' из ' + tasks.length + ' выполнено'"></span>
            <div class="w-24 h-1.5 bg-slate-100 rounded-full overflow-hidden">
                <div class="h-full bg-emerald-400 rounded-full transition-all"
                     :style="'width:' + totalProgressPct + '%'"></div>
            </div>
        </div>

        <div class="flex items-center gap-3">
            {{-- Кнопка создания задачи --}}
            <button @click="showCreateModal = true"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors shadow-sm">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                Добавить задачу
            </button>

            <div class="flex items-center bg-slate-100 rounded-xl p-1 gap-1">
                <button @click="viewMode = 'list'"
                        :class="viewMode === 'list' ? 'bg-white shadow-sm text-indigo-600' : 'text-slate-500 hover:text-slate-700'"
                        class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-all">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                    Список
                </button>
                <button @click="viewMode = 'checklist'"
                        :class="viewMode === 'checklist' ? 'bg-white shadow-sm text-indigo-600' : 'text-slate-500 hover:text-slate-700'"
                        class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-all">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Чеклист
                </button>
            </div>
        </div>
    </div>

    {{-- Нет задач --}}
    <div x-show="tasks.length === 0"
         class="bg-white rounded-2xl border border-slate-200/50 shadow-sm px-6 py-16 text-center">
        <div class="w-14 h-14 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg class="w-7 h-7 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
        </div>
        <p class="text-slate-500 text-sm">Нет задач. Добавьте внеплановую задачу или убедитесь, что у клиентов заполнены сметы и вы назначены на них.</p>
    </div>

    <div x-show="tasks.length > 0"
         class="bg-white rounded-2xl border border-slate-200/50 shadow-sm overflow-hidden">

        {{-- ===== РЕЖИМ СПИСОК ===== --}}
        <div x-show="viewMode === 'list'" class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="w-6 px-4 py-3"></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Задача</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Компания</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Периодичность</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Стоимость</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider w-24">Время</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Действия</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="(task, idx) in tasks" :key="task.uid">
                        <tr :class="task.status === 'completed' ? 'bg-emerald-50/30' : 'hover:bg-slate-50/50'">

                            {{-- Статус-точка --}}
                            <td class="px-4 py-3.5">
                                <div class="w-2 h-2 rounded-full mx-auto"
                                     :class="{
                                         'bg-slate-300': task.status === 'pending',
                                         'bg-indigo-500 animate-pulse': task.status === 'running',
                                         'bg-amber-400': task.status === 'paused',
                                         'bg-emerald-500': task.status === 'completed',
                                     }"></div>
                            </td>

                            <td class="px-4 py-3.5">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-medium"
                                          :class="task.status === 'completed' ? 'line-through text-slate-400' : 'text-slate-800'"
                                          x-text="task.name"></span>
                                    <span x-show="task.type === 'adhoc'"
                                          class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-700">доп.</span>
                                </div>
                            </td>

                            <td class="px-4 py-3.5">
                                <span class="text-sm text-slate-600" x-text="task.client_name"></span>
                            </td>

                            <td class="px-4 py-3.5">
                                <span x-show="task.periodicity"
                                      class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600"
                                      x-text="task.periodicity"></span>
                                <span x-show="!task.periodicity" class="text-slate-300 text-sm">—</span>
                            </td>

                            <td class="px-4 py-3.5 text-right">
                                <span class="text-sm font-semibold text-slate-700"
                                      x-text="task.cost > 0 ? formatPrice(task.cost) : '—'"></span>
                            </td>

                            <td class="px-4 py-3.5">
                                <span class="font-mono text-sm font-medium"
                                      :class="{
                                          'text-slate-300': task.status === 'pending',
                                          'text-indigo-600': task.status === 'running',
                                          'text-amber-500': task.status === 'paused',
                                          'text-emerald-600': task.status === 'completed',
                                      }"
                                      x-text="formatTime(getElapsed(task))"></span>
                            </td>

                            <td class="px-4 py-3.5 text-right whitespace-nowrap">

                                {{-- Старт (pending) --}}
                                <button x-show="task.status === 'pending'"
                                        @click.prevent="startTask(idx)"
                                        :disabled="task.loading"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-indigo-600 text-white text-xs font-medium rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-colors">
                                    <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" clip-rule="evenodd"/></svg>
                                    Старт
                                </button>

                                {{-- Пауза + Стоп (running) --}}
                                <span x-show="task.status === 'running'" class="inline-flex items-center gap-1.5">
                                    <button @click.prevent="pauseTask(idx)"
                                            :disabled="task.loading"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-amber-50 text-amber-600 border border-amber-200 text-xs font-medium rounded-lg hover:bg-amber-100 disabled:opacity-50 transition-colors">
                                        <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zM7 8a1 1 0 012 0v4a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v4a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                                        Пауза
                                    </button>
                                    <button @click.prevent="completeTask(idx)"
                                            :disabled="task.loading"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600 text-white text-xs font-medium rounded-lg hover:bg-emerald-700 disabled:opacity-50 transition-colors">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                        Стоп
                                    </button>
                                </span>

                                {{-- Продолжить + Стоп (paused) --}}
                                <span x-show="task.status === 'paused'" class="inline-flex items-center gap-1.5">
                                    <button @click.prevent="resumeTask(idx)"
                                            :disabled="task.loading"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-indigo-50 text-indigo-600 border border-indigo-200 text-xs font-medium rounded-lg hover:bg-indigo-100 disabled:opacity-50 transition-colors">
                                        <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" clip-rule="evenodd"/></svg>
                                        Продолжить
                                    </button>
                                    <button @click.prevent="completeTask(idx)"
                                            :disabled="task.loading"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600 text-white text-xs font-medium rounded-lg hover:bg-emerald-700 disabled:opacity-50 transition-colors">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                        Стоп
                                    </button>
                                </span>

                                {{-- Выполнено + Сброс (completed) --}}
                                <span x-show="task.status === 'completed'" class="inline-flex items-center gap-2">
                                    <span class="text-xs text-emerald-600 font-medium flex items-center gap-1">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                        Выполнено
                                    </span>
                                    <button @click.prevent="resetTask(idx)"
                                            :disabled="task.loading"
                                            class="p-1.5 text-slate-300 hover:text-slate-500 transition-colors rounded"
                                            title="Сбросить">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15"/></svg>
                                    </button>
                                </span>

                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

        {{-- ===== РЕЖИМ ЧЕКЛИСТ ===== --}}
        <div x-show="viewMode === 'checklist'" class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="w-10 px-4 py-3"></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Задача</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Компания</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Периодичность</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Стоимость</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="(task, idx) in tasks" :key="task.uid">
                        <tr :class="task.status === 'completed' ? 'bg-emerald-50/30' : 'hover:bg-slate-50/50'"
                            class="cursor-pointer" @click="toggleChecklist(idx)">
                            <td class="px-4 py-3.5" @click.stop>
                                <input type="checkbox"
                                       :checked="task.status === 'completed'"
                                       @change="toggleChecklist(idx)"
                                       :disabled="task.loading"
                                       class="w-4 h-4 text-emerald-600 border-slate-300 rounded focus:ring-emerald-500 cursor-pointer">
                            </td>
                            <td class="px-4 py-3.5">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-medium"
                                          :class="task.status === 'completed' ? 'line-through text-slate-400' : 'text-slate-800'"
                                          x-text="task.name"></span>
                                    <span x-show="task.type === 'adhoc'"
                                          class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-700">доп.</span>
                                </div>
                            </td>
                            <td class="px-4 py-3.5">
                                <span class="text-sm text-slate-600" x-text="task.client_name"></span>
                            </td>
                            <td class="px-4 py-3.5">
                                <span x-show="task.periodicity"
                                      class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600"
                                      x-text="task.periodicity"></span>
                                <span x-show="!task.periodicity" class="text-slate-300 text-sm">—</span>
                            </td>
                            <td class="px-4 py-3.5 text-right">
                                <span class="text-sm font-semibold text-slate-700"
                                      x-text="task.cost > 0 ? formatPrice(task.cost) : '—'"></span>
                            </td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>

    </div>

    {{-- ===== МОДАЛ СОЗДАНИЯ ЗАДАЧИ ===== --}}
    <div x-show="showCreateModal"
         x-transition:enter="ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/40"
         @click.self="showCreateModal = false"
         style="display:none">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 p-6">
            <div class="flex items-center justify-between mb-5">
                <h3 class="text-lg font-semibold text-slate-800">Добавить задачу</h3>
                <button @click="showCreateModal = false" class="text-slate-400 hover:text-slate-600 transition-colors">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>

            {{-- Переключатель режима --}}
            <div class="flex bg-slate-100 rounded-xl p-1 mb-5">
                <button type="button"
                        @click="newTask.mode = 'catalog'"
                        :class="newTask.mode === 'catalog' ? 'bg-white shadow-sm text-indigo-600' : 'text-slate-500 hover:text-slate-700'"
                        class="flex-1 py-2 px-3 rounded-lg text-sm font-medium transition-all">
                    Из каталога БП
                </button>
                <button type="button"
                        @click="newTask.mode = 'custom'"
                        :class="newTask.mode === 'custom' ? 'bg-white shadow-sm text-indigo-600' : 'text-slate-500 hover:text-slate-700'"
                        class="flex-1 py-2 px-3 rounded-lg text-sm font-medium transition-all">
                    Произвольная
                </button>
            </div>

            <div class="space-y-4">
                {{-- Компания --}}
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">Компания</label>
                    <select x-model="newTask.client_id"
                            class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 bg-white">
                        <option value="">Выберите компанию...</option>
                        <template x-for="client in allClients" :key="client.id">
                            <option :value="client.id" x-text="client.name"></option>
                        </template>
                    </select>
                </div>

                {{-- Режим: из каталога --}}
                <template x-if="newTask.mode === 'catalog'">
                    <div>
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Бизнес-процесс</label>
                        <select x-model="newTask.service_id"
                                @change="onServiceChange()"
                                class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 bg-white">
                            <option value="">Выберите услугу...</option>
                            <template x-for="svc in allServices" :key="'root_' + svc.id">
                                <template x-if="svc.children.length === 0">
                                    <option :value="svc.id" x-text="svc.name + (svc.cost > 0 ? ' — ' + formatPrice(svc.cost) : '')"></option>
                                </template>
                                <template x-if="svc.children.length > 0">
                                    <optgroup :label="svc.name">
                                        <template x-for="child in svc.children" :key="'child_' + child.id">
                                            <option :value="child.id" x-text="child.name + (child.cost > 0 ? ' — ' + formatPrice(child.cost) : '')"></option>
                                        </template>
                                    </optgroup>
                                </template>
                            </template>
                        </select>
                        <template x-if="newTask.service_id && newTask.cost > 0">
                            <p class="mt-1.5 text-xs text-slate-500">
                                Стоимость: <span class="font-semibold text-slate-700" x-text="formatPrice(newTask.cost)"></span>
                            </p>
                        </template>
                    </div>
                </template>

                {{-- Режим: произвольная --}}
                <template x-if="newTask.mode === 'custom'">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Название задачи</label>
                            <input type="text"
                                   x-model="newTask.name"
                                   placeholder="Например: Сверка с банком"
                                   class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-sm text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50">
                        </div>
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Стоимость (сом)</label>
                            <input type="number"
                                   x-model="newTask.cost"
                                   placeholder="0"
                                   min="0"
                                   step="0.01"
                                   class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-sm text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50">
                        </div>
                    </div>
                </template>

                <div x-show="createError" class="px-3 py-2 bg-red-50 rounded-lg text-sm text-red-600" x-text="createError"></div>
            </div>

            <div class="flex gap-3 mt-6">
                <button @click="showCreateModal = false"
                        class="flex-1 py-2.5 px-4 border border-slate-200 text-slate-600 text-sm font-medium rounded-xl hover:bg-slate-50 transition-colors">
                    Отмена
                </button>
                <button @click="createTask()"
                        :disabled="creating"
                        class="flex-1 py-2.5 px-4 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 disabled:opacity-60 transition-colors flex items-center justify-center gap-2">
                    <svg x-show="creating" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                    <span x-text="creating ? 'Создание...' : 'Добавить в смету'"></span>
                </button>
            </div>
        </div>
    </div>

</div>

<script>
function buhTasks(initialTasks, year, month, allClients, allServices) {
    return {
        tasks: initialTasks.map(t => ({ ...t, loading: false, client_resumed_at: null })),
        year,
        month,
        allClients,
        allServices,
        viewMode: 'list',
        ticker: null,
        now: Math.floor(Date.now() / 1000),

        showCreateModal: false,
        newTask: { mode: 'catalog', client_id: '', service_id: '', name: '', cost: '' },
        creating: false,
        createError: '',

        get totalCompleted() {
            return this.tasks.filter(t => t.status === 'completed').length;
        },

        get totalProgressPct() {
            if (!this.tasks.length) return 0;
            return Math.round(this.totalCompleted / this.tasks.length * 100);
        },

        init() {
            const clientNow = Math.floor(Date.now() / 1000);
            this.tasks = this.tasks.map(t =>
                t.status === 'running' ? { ...t, client_resumed_at: clientNow } : t
            );
            this.ticker = setInterval(() => { this.now = Math.floor(Date.now() / 1000); }, 1000);
        },

        destroy() {
            clearInterval(this.ticker);
        },

        getElapsed(task) {
            if (task.status === 'running' && task.client_resumed_at != null) {
                return task.elapsed_seconds + Math.max(0, this.now - task.client_resumed_at);
            }
            return task.elapsed_seconds ?? 0;
        },

        formatTime(seconds) {
            if (!seconds) return '00:00:00';
            const h = Math.floor(seconds / 3600);
            const m = Math.floor((seconds % 3600) / 60);
            const s = seconds % 60;
            return String(h).padStart(2,'0') + ':' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
        },

        formatPrice(price) {
            return new Intl.NumberFormat('ru-RU').format(Math.round(price || 0)) + ' сом';
        },

        csrf() {
            return document.querySelector('meta[name="csrf-token"]').content;
        },

        async post(url) {
            const r = await fetch(url, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' },
            });
            return r.json();
        },

        // Применяет результат (работает для обоих типов задач)
        applyResult(idx, log) {
            const task = this.tasks[idx];
            this.tasks[idx] = {
                ...task,
                loading:           false,
                log_id:            task.type === 'planned' ? log.id : task.log_id,
                status:            log.status,
                elapsed_seconds:   log.elapsed_seconds,
                client_resumed_at: log.status === 'running' ? this.now : null,
            };
        },

        // Возвращает URL для действия в зависимости от типа задачи
        actionUrl(task, action) {
            if (task.type === 'adhoc') {
                return `/buhtasks/adhoc/${task.adhoc_id}/${action}`;
            }
            return `/buhtasks/logs/${task.log_id}/${action}`;
        },

        async ensureLog(idx) {
            const task = this.tasks[idx];
            if (task.log_id) return task.log_id;

            const r = await fetch('/buhtasks/logs', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-TOKEN': this.csrf(),
                    'Accept': 'application/json',
                },
                body: JSON.stringify({
                    client_id:        task.client_id,
                    estimate_item_id: task.item_id,
                    year:             this.year,
                    month:            this.month,
                }),
            });
            const data = await r.json();
            if (data.success) {
                this.tasks[idx] = { ...this.tasks[idx], log_id: data.log.id };
                return data.log.id;
            }
            return null;
        },

        async startTask(idx) {
            const task = this.tasks[idx];
            this.tasks[idx] = { ...task, loading: true };

            if (task.type === 'planned') {
                const logId = await this.ensureLog(idx);
                if (!logId) { this.tasks[idx] = { ...this.tasks[idx], loading: false }; return; }
            }

            const data = await this.post(this.actionUrl(this.tasks[idx], 'start'));
            if (data.success) this.applyResult(idx, data.log);
            else this.tasks[idx] = { ...this.tasks[idx], loading: false };
        },

        async resumeTask(idx) {
            this.tasks[idx] = { ...this.tasks[idx], loading: true };
            const data = await this.post(this.actionUrl(this.tasks[idx], 'start'));
            if (data.success) this.applyResult(idx, data.log);
            else this.tasks[idx] = { ...this.tasks[idx], loading: false };
        },

        async pauseTask(idx) {
            this.tasks[idx] = { ...this.tasks[idx], loading: true };
            const data = await this.post(this.actionUrl(this.tasks[idx], 'pause'));
            if (data.success) this.applyResult(idx, data.log);
            else this.tasks[idx] = { ...this.tasks[idx], loading: false };
        },

        async completeTask(idx) {
            this.tasks[idx] = { ...this.tasks[idx], loading: true };

            if (this.tasks[idx].type === 'planned') {
                const logId = await this.ensureLog(idx);
                if (!logId) { this.tasks[idx] = { ...this.tasks[idx], loading: false }; return; }
            }

            const data = await this.post(this.actionUrl(this.tasks[idx], 'complete'));
            if (data.success) this.applyResult(idx, data.log);
            else this.tasks[idx] = { ...this.tasks[idx], loading: false };
        },

        async resetTask(idx) {
            this.tasks[idx] = { ...this.tasks[idx], loading: true };
            const data = await this.post(this.actionUrl(this.tasks[idx], 'reset'));
            if (data.success) this.applyResult(idx, data.log);
            else this.tasks[idx] = { ...this.tasks[idx], loading: false };
        },

        async toggleChecklist(idx) {
            const task = this.tasks[idx];
            if (task.loading) return;
            if (task.status === 'completed') {
                await this.resetTask(idx);
            } else {
                this.tasks[idx] = { ...this.tasks[idx], loading: true };

                if (task.type === 'planned') {
                    const logId = await this.ensureLog(idx);
                    if (!logId) { this.tasks[idx] = { ...this.tasks[idx], loading: false }; return; }
                    if (this.tasks[idx].status === 'pending') {
                        await this.post(`/buhtasks/logs/${logId}/start`);
                    }
                    const data = await this.post(`/buhtasks/logs/${logId}/complete`);
                    if (data.success) this.applyResult(idx, data.log);
                    else this.tasks[idx] = { ...this.tasks[idx], loading: false };
                } else {
                    if (this.tasks[idx].status === 'pending') {
                        await this.post(this.actionUrl(this.tasks[idx], 'start'));
                    }
                    const data = await this.post(this.actionUrl(this.tasks[idx], 'complete'));
                    if (data.success) this.applyResult(idx, data.log);
                    else this.tasks[idx] = { ...this.tasks[idx], loading: false };
                }
            }
        },

        onServiceChange() {
            if (!this.newTask.service_id) { this.newTask.cost = ''; return; }
            const id = parseInt(this.newTask.service_id);
            for (const svc of this.allServices) {
                if (svc.id === id) { this.newTask.cost = svc.cost; return; }
                const child = svc.children.find(c => c.id === id);
                if (child) { this.newTask.cost = child.cost; return; }
            }
        },

        async createTask() {
            this.createError = '';

            if (!this.newTask.client_id) { this.createError = 'Выберите компанию'; return; }

            if (this.newTask.mode === 'catalog') {
                if (!this.newTask.service_id) { this.createError = 'Выберите бизнес-процесс'; return; }
            } else {
                if (!this.newTask.name.trim()) { this.createError = 'Введите название задачи'; return; }
                if (this.newTask.cost === '' || this.newTask.cost < 0) { this.createError = 'Укажите стоимость'; return; }
            }

            this.creating = true;

            try {
                const body = { client_id: this.newTask.client_id };
                if (this.newTask.mode === 'catalog') {
                    body.service_id = this.newTask.service_id;
                } else {
                    body.name = this.newTask.name.trim();
                    body.cost = parseFloat(this.newTask.cost) || 0;
                }

                const r = await fetch('/buhtasks/extra', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': this.csrf(),
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(body),
                });
                const data = await r.json();

                if (data.success) {
                    this.tasks.push(data.task);
                    this.newTask = { mode: this.newTask.mode, client_id: '', service_id: '', name: '', cost: '' };
                    this.showCreateModal = false;
                } else {
                    this.createError = data.message || 'Ошибка создания задачи';
                }
            } catch (e) {
                this.createError = 'Ошибка: ' + e.message;
            }

            this.creating = false;
        },
    };
}
</script>
@endsection
