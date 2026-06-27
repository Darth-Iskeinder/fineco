@extends('layouts.app')

@section('title', 'БухЗадачник')
@section('page-title', 'БухЗадачник')

@section('content')

{{-- Сроки по клиентам: агенда по срочности (выход воркера напоминаний) --}}
<div x-data="taskReminders({{ json_encode($reminders) }}, {{ json_encode($reminderCounts) }})" x-cloak class="mb-6">
    <template x-if="items.length > 0">
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
            {{-- Компактная шапка: всегда видна, по клику раскрывает список --}}
            <button type="button" @click="toggle()"
                    class="w-full px-5 py-3 flex items-center gap-2.5 text-left hover:bg-slate-50/60 transition-colors">
                <svg class="w-5 h-5 text-indigo-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                <h2 class="text-sm font-bold text-slate-900 flex-shrink-0">Сроки по клиентам</h2>

                {{-- Счётчики по срочности (показываем только ненулевые) --}}
                <div class="flex items-center gap-1.5 flex-wrap">
                    <span x-show="counts.overdue > 0" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-red-100 text-red-700" x-text="'Просрочено ' + counts.overdue"></span>
                    <span x-show="counts.today > 0" class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-semibold bg-orange-100 text-orange-700" x-text="'Сегодня ' + counts.today"></span>
                </div>

                <span class="ml-auto flex items-center gap-1.5 text-xs text-slate-400 flex-shrink-0">
                    <span x-text="open ? 'скрыть' : 'показать'"></span>
                    <svg class="w-4 h-4 transition-transform" :class="open ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                </span>
            </button>

            {{-- Раскрывающийся список: агенда по срочности, с ограничением высоты --}}
            <div x-show="open"
                 x-transition:enter="transition ease-out duration-150"
                 x-transition:enter-start="opacity-0" x-transition:enter-end="opacity-100"
                 class="border-t border-slate-100">
                <div class="max-h-[22rem] overflow-y-auto divide-y divide-slate-100">
                    <template x-for="group in groups" :key="group.key">
                        <template x-if="group.items.length > 0">
                            <div>
                                <div class="px-6 py-2 text-xs font-semibold uppercase tracking-wider flex items-center gap-2" :class="group.headClass">
                                    <span x-text="group.label"></span>
                                    <span class="opacity-70" x-text="'(' + (group.key === 'overdue' ? counts.overdue : counts.today) + ')'"></span>
                                </div>

                                <div class="divide-y divide-slate-50">
                                    <template x-for="(r, i) in group.items" :key="group.key + '|' + i">
                                        <div class="flex items-center gap-3 px-6 py-3" :class="group.rowClass">
                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm font-medium text-slate-800 truncate" :title="r.name + (r.branch_label ? ' — ' + r.branch_label : '')">
                                                    <span x-text="r.name"></span>
                                                    <span x-show="r.branch_label" class="text-purple-600 font-normal" x-text="r.branch_label ? '· ' + r.branch_label : ''"></span>
                                                </p>
                                                <p class="text-xs text-slate-500 truncate" x-text="r.client_name"></p>
                                            </div>
                                            <div class="text-right flex-shrink-0">
                                                <p class="text-sm font-semibold" :class="group.dateClass" x-text="fmtDate(r.due_date)"></p>
                                                <p class="text-xs" :class="group.dateClass" x-text="relLabel(r)"></p>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </template>
                </div>
                <div x-show="(counts.overdue + counts.today) > items.length"
                     class="px-6 py-2.5 text-xs text-slate-400 border-t border-slate-100 bg-slate-50/40">
                    Показаны первые <span x-text="items.length"></span> из <span x-text="counts.overdue + counts.today"></span> — остальные закрывайте в таблице задач ниже
                </div>
            </div>

        </div>
    </template>
</div>

