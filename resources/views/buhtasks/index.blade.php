@extends('layouts.app')

@section('title', 'БухЗадачник')
@section('page-title', 'БухЗадачник')

@section('content')

{{-- Сроки по клиентам: агенда (воркер) + календарь (живая проекция расписаний) --}}
<div x-data="taskReminders({{ json_encode($reminders) }}, {{ json_encode($schedule) }})" x-cloak class="mb-6">
    <template x-if="items.length > 0 || schedule.length > 0">
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center gap-3 flex-wrap">
                <svg class="w-5 h-5 text-indigo-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/></svg>
                <h2 class="text-base font-bold text-slate-900">Сроки по клиентам</h2>
                <span class="text-xs text-slate-400" x-show="viewMode === 'list'" x-text="filteredItems.length + ' ' + plural(filteredItems.length, 'срок', 'срока', 'сроков')"></span>

                <div class="ml-auto flex items-center gap-2 flex-wrap">
                    {{-- Переключатель Список / Календарь --}}
                    <div class="flex items-center bg-slate-100 rounded-lg p-0.5">
                        <button type="button" @click="viewMode = 'list'"
                                :class="viewMode === 'list' ? 'bg-white shadow-sm text-indigo-600' : 'text-slate-500 hover:text-slate-700'"
                                class="px-3 py-1.5 rounded-md text-xs font-medium transition-all">Список</button>
                        <button type="button" @click="viewMode = 'calendar'"
                                :class="viewMode === 'calendar' ? 'bg-white shadow-sm text-indigo-600' : 'text-slate-500 hover:text-slate-700'"
                                class="px-3 py-1.5 rounded-md text-xs font-medium transition-all">Календарь</button>
                    </div>

                    {{-- Фильтр по компаниям --}}
                    <div class="flex items-center gap-2" x-show="clientOptions.length > 1">
                        <svg class="w-4 h-4 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4a1 1 0 011-1h16a1 1 0 011 1v2a1 1 0 01-.293.707L14 13.414V19a1 1 0 01-.553.894l-4 2A1 1 0 018 21v-7.586L3.293 6.707A1 1 0 013 6V4z"/></svg>
                        <select x-model="clientFilter"
                                class="px-3 py-1.5 border border-slate-200 rounded-lg text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                            <option value="all" x-text="'Все компании (' + currentTotal + ')'"></option>
                            <template x-for="c in clientOptions" :key="c.id">
                                <option :value="String(c.id)" x-text="c.name + ' (' + c.count + ')'"></option>
                            </template>
                        </select>
                    </div>
                </div>
            </div>

            {{-- ===== РЕЖИМ СПИСОК (агенда по срочности) ===== --}}
            <div x-show="viewMode === 'list'">
                <template x-if="filteredItems.length === 0">
                    <div class="px-6 py-8 text-center text-sm text-slate-400">Нет активных сроков по выбранной компании.</div>
                </template>

                <div class="divide-y divide-slate-100">
                    <template x-for="group in groups" :key="group.key">
                        <template x-if="group.items.length > 0">
                            <div>
                                <button type="button" @click="group.key === 'later' ? (showLater = !showLater) : null"
                                        class="w-full px-6 py-2 text-xs font-semibold uppercase tracking-wider flex items-center gap-2"
                                        :class="[group.headClass, group.key === 'later' ? 'cursor-pointer hover:brightness-95' : 'cursor-default']">
                                    <span x-text="group.label"></span>
                                    <span class="opacity-70" x-text="'(' + group.items.length + ')'"></span>
                                    <svg x-show="group.key === 'later'" class="w-3.5 h-3.5 ml-auto transition-transform" :class="showLater ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                                </button>

                                <div x-show="group.key !== 'later' || showLater" class="divide-y divide-slate-50">
                                    <template x-for="r in group.items" :key="r.id">
                                        <div class="flex items-center gap-3 px-6 py-3" :class="group.rowClass">
                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm font-medium text-slate-800 truncate" x-text="r.name" :title="r.name"></p>
                                                <p class="text-xs text-slate-500 truncate" x-text="r.client_name"></p>
                                            </div>
                                            <div class="text-right flex-shrink-0">
                                                <p class="text-sm font-semibold" :class="group.dateClass" x-text="fmtDate(r.due_date)"></p>
                                                <p class="text-xs" :class="group.dateClass" x-text="relLabel(r)"></p>
                                            </div>
                                            <button type="button" @click="complete(r)" :disabled="r.loading"
                                                    title="Отметить выполненным"
                                                    class="flex-shrink-0 w-9 h-9 inline-flex items-center justify-center rounded-full border border-slate-200 text-slate-300 hover:border-emerald-400 hover:text-emerald-500 disabled:opacity-50 transition-colors">
                                                <svg x-show="!r.loading" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                                                <svg x-show="r.loading" class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                            </button>
                                        </div>
                                    </template>
                                </div>
                            </div>
                        </template>
                    </template>
                </div>
            </div>

            {{-- ===== РЕЖИМ КАЛЕНДАРЬ (живая проекция, любой месяц) ===== --}}
            <div x-show="viewMode === 'calendar'" class="p-4 sm:p-6">
                <div class="flex items-center justify-center gap-4 mb-4">
                    <button type="button" @click="prevMonth()" :disabled="!canPrev"
                            class="p-1.5 rounded-lg text-slate-500 hover:bg-slate-100 disabled:opacity-30 disabled:hover:bg-transparent transition-colors">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                    </button>
                    <span class="text-sm font-semibold text-slate-800 w-40 text-center" x-text="monthLabel"></span>
                    <button type="button" @click="nextMonth()" :disabled="!canNext"
                            class="p-1.5 rounded-lg text-slate-500 hover:bg-slate-100 disabled:opacity-30 disabled:hover:bg-transparent transition-colors">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                    </button>
                </div>

                <div class="grid grid-cols-7 gap-1 mb-1">
                    <template x-for="w in ['Пн','Вт','Ср','Чт','Пт','Сб','Вс']" :key="w">
                        <div class="text-center text-xs font-medium text-slate-400 py-1" x-text="w"></div>
                    </template>
                </div>

                <div class="grid grid-cols-7 gap-1">
                    <template x-for="(cell, i) in monthCells" :key="i">
                        <div>
                            <template x-if="cell.day">
                                <button type="button" @click="cell.count ? selectDay(cell.date) : null"
                                        class="relative w-full min-h-[84px] rounded-lg border p-1.5 flex flex-col gap-1 text-left overflow-hidden transition-colors"
                                        :class="[
                                            cell.count ? 'cursor-pointer hover:border-indigo-300 hover:shadow-sm' : 'cursor-default',
                                            selectedDay === cell.date ? 'ring-2 ring-indigo-400 border-indigo-200' : 'border-slate-100',
                                            isToday(cell.date) ? 'bg-indigo-50/60' : 'bg-white'
                                        ]">
                                    <span class="text-xs font-semibold leading-none"
                                          :class="isToday(cell.date) ? 'text-indigo-600' : 'text-slate-500'"
                                          x-text="cell.day"></span>
                                    <div class="flex flex-col gap-0.5 w-full">
                                        <template x-for="(e, ei) in cell.entries.slice(0, 2)" :key="ei">
                                            <span class="block w-full truncate rounded-sm bg-slate-50 border-l-2 pl-1 pr-0.5 py-0.5 text-[10px] leading-tight text-slate-700"
                                                  :class="barClass(cell.date)"
                                                  :title="e.name + ' · ' + e.client_name"
                                                  x-text="e.name"></span>
                                        </template>
                                        <template x-if="cell.count > 2">
                                            <span class="pl-1 text-[10px] font-medium text-slate-400" x-text="'+' + (cell.count - 2) + ' ещё'"></span>
                                        </template>
                                    </div>
                                </button>
                            </template>
                            <template x-if="!cell.day"><div class="min-h-[84px]"></div></template>
                        </div>
                    </template>
                </div>

                <template x-if="selectedDay && selectedEntries.length">
                    <div class="mt-4 border-t border-slate-100 pt-3">
                        <div class="flex items-center gap-2 mb-2">
                            <p class="text-sm font-semibold text-slate-700" x-text="fmtFull(selectedDay)"></p>
                            <span class="text-xs text-slate-400" x-text="'· ' + selectedEntries.length + ' ' + plural(selectedEntries.length, 'задача', 'задачи', 'задач')"></span>
                        </div>
                        <div class="space-y-1.5">
                            <template x-for="(e, i) in selectedEntries" :key="i">
                                <div class="flex items-center gap-2 text-sm">
                                    <span class="w-1.5 h-1.5 rounded-full flex-shrink-0" :class="dotClass(selectedDay)"></span>
                                    <span class="font-medium text-slate-800 truncate" x-text="e.name"></span>
                                    <span class="text-slate-300">·</span>
                                    <span class="text-slate-500 truncate" x-text="e.client_name"></span>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                <template x-if="filteredSchedule.length === 0">
                    <p class="mt-4 text-center text-sm text-slate-400">Нет запланированных сроков по выбранной компании.</p>
                </template>
            </div>
        </div>
    </template>