<div x-data="buhTasks({{ json_encode($tasks) }}, {{ $year }}, {{ $month }}, {{ json_encode($allClients) }}, {{ json_encode($services) }}, {{ json_encode($completed) }}, {{ json_encode($employees) }}, {{ $employee->id }})" x-cloak>

    {{-- Шапка --}}
    <div class="flex items-center justify-between mb-2">
        <div class="flex items-center gap-2 text-sm text-slate-500">
            <span x-text="totalCompleted + ' из ' + tasks.length + ' выполнено'"></span>
            <div class="w-24 h-1.5 bg-slate-100 rounded-full overflow-hidden">
                <div class="h-full bg-emerald-400 rounded-full transition-all"
                     :style="'width:' + totalProgressPct + '%'"></div>
            </div>
        </div>

        <div class="flex items-center gap-3 flex-wrap">
            {{-- Фильтр по компаниям --}}
            <select x-show="clientOptions.length > 1" x-model="clientFilter"
                    class="px-3 py-2 border border-slate-200 rounded-xl text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                <option value="all" x-text="'Все компании (' + tasks.length + ')'"></option>
                <template x-for="c in clientOptions" :key="c.id">
                    <option :value="String(c.id)" x-text="c.name + ' (' + c.count + ')'"></option>
                </template>
            </select>

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
                <button @click="viewMode = 'completed'"
                        :class="viewMode === 'completed' ? 'bg-white shadow-sm text-indigo-600' : 'text-slate-500 hover:text-slate-700'"
                        class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-all">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                    Выполненные
                    <span x-show="completed.length > 0" class="text-xs text-slate-400" x-text="'(' + completed.length + ')'"></span>
                </button>
            </div>
        </div>
    </div>

    {{-- Подсказка про горизонт показа --}}
    <div class="flex items-center gap-1.5 mb-6 text-xs text-slate-400">
        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <span>Показаны задачи текущего месяца и все просроченные. Выполненные исчезают из списка на следующий день — их история во вкладке «Выполненные».</span>
    </div>

    {{-- Нет задач --}}
    <div x-show="viewMode !== 'completed' && tasks.length === 0"
         class="bg-white rounded-2xl border border-slate-200/50 shadow-sm px-6 py-16 text-center">
        <div class="w-14 h-14 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg class="w-7 h-7 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
        </div>
        <p class="text-slate-500 text-sm">Нет задач. Добавьте внеплановую задачу или убедитесь, что у клиентов заполнены сметы и вы назначены на них.</p>
    </div>

    {{-- Все задачи скрыты фильтром --}}
    <div x-show="viewMode !== 'completed' && tasks.length > 0 && visibleCount === 0"
         class="bg-white rounded-2xl border border-slate-200/50 shadow-sm px-6 py-10 text-center text-sm text-slate-400">
        Нет задач по выбранной компании.
    </div>

    <div x-show="viewMode === 'completed' || (tasks.length > 0 && visibleCount > 0)"
         class="bg-white rounded-2xl border border-slate-200/50 shadow-sm overflow-hidden">

        {{-- ===== РЕЖИМ СПИСОК ===== --}}
        <div x-show="viewMode === 'list'" class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="w-6 px-4 py-3"></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Задача</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Компания</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">
                            <button type="button" @click="toggleSort()"
                                    class="group inline-flex items-center gap-1 uppercase tracking-wider hover:text-slate-700 transition-colors"
                                    title="Сортировать по сроку">
                                Периодичность
                                <span class="inline-flex flex-col leading-[0]">
                                    <svg class="w-2 h-2" :class="sortDir === 'asc' ? 'text-indigo-600' : 'text-slate-300 group-hover:text-slate-400'" viewBox="0 0 8 8" fill="currentColor"><path d="M4 0l4 5H0z"/></svg>
                                    <svg class="w-2 h-2 mt-0.5" :class="sortDir === 'desc' ? 'text-indigo-600' : 'text-slate-300 group-hover:text-slate-400'" viewBox="0 0 8 8" fill="currentColor"><path d="M4 8L0 3h8z"/></svg>
                                </span>
                            </button>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Отчётный период</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Стоимость</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider w-24">Время</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Действия</th>
                    </tr>
                </thead>
                    <template x-for="(task, idx) in tasks" :key="task.uid">
                        <tbody class="divide-y divide-slate-100">
                        <template x-if="visibleSet.has(task.uid)">
                        <tr :class="{
                                'bg-emerald-50/30': task.status === 'completed',
                                'bg-sky-50/40': task.status === 'review',
                                'bg-rose-50/40': task.status === 'rework',
                                'border-l-4 border-l-red-400 bg-red-50/40': task.status !== 'completed' && urgency(task) === 'overdue',
                                'border-l-4 border-l-orange-400 bg-orange-50/30': task.status !== 'completed' && urgency(task) === 'today',
                                'border-l-4 border-l-amber-300 bg-amber-50/20': task.status !== 'completed' && urgency(task) === 'soon',
                                'border-l-4 border-l-violet-400 bg-violet-50/30': task.is_custom && task.status !== 'completed' && !urgency(task),
                                'hover:bg-slate-50/50': task.status !== 'completed' && !urgency(task) && !task.is_custom,
                            }" @dblclick="openTaskModal(idx)">

                            {{-- Статус-точка --}}
                            <td class="px-4 py-3.5">
                                <div class="w-2 h-2 rounded-full mx-auto"
                                     :class="{
                                         'bg-slate-300': task.status === 'pending',
                                         'bg-indigo-500 animate-pulse': task.status === 'running',
                                         'bg-amber-400': task.status === 'paused',
                                         'bg-sky-500': task.status === 'review',
                                         'bg-rose-500': task.status === 'rework',
                                         'bg-emerald-500': task.status === 'completed',
                                     }"></div>
                            </td>

                            <td class="px-4 py-3.5">
                                <div class="flex items-center gap-2">
                                    <span class="text-sm font-medium cursor-pointer"
                                          :class="task.status === 'completed' ? 'line-through text-slate-400' : 'text-slate-800'"
                                          x-text="task.name"></span>
                                    <span x-show="task.branch_label"
                                          class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-700">
                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 6h4"/></svg>
                                        <span x-text="task.branch_label"></span>
                                    </span>
                                    <span x-show="task.type === 'adhoc'"
                                          class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-violet-100 text-violet-700">произвольная</span>
                                    <span x-show="task.status !== 'completed' && urgency(task) === 'overdue'"
                                          class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-red-100 text-red-700">просрочена</span>
                                    <span x-show="task.status !== 'completed' && urgency(task) === 'today'"
                                          class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-700">сегодня</span>
                                    <span x-show="task.status === 'review'"
                                          class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-sky-100 text-sky-700">на проверке</span>
                                    <span x-show="task.status === 'rework'"
                                          class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-rose-100 text-rose-700">на доработку</span>
                                </div>
                            </td>

                            <td class="px-4 py-3.5">
                                <span class="text-sm text-slate-600" x-text="task.client_name || '—'"></span>
                            </td>

                            <td class="px-4 py-3.5">
                                <div class="flex flex-col gap-0.5">
                                    <span x-show="task.periodicity"
                                          class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600 w-fit"
                                          x-text="task.periodicity"></span>
                                    <span x-show="task.due_date"
                                          class="text-xs font-medium w-fit"
                                          :class="{
                                              'text-red-600': urgency(task) === 'overdue',
                                              'text-orange-600': urgency(task) === 'today',
                                              'text-amber-600': urgency(task) === 'soon',
                                              'text-slate-500': !urgency(task),
                                          }"
                                          x-text="'до ' + fmtDue(task.due_date)"></span>
                                    <span x-show="!task.periodicity && !task.due_date" class="text-slate-300 text-sm">—</span>
                                </div>
                            </td>

                            <td class="px-4 py-3.5">
                                <span x-show="task.reporting_period" class="text-sm text-slate-700" x-text="task.reporting_period"></span>
                                <span x-show="!task.reporting_period" class="text-slate-300 text-sm">—</span>
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
                                          'text-sky-600': task.status === 'review',
                                          'text-rose-500': task.status === 'rework',
                                          'text-emerald-600': task.status === 'completed',
                                      }"
                                      x-text="formatTime(getElapsed(task))"></span>
                            </td>

                            <td class="px-4 py-3.5 text-right whitespace-nowrap" @dblclick.stop>

                                {{-- Старт (pending) --}}
                                <button x-show="task.status === 'pending'"
                                        @click.prevent="askStart(idx)"
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

                                {{-- Продолжить + Стоп (rework) --}}
                                <span x-show="task.status === 'rework'" class="inline-flex items-center gap-1.5">
                                    <button @click.prevent="resumeTask(idx)"
                                            :disabled="task.loading"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-rose-50 text-rose-600 border border-rose-200 text-xs font-medium rounded-lg hover:bg-rose-100 disabled:opacity-50 transition-colors">
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

                                {{-- На проверке (review) --}}
                                <span x-show="task.status === 'review'" class="inline-flex items-center">
                                    <span class="text-xs text-sky-600 font-medium flex items-center gap-1">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        На проверке
                                    </span>
                                </span>

                                {{-- Выполнено (completed) --}}
                                <span x-show="task.status === 'completed'" class="inline-flex items-center">
                                    <span class="text-xs text-emerald-600 font-medium flex items-center gap-1">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                        Выполнено
                                    </span>
                                </span>

                            </td>
                        </tr>
                        </template>
                        </tbody>
                    </template>
            </table>

            {{-- Сентинел бесконечной прокрутки: догружает по 20 при приближении --}}
            <div x-ref="loadMore" x-show="visibleLimit < visibleCount"
                 class="flex items-center justify-center gap-2 py-4 text-xs text-slate-400">
                <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                <span x-text="'Показано ' + Math.min(visibleLimit, visibleCount) + ' из ' + visibleCount"></span>
            </div>
        </div>

        {{-- ===== РЕЖИМ ЧЕКЛИСТ (матрица: строки — компании, столбцы — задачи; только для чтения) ===== --}}
        <div x-show="viewMode === 'checklist'">
            {{-- Легенда статусов --}}
            <div class="flex flex-wrap items-center gap-5 px-6 py-3 border-b border-slate-200 bg-slate-50/60 text-xs text-slate-500">
                <span class="inline-flex items-center gap-1.5">
                    <span class="inline-flex items-center justify-center w-5 h-5 rounded-full bg-emerald-100 text-emerald-600">
                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                    </span>
                    выполнено
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span class="inline-block w-2.5 h-2.5 rounded-full bg-sky-500"></span>
                    на проверке
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span class="inline-block w-2.5 h-2.5 rounded-full bg-amber-400"></span>
                    в процессе
                </span>
                <span class="inline-flex items-center gap-1.5">
                    <span class="inline-block w-2.5 h-2.5 rounded-full border border-slate-300"></span>
                    не начато
                </span>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full border-separate border-spacing-0 border-t border-l border-slate-200">
                    <thead>
                        <tr>
                            <th class="sticky left-0 z-20 bg-slate-100 px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider border-r border-b border-slate-200 min-w-[200px] align-bottom">Компания</th>
                            <template x-for="col in checklistData.cols" :key="col.key">
                                <th class="bg-slate-100 px-1.5 pt-3 pb-2 border-r border-b border-slate-200 align-bottom">
                                    <div class="mx-auto" style="writing-mode: vertical-rl; transform: rotate(180deg);">
                                        <span class="text-xs font-semibold text-slate-600 whitespace-nowrap" x-text="col.label" :title="col.label"></span>
                                    </div>
                                </th>
                            </template>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="company in checklistData.companies" :key="company.id">
                            <tr class="group">
                                <td class="sticky left-0 z-10 bg-white group-hover:bg-slate-50 px-4 py-3 text-sm font-medium text-slate-800 border-r border-b border-slate-200 whitespace-nowrap" x-text="company.name"></td>
                                <template x-for="col in checklistData.cols" :key="col.key">
                                    <td class="px-2 py-3 text-center border-r border-b border-slate-200 group-hover:bg-slate-50/50">
                                        <template x-if="(checklistData.cells[company.id] || {})[col.key] === 'done'">
                                            <span class="inline-flex items-center justify-center w-6 h-6 rounded-full bg-emerald-100 text-emerald-600" title="выполнено">
                                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                            </span>
                                        </template>
                                        <template x-if="(checklistData.cells[company.id] || {})[col.key] === 'review'">
                                            <span class="inline-block w-2.5 h-2.5 rounded-full bg-sky-500" title="на проверке"></span>
                                        </template>
                                        <template x-if="(checklistData.cells[company.id] || {})[col.key] === 'progress'">
                                            <span class="inline-block w-2.5 h-2.5 rounded-full bg-amber-400" title="в процессе"></span>
                                        </template>
                                        <template x-if="(checklistData.cells[company.id] || {})[col.key] === 'none'">
                                            <span class="inline-block w-2.5 h-2.5 rounded-full border border-slate-300" title="не начато"></span>
                                        </template>
                                        <template x-if="!checklistData.cells[company.id] || !(col.key in checklistData.cells[company.id])">
                                            <span class="text-slate-200" title="нет такой задачи">·</span>
                                        </template>
                                    </td>
                                </template>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>

        {{-- ===== РЕЖИМ ВЫПОЛНЕННЫЕ (история за 90 дней, только чтение, пагинация по 20) ===== --}}
        <div x-show="viewMode === 'completed'">
            <div class="px-6 py-3 border-b border-slate-100 flex items-center gap-2 text-sm text-slate-500 bg-slate-50/60">
                <svg class="w-4 h-4 flex-shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span>История выполненных задач за последние <span class="font-semibold text-slate-700">{{ $completedDays }} дней</span></span>
                <span x-show="completed.length > 0" class="text-slate-400" x-text="'· всего ' + completed.length"></span>
                <span x-show="completed.length > 0" class="text-slate-400 hidden sm:inline">· двойной клик — детали</span>
            </div>
            <template x-if="completed.length === 0">
                <div class="px-6 py-10 text-center text-sm text-slate-400">За последние {{ $completedDays }} дней нет выполненных задач.</div>
            </template>
            <div class="divide-y divide-slate-100">
                <template x-for="c in completedPageItems" :key="c.id">
                    <div class="flex items-center gap-3 px-6 py-3 cursor-pointer hover:bg-slate-50/60 transition-colors" @dblclick="openCompleted(c)">
                        <span class="flex-shrink-0 w-8 h-8 inline-flex items-center justify-center rounded-full bg-emerald-50 text-emerald-500">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        </span>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-slate-700 truncate" x-text="c.name"></p>
                            <p class="text-xs text-slate-400 truncate" x-text="c.client_name"></p>
                        </div>
                        <span class="text-xs text-slate-400 whitespace-nowrap" x-text="fmtCompleted(c.completed_at)"></span>
                    </div>
                </template>
            </div>

            {{-- Пагинация --}}
            <div x-show="completedTotalPages > 1" class="px-6 py-3 border-t border-slate-100 flex items-center justify-between gap-3">
                <span class="text-xs text-slate-400" x-text="'Страница ' + completedPage + ' из ' + completedTotalPages"></span>
                <div class="flex items-center gap-1">
                    <button type="button" @click="completedPage = Math.max(1, completedPage - 1)"
                            :disabled="completedPage <= 1"
                            class="px-3 py-1.5 rounded-lg text-xs font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 disabled:opacity-40 disabled:hover:bg-transparent transition-colors">
                        Назад
                    </button>
                    <button type="button" @click="completedPage = Math.min(completedTotalPages, completedPage + 1)"
                            :disabled="completedPage >= completedTotalPages"
                            class="px-3 py-1.5 rounded-lg text-xs font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 disabled:opacity-40 disabled:hover:bg-transparent transition-colors">
                        Вперёд
                    </button>
                </div>
            </div>
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
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">
                        Компания
                        <span x-show="newTask.mode === 'custom'" class="text-slate-400 font-normal">(необязательно)</span>
                    </label>
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

                {{-- Режим: произвольная (внеплановая задача сотруднику, не в смете) --}}
                <template x-if="newTask.mode === 'custom'">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-medium text-slate-700 mb-1.5">Название задачи</label>
                            <input type="text"
                                   x-model="newTask.name"
                                   placeholder="Например: Сверка с банком"
                                   class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-sm text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50">
                        </div>
                        <div class="flex gap-3">
                            <div class="flex-1">
                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Сотрудник</label>
                                <select x-model="newTask.employee_id"
                                        class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 bg-white">
                                    <template x-for="emp in employees" :key="emp.id">
                                        <option :value="emp.id" x-text="emp.full_name"></option>
                                    </template>
                                </select>
                            </div>
                            <div class="w-40">
                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Дата</label>
                                <input type="date"
                                       x-model="newTask.due_date"
                                       class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50">
                            </div>
                        </div>
                        <p class="text-xs text-slate-400 flex items-center gap-1">
                            <span class="inline-block w-2 h-2 rounded-full bg-violet-500"></span>
                            Произвольная задача — не попадает в смету, напоминает назначенному сотруднику.
                        </p>
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
                    <span x-text="creating ? 'Создание...' : (newTask.mode === 'custom' ? 'Создать задачу' : 'Добавить в смету')"></span>
                </button>
            </div>
        </div>
    </div>

    {{-- ===== МОДАЛ ПОДТВЕРЖДЕНИЯ СТАРТА ===== --}}
    <div x-show="startConfirm.show"
         x-transition:enter="ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-[60] flex items-center justify-center bg-black/40"
         @click.self="startConfirm = { show: false, idx: null }"
         @keydown.escape.window="startConfirm = { show: false, idx: null }"
         style="display:none">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm mx-4 p-6">
            <div class="flex items-start gap-4">
                <div class="flex-shrink-0 w-11 h-11 rounded-full bg-indigo-100 flex items-center justify-center">
                    <svg class="w-5 h-5 text-indigo-600" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" clip-rule="evenodd"/></svg>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="text-base font-semibold text-slate-800">Начать выполнение задачи?</h3>
                    <p class="mt-1 text-sm text-slate-500">Запустится отсчёт времени по задаче.</p>
                </div>
            </div>
            <div class="flex gap-3 mt-6">
                <button @click="startConfirm = { show: false, idx: null }"
                        class="flex-1 py-2.5 px-4 border border-slate-200 text-slate-600 text-sm font-medium rounded-xl hover:bg-slate-50 transition-colors">
                    Отмена
                </button>
                <button @click="confirmStart()"
                        class="flex-1 py-2.5 px-4 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors">
                    Начать
                </button>
            </div>
        </div>
    </div>

    {{-- ===== МОДАЛ ПРЕДУПРЕЖДЕНИЯ: НУЖЕН ДОКУМЕНТ ===== --}}
    <div x-show="docRequiredModal.show"
         x-transition:enter="ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-[60] flex items-center justify-center bg-black/40"
         @click.self="docRequiredModal = { show: false, taskIdx: null }"
         @keydown.escape.window="docRequiredModal = { show: false, taskIdx: null }"
         style="display:none">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm mx-4 p-6">
            <div class="flex items-start gap-4">
                <div class="flex-shrink-0 w-11 h-11 rounded-full bg-amber-100 flex items-center justify-center">
                    <svg class="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="text-base font-semibold text-slate-800">Нужно прикрепить документ</h3>
                    <p class="mt-1 text-sm text-slate-500">Для этого БП правило закрытия — «с документом». Откройте задачу и прикрепите файл, после этого можно будет завершить.</p>
                </div>
            </div>
            <div class="flex gap-3 mt-6">
                <button @click="docRequiredModal = { show: false, taskIdx: null }"
                        class="flex-1 py-2.5 px-4 border border-slate-200 text-slate-600 text-sm font-medium rounded-xl hover:bg-slate-50 transition-colors">
                    Понятно
                </button>
                <button @click="openTaskModal(docRequiredModal.taskIdx); docRequiredModal = { show: false, taskIdx: null }"
                        class="flex-1 py-2.5 px-4 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors">
                    Открыть задачу
                </button>
            </div>
        </div>
    </div>

    {{-- ===== МОДАЛ ПРЕДУПРЕЖДЕНИЯ: НУЖНО ОТМЕТИТЬ ПОДПУНКТЫ ===== --}}
    <div x-show="checklistRequiredModal.show"
         x-transition:enter="ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-[60] flex items-center justify-center bg-black/40"
         @click.self="checklistRequiredModal = { show: false, taskIdx: null }"
         @keydown.escape.window="checklistRequiredModal = { show: false, taskIdx: null }"
         style="display:none">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm mx-4 p-6">
            <div class="flex items-start gap-4">
                <div class="flex-shrink-0 w-11 h-11 rounded-full bg-amber-100 flex items-center justify-center">
                    <svg class="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="text-base font-semibold text-slate-800">Сначала отметьте подпункты</h3>
                    <p class="mt-1 text-sm text-slate-500">У этой задачи есть подпункты — отметьте все галочки, прежде чем завершить задачу.</p>
                </div>
            </div>
            <div class="flex gap-3 mt-6">
                <button @click="checklistRequiredModal = { show: false, taskIdx: null }"
                        class="flex-1 py-2.5 px-4 border border-slate-200 text-slate-600 text-sm font-medium rounded-xl hover:bg-slate-50 transition-colors">
                    Понятно
                </button>
                <button @click="openTaskModal(checklistRequiredModal.taskIdx); checklistRequiredModal = { show: false, taskIdx: null }"
                        class="flex-1 py-2.5 px-4 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 transition-colors">
                    Открыть подпункты
                </button>
            </div>
        </div>
    </div>

    {{-- ===== МОДАЛ ЗАДАЧИ (описание + чек-лист подпунктов) ===== --}}
    <div x-show="taskModalIdx !== null"
         x-transition:enter="ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4"
         @click.self="closeTaskModal()"
         @keydown.escape.window="closeTaskModal()"
         style="display:none">
        <template x-if="taskModalIdx !== null">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4 p-6 max-h-[85vh] overflow-y-auto">
                <div class="flex items-start justify-between gap-4">
                    <h3 class="text-base font-semibold text-slate-800">
                        <span x-text="tasks[taskModalIdx].name"></span>
                        <span x-show="tasks[taskModalIdx].branch_label"
                              class="ml-1 inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-700 align-middle">
                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 6h4"/></svg>
                            <span x-text="tasks[taskModalIdx].branch_label"></span>
                        </span>
                    </h3>
                    <button @click="closeTaskModal()" class="text-slate-300 hover:text-slate-500 transition-colors flex-shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <p class="text-sm text-slate-500 mt-0.5" x-text="tasks[taskModalIdx].client_name || '—'"></p>

                {{-- Таймер + управление (Старт/Пауза/Стоп) — те же действия, что и в строке таблицы --}}
                <div class="mt-4 flex items-center justify-between gap-3 bg-slate-50 rounded-xl px-4 py-3">
                    <div class="flex items-center gap-2.5">
                        <span class="w-2.5 h-2.5 rounded-full flex-shrink-0"
                              :class="{
                                  'bg-slate-300': tasks[taskModalIdx].status === 'pending',
                                  'bg-indigo-500 animate-pulse': tasks[taskModalIdx].status === 'running',
                                  'bg-amber-400': tasks[taskModalIdx].status === 'paused',
                                  'bg-sky-500': tasks[taskModalIdx].status === 'review',
                                  'bg-rose-500': tasks[taskModalIdx].status === 'rework',
                                  'bg-emerald-500': tasks[taskModalIdx].status === 'completed',
                              }"></span>
                        <span class="font-mono text-lg font-semibold tracking-tight"
                              :class="{
                                  'text-slate-300': tasks[taskModalIdx].status === 'pending',
                                  'text-indigo-600': tasks[taskModalIdx].status === 'running',
                                  'text-amber-500': tasks[taskModalIdx].status === 'paused',
                                  'text-sky-600': tasks[taskModalIdx].status === 'review',
                                  'text-rose-500': tasks[taskModalIdx].status === 'rework',
                                  'text-emerald-600': tasks[taskModalIdx].status === 'completed',
                              }"
                              x-text="formatTime(getElapsed(tasks[taskModalIdx]))"></span>
                    </div>

                    <div class="flex-shrink-0">
                        {{-- Старт (pending) --}}
                        <button x-show="tasks[taskModalIdx].status === 'pending'"
                                @click.prevent="askStart(taskModalIdx)"
                                :disabled="tasks[taskModalIdx].loading"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-indigo-600 text-white text-xs font-medium rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-colors">
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" clip-rule="evenodd"/></svg>
                            Старт
                        </button>

                        {{-- Пауза + Стоп (running) --}}
                        <span x-show="tasks[taskModalIdx].status === 'running'" class="inline-flex items-center gap-1.5">
                            <button @click.prevent="pauseTask(taskModalIdx)"
                                    :disabled="tasks[taskModalIdx].loading"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-amber-50 text-amber-600 border border-amber-200 text-xs font-medium rounded-lg hover:bg-amber-100 disabled:opacity-50 transition-colors">
                                <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M18 10a8 8 0 11-16 0 8 8 0 0116 0zM7 8a1 1 0 012 0v4a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v4a1 1 0 102 0V8a1 1 0 00-1-1z" clip-rule="evenodd"/></svg>
                                Пауза
                            </button>
                            <button @click.prevent="completeTask(taskModalIdx)"
                                    :disabled="tasks[taskModalIdx].loading"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600 text-white text-xs font-medium rounded-lg hover:bg-emerald-700 disabled:opacity-50 transition-colors">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                Стоп
                            </button>
                        </span>

                        {{-- Продолжить + Стоп (paused) --}}
                        <span x-show="tasks[taskModalIdx].status === 'paused'" class="inline-flex items-center gap-1.5">
                            <button @click.prevent="resumeTask(taskModalIdx)"
                                    :disabled="tasks[taskModalIdx].loading"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-indigo-50 text-indigo-600 border border-indigo-200 text-xs font-medium rounded-lg hover:bg-indigo-100 disabled:opacity-50 transition-colors">
                                <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" clip-rule="evenodd"/></svg>
                                Продолжить
                            </button>
                            <button @click.prevent="completeTask(taskModalIdx)"
                                    :disabled="tasks[taskModalIdx].loading"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600 text-white text-xs font-medium rounded-lg hover:bg-emerald-700 disabled:opacity-50 transition-colors">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                Стоп
                            </button>
                        </span>

                        {{-- Продолжить + Стоп (rework) --}}
                        <span x-show="tasks[taskModalIdx].status === 'rework'" class="inline-flex items-center gap-1.5">
                            <button @click.prevent="resumeTask(taskModalIdx)"
                                    :disabled="tasks[taskModalIdx].loading"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-rose-50 text-rose-600 border border-rose-200 text-xs font-medium rounded-lg hover:bg-rose-100 disabled:opacity-50 transition-colors">
                                <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM9.555 7.168A1 1 0 008 8v4a1 1 0 001.555.832l3-2a1 1 0 000-1.664l-3-2z" clip-rule="evenodd"/></svg>
                                Продолжить
                            </button>
                            <button @click.prevent="completeTask(taskModalIdx)"
                                    :disabled="tasks[taskModalIdx].loading"
                                    class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600 text-white text-xs font-medium rounded-lg hover:bg-emerald-700 disabled:opacity-50 transition-colors">
                                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                Стоп
                            </button>
                        </span>

                        {{-- На проверке (review) --}}
                        <span x-show="tasks[taskModalIdx].status === 'review'"
                              class="inline-flex items-center gap-1 text-xs text-sky-600 font-medium">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            На проверке
                        </span>

                        {{-- Выполнено (completed) --}}
                        <span x-show="tasks[taskModalIdx].status === 'completed'"
                              class="inline-flex items-center gap-1 text-xs text-emerald-600 font-medium">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                            Выполнено
                        </span>
                    </div>
                </div>

                <div class="mt-4 space-y-3">
                    <template x-if="tasks[taskModalIdx].description">
                        <div>
                            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-0.5">Описание</p>
                            <p class="text-sm text-slate-600 whitespace-pre-line" x-text="tasks[taskModalIdx].description"></p>
                        </div>
                    </template>
                    <template x-if="tasks[taskModalIdx].comment">
                        <div>
                            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-0.5">Комментарий</p>
                            <p class="text-sm text-slate-600 whitespace-pre-line" x-text="tasks[taskModalIdx].comment"></p>
                        </div>
                    </template>
                    <template x-if="tasks[taskModalIdx].review_comment">
                        <div>
                            <p class="text-xs font-semibold text-rose-500 uppercase tracking-wider mb-0.5">Комментарий проверяющего</p>
                            <p class="text-sm text-rose-700 whitespace-pre-line" x-text="tasks[taskModalIdx].review_comment"></p>
                        </div>
                    </template>
                    <template x-if="!tasks[taskModalIdx].description && !tasks[taskModalIdx].comment && !tasks[taskModalIdx].review_comment">
                        <p class="text-sm text-slate-400 italic">Описание и комментарий не заполнены</p>
                    </template>
                </div>

                <template x-if="tasks[taskModalIdx].allows_quantity">
                    <div class="mt-4 pt-4 border-t border-slate-100 flex items-center justify-between gap-3">
                        <div>
                            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-0.5">Количество</p>
                            <p class="text-sm text-slate-600">План: <span class="font-medium text-slate-800" x-text="tasks[taskModalIdx].quantity"></span></p>
                        </div>
                        <div class="text-right">
                            <label class="text-xs text-slate-400 block mb-0.5">Факт</label>
                            <input type="number" min="0"
                                   :placeholder="tasks[taskModalIdx].quantity"
                                   :value="tasks[taskModalIdx].actual_quantity"
                                   @change="updateRootQuantity(taskModalIdx, $event.target.value)"
                                   class="w-20 px-2 py-1 border border-slate-200 rounded-lg text-sm text-right focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                        </div>
                    </div>
                </template>

                <template x-if="tasks[taskModalIdx].requires_document">
                    <div class="mt-4 pt-4 border-t border-slate-100">
                        <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5">Документ для закрытия</p>
                        <div class="flex items-center gap-2">
                            <template x-if="tasks[taskModalIdx].document_name && !tasks[taskModalIdx].pending_file_name">
                                <a :href="'/storage/' + tasks[taskModalIdx].document_path" target="_blank"
                                   class="inline-flex items-center gap-1.5 text-sm text-indigo-600 hover:text-indigo-800 underline truncate max-w-[220px]">
                                    <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                    <span x-text="tasks[taskModalIdx].document_name"></span>
                                </a>
                            </template>
                            <template x-if="!tasks[taskModalIdx].document_name && !tasks[taskModalIdx].pending_file_name">
                                <span class="text-sm text-slate-400 italic">Не прикреплён</span>
                            </template>
                            <template x-if="tasks[taskModalIdx].pending_file_name">
                                <span class="text-sm text-slate-600 truncate max-w-[220px]" x-text="tasks[taskModalIdx].pending_file_name"></span>
                            </template>
                            <label class="ml-auto inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 text-slate-600 text-xs font-medium rounded-lg hover:bg-slate-200 cursor-pointer transition-colors">
                                <span x-text="tasks[taskModalIdx].document_name ? 'Заменить' : 'Выбрать файл'"></span>
                                <input type="file" class="hidden" @change="selectRootDocument(taskModalIdx, $event)">
                            </label>
                            <template x-if="tasks[taskModalIdx].pending_file_name">
                                <button @click="saveRootDocument(taskModalIdx)"
                                        :disabled="tasks[taskModalIdx].doc_uploading"
                                        class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-indigo-600 text-white text-xs font-medium rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-colors">
                                    <span x-text="tasks[taskModalIdx].doc_uploading ? 'Сохранение...' : 'Сохранить'"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                </template>

                <template x-if="tasks[taskModalIdx].children && tasks[taskModalIdx].children.length > 0">
                    <div class="mt-5 pt-4 border-t border-slate-100">
                        <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Подпункты</p>
                        <div class="space-y-1.5">
                            <template x-for="(child, cidx) in tasks[taskModalIdx].children" :key="child.id">
                                <div class="py-1">
                                    <label class="flex items-center gap-2.5 cursor-pointer"
                                           :class="child.status === 'review' ? 'opacity-60 cursor-not-allowed' : ''">
                                        <input type="checkbox"
                                               :checked="child.status === 'completed'"
                                               :disabled="child.loading || child.status === 'review'"
                                               @change="toggleChild(taskModalIdx, cidx)"
                                               class="w-4 h-4 text-indigo-600 border-slate-300 rounded focus:ring-indigo-500">
                                        <span class="text-sm flex-1"
                                              :class="child.status === 'completed' ? 'line-through text-slate-400' : 'text-slate-700'"
                                              x-text="child.name"></span>
                                        <span x-show="child.status === 'review'" class="text-xs text-sky-600 font-medium">на проверке</span>
                                        <span x-show="child.status === 'rework'" class="text-xs text-rose-600 font-medium">на доработку</span>
                                    </label>
                                    <template x-if="child.allows_quantity">
                                        <div class="flex items-center gap-2 mt-1 ml-6 text-xs text-slate-500">
                                            <span>План: <span class="font-medium text-slate-700" x-text="child.quantity"></span></span>
                                            <span>Факт:</span>
                                            <input type="number" min="0"
                                                   :placeholder="child.quantity"
                                                   :value="child.actual_quantity"
                                                   @change="updateChildQuantity(taskModalIdx, cidx, $event.target.value)"
                                                   class="w-16 px-1.5 py-0.5 border border-slate-200 rounded text-xs text-right focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                        </div>
                                    </template>
                                    <template x-if="child.requires_document">
                                        <div class="flex items-center gap-2 mt-1 ml-6 text-xs">
                                            <template x-if="child.document_name && !child.pending_file_name">
                                                <a :href="'/storage/' + child.document_path" target="_blank"
                                                   class="text-indigo-600 hover:text-indigo-800 underline truncate max-w-[140px]" x-text="child.document_name"></a>
                                            </template>
                                            <template x-if="!child.document_name && !child.pending_file_name">
                                                <span class="text-slate-400 italic">Документ не прикреплён</span>
                                            </template>
                                            <template x-if="child.pending_file_name">
                                                <span class="text-slate-600 truncate max-w-[140px]" x-text="child.pending_file_name"></span>
                                            </template>
                                            <label class="ml-auto inline-flex items-center px-2 py-1 bg-slate-100 text-slate-600 rounded-lg hover:bg-slate-200 cursor-pointer transition-colors">
                                                <span x-text="child.document_name ? 'Заменить' : 'Выбрать файл'"></span>
                                                <input type="file" class="hidden" @change="selectChildDocument(taskModalIdx, cidx, $event)">
                                            </label>
                                            <template x-if="child.pending_file_name">
                                                <button @click="saveChildDocument(taskModalIdx, cidx)"
                                                        :disabled="child.doc_uploading"
                                                        class="inline-flex items-center px-2 py-1 bg-indigo-600 text-white rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-colors">
                                                    <span x-text="child.doc_uploading ? 'Сохранение...' : 'Сохранить'"></span>
                                                </button>
                                            </template>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </template>
    </div>

    {{-- ===== МОДАЛ ВЫПОЛНЕННОЙ ЗАДАЧИ (история, только чтение) ===== --}}
    <div x-show="completedItem !== null"
         x-transition:enter="ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/40 px-4"
         @click.self="completedItem = null"
         @keydown.escape.window="completedItem = null"
         style="display:none">
        <template x-if="completedItem !== null">
            <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4 p-6 max-h-[85vh] overflow-y-auto">
                <div class="flex items-start justify-between gap-4">
                    <div class="min-w-0">
                        <h3 class="text-base font-semibold text-slate-800">
                            <span x-text="completedItem.name"></span>
                            <span x-show="completedItem.branch_label"
                                  class="ml-1 inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-700 align-middle">
                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 6h4"/></svg>
                                <span x-text="completedItem.branch_label"></span>
                            </span>
                        </h3>
                        <p class="text-sm text-slate-500 mt-0.5" x-text="completedItem.client_name"></p>
                    </div>
                    <button @click="completedItem = null" class="text-slate-300 hover:text-slate-500 transition-colors flex-shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                {{-- Плашка «выполнено» --}}
                <div class="mt-4 flex items-center gap-2 bg-emerald-50 border border-emerald-100 rounded-xl px-4 py-2.5 text-sm text-emerald-700">
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                    <span>Выполнено · <span x-text="fmtCompleted(completedItem.completed_at)"></span></span>
                </div>

                {{-- Затрачено + периодичность --}}
                <div class="mt-4 flex items-center gap-6 bg-slate-50 rounded-xl px-4 py-3">
                    <div>
                        <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-0.5">Затрачено</p>
                        <p class="font-mono text-base font-semibold text-slate-700" x-text="formatTime(completedItem.elapsed_seconds)"></p>
                    </div>
                    <template x-if="completedItem.periodicity">
                        <div>
                            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-0.5">Периодичность</p>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600" x-text="completedItem.periodicity"></span>
                        </div>
                    </template>
                </div>

                <div class="mt-4 space-y-3">
                    <template x-if="completedItem.description">
                        <div>
                            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-0.5">Описание</p>
                            <p class="text-sm text-slate-600 whitespace-pre-line" x-text="completedItem.description"></p>
                        </div>
                    </template>
                    <template x-if="completedItem.comment">
                        <div>
                            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-0.5">Комментарий</p>
                            <p class="text-sm text-slate-600 whitespace-pre-line" x-text="completedItem.comment"></p>
                        </div>
                    </template>
                    <template x-if="!completedItem.description && !completedItem.comment">
                        <p class="text-sm text-slate-400 italic">Описание и комментарий не заполнены</p>
                    </template>
                </div>

                {{-- Количество (план / факт) --}}
                <template x-if="completedItem.allows_quantity">
                    <div class="mt-4 pt-4 border-t border-slate-100 flex items-center gap-8">
                        <div>
                            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-0.5">План</p>
                            <p class="text-sm font-medium text-slate-800" x-text="completedItem.quantity"></p>
                        </div>
                        <div>
                            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-0.5">Факт</p>
                            <p class="text-sm font-medium text-slate-800" x-text="completedItem.actual_quantity ?? '—'"></p>
                        </div>
                    </div>
                </template>

                {{-- Документ --}}
                <template x-if="completedItem.requires_document">
                    <div class="mt-4 pt-4 border-t border-slate-100">
                        <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5">Прикреплённый документ</p>
                        <template x-if="completedItem.document_name">
                            <a :href="'/storage/' + completedItem.document_path" target="_blank"
                               class="inline-flex items-center gap-1.5 text-sm text-indigo-600 hover:text-indigo-800 underline truncate max-w-[320px]">
                                <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                <span x-text="completedItem.document_name"></span>
                            </a>
                        </template>
                        <template x-if="!completedItem.document_name">
                            <span class="text-sm text-slate-400 italic">Документ не прикреплён</span>
                        </template>
                    </div>
                </template>

                {{-- Подпункты (read-only) --}}
                <template x-if="completedItem.children && completedItem.children.length > 0">
                    <div class="mt-5 pt-4 border-t border-slate-100">
                        <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-2">Подпункты</p>
                        <div class="space-y-1.5">
                            <template x-for="child in completedItem.children" :key="child.id">
                                <div class="py-1">
                                    <div class="flex items-center gap-2.5">
                                        <span class="flex-shrink-0 w-4 h-4 inline-flex items-center justify-center rounded-full"
                                              :class="child.status === 'completed' ? 'bg-emerald-100 text-emerald-600' : 'border border-slate-300'">
                                            <svg x-show="child.status === 'completed'" class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                        </span>
                                        <span class="text-sm flex-1"
                                              :class="child.status === 'completed' ? 'line-through text-slate-400' : 'text-slate-700'"
                                              x-text="child.name"></span>
                                        <span x-show="child.allows_quantity" class="text-xs text-slate-400 whitespace-nowrap"
                                              x-text="'факт: ' + (child.actual_quantity ?? '—') + ' / ' + child.quantity"></span>
                                    </div>
                                    <template x-if="child.requires_document && child.document_name">
                                        <div class="flex items-center gap-2 mt-1 text-xs" style="margin-left:1.625rem">
                                            <a :href="'/storage/' + child.document_path" target="_blank"
                                               class="inline-flex items-center gap-1 text-indigo-600 hover:text-indigo-800 underline truncate max-w-[260px]">
                                                <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                                <span x-text="child.document_name"></span>
                                            </a>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>
            </div>
        </template>
    </div>