</div>

<div x-data="buhTasks({{ json_encode($tasks) }}, {{ $year }}, {{ $month }}, {{ json_encode($allClients) }}, {{ json_encode($services) }})" x-cloak>

    {{-- Баннер срочных задач --}}
    <template x-if="urgentSummary.overdue.length > 0 || urgentSummary.today.length > 0 || urgentSummary.soon.length > 0">
        <div class="mb-4 rounded-2xl border px-4 py-3 flex flex-wrap items-center gap-x-5 gap-y-1"
             :class="urgentSummary.overdue.length > 0 ? 'bg-red-50 border-red-200' : (urgentSummary.today.length > 0 ? 'bg-orange-50 border-orange-200' : 'bg-amber-50 border-amber-200')">
            <svg class="w-5 h-5 flex-shrink-0"
                 :class="urgentSummary.overdue.length > 0 ? 'text-red-500' : 'text-amber-500'"
                 fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/>
            </svg>
            <span x-show="urgentSummary.overdue.length > 0"
                  class="text-sm font-semibold text-red-700"
                  x-text="'Просрочено: ' + urgentSummary.overdue.length + ' ' + plural(urgentSummary.overdue.length, 'задача', 'задачи', 'задач')"></span>
            <span x-show="urgentSummary.today.length > 0"
                  class="text-sm font-semibold text-orange-700"
                  x-text="'Сегодня истекает: ' + urgentSummary.today.length + ' ' + plural(urgentSummary.today.length, 'задача', 'задачи', 'задач')"></span>
            <span x-show="urgentSummary.soon.length > 0"
                  class="text-sm text-amber-700"
                  x-text="'Скоро истекает: ' + urgentSummary.soon.length + ' ' + plural(urgentSummary.soon.length, 'задача', 'задачи', 'задач')"></span>
        </div>
    </template>

    {{-- Шапка --}}
    <div class="flex items-center justify-between mb-6">
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

    {{-- Все задачи скрыты фильтром --}}
    <div x-show="tasks.length > 0 && visibleCount === 0"
         class="bg-white rounded-2xl border border-slate-200/50 shadow-sm px-6 py-10 text-center text-sm text-slate-400">
        Нет задач по выбранной компании.
    </div>

    <div x-show="tasks.length > 0 && visibleCount > 0"
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
                        <th class="px-4 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Стоимость</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider w-24">Время</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Действия</th>
                    </tr>
                </thead>
                    <template x-for="(task, idx) in tasks" :key="task.uid">
                        <tbody class="divide-y divide-slate-100">
                        <tr :class="{
                                'bg-emerald-50/30': task.status === 'completed',
                                'border-l-4 border-l-red-400 bg-red-50/40': task.status !== 'completed' && urgency(task) === 'overdue',
                                'border-l-4 border-l-orange-400 bg-orange-50/30': task.status !== 'completed' && urgency(task) === 'today',
                                'border-l-4 border-l-amber-300 bg-amber-50/20': task.status !== 'completed' && urgency(task) === 'soon',
                                'hover:bg-slate-50/50': task.status !== 'completed' && !urgency(task),
                            }" x-show="matchesFilter(task)">

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
                                    <button type="button" @click.prevent="toggleExpand(task.uid)"
                                            class="flex-shrink-0 text-slate-300 hover:text-indigo-500 transition-all"
                                            :class="expanded[task.uid] ? 'rotate-90 text-indigo-500' : ''"
                                            title="Описание / комментарий">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                    </button>
                                    <span class="text-sm font-medium cursor-pointer"
                                          @click="toggleExpand(task.uid)"
                                          :class="task.status === 'completed' ? 'line-through text-slate-400' : 'text-slate-800'"
                                          x-text="task.name"></span>
                                    <span x-show="task.type === 'adhoc'"
                                          class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-700">доп.</span>
                                    <span x-show="task.status !== 'completed' && urgency(task) === 'overdue'"
                                          class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-red-100 text-red-700">просрочена</span>
                                    <span x-show="task.status !== 'completed' && urgency(task) === 'today'"
                                          class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-700">сегодня</span>
                                </div>
                            </td>

                            <td class="px-4 py-3.5">
                                <span class="text-sm text-slate-600" x-text="task.client_name"></span>
                            </td>

                            <td class="px-4 py-3.5">
                                <div class="flex flex-col gap-0.5">
                                    <span x-show="task.periodicity"
                                          class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600 w-fit"
                                          x-text="task.periodicity"></span>
                                    <span x-show="task.due_day"
                                          class="text-xs font-medium w-fit"
                                          :class="{
                                              'text-red-600': urgency(task) === 'overdue',
                                              'text-orange-600': urgency(task) === 'today',
                                              'text-amber-600': urgency(task) === 'soon',
                                              'text-slate-500': !urgency(task),
                                          }"
                                          x-text="'до ' + task.due_day + '-го'"></span>
                                    <span x-show="!task.periodicity && !task.due_day" class="text-slate-300 text-sm">—</span>
                                </div>
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

                                {{-- Выполнено (completed) --}}
                                <span x-show="task.status === 'completed'" class="inline-flex items-center">
                                    <span class="text-xs text-emerald-600 font-medium flex items-center gap-1">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                        Выполнено
                                    </span>
                                </span>

                            </td>
                        </tr>

                        {{-- Раскрывающаяся сноска: описание / комментарий БП --}}
                        <tr x-show="matchesFilter(task) && expanded[task.uid]" class="bg-slate-50/60">
                            <td></td>
                            <td colspan="6" class="px-4 pb-4 pt-0">
                                <div class="text-sm text-slate-600 space-y-2 border-l-2 border-indigo-200 pl-3">
                                    <template x-if="task.description">
                                        <div>
                                            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-0.5">Описание</p>
                                            <p class="whitespace-pre-line" x-text="task.description"></p>
                                        </div>
                                    </template>
                                    <template x-if="task.comment">
                                        <div>
                                            <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-0.5">Комментарий</p>
                                            <p class="whitespace-pre-line" x-text="task.comment"></p>
                                        </div>
                                    </template>
                                    <template x-if="!task.description && !task.comment">
                                        <p class="text-slate-400 italic">Описание и комментарий не заполнены</p>
                                    </template>
                                </div>
                            </td>
                        </tr>
                        </tbody>
                    </template>
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
                        <th class="px-4 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Стоимость</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="(task, idx) in tasks" :key="task.uid">
                        <tr :class="{
                                'bg-emerald-50/30': task.status === 'completed',
                                'border-l-4 border-l-red-400 bg-red-50/40': task.status !== 'completed' && urgency(task) === 'overdue',
                                'border-l-4 border-l-orange-400 bg-orange-50/30': task.status !== 'completed' && urgency(task) === 'today',
                                'border-l-4 border-l-amber-300 bg-amber-50/20': task.status !== 'completed' && urgency(task) === 'soon',
                                'hover:bg-slate-50/50': task.status !== 'completed' && !urgency(task),
                            }"
                            class="cursor-pointer" x-show="matchesFilter(task)" @click="toggleChecklist(idx)">
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
                                    <span x-show="task.status !== 'completed' && urgency(task) === 'overdue'"
                                          class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-red-100 text-red-700">просрочена</span>
                                    <span x-show="task.status !== 'completed' && urgency(task) === 'today'"
                                          class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-orange-100 text-orange-700">сегодня</span>
                                </div>
                            </td>
                            <td class="px-4 py-3.5">
                                <span class="text-sm text-slate-600" x-text="task.client_name"></span>
                            </td>
                            <td class="px-4 py-3.5">
                                <div class="flex flex-col gap-0.5">
                                    <span x-show="task.periodicity"
                                          class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600 w-fit"
                                          x-text="task.periodicity"></span>
                                    <span x-show="task.due_day"
                                          class="text-xs font-medium w-fit"
                                          :class="{
                                              'text-red-600': urgency(task) === 'overdue',
                                              'text-orange-600': urgency(task) === 'today',
                                              'text-amber-600': urgency(task) === 'soon',
                                              'text-slate-500': !urgency(task),
                                          }"
                                          x-text="'до ' + task.due_day + '-го'"></span>
                                    <span x-show="!task.periodicity && !task.due_day" class="text-slate-300 text-sm">—</span>
                                </div>
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
                        <div class="flex gap-3">
                            <div class="flex-1">
                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Стоимость (сом)</label>
                                <input type="number"
                                       x-model="newTask.cost"
                                       placeholder="0"
                                       min="0"
                                       step="0.01"
                                       class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-sm text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50">
                            </div>
                            <div class="w-32">
                                <label class="block text-sm font-medium text-slate-700 mb-1.5">Срок (день)</label>
                                <input type="number"
                                       x-model="newTask.due_day"
                                       placeholder="25"
                                       min="1"
                                       max="31"
                                       class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-sm text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50">
                            </div>
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

    {{-- ===== МОДАЛ ПОДТВЕРЖДЕНИЯ СТАРТА ===== --}}
    <div x-show="startConfirm.show"
         x-transition:enter="ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-50 flex items-center justify-center bg-black/40"
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