</div>

<script>
function buhTasks(initialTasks, year, month, allClients, allServices, completed, employees, currentEmployeeId) {
    // File-объекты держим вне реактивного state — Alpine оборачивает объекты в Proxy,
    // что ломает внутренние методы File/Blob при передаче в FormData
    const pendingFiles = new Map();
    // Кэш окна видимости вне реактивного state (чтобы геттер не пересобирал Set на каждую строку)
    let visibleCache = { key: null, set: new Set() };

    return {
        tasks: initialTasks.map((t, i) => ({
            ...t,
            loading: false,
            client_resumed_at: null,
            _seq: i,
            doc_uploading: false,
            pending_file_name: null,
            children: (t.children || []).map(c => ({ ...c, doc_uploading: false, pending_file_name: null })),
        })),
        completed: completed || [],
        completedPage: 1,
        completedPerPage: 20,
        completedItem: null,
        year,
        month,
        allClients,
        allServices,
        employees: employees || [],
        currentEmployeeId,
        viewMode: 'list',
        ticker: null,
        now: Math.floor(Date.now() / 1000),
        clientFilter: 'all',
        sortDir: null, // null = исходный порядок | 'asc' | 'desc' (по сроку due_day)
        visibleLimit: 20, // бесконечная прокрутка списка: сколько строк отрисовано (по 20)
        taskModalIdx: null,
        docRequiredModal: { show: false, taskIdx: null },
        checklistRequiredModal: { show: false, taskIdx: null },

        showCreateModal: false,
        startConfirm: { show: false, idx: null },
        newTask: { mode: 'catalog', client_id: '', service_id: '', name: '', cost: '', due_day: '', employee_id: currentEmployeeId, due_date: '' },
        creating: false,
        createError: '',

        // Фильтр по компаниям (idx строк сохраняем — строки прячем через x-show)
        get clientOptions() {
            const map = {};
            this.tasks.forEach(t => {
                const k = t.client_id ?? t.client_name;
                if (!map[k]) map[k] = { id: t.client_id, name: t.client_name, count: 0 };
                map[k].count++;
            });
            return Object.values(map).sort((a, b) => a.name.localeCompare(b.name, 'ru'));
        },
        matchesFilter(task) {
            return this.clientFilter === 'all' || String(task.client_id) === String(this.clientFilter);
        },

        // Матрица вкладки «Чеклист»: строки — компании, столбцы — задачи, ячейка — статус (только чтение).
        // done = выполнено (зелёная галочка), review = на проверке (синий), progress = начато, но не закрыто (жёлтый),
        // none = задача есть, но не начата (пусто). Если у компании нет такой задачи — ячейка отсутствует.
        get checklistData() {
            const companyMap = {};
            const colMap = {};
            const cells = {};
            const rank = { none: 0, done: 1, review: 2, progress: 3 };
            this.tasks.filter(t => this.matchesFilter(t)).forEach(t => {
                const cid = String(t.client_id ?? t.client_name);
                if (!companyMap[cid]) companyMap[cid] = { id: cid, name: t.client_name || '—' };
                // Филиальные задачи — отдельный столбец на каждый НО (name + филиал).
                const key = t.branch_label ? (t.name + ' · ' + t.branch_label) : t.name;
                if (!colMap[key]) colMap[key] = { key, label: key, count: 0 };
                colMap[key].count++;
                let cat;
                if (t.status === 'completed')   cat = 'done';
                else if (t.status === 'pending') cat = 'none';
                else if (t.status === 'review')  cat = 'review';
                else                             cat = 'progress';
                if (!cells[cid]) cells[cid] = {};
                const prev = cells[cid][key];
                cells[cid][key] = (!prev || rank[cat] > rank[prev]) ? cat : prev;
            });
            return {
                companies: Object.values(companyMap).sort((a, b) => a.name.localeCompare(b.name, 'ru')),
                cols: Object.values(colMap).sort((a, b) => b.count - a.count || a.label.localeCompare(b.label, 'ru')),
                cells,
            };
        },
        openTaskModal(idx) {
            this.taskModalIdx = idx;
        },

        closeTaskModal() {
            this.taskModalIdx = null;
        },

        async ensureChildLog(taskIdx, cidx) {
            const task = this.tasks[taskIdx];
            const child = task.children[cidx];
            if (child.log_id) return child.log_id;

            const r = await fetch('/buhtasks/logs', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' },
                body: JSON.stringify({
                    client_id:        task.client_id,
                    estimate_item_id: child.id,
                    year:             task.year,
                    month:            task.month,
                }),
            });
            const data = await r.json();
            if (!data.success) return null;
            task.children[cidx] = { ...task.children[cidx], log_id: data.log.id };
            return data.log.id;
        },

        async toggleChild(taskIdx, cidx) {
            const task = this.tasks[taskIdx];
            const child = task.children[cidx];
            if (child.loading || child.status === 'review') return;

            task.children[cidx] = { ...child, loading: true };

            if (child.status === 'completed') {
                const data = await this.post(`/buhtasks/logs/${child.log_id}/reset`);
                task.children[cidx] = data.success
                    ? { ...task.children[cidx], loading: false, status: data.log.status, review_comment: data.log.review_comment ?? null }
                    : { ...task.children[cidx], loading: false };
                return;
            }

            const logId = await this.ensureChildLog(taskIdx, cidx);
            if (!logId) { task.children[cidx] = { ...task.children[cidx], loading: false }; return; }

            if (task.children[cidx].status === 'pending' || task.children[cidx].status === 'rework') {
                await this.post(`/buhtasks/logs/${logId}/start`);
            }
            const data = await this.post(`/buhtasks/logs/${logId}/complete`);
            task.children[cidx] = data.success
                ? { ...task.children[cidx], loading: false, status: data.log.status, review_comment: data.log.review_comment ?? null }
                : { ...task.children[cidx], loading: false };
            if (!data.success && data.requires_document) {
                this.docRequiredModal = { show: true, taskIdx };
            }
        },

        async updateRootQuantity(taskIdx, rawValue) {
            const task = this.tasks[taskIdx];
            const value = rawValue === '' ? null : Math.max(0, parseInt(rawValue, 10) || 0);

            const logId = await this.ensureLog(taskIdx);
            if (!logId) return;

            const data = await this.post(`/buhtasks/logs/${logId}/quantity`, { actual_quantity: value });
            if (data.success) {
                this.tasks[taskIdx] = { ...this.tasks[taskIdx], actual_quantity: data.log.actual_quantity };
            }
        },

        async updateChildQuantity(taskIdx, cidx, rawValue) {
            const task = this.tasks[taskIdx];
            const value = rawValue === '' ? null : Math.max(0, parseInt(rawValue, 10) || 0);

            const logId = await this.ensureChildLog(taskIdx, cidx);
            if (!logId) return;

            const data = await this.post(`/buhtasks/logs/${logId}/quantity`, { actual_quantity: value });
            if (data.success) {
                task.children[cidx] = { ...task.children[cidx], actual_quantity: data.log.actual_quantity };
            }
        },

        selectRootDocument(taskIdx, event) {
            const file = event.target.files[0];
            if (!file) return;
            pendingFiles.set('root_' + taskIdx, file);
            this.tasks[taskIdx] = { ...this.tasks[taskIdx], pending_file_name: file.name };
            event.target.value = '';
        },

        async saveRootDocument(taskIdx) {
            const file = pendingFiles.get('root_' + taskIdx);
            if (!file) return;

            this.tasks[taskIdx] = { ...this.tasks[taskIdx], doc_uploading: true };

            const logId = await this.ensureLog(taskIdx);
            if (!logId) { this.tasks[taskIdx] = { ...this.tasks[taskIdx], doc_uploading: false }; return; }

            const fd = new FormData();
            fd.append('file', file);
            const r = await fetch(`/buhtasks/logs/${logId}/document`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' },
                body: fd,
            });
            const data = await r.json();
            if (data.success) {
                pendingFiles.delete('root_' + taskIdx);
                this.tasks[taskIdx] = { ...this.tasks[taskIdx], doc_uploading: false, pending_file_name: null, document_name: data.log.document_name, document_path: data.log.document_path };
            } else {
                this.tasks[taskIdx] = { ...this.tasks[taskIdx], doc_uploading: false };
            }
        },

        selectChildDocument(taskIdx, cidx, event) {
            const file = event.target.files[0];
            if (!file) return;
            const task = this.tasks[taskIdx];
            pendingFiles.set('child_' + taskIdx + '_' + cidx, file);
            task.children[cidx] = { ...task.children[cidx], pending_file_name: file.name };
            event.target.value = '';
        },

        async saveChildDocument(taskIdx, cidx) {
            const key = 'child_' + taskIdx + '_' + cidx;
            const file = pendingFiles.get(key);
            if (!file) return;
            const task = this.tasks[taskIdx];

            task.children[cidx] = { ...task.children[cidx], doc_uploading: true };

            const logId = await this.ensureChildLog(taskIdx, cidx);
            if (!logId) { task.children[cidx] = { ...task.children[cidx], doc_uploading: false }; return; }

            const fd = new FormData();
            fd.append('file', file);
            const r = await fetch(`/buhtasks/logs/${logId}/document`, {
                method: 'POST',
                headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' },
                body: fd,
            });
            const data = await r.json();
            if (data.success) {
                pendingFiles.delete(key);
                task.children[cidx] = { ...task.children[cidx], doc_uploading: false, pending_file_name: null, document_name: data.log.document_name, document_path: data.log.document_path };
            } else {
                task.children[cidx] = { ...task.children[cidx], doc_uploading: false };
            }
        },

        // Сортировка по сроку (due_day): клик переключает asc → desc → исходный порядок
        toggleSort() {
            this.sortDir = this.sortDir === null ? 'asc' : (this.sortDir === 'asc' ? 'desc' : null);
            this.applySort();
        },
        applySort() {
            const bySeq = (a, b) => a._seq - b._seq;
            if (!this.sortDir) {
                this.tasks = [...this.tasks].sort(bySeq);
                return;
            }
            const mult = this.sortDir === 'desc' ? -1 : 1;
            this.tasks = [...this.tasks].sort((a, b) => {
                const av = a.due_date, bv = b.due_date;
                if (!av && !bv) return bySeq(a, b); // обе без срока — сохраняем порядок
                if (!av) return 1;  // без срока — всегда в конец
                if (!bv) return -1;
                if (av === bv) return bySeq(a, b);
                return (av < bv ? -1 : 1) * mult;
            });
        },
        get visibleCount() {
            return this.tasks.filter(t => this.matchesFilter(t)).length;
        },

        // Окно бесконечной прокрутки: uid-ы первых visibleLimit задач, прошедших фильтр (в порядке списка).
        // Мемоизируем по сигнатуре зависимостей — иначе Set пересобирался бы на каждую строку (O(n²)).
        get visibleSet() {
            const key = this.clientFilter + '|' + this.visibleLimit + '|' + this.sortDir + '|' + this.tasks.length;
            if (visibleCache.key !== key) {
                const set = new Set();
                let count = 0;
                for (const t of this.tasks) {
                    if (!this.matchesFilter(t)) continue;
                    if (count >= this.visibleLimit) break;
                    set.add(t.uid);
                    count++;
                }
                visibleCache = { key, set };
            }
            return visibleCache.set;
        },
        loadMore() {
            if (this.visibleLimit < this.visibleCount) this.visibleLimit += 20;
        },
        _initInfiniteScroll() {
            if (typeof IntersectionObserver === 'undefined') return;
            this._io = new IntersectionObserver((entries) => {
                if (!entries.some(e => e.isIntersecting)) return;
                if (this.visibleLimit >= this.visibleCount) return;
                this.loadMore();
                // Сентинел мог остаться в зоне видимости после дорисовки —
                // переподписываемся, чтобы получить свежий колбэк и продолжить.
                this.$nextTick(() => {
                    const s = this.$refs.loadMore;
                    if (s) { this._io.unobserve(s); this._io.observe(s); }
                });
            }, { rootMargin: '400px' });
            if (this.$refs.loadMore) this._io.observe(this.$refs.loadMore);
        },

        // Пагинация вкладки «Выполненные» (по 20)
        get completedTotalPages() {
            return Math.max(1, Math.ceil(this.completed.length / this.completedPerPage));
        },
        get completedPageItems() {
            const start = (this.completedPage - 1) * this.completedPerPage;
            return this.completed.slice(start, start + this.completedPerPage);
        },
        openCompleted(c) {
            this.completedItem = c;
        },

        get totalCompleted() {
            return this.tasks.filter(t => t.status === 'completed').length;
        },

        get totalProgressPct() {
            if (!this.tasks.length) return 0;
            return Math.round(this.totalCompleted / this.tasks.length * 100);
        },

        // Разница в днях между сроком задачи и сегодня (отриц. = просрочено)
        dueDiffDays(task) {
            if (!task.due_date) return null;
            const today = new Date(); today.setHours(0, 0, 0, 0);
            const due = new Date(task.due_date + 'T00:00:00');
            return Math.round((due - today) / 86400000);
        },

        urgency(task) {
            if (task.status === 'completed' || task.status === 'review') return null;
            const d = this.dueDiffDays(task);
            if (d === null) return null;
            if (d < 0) return 'overdue';
            if (d === 0) return 'today';
            if (d <= 3) return 'soon';
            return null;
        },

        // Срок задачи полной датой: «3.06.2026»
        fmtDue(dateStr) {
            if (!dateStr) return '';
            const d = new Date(dateStr + 'T00:00:00');
            return d.getDate() + '.' + String(d.getMonth() + 1).padStart(2, '0') + '.' + d.getFullYear();
        },

        // Дата+время выполнения для истории: «3.06.2026, 14:30»
        fmtCompleted(iso) {
            if (!iso) return '';
            const d = new Date(iso);
            const hh = String(d.getHours()).padStart(2, '0');
            const mm = String(d.getMinutes()).padStart(2, '0');
            return d.getDate() + '.' + String(d.getMonth() + 1).padStart(2, '0') + '.' + d.getFullYear() + ', ' + hh + ':' + mm;
        },

        init() {
            const clientNow = Math.floor(Date.now() / 1000);
            this.tasks = this.tasks.map(t =>
                t.status === 'running' ? { ...t, client_resumed_at: clientNow } : t
            );
            this.ticker = setInterval(() => { this.now = Math.floor(Date.now() / 1000); }, 1000);

            // Бесконечная прокрутка: при смене фильтра/сортировки начинаем показ заново с 20.
            this.$watch('clientFilter', () => { this.visibleLimit = 20; });
            this.$watch('sortDir', () => { this.visibleLimit = 20; });
            this.$nextTick(() => this._initInfiniteScroll());
        },

        destroy() {
            clearInterval(this.ticker);
            this._io?.disconnect();
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

        async post(url, body = null) {
            const headers = { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' };
            const options = { method: 'POST', headers };
            if (body !== null) {
                headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify(body);
            }
            const r = await fetch(url, options);
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
                review_comment:    log.review_comment ?? null,
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
                    year:             task.year,
                    month:            task.month,
                }),
            });
            const data = await r.json();
            if (data.success) {
                this.tasks[idx] = { ...this.tasks[idx], log_id: data.log.id };
                return data.log.id;
            }
            return null;
        },

        askStart(idx) {
            this.startConfirm = { show: true, idx };
        },

        confirmStart() {
            const idx = this.startConfirm.idx;
            this.startConfirm = { show: false, idx: null };
            if (idx !== null) this.startTask(idx);
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

        // Все подпункты (чекбоксы) отмечены? Без подпунктов — считается выполнимой.
        allChildrenDone(task) {
            if (!task.children || task.children.length === 0) return true;
            return task.children.every(c => c.status === 'completed');
        },

        async completeTask(idx) {
            // Задачу с подпунктами нельзя закрыть, пока не отмечены все чекбоксы
            if (!this.allChildrenDone(this.tasks[idx])) {
                this.checklistRequiredModal = { show: true, taskIdx: idx };
                return;
            }

            this.tasks[idx] = { ...this.tasks[idx], loading: true };

            if (this.tasks[idx].type === 'planned') {
                const logId = await this.ensureLog(idx);
                if (!logId) { this.tasks[idx] = { ...this.tasks[idx], loading: false }; return; }
            }

            const data = await this.post(this.actionUrl(this.tasks[idx], 'complete'));
            if (data.success) {
                this.applyResult(idx, data.log);
            } else {
                this.tasks[idx] = { ...this.tasks[idx], loading: false };
                if (data.requires_document) this.docRequiredModal = { show: true, taskIdx: idx };
                if (data.requires_checklist) this.checklistRequiredModal = { show: true, taskIdx: idx };
            }
        },

        async resetTask(idx) {
            this.tasks[idx] = { ...this.tasks[idx], loading: true };
            const data = await this.post(this.actionUrl(this.tasks[idx], 'reset'));
            if (data.success) this.applyResult(idx, data.log);
            else this.tasks[idx] = { ...this.tasks[idx], loading: false };
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

        resetNewTask() {
            this.newTask = { mode: this.newTask.mode, client_id: '', service_id: '', name: '', cost: '', due_day: '', employee_id: this.currentEmployeeId, due_date: '' };
        },

        async createTask() {
            this.createError = '';

            // Каталог — позиция в смете (нужна компания). Произвольная — внеплановая задача сотруднику (компания необязательна).
            const isCustom = this.newTask.mode === 'custom';

            if (isCustom) {
                if (!this.newTask.name.trim())  { this.createError = 'Введите название задачи'; return; }
                if (!this.newTask.employee_id)  { this.createError = 'Выберите сотрудника'; return; }
                if (!this.newTask.due_date)     { this.createError = 'Укажите дату'; return; }
            } else {
                if (!this.newTask.client_id)    { this.createError = 'Выберите компанию'; return; }
                if (!this.newTask.service_id)   { this.createError = 'Выберите бизнес-процесс'; return; }
            }

            this.creating = true;

            try {
                const url = isCustom ? '/buhtasks/adhoc' : '/buhtasks/extra';
                const body = isCustom
                    ? {
                        employee_id: this.newTask.employee_id,
                        client_id:   this.newTask.client_id || null,
                        name:        this.newTask.name.trim(),
                        due_date:    this.newTask.due_date,
                    }
                    : { client_id: this.newTask.client_id, year: this.year, month: this.month, service_id: this.newTask.service_id };

                const r = await fetch(url, {
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
                    // Добавляем в текущий список только если задача для меня (иначе она у назначенного сотрудника).
                    // unshift (в начало), чтобы созданная задача сразу попадала в видимое окно пагинации.
                    if (data.mine === undefined || data.mine) {
                        this.tasks.unshift(data.task);
                    }
                    this.resetNewTask();
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

// «Сроки по клиентам» — уведомление (только чтение): срез просроченных/сегодняшних задач.
// Источник тот же, что и у таблицы; завершать отсюда нельзя — задачу закрывают в таблице.
function taskReminders(initial, serverCounts) {
    return {
        items: initial || [],
        // Истинные счётчики с сервера (items обрезаны до первых N для лёгкости рендера)
        counts: serverCounts || { overdue: 0, today: 0 },
        open: false,

        init() {
            // Интуитивно: если есть срочное (просрочено/сегодня) — раскрываем,
            // иначе сворачиваем, чтобы таблица была сразу на виду.
            // Ручной выбор пользователя запоминаем и уважаем при следующих заходах.
            const saved = localStorage.getItem('buhReminders.open');
            this.open = saved === null
                ? (this.counts.overdue > 0 || this.counts.today > 0)
                : saved === '1';
        },
        toggle() {
            this.open = !this.open;
            localStorage.setItem('buhReminders.open', this.open ? '1' : '0');
        },

        today() { const d = new Date(); d.setHours(0, 0, 0, 0); return d; },
        daysUntil(r) {
            const due = new Date(r.due_date + 'T00:00:00');
            return Math.round((due - this.today()) / 86400000);
        },

        get groups() {
            const make = (key, label, filter, cls) => ({ key, label, items: this.items.filter(filter), ...cls });
            return [
                make('overdue', 'Просрочено', r => this.daysUntil(r) < 0,
                    { headClass: 'text-red-600 bg-red-50', rowClass: 'bg-red-50/30', dateClass: 'text-red-600' }),
                make('today', 'Сегодня', r => this.daysUntil(r) === 0,
                    { headClass: 'text-orange-600 bg-orange-50', rowClass: 'bg-orange-50/20', dateClass: 'text-orange-600' }),
            ];
        },

        fmtDate(s) {
            const [, m, d] = s.split('-');
            const months = ['янв','фев','мар','апр','мая','июн','июл','авг','сен','окт','ноя','дек'];
            return parseInt(d) + ' ' + months[parseInt(m) - 1];
        },
        relLabel(r) {
            const d = this.daysUntil(r);
            if (d < 0) return 'просрочено на ' + Math.abs(d) + ' ' + this.plural(Math.abs(d), 'день', 'дня', 'дней');
            if (d === 0) return 'сегодня';
            if (d === 1) return 'завтра';
            return 'через ' + d + ' ' + this.plural(d, 'день', 'дня', 'дней');
        },
        plural(n, one, few, many) {
            const m10 = n % 10, m100 = n % 100;
            if (m10 === 1 && m100 !== 11) return one;
            if (m10 >= 2 && m10 <= 4 && (m100 < 10 || m100 >= 20)) return few;
            return many;
        },
    };
}
</script>
@endsection