</div>

<script>
function buhTasks(initialTasks, year, month, allClients, allServices) {
    return {
        tasks: initialTasks.map((t, i) => ({ ...t, loading: false, client_resumed_at: null, _seq: i })),
        year,
        month,
        allClients,
        allServices,
        viewMode: 'list',
        ticker: null,
        now: Math.floor(Date.now() / 1000),
        clientFilter: 'all',
        sortDir: null, // null = исходный порядок | 'asc' | 'desc' (по сроку due_day)
        expanded: {},  // uid задачи -> раскрыта ли сноска с описанием/комментарием

        showCreateModal: false,
        startConfirm: { show: false, idx: null },
        newTask: { mode: 'catalog', client_id: '', service_id: '', name: '', cost: '', due_day: '' },
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
        toggleExpand(uid) {
            this.expanded[uid] = !this.expanded[uid];
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
                const av = a.due_day, bv = b.due_day;
                if (av == null && bv == null) return bySeq(a, b); // обе без срока — сохраняем порядок
                if (av == null) return 1;  // без срока — всегда в конец
                if (bv == null) return -1;
                if (av === bv) return bySeq(a, b);
                return (av - bv) * mult;
            });
        },
        get visibleCount() {
            return this.tasks.filter(t => this.matchesFilter(t)).length;
        },

        get totalCompleted() {
            return this.tasks.filter(t => t.status === 'completed').length;
        },

        get totalProgressPct() {
            if (!this.tasks.length) return 0;
            return Math.round(this.totalCompleted / this.tasks.length * 100);
        },

        urgency(task) {
            if (task.status === 'completed' || !task.due_day) return null;
            const now = new Date();
            if (this.year !== now.getFullYear() || this.month !== now.getMonth() + 1) return null;
            const today = now.getDate();
            if (task.due_day < today) return 'overdue';
            if (task.due_day === today) return 'today';
            if (task.due_day <= today + 3) return 'soon';
            return null;
        },

        get urgentSummary() {
            const pending = this.tasks.filter(t => t.status !== 'completed' && t.due_day);
            const now = new Date();
            if (this.year !== now.getFullYear() || this.month !== now.getMonth() + 1) {
                return { overdue: [], today: [], soon: [] };
            }
            const today = now.getDate();
            return {
                overdue: pending.filter(t => t.due_day < today),
                today:   pending.filter(t => t.due_day === today),
                soon:    pending.filter(t => t.due_day > today && t.due_day <= today + 3),
            };
        },

        plural(n, one, few, many) {
            const mod10  = n % 10;
            const mod100 = n % 100;
            if (mod100 >= 11 && mod100 <= 19) return many;
            if (mod10 === 1) return one;
            if (mod10 >= 2 && mod10 <= 4) return few;
            return many;
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
                const body = { client_id: this.newTask.client_id, year: this.year, month: this.month };
                if (this.newTask.mode === 'catalog') {
                    body.service_id = this.newTask.service_id;
                } else {
                    body.name    = this.newTask.name.trim();
                    body.cost    = parseFloat(this.newTask.cost) || 0;
                    body.due_day = this.newTask.due_day ? parseInt(this.newTask.due_day) : null;
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
                    this.newTask = { mode: this.newTask.mode, client_id: '', service_id: '', name: '', cost: '', due_day: '' };
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

// Агенда сроков по клиентам — группировка по срочности + отметка «выполнено»
function taskReminders(initial, schedule) {
    return {
        items: (initial || []).map(r => ({ ...r, loading: false })),
        schedule: schedule || [],          // живая проекция: [{date, name, client_id, client_name}]
        viewMode: 'list',                  // list (агенда) | calendar
        showLater: false,
        clientFilter: 'all',
        calYear: null,
        calMonth: null,                    // 1–12
        selectedDay: null,                 // 'YYYY-MM-DD'

        init() {
            const n = new Date();
            this.calYear = n.getFullYear();
            this.calMonth = n.getMonth() + 1;
        },

        today() { const d = new Date(); d.setHours(0, 0, 0, 0); return d; },
        daysUntil(r) {
            const due = new Date(r.due_date + 'T00:00:00');
            return Math.round((due - this.today()) / 86400000);
        },

        // Текущий набор данных зависит от режима (агенда=items, календарь=schedule)
        get currentDataset() { return this.viewMode === 'calendar' ? this.schedule : this.items; },
        get currentTotal() { return this.currentDataset.length; },

        // Список компаний для фильтра (с количеством по текущему режиму)
        get clientOptions() {
            const map = {};
            this.currentDataset.forEach(r => {
                const k = r.client_id ?? r.client_name;
                if (!map[k]) map[k] = { id: r.client_id, name: r.client_name, count: 0 };
                map[k].count++;
            });
            return Object.values(map).sort((a, b) => a.name.localeCompare(b.name, 'ru'));
        },

        matchesClient(r) {
            return this.clientFilter === 'all' || String(r.client_id) === String(this.clientFilter);
        },
        get filteredItems() { return this.items.filter(r => this.matchesClient(r)); },
        get filteredSchedule() { return this.schedule.filter(r => this.matchesClient(r)); },

        get groups() {
            const make = (key, label, filter, cls) => ({ key, label, items: this.filteredItems.filter(filter), ...cls });
            return [
                make('overdue', 'Просрочено', r => this.daysUntil(r) < 0,
                    { headClass: 'text-red-600 bg-red-50', rowClass: 'bg-red-50/30', dateClass: 'text-red-600' }),
                make('today', 'Сегодня', r => this.daysUntil(r) === 0,
                    { headClass: 'text-orange-600 bg-orange-50', rowClass: 'bg-orange-50/20', dateClass: 'text-orange-600' }),
                make('week', 'На этой неделе', r => { const d = this.daysUntil(r); return d > 0 && d <= 7; },
                    { headClass: 'text-amber-600 bg-amber-50', rowClass: '', dateClass: 'text-amber-600' }),
                make('later', 'Позже', r => this.daysUntil(r) > 7,
                    { headClass: 'text-slate-500 bg-slate-50', rowClass: '', dateClass: 'text-slate-500' }),
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

        // ===== Календарь =====
        get monthLabel() {
            const names = ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'];
            return names[this.calMonth - 1] + ' ' + this.calYear;
        },
        pad(n) { return String(n).padStart(2, '0'); },
        get monthCells() {
            const first = new Date(this.calYear, this.calMonth - 1, 1);
            const lead = (first.getDay() + 6) % 7;               // Пн=0
            const dim = new Date(this.calYear, this.calMonth, 0).getDate();
            const byDate = {};
            this.filteredSchedule.forEach(s => { (byDate[s.date] = byDate[s.date] || []).push(s); });
            const cells = [];
            for (let i = 0; i < lead; i++) cells.push({ day: null, date: null, count: 0, entries: [] });
            for (let d = 1; d <= dim; d++) {
                const date = this.calYear + '-' + this.pad(this.calMonth) + '-' + this.pad(d);
                const entries = byDate[date] || [];
                cells.push({ day: d, date, count: entries.length, entries });
            }
            return cells;
        },
        isToday(date) {
            const n = new Date();
            return date === (n.getFullYear() + '-' + this.pad(n.getMonth() + 1) + '-' + this.pad(n.getDate()));
        },
        // Срочность дня (для цветовой кодировки) — все записи одной даты одинаковой срочности
        daysUntilDate(date) {
            const due = new Date(date + 'T00:00:00');
            return Math.round((due - this.today()) / 86400000);
        },
        urgencyOf(date) {
            const d = this.daysUntilDate(date);
            if (d < 0) return 'overdue';
            if (d === 0) return 'today';
            if (d <= 7) return 'soon';
            return 'later';
        },
        barClass(date) {
            return {
                overdue: 'border-l-red-400',
                today:   'border-l-orange-400',
                soon:    'border-l-amber-300',
                later:   'border-l-slate-300',
            }[this.urgencyOf(date)];
        },
        dotClass(date) {
            return {
                overdue: 'bg-red-400',
                today:   'bg-orange-400',
                soon:    'bg-amber-300',
                later:   'bg-slate-300',
            }[this.urgencyOf(date)];
        },
        selectDay(date) { this.selectedDay = this.selectedDay === date ? null : date; },
        get selectedEntries() {
            return this.selectedDay ? this.filteredSchedule.filter(s => s.date === this.selectedDay) : [];
        },
        fmtFull(s) {
            const [y, m, d] = s.split('-');
            const months = ['января','февраля','марта','апреля','мая','июня','июля','августа','сентября','октября','ноября','декабря'];
            return parseInt(d) + ' ' + months[parseInt(m) - 1] + ' ' + y;
        },
        get _monthIdx() { return this.calYear * 12 + this.calMonth; },
        get _bounds() {
            if (this.schedule.length === 0) return { min: this._monthIdx, max: this._monthIdx };
            let min = Infinity, max = -Infinity;
            this.schedule.forEach(s => {
                const [y, m] = s.date.split('-');
                const idx = (+y) * 12 + (+m);
                if (idx < min) min = idx;
                if (idx > max) max = idx;
            });
            return { min, max };
        },
        get canPrev() { return this._monthIdx > this._bounds.min; },
        get canNext() { return this._monthIdx < this._bounds.max; },
        prevMonth() {
            if (!this.canPrev) return;
            if (this.calMonth === 1) { this.calMonth = 12; this.calYear--; } else this.calMonth--;
            this.selectedDay = null;
        },
        nextMonth() {
            if (!this.canNext) return;
            if (this.calMonth === 12) { this.calMonth = 1; this.calYear++; } else this.calMonth++;
            this.selectedDay = null;
        },

        async complete(r) {
            r.loading = true;
            try {
                const res = await fetch('/buhtasks/reminders/' + r.id + '/complete', {
                    method: 'POST',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                });
                const data = await res.json();
                if (data.success) {
                    this.items = this.items.filter(x => x.id !== r.id);
                } else {
                    r.loading = false;
                }
            } catch (e) {
                r.loading = false;
            }
        },
    };
}
</script>
@endsection
