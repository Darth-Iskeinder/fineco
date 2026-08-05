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

<div x-data="buhTasks({{ json_encode($tasks) }}, {{ $year }}, {{ $month }}, {{ json_encode($allClients) }}, {{ json_encode($completed) }}, {{ json_encode($employees) }}, {{ $employee->id }}, {{ json_encode($catalog) }}, {{ json_encode($teamTasks) }}, {{ json_encode($teamMembers) }})" x-cloak>

    {{-- Шапка --}}
    <div class="flex items-center justify-between mb-2">
        <div class="flex items-center gap-2 text-sm text-slate-500">
            <span x-text="'Выполнено за месяц: ' + totalCompleted + ' из ' + totalTasks"></span>
            <div class="w-24 h-1.5 bg-slate-100 rounded-full overflow-hidden">
                <div class="h-full bg-emerald-400 rounded-full transition-all"
                     :style="'width:' + totalProgressPct + '%'"></div>
            </div>
        </div>

        <div class="flex items-center gap-3 flex-wrap">
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
                </button>
                {{-- Вкладка главбуха: текущие задачи его бухгалтеров. Видна только когда такие задачи есть. --}}
                <template x-if="teamTasks.length > 0">
                    <button @click="viewMode = 'team'"
                            :class="viewMode === 'team' ? 'bg-white shadow-sm text-indigo-600' : 'text-slate-500 hover:text-slate-700'"
                            class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-all">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0M15 7a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                        Задачи бухгалтеров
                        <span class="inline-flex items-center justify-center min-w-[1.25rem] h-5 px-1.5 rounded-full text-xs font-semibold bg-indigo-100 text-indigo-600"
                              x-text="teamTasks.length"></span>
                    </button>
                </template>
            </div>
        </div>
    </div>

    {{-- Фильтры (только на вкладке «Список») --}}
    <div x-show="viewMode === 'list'" class="flex items-center gap-3 flex-wrap mb-4">
        {{-- Компания (показываем всегда, чтобы выбранная компания оставалась видна в фильтре) --}}
        <select x-model="clientFilter"
                class="px-3 py-2 border border-slate-200 rounded-xl text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
            <option value="all" x-text="'Все компании (' + activeCount + ')'"></option>
            <template x-for="c in clientOptions" :key="c.id">
                <option :value="String(c.id)" x-text="c.name + ' (' + c.count + ')'"></option>
            </template>
        </select>

        {{-- Срок: воронка по горизонту (просрочка всегда попадает внутрь). --}}
        <select x-model="dueFilter"
                class="px-3 py-2 border border-slate-200 rounded-xl text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
            <option value="all">Все сроки</option>
            <option value="d3">Ближайшие 3 дня</option>
            <option value="d7">Ближайшая неделя</option>
        </select>

        {{-- Действие: фильтр по состоянию из колонки «Действия» (не начаты / на паузе и т.д.). --}}
        <select x-model="statusFilter"
                class="px-3 py-2 border border-slate-200 rounded-xl text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
            <option value="all">Все действия</option>
            <option value="pending" x-text="'Не начаты (' + statusCounts.pending + ')'"></option>
            <option value="paused" x-text="'На паузе (' + statusCounts.paused + ')'"></option>
            <option value="running" x-text="'В работе (' + statusCounts.running + ')'"></option>
            <option value="rework" x-text="'На доработку (' + statusCounts.rework + ')'"></option>
        </select>

        <button x-show="clientFilter !== 'all' || dueFilter !== 'all' || statusFilter !== 'all'"
                @click="clientFilter = 'all'; dueFilter = 'all'; statusFilter = 'all'"
                class="text-xs text-slate-400 hover:text-slate-600 underline">Сбросить</button>

        <span class="ml-auto text-sm text-slate-500 font-medium" x-text="visibleCount + ' задач'"></span>
    </div>

    {{-- Подсказка про горизонт показа — только там, где виден активный набор задач.
         На вкладке «Выполненные» она противоречит содержимому (там история за 90 дней,
         и у неё своя шапка), поэтому скрыта. --}}
    <div x-show="viewMode !== 'completed'" class="flex items-center gap-1.5 mb-6 text-xs text-slate-400">
        <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
        <span>Показаны задачи текущего месяца и все просроченные. Выполненные исчезают из списка на следующий день — их история во вкладке «Выполненные».</span>
    </div>

    {{-- Фильтры вкладки «Выполненные»: поиск + компания + период + исполнитель.
         Всё считается на клиенте по уже загруженной истории — без обращений к серверу. --}}
    <div x-show="viewMode === 'completed'" class="flex items-center gap-3 flex-wrap mb-4">
        {{-- Поиск: главный инструмент. Ищет по названию, компании, заметке и исполнителю сразу. --}}
        <div class="relative flex-1 min-w-[16rem] max-w-md">
            <svg class="w-4 h-4 absolute left-3 top-1/2 -translate-y-1/2 text-slate-400 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z"/></svg>
            <input type="search" x-model="completedSearch"
                   placeholder="Поиск по задаче, компании, заметке…"
                   class="w-full pl-9 pr-3 py-2 border border-slate-200 rounded-xl text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
        </div>

        {{-- Компания: только те, что реально есть в истории, со счётчиками --}}
        <select x-model="completedClient"
                class="px-3 py-2 border border-slate-200 rounded-xl text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
            <option value="all" x-text="'Все компании (' + completed.length + ')'"></option>
            <template x-for="c in completedClientOptions" :key="c.id">
                <option :value="String(c.id)" x-text="c.name + ' (' + c.count + ')'"></option>
            </template>
        </select>

        {{-- Период выполнения: пресеты кнопками — частый вопрос «что закрыли за неделю» в одно нажатие --}}
        <div class="inline-flex bg-slate-100 rounded-xl p-1 gap-1">
            <template x-for="p in [{ v: 'all', l: 'Всё' }, { v: 'today', l: 'Сегодня' }, { v: 'd7', l: 'Неделя' }, { v: 'd30', l: 'Месяц' }]" :key="p.v">
                <button type="button" @click="completedPeriod = p.v"
                        :class="completedPeriod === p.v ? 'bg-white shadow-sm text-indigo-600' : 'text-slate-500 hover:text-slate-700'"
                        class="px-3 py-1.5 rounded-lg text-sm font-medium transition-all" x-text="p.l"></button>
            </template>
        </div>

        {{-- Только с документом: выключен по умолчанию, ничего не прячем без спроса.
             Переключатель — тот же, что в настройках услуг, чтобы вид был один на всё приложение.
             Кликается целиком, вместе с подписью. --}}
        <button type="button" role="switch" :aria-checked="completedWithDoc"
                @click="completedWithDoc = !completedWithDoc"
                :class="completedWithDoc ? 'text-indigo-700' : 'text-slate-500 hover:text-slate-700'"
                class="inline-flex items-center gap-2 px-1 py-2 text-sm font-medium transition-colors focus:outline-none">
            <span :class="completedWithDoc ? 'bg-indigo-600' : 'bg-slate-300'"
                  class="relative inline-flex h-6 w-11 flex-shrink-0 items-center rounded-full transition-colors">
                <span :class="completedWithDoc ? 'translate-x-6' : 'translate-x-1'"
                      class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform"></span>
            </span>
            <span x-text="'Только с документом (' + completedWithDocCount + ')'"></span>
        </button>

        {{-- Исполнитель: только у главбуха — появляется, когда в истории есть чужие задачи --}}
        <select x-show="completedDoerOptions.length > 0" x-model="completedDoer"
                class="px-3 py-2 border border-slate-200 rounded-xl text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
            <option value="all" x-text="'Все исполнители (' + completed.length + ')'"></option>
            <option value="mine" x-text="'Мои задачи (' + completedMineCount + ')'"></option>
            <template x-for="d in completedDoerOptions" :key="d.id">
                <option :value="String(d.id)" x-text="d.name + ' (' + d.count + ')'"></option>
            </template>
        </select>

        <button x-show="completedFiltersActive" @click="resetCompletedFilters()"
                class="text-xs text-slate-400 hover:text-slate-600 underline">Сбросить</button>

        <span class="ml-auto text-sm text-slate-500 font-medium"
              x-text="completedFiltersActive
                        ? 'Найдено ' + filteredCompleted.length + ' из ' + completed.length
                        : completed.length + ' задач'"></span>
    </div>

    {{-- Фильтр вкладки «Задачи бухгалтеров»: по исполнителю --}}
    <div x-show="viewMode === 'team'" class="flex items-center gap-3 flex-wrap mb-4">
        <select x-model="teamFilter"
                class="px-3 py-2 border border-slate-200 rounded-xl text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
            <option value="all" x-text="'Все бухгалтеры (' + teamTasks.length + ')'"></option>
            <template x-for="m in teamMembers" :key="m.id">
                <option :value="String(m.id)" x-text="m.name + ' (' + teamCountFor(m.id) + ')'"></option>
            </template>
        </select>
    </div>

    {{-- Нет задач --}}
    <div x-show="viewMode !== 'completed' && viewMode !== 'team' && tasks.length === 0"
         class="bg-white rounded-2xl border border-slate-200/50 shadow-sm px-6 py-16 text-center">
        <div class="w-14 h-14 bg-slate-100 rounded-full flex items-center justify-center mx-auto mb-4">
            <svg class="w-7 h-7 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
        </div>
        <p class="text-slate-500 text-sm">Нет задач. Добавьте внеплановую задачу или убедитесь, что у клиентов заполнены сметы и вы назначены на них.</p>
    </div>

    {{-- Все задачи скрыты фильтром (только список — у чеклиста свои фильтры) --}}
    <div x-show="viewMode === 'list' && tasks.length > 0 && visibleCount === 0"
         class="bg-white rounded-2xl border border-slate-200/50 shadow-sm px-6 py-10 text-center text-sm text-slate-400">
        Нет задач по выбранным фильтрам.
    </div>

    <div x-show="viewMode === 'completed' || viewMode === 'team' || (viewMode === 'checklist' && tasks.length > 0) || (viewMode === 'list' && visibleCount > 0)"
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
                            <button type="button" @click="toggleSort('due')"
                                    class="group inline-flex items-center gap-1 uppercase tracking-wider hover:text-slate-700 transition-colors"
                                    title="Сортировать по сроку">
                                Периодичность
                                <span class="inline-flex flex-col leading-[0]">
                                    <svg class="w-2 h-2" :class="sortBy === 'due' && sortDir === 'asc' ? 'text-indigo-600' : 'text-slate-300 group-hover:text-slate-400'" viewBox="0 0 8 8" fill="currentColor"><path d="M4 0l4 5H0z"/></svg>
                                    <svg class="w-2 h-2 mt-0.5" :class="sortBy === 'due' && sortDir === 'desc' ? 'text-indigo-600' : 'text-slate-300 group-hover:text-slate-400'" viewBox="0 0 8 8" fill="currentColor"><path d="M4 8L0 3h8z"/></svg>
                                </span>
                            </button>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">
                            <button type="button" @click="toggleSort('period')"
                                    class="group inline-flex items-center gap-1 uppercase tracking-wider hover:text-slate-700 transition-colors"
                                    title="Сортировать по отчётному периоду">
                                Отчётный период
                                <span class="inline-flex flex-col leading-[0]">
                                    <svg class="w-2 h-2" :class="sortBy === 'period' && sortDir === 'asc' ? 'text-indigo-600' : 'text-slate-300 group-hover:text-slate-400'" viewBox="0 0 8 8" fill="currentColor"><path d="M4 0l4 5H0z"/></svg>
                                    <svg class="w-2 h-2 mt-0.5" :class="sortBy === 'period' && sortDir === 'desc' ? 'text-indigo-600' : 'text-slate-300 group-hover:text-slate-400'" viewBox="0 0 8 8" fill="currentColor"><path d="M4 8L0 3h8z"/></svg>
                                </span>
                            </button>
                        </th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider w-24">Время</th>
                        <th class="px-4 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Действия</th>
                    </tr>
                </thead>
                    <template x-for="({ task, idx }) in visibleTasks" :key="task.uid">
                        <tbody class="divide-y divide-slate-100">
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
                                    {{-- Закрыта в обход документа/подпунктов; причина — в title и в модалке задачи --}}
                                    <span x-show="task.force_closed"
                                          class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-700"
                                          :title="task.force_close_comment">принудительно</span>
                                    {{-- Задача бухгалтера, пришедшая главбуху на проверку (шаг 7.1) --}}
                                    <span x-show="task.review_for_head"
                                          class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-700">
                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                        <span x-text="'бухгалтер: ' + (task.doer_name || '—')"></span>
                                    </span>
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

                                {{-- На проверке (review) — собственная задача исполнителя: пассивная метка --}}
                                <span x-show="task.status === 'review' && !task.review_for_head" class="inline-flex items-center">
                                    <span class="text-xs text-sky-600 font-medium flex items-center gap-1">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                        На проверке
                                    </span>
                                </span>

                                {{-- Проверка главбухом: принять / вернуть задачу бухгалтера (шаг 7.2) --}}
                                <span x-show="task.status === 'review' && task.review_for_head" class="inline-flex items-center gap-1.5">
                                    <button @click.prevent="approveReview(idx)"
                                            :disabled="task.loading"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-emerald-600 text-white text-xs font-medium rounded-lg hover:bg-emerald-700 disabled:opacity-50 transition-colors">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                        Принять
                                    </button>
                                    <button @click.prevent="openReviewReject(idx)"
                                            :disabled="task.loading"
                                            class="inline-flex items-center gap-1.5 px-3 py-1.5 bg-rose-50 text-rose-600 border border-rose-200 text-xs font-medium rounded-lg hover:bg-rose-100 disabled:opacity-50 transition-colors">
                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a5 5 0 015 5v2M3 10l4-4M3 10l4 4"/></svg>
                                        Вернуть
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
        {{-- x-if (не x-show): матрица чеклиста тяжёлая (компании × столбцы × 5 x-if,
             геттер checklistData бежит по всем задачам). Строим только когда вкладка открыта. --}}
        <template x-if="viewMode === 'checklist'">
        <div>
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

                {{-- Фильтр столбцов — совместный (группа + период); рядом со статусами, чтобы был на виду --}}
                <div class="inline-flex items-center gap-2 pl-5 ml-1 border-l border-slate-200">
                    <span class="text-slate-400">Фильтр:</span>
                    <select x-model="checklistFilter.group"
                            class="px-2.5 py-1 text-xs text-slate-600 rounded-lg border border-slate-200 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50">
                        <option value="">Все группы</option>
                        <template x-for="g in checklistGroups" :key="g">
                            <option :value="g" x-text="g"></option>
                        </template>
                    </select>
                    <select x-model="checklistFilter.period"
                            class="px-2.5 py-1 text-xs text-slate-600 rounded-lg border border-slate-200 bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50">
                        <option value="">Все периоды</option>
                        <template x-for="p in checklistPeriods" :key="p">
                            <option :value="p" x-text="p"></option>
                        </template>
                    </select>
                    <button type="button" x-show="checklistFilter.group || checklistFilter.period"
                            @click="checklistFilter.group = ''; checklistFilter.period = ''"
                            class="px-2 py-1 text-xs text-slate-400 hover:text-slate-600 transition-colors" title="Сбросить фильтр">✕</button>
                </div>
            </div>

            <div class="overflow-x-auto">
                <table class="min-w-full border-separate border-spacing-0 border-t border-l border-slate-200">
                    <thead>
                        {{-- Фон на строке: наклонный текст столбцов выходит за свою ячейку,
                             прозрачные th не перекрывают его соседними фонами. --}}
                        <tr class="bg-slate-100">
                            <th class="sticky left-0 z-20 bg-slate-100 px-4 py-3 text-left text-xs font-semibold text-slate-600 uppercase tracking-wider border-r border-b border-slate-200 w-px whitespace-nowrap align-bottom">Компания</th>
                            <template x-for="(col, ci) in checklistData.cols" :key="col.key">
                                {{-- Шапка с наклоном −45°: название лежит в наклонной «дорожке» между диагональными линиями --}}
                                <th class="relative border-b border-slate-200 p-0 align-bottom overflow-visible"
                                    :style="`height:${checklistData.headerHeight}px; width:36px;`">
                                    {{-- диагональная линия-разделитель слева --}}
                                    <div class="absolute bottom-0 left-0 bg-slate-200 pointer-events-none"
                                         :style="`height:1px; width:${Math.round(checklistData.headerHeight*1.414)}px; transform-origin:bottom left; transform:rotate(-45deg);`"></div>
                                    {{-- закрывающая линия справа у последнего столбца --}}
                                    <template x-if="ci === checklistData.cols.length - 1">
                                        <div class="absolute bottom-0 bg-slate-200 pointer-events-none"
                                             :style="`left:36px; height:1px; width:${Math.round(checklistData.headerHeight*1.414)}px; transform-origin:bottom left; transform:rotate(-45deg);`"></div>
                                    </template>
                                    {{-- название по центру дорожки (базовая линия посередине между диагоналями) --}}
                                    <span class="absolute bottom-0.5 left-1/2 text-[11px] font-semibold text-slate-600 whitespace-nowrap leading-none"
                                          style="transform-origin: bottom left; transform: rotate(-45deg);"
                                          x-text="col.label" :title="col.label"></span>
                                </th>
                            </template>
                            {{-- Распорка: забирает лишнюю ширину, чтобы «Компания» и столбцы не растягивались --}}
                            <th class="border-b border-slate-200" style="width:100%"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <template x-for="company in checklistData.companies" :key="company.id">
                            <tr class="group">
                                <td class="sticky left-0 z-10 bg-white group-hover:bg-slate-50 px-4 py-3 text-sm font-medium text-slate-800 border-r border-b border-slate-200 whitespace-nowrap w-px" x-text="company.name"></td>
                                <template x-for="col in checklistData.cols" :key="col.key">
                                    <td class="px-2 py-3 text-center border-b border-slate-200 group-hover:bg-slate-50/50">
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
                                {{-- Распорка под шапочную: забирает лишнюю ширину строки --}}
                                <td class="border-b border-slate-200 group-hover:bg-slate-50/50" style="width:100%"></td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
        </div>
        </template>

        {{-- ===== РЕЖИМ ВЫПОЛНЕННЫЕ (история за 90 дней, только чтение, пагинация по 20) ===== --}}
        <template x-if="viewMode === 'completed'">
        <div>
            <div class="px-6 py-3 border-b border-slate-100 flex items-center gap-2 text-sm text-slate-500 bg-slate-50/60">
                <svg class="w-4 h-4 flex-shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span>История выполненных задач за последние <span class="font-semibold text-slate-700">{{ $completedDays }} дней</span></span>
                <span x-show="completed.length > 0" class="text-slate-400 hidden sm:inline">· двойной клик — детали</span>
            </div>
            <template x-if="completed.length === 0">
                <div class="px-6 py-10 text-center text-sm text-slate-400">За последние {{ $completedDays }} дней нет выполненных задач.</div>
            </template>
            {{-- Ничего не нашлось по фильтрам: отдельный текст, чтобы не читалось как «задач нет вообще» --}}
            <template x-if="completed.length > 0 && filteredCompleted.length === 0">
                <div class="px-6 py-10 text-center">
                    <p class="text-sm text-slate-400">Ничего не найдено среди выполненных за последние {{ $completedDays }} дней.</p>
                    <button type="button" @click="resetCompletedFilters()"
                            class="mt-2 text-sm text-indigo-600 hover:text-indigo-700 underline">Сбросить фильтры</button>
                </div>
            </template>
            <div class="divide-y divide-slate-100">
                <template x-for="c in completedPageItems" :key="c.id">
                    <div class="flex items-center gap-3 px-6 py-3 cursor-pointer hover:bg-slate-50/60 transition-colors" @dblclick="openCompleted(c)">
                        <span class="flex-shrink-0 w-8 h-8 inline-flex items-center justify-center rounded-full bg-emerald-50 text-emerald-500">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
                        </span>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 min-w-0">
                                <p class="text-sm font-medium text-slate-700 truncate" x-text="c.name"></p>
                                <span x-show="c.force_closed"
                                      class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-amber-100 text-amber-700 flex-shrink-0"
                                      :title="c.force_close_comment">закрыта принудительно</span>
                                {{-- Задача выполнена бухгалтером (этап 2): пометка исполнителя у главбуха --}}
                                <span x-show="c.doer_name"
                                      class="inline-flex items-center gap-1 px-1.5 py-0.5 rounded text-xs font-medium bg-indigo-100 text-indigo-700 flex-shrink-0">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                                    <span x-text="'бухгалтер: ' + c.doer_name"></span>
                                </span>
                            </div>
                            <p class="text-xs text-slate-400 truncate">
                                <span x-text="c.client_name"></span><span x-show="periodLabel(c)" class="text-slate-500 font-medium" x-text="' · ' + periodLabel(c)"></span>
                            </p>
                        </div>
                        {{-- Колонка «Комментарий»: причина принудительного закрытия + заметка сотрудника --}}
                        <div class="hidden sm:flex flex-1 min-w-0 items-center gap-1.5 text-slate-500">
                            <template x-if="c.force_close_comment">
                                <span class="inline-flex items-center gap-1.5 min-w-0">
                                    <svg class="w-3.5 h-3.5 flex-shrink-0 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                                    <span class="text-sm text-amber-700 truncate" :title="c.force_close_comment" x-text="c.force_close_comment"></span>
                                </span>
                            </template>
                            <template x-if="c.employee_comment">
                                <span class="inline-flex items-center gap-1.5 min-w-0">
                                    <svg class="w-3.5 h-3.5 flex-shrink-0 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M8 12h8m-8-4h8m-8 8h4m-9 4V6a2 2 0 012-2h12a2 2 0 012 2v8a2 2 0 01-2 2H9l-4 4z"/></svg>
                                    <span class="text-sm truncate" :title="c.employee_comment" x-text="c.employee_comment"></span>
                                </span>
                            </template>
                            <template x-if="!c.employee_comment && !c.force_close_comment">
                                <span class="text-xs text-slate-300 italic">без заметки</span>
                            </template>
                        </div>
                        {{-- Скрепка: сразу видно, есть ли документ, без открывания карточки.
                             Клик по ней — просмотр (один PDF) или карточка (несколько файлов). --}}
                        <span class="flex-shrink-0 w-9 flex justify-end">
                            <button type="button" x-show="docCount(c) > 0"
                                    @click.stop="openDocFromRow(c)"
                                    :title="docCount(c) === 1 ? 'Посмотреть документ' : 'Документов: ' + docCount(c)"
                                    class="inline-flex items-center gap-0.5 p-1 rounded-lg text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 transition-colors">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                                <span x-show="docCount(c) > 1" class="text-xs font-medium" x-text="docCount(c)"></span>
                            </button>
                        </span>
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
        </template>

        {{-- ===== РЕЖИМ «ЗАДАЧИ БУХГАЛТЕРОВ» (только главбух) ===== --}}
        <template x-if="viewMode === 'team'">
        <div>
            <div class="px-6 py-3 border-b border-slate-100 flex items-center gap-2 text-sm text-slate-500 bg-slate-50/60">
                <svg class="w-4 h-4 flex-shrink-0 text-slate-400" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <span>Текущие задачи ваших бухгалтеров по вашим клиентам — не начатые, в работе и на доработке. Выполненное бухгалтером закрывается у него, задачи «на проверке» — в вашем основном списке.</span>
            </div>
            <template x-if="filteredTeamTasks.length === 0">
                <div class="px-6 py-10 text-center text-sm text-slate-400">По выбранному фильтру задач нет.</div>
            </template>
            <div class="divide-y divide-slate-100">
                <template x-for="t in filteredTeamTasks" :key="t.uid">
                    <div class="flex items-center gap-3 px-6 py-3">
                        {{-- Статус-иконка --}}
                        <span class="flex-shrink-0 w-8 h-8 inline-flex items-center justify-center rounded-full"
                              :class="{
                                  'bg-slate-100 text-slate-400':   t.status === 'pending',
                                  'bg-emerald-50 text-emerald-500': t.status === 'running',
                                  'bg-amber-50 text-amber-500':     t.status === 'paused',
                                  'bg-rose-50 text-rose-500':       t.status === 'rework',
                              }">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        </span>

                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <p class="text-sm font-medium text-slate-700" x-text="t.name"></p>
                                <span x-show="t.branch_label" class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-700" x-text="t.branch_label"></span>
                                <span x-show="t.is_custom" class="inline-flex items-center px-1.5 py-0.5 rounded text-xs font-medium bg-sky-100 text-sky-700">вручную</span>
                            </div>
                            <p class="text-xs text-slate-400 truncate">
                                <span x-text="t.client_name"></span><span x-show="t.reporting_period" x-text="' · ' + t.reporting_period"></span>
                            </p>
                        </div>

                        {{-- Исполнитель --}}
                        <span class="hidden sm:inline-flex items-center gap-1.5 text-xs text-slate-500 whitespace-nowrap">
                            <svg class="w-3.5 h-3.5 text-slate-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                            <span x-text="t.doer_name || '—'"></span>
                        </span>

                        {{-- Срок --}}
                        <span class="text-xs whitespace-nowrap"
                              :class="t.due_date && t.due_date < todayStr && t.status !== 'completed' ? 'text-rose-500 font-medium' : 'text-slate-400'"
                              x-text="t.due_date ? 'до ' + fmtDue(t.due_date) : 'без срока'"></span>

                        {{-- Статус --}}
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium whitespace-nowrap"
                              :class="{
                                  'bg-slate-100 text-slate-500':    t.status === 'pending',
                                  'bg-emerald-100 text-emerald-700': t.status === 'running',
                                  'bg-amber-100 text-amber-700':     t.status === 'paused',
                                  'bg-rose-100 text-rose-700':       t.status === 'rework',
                              }"
                              x-text="{pending: 'Не начата', running: 'В работе', paused: 'Пауза', rework: 'На доработке'}[t.status] || t.status"></span>
                    </div>
                </template>
            </div>
        </div>
        </template>

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

            <div class="space-y-4">
                {{-- Компания --}}
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">
                        Компания
                        <span class="text-slate-400 font-normal">(необязательно)</span>
                    </label>
                    <select x-model="newTask.client_id"
                            class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 bg-white">
                        <option value="">Выберите компанию...</option>
                        <template x-for="client in allClients" :key="client.id">
                            <option :value="client.id" x-text="client.name"></option>
                        </template>
                    </select>
                </div>

                {{-- Источник задачи: из каталога (берём только название) или своя --}}
                <div>
                    <div class="inline-flex p-0.5 bg-slate-100 rounded-lg mb-3">
                        <button type="button" @click="newTask.source = 'custom'"
                                :class="newTask.source === 'custom' ? 'bg-white shadow-sm text-indigo-600' : 'text-slate-500 hover:text-slate-700'"
                                class="px-3 py-1.5 text-sm font-medium rounded-md transition-colors">Своя задача</button>
                        <button type="button" @click="newTask.source = 'catalog'"
                                :class="newTask.source === 'catalog' ? 'bg-white shadow-sm text-indigo-600' : 'text-slate-500 hover:text-slate-700'"
                                class="px-3 py-1.5 text-sm font-medium rounded-md transition-colors">Из каталога</button>
                    </div>

                    {{-- Из каталога: выбор услуги, переносим ТОЛЬКО название --}}
                    <div x-show="newTask.source === 'catalog'">
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Услуга из каталога</label>
                        <select x-model="newTask.service_id" @change="onCatalogPick()"
                                class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-sm text-slate-700 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 bg-white">
                            <option value="">Выберите услугу...</option>
                            <template x-for="s in catalog" :key="s.id">
                                <option :value="s.id" x-text="s.name"></option>
                            </template>
                        </select>
                        <p class="text-xs text-slate-400 mt-1">Из каталога берётся только название — остальное настраивается ниже.</p>
                    </div>

                    {{-- Своя: произвольное название --}}
                    <div x-show="newTask.source === 'custom'">
                        <label class="block text-sm font-medium text-slate-700 mb-1.5">Название задачи</label>
                        <input type="text"
                               x-model="newTask.name"
                               placeholder="Например: Сверка с банком"
                               class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-sm text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50">
                    </div>
                </div>

                {{-- Описание (необязательно) --}}
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">
                        Описание <span class="text-slate-400 font-normal">(необязательно)</span>
                    </label>
                    <textarea x-model="newTask.description" rows="2"
                              placeholder="Детали задачи для сотрудника"
                              class="block w-full px-3 py-2.5 border border-slate-200 rounded-xl text-sm text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50"></textarea>
                </div>

                {{-- На проверку — тогл как в попапе БП --}}
                <div class="flex items-center justify-between gap-3">
                    <span class="text-sm text-slate-700">Отправлять на проверку после выполнения</span>
                    <button type="button" @click="newTask.requires_review = !newTask.requires_review"
                            class="flex-shrink-0 w-11 h-6 rounded-full relative transition-colors duration-200"
                            :class="newTask.requires_review ? 'bg-indigo-600' : 'bg-slate-200'">
                        <span class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform duration-200"
                              :style="newTask.requires_review ? 'transform: translateX(20px)' : ''"></span>
                    </button>
                </div>

                {{-- Документ (необязательно) --}}
                <div>
                    <label class="block text-sm font-medium text-slate-700 mb-1.5">
                        Документ <span class="text-slate-400 font-normal">(необязательно)</span>
                    </label>
                    <div class="flex items-center gap-2">
                        <label class="inline-flex items-center gap-1.5 px-3 py-2 bg-slate-100 text-slate-600 text-sm font-medium rounded-xl hover:bg-slate-200 cursor-pointer transition-colors">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15.172 7l-6.586 6.586a2 2 0 102.828 2.828l6.414-6.586a4 4 0 00-5.656-5.656l-6.415 6.585a6 6 0 108.486 8.486L20.5 13"/></svg>
                            <span x-text="newTaskFileName ? 'Заменить файл' : 'Выбрать файл'"></span>
                            <input type="file" class="hidden" @change="selectCreateDocument($event)">
                        </label>
                        <span x-show="newTaskFileName" class="text-sm text-slate-600 truncate max-w-[180px]" x-text="newTaskFileName"></span>
                    </div>
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
                    <span x-text="creating ? 'Создание...' : 'Создать задачу'"></span>
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

    {{-- ===== МОДАЛ: ВЕРНУТЬ ЗАДАЧУ НА ДОРАБОТКУ (проверка главбухом, шаг 7.2) ===== --}}
    <div x-show="reviewReject.show"
         x-transition:enter="ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-[60] flex items-center justify-center bg-black/40"
         @click.self="reviewReject = { show: false, idx: null, comment: '' }"
         @keydown.escape.window="reviewReject = { show: false, idx: null, comment: '' }"
         style="display:none">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-md mx-4 p-6">
            <div class="flex items-start gap-4">
                <div class="flex-shrink-0 w-11 h-11 rounded-full bg-rose-100 flex items-center justify-center">
                    <svg class="w-5 h-5 text-rose-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a5 5 0 015 5v2M3 10l4-4M3 10l4 4"/></svg>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="text-base font-semibold text-slate-800">Вернуть на доработку</h3>
                    <p class="mt-1 text-sm text-slate-500">Задача вернётся бухгалтеру со статусом «на доработку». Напишите, что нужно исправить.</p>
                </div>
            </div>
            <textarea x-model="reviewReject.comment"
                      rows="3"
                      placeholder="Что исправить…"
                      class="mt-4 w-full rounded-xl border border-slate-200 px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-rose-200 focus:border-rose-300"></textarea>
            <div class="flex gap-3 mt-5">
                <button @click="reviewReject = { show: false, idx: null, comment: '' }"
                        class="flex-1 py-2.5 px-4 border border-slate-200 text-slate-600 text-sm font-medium rounded-xl hover:bg-slate-50 transition-colors">
                    Отмена
                </button>
                <button @click="confirmReviewReject()"
                        :disabled="!reviewReject.comment.trim()"
                        class="flex-1 py-2.5 px-4 bg-rose-600 text-white text-sm font-medium rounded-xl hover:bg-rose-700 disabled:opacity-50 transition-colors">
                    Вернуть
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

    {{-- ===== МОДАЛ: ПРИНУДИТЕЛЬНОЕ ЗАКРЫТИЕ (без документа и подпунктов, причина обязательна) ===== --}}
    <div x-show="forceCloseModal.show"
         x-transition:enter="ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-[60] flex items-center justify-center bg-black/40"
         @click.self="closeForceClose()"
         @keydown.escape.window="closeForceClose()"
         style="display:none">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm mx-4 p-6">
            <div class="flex items-start gap-4">
                <div class="flex-shrink-0 w-11 h-11 rounded-full bg-amber-100 flex items-center justify-center">
                    <svg class="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                </div>
                <div class="flex-1 min-w-0">
                    <h3 class="text-base font-semibold text-slate-800">Принудительное закрытие</h3>
                    <p class="mt-1 text-sm text-slate-500">Задача закроется без документа и без отметки подпунктов. Если по БП положена проверка — задача уйдёт на проверку как обычно. В выполненных она будет помечена как закрытая принудительно.</p>
                </div>
            </div>
            <div class="mt-4">
                <label class="block text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1">Причина (обязательно)</label>
                <textarea x-model="forceCloseModal.comment" rows="3"
                          placeholder="Почему задача закрывается без документа/подпунктов?"
                          class="w-full text-sm border border-slate-200 rounded-xl px-3 py-2 focus:outline-none focus:ring-2 focus:ring-amber-400 focus:border-transparent resize-none"></textarea>
                <p x-show="forceCloseModal.error" class="mt-1 text-xs text-rose-500" x-text="forceCloseModal.error"></p>
            </div>
            <div class="flex gap-3 mt-5">
                <button @click="closeForceClose()"
                        class="flex-1 py-2.5 px-4 border border-slate-200 text-slate-600 text-sm font-medium rounded-xl hover:bg-slate-50 transition-colors">
                    Отмена
                </button>
                <button @click="submitForceClose()" :disabled="forceCloseModal.saving"
                        class="flex-1 py-2.5 px-4 bg-amber-600 text-white text-sm font-medium rounded-xl hover:bg-amber-700 disabled:opacity-50 transition-colors">
                    Закрыть задачу
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

                {{-- Принудительное закрытие: доступно, пока задача у сотрудника (не на проверке/не закрыта) --}}
                <template x-if="tasks[taskModalIdx].type === 'planned' && !tasks[taskModalIdx].review_for_head
                                && ['pending','running','paused','rework'].includes(tasks[taskModalIdx].status)">
                    <div class="mt-1.5 text-right">
                        <button @click="openForceClose(taskModalIdx)"
                                class="text-xs text-slate-400 hover:text-amber-600 underline decoration-dotted underline-offset-2 transition-colors">
                            Закрыть принудительно…
                        </button>
                    </div>
                </template>

                {{-- Причина принудительного закрытия (видна и главбуху на проверке) --}}
                <template x-if="tasks[taskModalIdx].force_closed">
                    <div class="mt-3 bg-amber-50 border border-amber-200 rounded-xl px-4 py-2.5">
                        <p class="text-xs font-semibold text-amber-700 mb-0.5">Закрыта принудительно — причина</p>
                        <p class="text-sm text-amber-800 whitespace-pre-line" x-text="tasks[taskModalIdx].force_close_comment || '—'"></p>
                    </div>
                </template>

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

                {{-- Моя заметка: личный комментарий сотрудника по задаче (автосохранение по blur) --}}
                <div class="mt-4 pt-4 border-t border-slate-100">
                    <label class="block text-xs font-semibold text-indigo-500 uppercase tracking-wider mb-1.5">Моя заметка</label>
                    <textarea rows="2"
                              :value="tasks[taskModalIdx].employee_comment"
                              @change="saveComment(taskModalIdx, $event.target.value)"
                              placeholder="Нюансы по задаче — чтобы вспомнить позже"
                              class="block w-full px-3 py-2 border border-slate-200 rounded-xl text-sm text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 whitespace-pre-line"></textarea>
                    <p class="text-xs text-slate-400 mt-1">Сохраняется автоматически</p>
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

                <template x-if="tasks[taskModalIdx].requires_document || tasks[taskModalIdx].type === 'adhoc'">
                    <div class="mt-4 pt-4 border-t border-slate-100">
                        <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5"
                           x-text="tasks[taskModalIdx].requires_document ? 'Документы для закрытия' : 'Документы (необязательно)'"></p>
                        <div class="space-y-1">
                            <template x-for="doc in (tasks[taskModalIdx].documents || [])" :key="doc.id">
                                <div class="flex items-center gap-2">
                                    <a :href="doc.url" target="_blank"
                                       class="inline-flex items-center gap-1.5 text-sm text-indigo-600 hover:text-indigo-800 underline truncate max-w-[260px]">
                                        <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                        <span x-text="doc.name"></span>
                                    </a>
                                    <button x-show="canEditDocs(tasks[taskModalIdx])"
                                            @click="deleteRootDocument(taskModalIdx, doc.id)"
                                            title="Удалить документ"
                                            class="text-slate-300 hover:text-rose-500 transition-colors flex-shrink-0">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                    </button>
                                </div>
                            </template>
                            <template x-if="(tasks[taskModalIdx].documents || []).length === 0 && !tasks[taskModalIdx].doc_uploading">
                                <span class="text-sm text-slate-400 italic">Не прикреплены</span>
                            </template>
                        </div>
                        <div x-show="canEditDocs(tasks[taskModalIdx])" class="flex items-center gap-2 mt-2">
                            <span x-show="tasks[taskModalIdx].doc_uploading" class="text-sm text-slate-500">Загрузка...</span>
                            <label class="ml-auto inline-flex items-center gap-1.5 px-3 py-1.5 bg-slate-100 text-slate-600 text-xs font-medium rounded-lg hover:bg-slate-200 cursor-pointer transition-colors"
                                   :class="tasks[taskModalIdx].doc_uploading ? 'opacity-50 pointer-events-none' : ''">
                                <span x-text="(tasks[taskModalIdx].documents || []).length ? 'Прикрепить ещё' : 'Выбрать файлы'"></span>
                                <input type="file" multiple class="hidden" @change="selectRootDocument(taskModalIdx, $event)">
                            </label>
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
                                        <div class="mt-1 ml-6 text-xs">
                                            <template x-for="doc in (child.documents || [])" :key="doc.id">
                                                <div class="flex items-center gap-1.5 py-0.5">
                                                    <a :href="doc.url" target="_blank"
                                                       class="text-indigo-600 hover:text-indigo-800 underline truncate max-w-[180px]" x-text="doc.name"></a>
                                                    <button x-show="canEditDocs(tasks[taskModalIdx]) && child.status !== 'review'"
                                                            @click="deleteChildDocument(taskModalIdx, cidx, doc.id)"
                                                            title="Удалить документ"
                                                            class="text-slate-300 hover:text-rose-500 transition-colors flex-shrink-0">
                                                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                                    </button>
                                                </div>
                                            </template>
                                            <div class="flex items-center gap-2 mt-0.5">
                                                <template x-if="(child.documents || []).length === 0 && !child.doc_uploading">
                                                    <span class="text-slate-400 italic">Документ не прикреплён</span>
                                                </template>
                                                <span x-show="child.doc_uploading" class="text-slate-500">Загрузка...</span>
                                                <label class="ml-auto inline-flex items-center px-2 py-1 bg-slate-100 text-slate-600 rounded-lg hover:bg-slate-200 cursor-pointer transition-colors"
                                                       :class="child.doc_uploading ? 'opacity-50 pointer-events-none' : ''">
                                                    <span x-text="(child.documents || []).length ? 'Прикрепить ещё' : 'Выбрать файлы'"></span>
                                                    <input type="file" multiple class="hidden" @change="selectChildDocument(taskModalIdx, cidx, $event)">
                                                </label>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                </template>

                {{-- Удаление произвольной задачи: только для внеплановых (созданных вручную),
                     и не для строки проверки главбуха (там это чужая задача). --}}
                <template x-if="tasks[taskModalIdx].is_custom && !tasks[taskModalIdx].review_for_head">
                    <div class="mt-5 pt-4 border-t border-slate-100 flex justify-end">
                        <button @click.prevent="deleteConfirm = { show: true, idx: taskModalIdx }"
                                class="inline-flex items-center gap-1.5 px-3 py-1.5 text-rose-600 text-xs font-medium rounded-lg hover:bg-rose-50 transition-colors">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                            Удалить задачу
                        </button>
                    </div>
                </template>
            </div>
        </template>
    </div>

    {{-- ===== ПОДТВЕРЖДЕНИЕ УДАЛЕНИЯ ПРОИЗВОЛЬНОЙ ЗАДАЧИ ===== --}}
    <div x-show="deleteConfirm.show"
         x-transition:enter="ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0"
         class="fixed inset-0 z-[60] flex items-center justify-center bg-black/40 px-4"
         @click.self="deleteConfirm = { show: false, idx: null }"
         @keydown.escape.window="deleteConfirm = { show: false, idx: null }"
         style="display:none">
        <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm mx-4 p-6">
            <h3 class="text-base font-semibold text-slate-800">Удалить задачу?</h3>
            <p class="text-sm text-slate-500 mt-1.5">
                Задача
                <span class="font-medium text-slate-700" x-text="deleteConfirm.idx !== null ? tasks[deleteConfirm.idx].name : ''"></span>
                будет удалена без возможности восстановления.
            </p>
            <div class="mt-5 flex justify-end gap-2">
                <button @click="deleteConfirm = { show: false, idx: null }"
                        class="px-4 py-2 text-sm text-slate-600 font-medium rounded-lg hover:bg-slate-100 transition-colors">
                    Отмена
                </button>
                <button @click="deleteTask()"
                        class="inline-flex items-center gap-1.5 px-4 py-2 bg-rose-600 text-white text-sm font-medium rounded-lg hover:bg-rose-700 transition-colors">
                    Удалить
                </button>
            </div>
        </div>
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
         {{-- Escape закрывает сначала просмотрщик документа, и только потом карточку задачи --}}
         @keydown.escape.window="docViewer.show ? closeDocViewer() : (completedItem = null)"
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
                        <p class="text-sm text-slate-500 mt-0.5">
                            <span x-text="completedItem.client_name"></span><span x-show="periodLabel(completedItem)" class="text-slate-700 font-medium" x-text="' · ' + periodLabel(completedItem)"></span>
                        </p>
                    </div>
                    <button @click="completedItem = null" class="text-slate-300 hover:text-slate-500 transition-colors flex-shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                {{-- Плашка «выполнено» (+ кто выполнил, если это задача бухгалтера) --}}
                <div class="mt-4 flex items-center gap-2 bg-emerald-50 border border-emerald-100 rounded-xl px-4 py-2.5 text-sm text-emerald-700">
                    <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                    <span>Выполнено · <span x-text="fmtCompleted(completedItem.completed_at)"></span><span x-show="completedItem.doer_name" x-text="' · бухгалтер: ' + completedItem.doer_name"></span></span>
                </div>

                {{-- Закрыта принудительно: без документа/подпунктов, с обязательной причиной --}}
                <template x-if="completedItem.force_closed">
                    <div class="mt-3 bg-amber-50 border border-amber-200 rounded-xl px-4 py-2.5">
                        <p class="text-xs font-semibold text-amber-700 mb-0.5">Закрыта принудительно — причина</p>
                        <p class="text-sm text-amber-800 whitespace-pre-line" x-text="completedItem.force_close_comment || '—'"></p>
                    </div>
                </template>

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

                {{-- Моя заметка: остаётся редактируемой и в выполненных (autosave по blur).
                     Чужая задача (comment_url = null) — заметка бухгалтера только для чтения. --}}
                <template x-if="completedItem.comment_url">
                    <div class="mt-4 pt-4 border-t border-slate-100">
                        <label class="block text-xs font-semibold text-indigo-500 uppercase tracking-wider mb-1.5">Моя заметка</label>
                        <textarea rows="2"
                                  :value="completedItem.employee_comment"
                                  @change="saveCompletedComment(completedItem, $event.target.value)"
                                  placeholder="Нюансы по задаче — чтобы вспомнить позже"
                                  class="block w-full px-3 py-2 border border-slate-200 rounded-xl text-sm text-slate-700 placeholder-slate-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500/50 whitespace-pre-line"></textarea>
                        <p class="text-xs text-slate-400 mt-1">Сохраняется автоматически</p>
                    </div>
                </template>
                <template x-if="!completedItem.comment_url && completedItem.employee_comment">
                    <div class="mt-4 pt-4 border-t border-slate-100">
                        <label class="block text-xs font-semibold text-indigo-500 uppercase tracking-wider mb-1.5">Заметка бухгалтера</label>
                        <p class="text-sm text-slate-600 whitespace-pre-line" x-text="completedItem.employee_comment"></p>
                    </div>
                </template>

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

                {{-- Документы --}}
                <template x-if="completedItem.requires_document || (completedItem.documents || []).length > 0">
                    <div class="mt-4 pt-4 border-t border-slate-100">
                        <p class="text-xs font-semibold text-slate-400 uppercase tracking-wider mb-1.5">Прикреплённые документы</p>
                        <div class="space-y-1">
                            {{-- PDF открывается тут же во встроенном просмотрщике,
                                 рядом иконка скачивания. Остальные типы — как раньше, ссылкой. --}}
                            <template x-for="doc in (completedItem.documents || [])" :key="doc.id">
                                <div class="flex items-center gap-1.5 max-w-[340px]">
                                    <template x-if="isPdf(doc)">
                                        <button type="button" @click="openDocViewer(doc)"
                                                title="Посмотреть, не покидая страницу"
                                                class="flex items-center gap-1.5 text-sm text-indigo-600 hover:text-indigo-800 underline truncate min-w-0">
                                            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                            <span class="truncate" x-text="doc.name"></span>
                                        </button>
                                    </template>
                                    <template x-if="!isPdf(doc)">
                                        <a :href="doc.url" target="_blank"
                                           class="flex items-center gap-1.5 text-sm text-indigo-600 hover:text-indigo-800 underline truncate min-w-0">
                                            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                            <span class="truncate" x-text="doc.name"></span>
                                        </a>
                                    </template>
                                    <a x-show="isPdf(doc)" :href="doc.url" title="Скачать"
                                       class="flex-shrink-0 text-slate-300 hover:text-slate-500 transition-colors">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                                    </a>
                                </div>
                            </template>
                            <template x-if="(completedItem.documents || []).length === 0">
                                <span class="text-sm text-slate-400 italic">Документы не прикреплены</span>
                            </template>
                        </div>
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
                                    <template x-if="child.requires_document && (child.documents || []).length > 0">
                                        <div class="mt-1 text-xs space-y-0.5" style="margin-left:1.625rem">
                                            <template x-for="doc in (child.documents || [])" :key="doc.id">
                                                <div class="flex items-center gap-1 max-w-[280px]">
                                                    <template x-if="isPdf(doc)">
                                                        <button type="button" @click="openDocViewer(doc)"
                                                                title="Посмотреть, не покидая страницу"
                                                                class="flex items-center gap-1 text-indigo-600 hover:text-indigo-800 underline truncate min-w-0">
                                                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"/></svg>
                                                            <span class="truncate" x-text="doc.name"></span>
                                                        </button>
                                                    </template>
                                                    <template x-if="!isPdf(doc)">
                                                        <a :href="doc.url" target="_blank"
                                                           class="flex items-center gap-1 text-indigo-600 hover:text-indigo-800 underline truncate min-w-0">
                                                            <svg class="w-3.5 h-3.5 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                                                            <span class="truncate" x-text="doc.name"></span>
                                                        </a>
                                                    </template>
                                                </div>
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

    {{-- Просмотрщик PDF: поверх карточки задачи, чтобы, закрыв его, вернуться к деталям.
         Рисует сам браузер — iframe на ссылку документа с ?inline=1. --}}
    <div x-show="docViewer.show"
         x-transition:enter="ease-out duration-150"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         class="fixed inset-0 z-[60] flex flex-col bg-black/70 p-3 sm:p-6"
         @click.self="closeDocViewer()"
         style="display:none">
        <div class="w-full max-w-5xl mx-auto flex flex-col flex-1 min-h-0 bg-white rounded-2xl shadow-2xl overflow-hidden">
            <div class="flex items-center gap-3 px-4 py-3 border-b border-slate-100 flex-shrink-0">
                <svg class="w-5 h-5 text-slate-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                <p class="text-sm font-medium text-slate-700 truncate flex-1" x-text="docViewer.name"></p>
                <a :href="docViewer.url.replace('?inline=1', '')" title="Скачать"
                   class="flex-shrink-0 p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                </a>
                <a :href="docViewer.url" target="_blank" title="Открыть в новой вкладке"
                   class="flex-shrink-0 p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                </a>
                <button type="button" @click="closeDocViewer()" title="Закрыть"
                        class="flex-shrink-0 p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            {{-- iframe создаём только когда просмотрщик открыт: иначе браузер тянет файл заранее --}}
            <template x-if="docViewer.show">
                <iframe :src="docViewer.url" :title="docViewer.name" class="flex-1 w-full min-h-0 border-0"></iframe>
            </template>
        </div>
    </div>

</div>

<script>
function buhTasks(initialTasks, year, month, allClients, completed, employees, currentEmployeeId, catalog, teamTasks, teamMembers) {
    // File-объекты держим вне реактивного state — Alpine оборачивает объекты в Proxy,
    // что ломает внутренние методы File/Blob при передаче в FormData
    const pendingFiles = new Map();
    // Кэш окна видимости вне реактивного state (чтобы геттер не пересобирал Set на каждую строку)
    let visibleCache = { key: null, list: [] };
    // Кэш матрицы чеклиста: тяжёлый расчёт (проход по всем задачам) не должен повторяться
    // на каждое из тысяч обращений из ячеек. Инвалидируется по сигнатуре ниже.
    let checklistCache = { key: null, data: null };
    // Кэш фильтрации «Выполненных» — тоже вне реактивного state: запись в реактивное
    // свойство прямо из геттера гоняла бы Alpine по кругу.
    let completedCache = { key: null, list: [] };

    return {
        tasks: initialTasks.map((t, i) => ({
            ...t,
            loading: false,
            client_resumed_at: null,
            _seq: i,
            doc_uploading: false,
            children: (t.children || []).map(c => ({ ...c, doc_uploading: false })),
        })),
        completed: completed || [],
        completedPage: 1,
        completedPerPage: 20,
        completedItem: null,
        // Фильтры вкладки «Выполненные». Вся история (90 дней) уже на клиенте,
        // поэтому фильтруем без запросов к серверу — результат мгновенный.
        completedSearch: '',        // поиск по названию, компании, заметке, исполнителю
        completedClient: 'all',     // 'all' | client_id
        completedPeriod: 'all',     // 'all' | 'today' | 'd7' | 'd30' — по дате выполнения
        completedDoer: 'all',       // 'all' | 'mine' (свои) | employee_id
        completedWithDoc: false,    // «Только с документом» — по умолчанию выключен, ничего не прячем
        // Просмотр PDF прямо в окне: DocumentController отдаёт файл с ?inline=1,
        // дальше его рисует встроенный просмотрщик браузера в iframe.
        docViewer: { show: false, name: '', url: '' },
        year,
        month,
        allClients,
        employees: employees || [],
        currentEmployeeId,
        viewMode: 'list',
        checklistFilter: { group: '', period: '' }, // совместный фильтр столбцов чеклиста (группа + период)
        ticker: null,
        now: Math.floor(Date.now() / 1000),
        clientFilter: 'all',
        // Вкладка «Задачи бухгалтеров» (только главбух): текущие задачи его бухгалтеров
        teamTasks: teamTasks || [],
        teamMembers: teamMembers || [],
        teamFilter: 'all', // фильтр по бухгалтеру: 'all' | employee_id
        todayStr: new Date().toLocaleDateString('en-CA'), // YYYY-MM-DD в локальной зоне (подсветка просрочки)
        dueFilter: 'all', // воронка по сроку: 'all' | 'today' | 'd3' | 'd7' | 'd30' (только вкладка «Список»)
        statusFilter: 'all', // фильтр колонки «Действия»: 'all' | 'pending' | 'paused' | 'running' | 'rework'
        sortBy: null, // null | 'due' (срок/периодичность) | 'period' (отчётный период)
        sortDir: null, // null = исходный порядок | 'asc' | 'desc'
        visibleLimit: 20, // бесконечная прокрутка списка: сколько строк отрисовано (по 20)
        _taskVer: 0, // версия статусов задач: растёт при изменениях, инвалидирует кэш чеклиста
        taskModalIdx: null,
        docRequiredModal: { show: false, taskIdx: null },
        checklistRequiredModal: { show: false, taskIdx: null },
        forceCloseModal: { show: false, taskIdx: null, comment: '', error: '', saving: false }, // принудительное закрытие (обязательная причина)

        showCreateModal: false,
        startConfirm: { show: false, idx: null },
        deleteConfirm: { show: false, idx: null }, // модалка удаления произвольной задачи
        reviewReject: { show: false, idx: null, comment: '' }, // модалка «вернуть на доработку» (проверка главбухом)
        catalog: catalog || [],
        newTask: {
            source: 'custom',       // 'custom' | 'catalog'
            service_id: '',         // выбранная услуга из каталога (берём только имя)
            client_id: '', name: '', description: '', requires_review: false,
            employee_id: currentEmployeeId, due_date: '',
        },
        newTaskFileName: null,      // имя выбранного документа (сам File — в pendingFiles)
        creating: false,
        createError: '',

        // Фильтр по компаниям: только компании, по которым в списке реально есть задачи.
        // allClients содержит и компании с ПУСТОЙ сметой (сотрудник — ответственный, но
        // корневых позиций/БП нет), они дают 0 задач — в фильтре им не место, иначе выбор
        // такой компании показывает пустой список. Счётчик — активные (невыполненные) задачи.
        get clientOptions() {
            const counts = {};
            this.tasks.forEach(t => {
                if (t.status === 'completed' || t.client_id == null) return;
                counts[t.client_id] = (counts[t.client_id] || 0) + 1;
            });
            return (this.allClients || [])
                .map(c => ({ id: c.id, name: c.name, count: counts[c.id] || 0 }))
                .filter(c => c.count > 0)
                .sort((a, b) => (a.name || '').localeCompare(b.name || '', 'ru'));
        },
        // Активные (невыполненные) задачи всех компаний — для «Все компании (N)»,
        // чтобы «Все» = сумме счётчиков по компаниям (в списке completed скрыты).
        get activeCount() {
            return this.tasks.filter(t => t.status !== 'completed').length;
        },
        matchesFilter(task) {
            return this.clientFilter === 'all' || String(task.client_id) === String(this.clientFilter);
        },

        // Воронка по сроку (только «Список»): показываем задачи со сроком до выбранного горизонта.
        // Просрочка всегда попадает внутрь (diff < 0). Задачи без срока — только в режиме «Все».
        matchesDue(task) {
            if (this.dueFilter === 'all') return true;
            if (task.due_date == null) return false;
            const horizon = { today: 0, d3: 3, d7: 7, d30: 30 }[this.dueFilter];
            const diff = this.dueDiffDays(task); // дней до срока; отрицательное = просрочено
            return diff !== null && diff <= horizon;
        },

        // Фильтр по состоянию из колонки «Действия»: не начаты, на паузе, в работе, на доработку.
        matchesStatus(task) {
            return this.statusFilter === 'all' || task.status === this.statusFilter;
        },

        // Счётчики по состоянию (для подписей в выпадающем списке фильтра). Считаем по активным
        // строкам с учётом остальных фильтров (компания/срок) — как их рисует список.
        get statusCounts() {
            const c = { pending: 0, paused: 0, running: 0, rework: 0 };
            for (const t of this.tasks) {
                if (t.status === 'completed') continue;
                if (!this.matchesFilter(t) || !this.matchesDue(t)) continue;
                if (c[t.status] !== undefined) c[t.status]++;
            }
            return c;
        },

        // Матрица вкладки «Чеклист»: строки — компании, столбцы — задачи, ячейка — статус (только чтение).
        // done = выполнено (зелёная галочка), review = на проверке (синий), progress = начато, но не закрыто (жёлтый),
        // none = задача есть, но не начата (пусто). Если у компании нет такой задачи — ячейка отсутствует.
        get checklistData() {
            // Мемоизация: геттер читается из тысяч ячеек (5 x-if каждая) — без кэша это
            // тысячи полных проходов по задачам. Пересчёт только при смене фильтров/состава/статусов.
            // Чеклист не зависит от фильтра по компаниям (компании — это строки матрицы).
            const key = this.checklistFilter.group + '|' + this.checklistFilter.period
                + '|' + this.tasks.length + '|' + this._taskVer;
            if (checklistCache.key === key) return checklistCache.data;

            const companyMap = {};
            const colMap = {};
            const cells = {};
            const rank = { none: 0, done: 1, review: 2, progress: 3 };
            this.tasks.filter(t => this.matchesChecklistFilter(t)).forEach(t => {
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
            // Высота шапки под самое длинное название: при −45° вертикальная проекция
            // текста = длина/√2. ~6.2px на символ при 12px шрифте; ограничиваем разумным диапазоном.
            const maxLen = Object.values(colMap).reduce((m, c) => Math.max(m, c.label.length), 0);
            const headerHeight = Math.min(340, Math.max(96, Math.round(maxLen * 6.2 / 1.414) + 24));
            const data = {
                companies: Object.values(companyMap).sort((a, b) => a.name.localeCompare(b.name, 'ru')),
                cols: Object.values(colMap).sort((a, b) => b.count - a.count || a.label.localeCompare(b.label, 'ru')),
                cells,
                headerHeight,
            };
            checklistCache = { key, data };
            return data;
        },

        // Совпадает ли задача с активными фильтрами чеклиста (группа + период, совместно/AND).
        matchesChecklistFilter(t) {
            const f = this.checklistFilter;
            if (f.group  && (t.service_group   || '') !== f.group)  return false;
            if (f.period && (t.reporting_period || '') !== f.period) return false;
            return true;
        },

        // Доступные значения для выпадающих фильтров (из всех задач, без учёта текущего фильтра).
        get checklistGroups() {
            const s = new Set();
            this.tasks.forEach(t => { if (t.service_group) s.add(t.service_group); });
            return [...s].sort((a, b) => a.localeCompare(b, 'ru'));
        },
        get checklistPeriods() {
            const s = new Set();
            this.tasks.forEach(t => { if (t.reporting_period) s.add(t.reporting_period); });
            return [...s].sort((a, b) => a.localeCompare(b, 'ru'));
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
                    due_date:         task.slot, // weekly → дата вхождения, иначе null
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
                this.patch(taskIdx, { actual_quantity: data.log.actual_quantity });
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

        // Документы можно менять, пока задача у сотрудника (не закрыта, не на проверке, не чужая review-строка)
        canEditDocs(task) {
            return !task.review_for_head && !['completed', 'review'].includes(task.status);
        },

        // Выбранные файлы (можно несколько сразу) загружаются немедленно, по одному —
        // каждый успешный сразу появляется в списке документов задачи.
        async selectRootDocument(taskIdx, event) {
            const files = Array.from(event.target.files || []);
            event.target.value = '';
            if (!files.length) return;
            const task = this.tasks[taskIdx];
            if ((task.documents || []).length + files.length > 10) {
                alert('Не больше 10 документов на задачу');
                return;
            }

            // Внеплановая задача грузит документ прямо в себя; плановая — через лог.
            let docUrl;
            if (task.type === 'adhoc') {
                docUrl = `/buhtasks/adhoc/${task.adhoc_id}/document`;
            } else {
                const logId = await this.ensureLog(taskIdx);
                if (!logId) return;
                docUrl = `/buhtasks/logs/${logId}/document`;
            }

            this.patch(taskIdx, { doc_uploading: true });
            for (const file of files) {
                const fd = new FormData();
                fd.append('file', file);
                const r = await fetch(docUrl, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' },
                    body: fd,
                });
                const data = await r.json();
                if (data.success) {
                    this.patch(taskIdx, { documents: data.log.documents });
                } else {
                    if (data.message) alert(data.message);
                    break;
                }
            }
            this.patch(taskIdx, { doc_uploading: false });
        },

        // Удаление прикреплённого документа задачи (пока не закрыта/не на проверке)
        async deleteRootDocument(taskIdx, docId) {
            const task = this.tasks[taskIdx];
            const url = task.type === 'adhoc'
                ? `/buhtasks/adhoc/${task.adhoc_id}/documents/${docId}/delete`
                : `/buhtasks/logs/${task.log_id}/documents/${docId}/delete`;
            const data = await this.post(url);
            if (data.success) {
                this.patch(taskIdx, { documents: data.log.documents });
            } else if (data.message) {
                alert(data.message);
            }
        },

        async deleteChildDocument(taskIdx, cidx, docId) {
            const task = this.tasks[taskIdx];
            const child = task.children[cidx];
            if (!child.log_id) return;
            const data = await this.post(`/buhtasks/logs/${child.log_id}/documents/${docId}/delete`);
            if (data.success) {
                task.children[cidx] = { ...child, documents: data.log.documents };
            } else if (data.message) {
                alert(data.message);
            }
        },

        async selectChildDocument(taskIdx, cidx, event) {
            const files = Array.from(event.target.files || []);
            event.target.value = '';
            if (!files.length) return;
            const task = this.tasks[taskIdx];
            if ((task.children[cidx].documents || []).length + files.length > 10) {
                alert('Не больше 10 документов на задачу');
                return;
            }

            const logId = await this.ensureChildLog(taskIdx, cidx);
            if (!logId) return;

            task.children[cidx] = { ...task.children[cidx], doc_uploading: true };
            for (const file of files) {
                const fd = new FormData();
                fd.append('file', file);
                const r = await fetch(`/buhtasks/logs/${logId}/document`, {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' },
                    body: fd,
                });
                const data = await r.json();
                if (data.success) {
                    task.children[cidx] = { ...task.children[cidx], documents: data.log.documents };
                } else {
                    if (data.message) alert(data.message);
                    break;
                }
            }
            task.children[cidx] = { ...task.children[cidx], doc_uploading: false };
        },

        // Сортировка колонок: клик переключает asc → desc → исходный порядок.
        // field: 'due' — по сроку (колонка «Периодичность»), 'period' — по отчётному периоду.
        // Переключение на другую колонку начинает с asc.
        toggleSort(field) {
            if (this.sortBy !== field) {
                this.sortBy = field;
                this.sortDir = 'asc';
            } else {
                this.sortDir = this.sortDir === 'asc' ? 'desc' : (this.sortDir === 'desc' ? null : 'asc');
                if (!this.sortDir) this.sortBy = null;
            }
            this.applySort();
        },
        applySort() {
            const bySeq = (a, b) => a._seq - b._seq;
            if (!this.sortDir || !this.sortBy) {
                this.tasks = [...this.tasks].sort(bySeq);
                return;
            }
            const mult = this.sortDir === 'desc' ? -1 : 1;
            // Сортируем по сроку напрямую; отчётный период — тоже по сроку (метка периода
            // монотонна по due_date), так одинаковые периоды идут подряд и хронологично.
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
            // Считаем только активные строки (без выполненных) — как их и рисует visibleTasks,
            // иначе сентинел бесконечной прокрутки не «догрузит» до реального конца списка.
            return this.tasks.filter(t => t.status !== 'completed' && this.matchesFilter(t) && this.matchesDue(t) && this.matchesStatus(t)).length;
        },

        // Окно бесконечной прокрутки: первые visibleLimit задач, прошедших фильтр,
        // как пары { task, idx } (idx — глобальный индекс в this.tasks для обработчиков).
        // Рендерим ТОЛЬКО этот срез (x-for по нему), а не все 300 строк — иначе Alpine
        // поднимал бы реактивность на каждую задачу и лагал при загрузке/сортировке.
        // Мемоизируем по сигнатуре зависимостей, чтобы срез не пересобирался на каждый доступ.
        get visibleTasks() {
            // _taskVer в ключе: при закрытии задачи (status → completed) она сразу пропадает
            // из активного списка и уходит во вкладку «Выполненные» — кэш пересобирается.
            const key = this.clientFilter + '|' + this.dueFilter + '|' + this.statusFilter + '|' + this.visibleLimit + '|' + this.sortBy + '|' + this.sortDir + '|' + this.tasks.length + '|' + this._taskVer;
            if (visibleCache.key !== key) {
                const list = [];
                for (let i = 0; i < this.tasks.length; i++) {
                    const task = this.tasks[i];
                    if (task.status === 'completed') continue; // выполненные — только во вкладке «Выполненные»
                    if (!this.matchesFilter(task) || !this.matchesDue(task) || !this.matchesStatus(task)) continue;
                    if (list.length >= this.visibleLimit) break;
                    list.push({ task, idx: i });
                }
                visibleCache = { key, list };
            }
            return visibleCache.list;
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

        // === Фильтры вкладки «Выполненные» ===
        // История за 90 дней целиком лежит на клиенте, поэтому фильтруем локально:
        // ни одного запроса к серверу, результат появляется по мере ввода.
        // Скорость держится на трёх вещах: строка поиска склеивается в запись один раз
        // (_s, ленивое поле), результат мемоизируется по ключу фильтров, и рисуется
        // всегда максимум одна страница (20 строк).

        /** Строка записи для поиска: название + компания + заметка + исполнитель, в нижнем регистре. */
        _completedHaystack(c) {
            if (c._s === undefined) {
                c._s = [c.name, c.client_name, c.employee_comment, c.doer_name, c.force_close_comment]
                    .filter(Boolean).join(' ').toLowerCase();
            }

            return c._s;
        },

        /** Порог даты выполнения для пресета периода: null = без ограничения. */
        _completedPeriodFrom() {
            const days = { today: 0, d7: 6, d30: 29 }[this.completedPeriod];
            if (days === undefined) return null;

            const from = new Date();
            from.setHours(0, 0, 0, 0);
            from.setDate(from.getDate() - days);

            return from;
        },

        get filteredCompleted() {
            const key = [this.completedSearch, this.completedClient, this.completedPeriod,
                this.completedDoer, this.completedWithDoc, this.completed.length].join('|');
            if (completedCache.key === key) {
                return completedCache.list;
            }

            const q    = this.completedSearch.trim().toLowerCase();
            const from = this._completedPeriodFrom();
            const list = this.completed.filter(c => {
                if (this.completedWithDoc && this.docCount(c) === 0) return false;
                if (this.completedClient !== 'all' && String(c.client_id) !== this.completedClient) return false;
                if (this.completedDoer === 'mine' && c.doer_id) return false;
                if (this.completedDoer !== 'all' && this.completedDoer !== 'mine'
                    && String(c.doer_id) !== this.completedDoer) return false;
                if (from && new Date(c.completed_at) < from) return false;
                if (q && !this._completedHaystack(c).includes(q)) return false;

                return true;
            });

            completedCache = { key, list };

            return list;
        },

        /** Компании, реально встречающиеся в истории, со счётчиками — пустых вариантов не предлагаем. */
        get completedClientOptions() {
            const byId = new Map();
            for (const c of this.completed) {
                if (!c.client_id) continue;
                const row = byId.get(c.client_id) || { id: c.client_id, name: c.client_name, count: 0 };
                row.count++;
                byId.set(c.client_id, row);
            }

            return [...byId.values()].sort((a, b) => a.name.localeCompare(b.name, 'ru'));
        },

        /** Исполнители в истории: свои задачи (doer_id пуст) + бухгалтеры. Пусто → селект скрыт. */
        get completedDoerOptions() {
            const byId = new Map();
            for (const c of this.completed) {
                if (!c.doer_id) continue;
                const row = byId.get(c.doer_id) || { id: c.doer_id, name: c.doer_name, count: 0 };
                row.count++;
                byId.set(c.doer_id, row);
            }

            return [...byId.values()].sort((a, b) => (a.name || '').localeCompare(b.name || '', 'ru'));
        },

        get completedMineCount() {
            return this.completed.filter(c => !c.doer_id).length;
        },

        /** Документов у записи (у своих и командных строк поле одно и то же). */
        docCount(c) {
            return (c.documents || []).length;
        },

        get completedWithDocCount() {
            return this.completed.filter(c => this.docCount(c) > 0).length;
        },

        get completedFiltersActive() {
            return this.completedSearch.trim() !== '' || this.completedClient !== 'all'
                || this.completedPeriod !== 'all' || this.completedDoer !== 'all'
                || this.completedWithDoc;
        },

        resetCompletedFilters() {
            this.completedSearch  = '';
            this.completedClient  = 'all';
            this.completedPeriod  = 'all';
            this.completedDoer    = 'all';
            this.completedWithDoc = false;
        },

        // Пагинация вкладки «Выполненные» (по 20) — уже по отфильтрованному набору
        get completedTotalPages() {
            return Math.max(1, Math.ceil(this.filteredCompleted.length / this.completedPerPage));
        },
        get completedPageItems() {
            const start = (this.completedPage - 1) * this.completedPerPage;
            return this.filteredCompleted.slice(start, start + this.completedPerPage);
        },
        openCompleted(c) {
            this.completedItem = c;
        },

        // === Просмотр документа без ухода со страницы ===
        // Показываем только PDF: остальные типы браузер либо скачает, либо покажет
        // непредсказуемо. Тип определяем по расширению — с сервера приходят только id, имя и ссылка.
        isPdf(doc) {
            return /\.pdf$/i.test(doc?.name || '');
        },
        openDocViewer(doc) {
            // inline=1 — единственный режим, в котором контроллер отдаёт файл
            // с Content-Disposition: inline (и только для разрешённых типов).
            this.docViewer = { show: true, name: doc.name, url: doc.url + '?inline=1' };
        },
        closeDocViewer() {
            this.docViewer = { show: false, name: '', url: '' };
        },

        /**
         * Клик по скрепке в строке «Выполненных». Один PDF — сразу открываем просмотр
         * (ради этого скрепка и кликабельная). Если файлов несколько или это не PDF,
         * показываем карточку задачи: там видны имена и можно выбрать нужный.
         */
        openDocFromRow(c) {
            const docs = c.documents || [];
            if (docs.length === 1 && this.isPdf(docs[0])) {
                this.openDocViewer(docs[0]);

                return;
            }

            this.openCompleted(c);
        },

        // Числитель: выполнено в текущем месяце. Контроллер держит в наборе только закрытые
        // в этом месяце (закрытые в прошлых месяцах из набора убраны), поэтому просто считаем completed.
        get totalCompleted() {
            return this.tasks.filter(t => t.status === 'completed').length;
        },

        // Знаменатель = всё в наборе (просрочка + задачи месяца, выполненные и нет).
        // Совпадает с «Все компании» (там тоже tasks.length).
        get totalTasks() {
            return this.tasks.length;
        },

        get totalProgressPct() {
            const total = this.totalTasks;
            return total ? Math.round(this.totalCompleted / total * 100) : 0;
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

        // Вкладка «Задачи бухгалтеров»: фильтр по исполнителю + сортировка по сроку (без срока — в конец)
        get filteredTeamTasks() {
            const list = this.teamFilter === 'all'
                ? this.teamTasks
                : this.teamTasks.filter(t => String(t.doer_id) === this.teamFilter);
            return [...list].sort((a, b) => (a.due_date || '9999') < (b.due_date || '9999') ? -1 : 1);
        },
        teamCountFor(id) {
            return this.teamTasks.filter(t => t.doer_id === id).length;
        },

        // За какой период сделана выполненная задача. Дата закрытия отвечает «когда»,
        // а этого мало: отчёт за июль могли сдать 3 августа. Помесячные/квартальные/годовые
        // получают метку словами («за июль»), еженедельные — дату срока, потому что словами
        // периода у них нет и различить вхождения можно только по ней. Внеплановые — пусто.
        periodLabel(c) {
            if (c.reporting_period) return c.reporting_period;
            if (c.due_date) return 'срок ' + this.fmtDue(c.due_date);
            return '';
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
            this.$watch('dueFilter', () => { this.visibleLimit = 20; });
            this.$watch('statusFilter', () => { this.visibleLimit = 20; });
            this.$watch('sortDir', () => { this.visibleLimit = 20; });
            this.$watch('sortBy', () => { this.visibleLimit = 20; });

            // Смена любого фильтра «Выполненных» возвращает на первую страницу — иначе
            // при сузившемся списке пользователь оказывается на пустой странице.
            ['completedSearch', 'completedClient', 'completedPeriod', 'completedDoer', 'completedWithDoc']
                .forEach(f => this.$watch(f, () => { this.completedPage = 1; }));
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

        // Точечно мутирует поля одной задачи. Важно для производительности:
        // замена элемента массива (this.tasks[idx] = {...}) заставляет x-for пере-сверять
        // все задачи (у сотрудника их сотни) — мутация свойств обновляет только одну строку.
        patch(idx, props) {
            const t = this.tasks[idx];
            for (const k in props) t[k] = props[k];
            this._taskVer++; // статус/поля изменились → кэш чеклиста пересчитается
        },

        // Применяет результат (работает для обоих типов задач)
        applyResult(idx, log) {
            const task = this.tasks[idx];
            const wasCompleted = task.status === 'completed';
            task.loading = false;
            if (task.type === 'planned') task.log_id = log.id;
            task.status = log.status;
            task.elapsed_seconds = log.elapsed_seconds;
            task.review_comment = log.review_comment ?? null;
            if (log.force_closed !== undefined) {
                task.force_closed = !!log.force_closed;
                task.force_close_comment = log.force_close_comment ?? null;
            }
            if (log.documents !== undefined) task.documents = log.documents;
            task.client_resumed_at = log.status === 'running' ? this.now : null;
            this._taskVer++; // статус изменился → кэш чеклиста/списка пересчитается

            // Задача только что закрыта → строка пропадает из активного списка (visibleTasks),
            // а её копия добавляется во вкладку «Выполненные» без перезагрузки страницы.
            if (log.status === 'completed' && !wasCompleted) {
                this.addToCompleted(task, log);
            }
        },

        // Добавляет запись во вкладку «Выполненные» (форма как у контроллера при следующем заходе).
        addToCompleted(task, log) {
            const cid = task.type === 'planned' ? 'log_' + log.id : 'adhoc_' + task.adhoc_id;
            if (this.completed.some(c => c.id === cid)) return; // защита от дубля при повторном закрытии
            this.completed.unshift({
                id: cid,
                type: task.type,
                name: task.name,
                branch_label: task.branch_label ?? null,
                client_id: task.client_id ?? null, // нужен фильтру по компании
                client_name: task.client_name ?? '—',
                doer_id: null,   // закрыть задачу может только её исполнитель — значит это своя
                doer_name: null,
                completed_at: new Date().toISOString(),
                employee_comment: task.employee_comment ?? null,
                comment_url: task.type === 'planned'
                    ? '/buhtasks/logs/' + log.id + '/comment'
                    : '/buhtasks/adhoc/' + task.adhoc_id + '/comment',
                elapsed_seconds: task.elapsed_seconds,
                description: task.description ?? null,
                comment: task.comment ?? null,
                periodicity: task.periodicity ?? null,
                // Отчётный период — как отдаёт контроллер при следующем заходе. У внеплановых
                // в активном списке due_date есть, а в истории его быть не должно: там прочерк.
                reporting_period: task.reporting_period ?? null,
                due_date: task.type === 'planned' ? (task.due_date ?? null) : null,
                allows_quantity: !!task.allows_quantity,
                quantity: task.quantity ?? 0,
                actual_quantity: task.actual_quantity ?? null,
                requires_document: !!task.requires_document,
                documents: task.documents ?? [],
                force_closed: !!task.force_closed,
                force_close_comment: task.force_close_comment ?? null,
                children: (task.children || []).map(c => ({
                    id: c.id,
                    name: c.name,
                    status: c.status,
                    allows_quantity: !!c.allows_quantity,
                    quantity: c.quantity ?? 0,
                    actual_quantity: c.actual_quantity ?? null,
                    requires_document: !!c.requires_document,
                    documents: c.documents ?? [],
                })),
            });
            this.completedPage = 1;
            if (this.taskModalIdx !== null && this.tasks[this.taskModalIdx] === task) {
                this.taskModalIdx = null; // модал закрытой задачи больше не нужен — строки нет
            }
        },

        // Заметка сотрудника в попапе активной задачи — автосохранение по blur.
        // Для плановой задачи сначала лениво создаём лог (как с количеством).
        async saveComment(idx, value) {
            const task = this.tasks[idx];
            const v = (value ?? '').trim();
            if ((task.employee_comment ?? '') === v) return; // без изменений — не дёргаем сервер

            task.employee_comment = v; // оптимистично: сразу подхватят и addToCompleted, и UI

            if (task.type === 'planned') {
                const logId = await this.ensureLog(idx);
                if (!logId) return;
            }
            const data = await this.post(this.actionUrl(task, 'comment'), { employee_comment: v });
            if (data.success) task.employee_comment = data.log.employee_comment;
        },

        // Заметка во вкладке «Выполненные» — редактируется и там (autosave по blur).
        // Эндпоинт задан прямо в записи (comment_url), т.к. записи нет в this.tasks.
        async saveCompletedComment(item, value) {
            const v = (value ?? '').trim();
            if ((item.employee_comment ?? '') === v) return;

            item.employee_comment = v; // оптимистично — строка списка и деталь обновятся сразу
            this._invalidateCompletedSearch(item); // заметка входит в поиск — пересобрать строку
            const data = await this.post(item.comment_url, { employee_comment: v });
            if (data.success) {
                item.employee_comment = data.log.employee_comment;
                this._invalidateCompletedSearch(item);
            }
        },

        /** Сбрасывает закэшированную строку поиска записи и результат фильтрации. */
        _invalidateCompletedSearch(item) {
            item._s = undefined;
            completedCache = { key: null, list: [] };
        },

        // Возвращает URL для действия в зависимости от типа задачи
        actionUrl(task, action) {
            if (task.type === 'adhoc') {
                return `/buhtasks/adhoc/${task.adhoc_id}/${action}`;
            }
            return `/buhtasks/logs/${task.log_id}/${action}`;
        },

        // ===== Проверка главбухом задачи бухгалтера (шаг 7.2) =====
        // Строка review_for_head после «Принять»/«Вернуть» уходит из списка главбуха:
        //  - принять → задача становится completed (у бухгалтера — «выполнено»);
        //  - вернуть → задача становится rework и возвращается бухгалтеру.
        // В обоих случаях у главбуха она больше не «на проверке», поэтому убираем строку.
        async approveReview(idx) {
            const task = this.tasks[idx];
            if (task.loading) return;
            task.loading = true;
            const data = await this.post(this.actionUrl(task, 'review-approve'));
            task.loading = false;
            if (data.success) this.removeReviewRow(task);
        },
        openReviewReject(idx) {
            this.reviewReject = { show: true, idx, comment: '' };
        },
        async confirmReviewReject() {
            const comment = (this.reviewReject.comment || '').trim();
            if (!comment) return;
            const task = this.tasks[this.reviewReject.idx];
            task.loading = true;
            const data = await this.post(this.actionUrl(task, 'review-reject'), { comment });
            task.loading = false;
            this.reviewReject = { show: false, idx: null, comment: '' };
            if (data.success) this.removeReviewRow(task);
        },
        removeReviewRow(task) {
            if (this.taskModalIdx !== null && this.tasks[this.taskModalIdx] === task) this.taskModalIdx = null;
            this.tasks = this.tasks.filter(t => t !== task);
            this._taskVer++; // состав задач изменился → кэши списка/чеклиста пересчитаются
        },

        // Удаление произвольной (внеплановой) задачи. Доступно только для is_custom —
        // кнопка в модалке показывается лишь у таких задач.
        async deleteTask() {
            const idx = this.deleteConfirm.idx;
            if (idx === null) return;
            const task = this.tasks[idx];
            this.deleteConfirm = { show: false, idx: null };
            if (task.loading) return;
            task.loading = true;
            const data = await this.post(this.actionUrl(task, 'delete'));
            task.loading = false;
            if (data.success) {
                if (this.taskModalIdx !== null && this.tasks[this.taskModalIdx] === task) this.taskModalIdx = null;
                this.tasks = this.tasks.filter(t => t !== task);
                this._taskVer++; // состав задач изменился → кэши списка/чеклиста пересчитаются
            }
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
                    due_date:         task.slot, // weekly → дата вхождения, иначе null
                }),
            });
            const data = await r.json();
            if (data.success) {
                this.tasks[idx].log_id = data.log.id;
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
            this.patch(idx, { loading: true });

            if (task.type === 'planned') {
                const logId = await this.ensureLog(idx);
                if (!logId) { this.patch(idx, { loading: false }); return; }
            }

            const data = await this.post(this.actionUrl(this.tasks[idx], 'start'));
            if (data.success) this.applyResult(idx, data.log);
            else this.patch(idx, { loading: false });
        },

        async resumeTask(idx) {
            this.patch(idx, { loading: true });
            const data = await this.post(this.actionUrl(this.tasks[idx], 'start'));
            if (data.success) this.applyResult(idx, data.log);
            else this.patch(idx, { loading: false });
        },

        async pauseTask(idx) {
            this.patch(idx, { loading: true });
            const data = await this.post(this.actionUrl(this.tasks[idx], 'pause'));
            if (data.success) this.applyResult(idx, data.log);
            else this.patch(idx, { loading: false });
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

            this.patch(idx, { loading: true });

            if (this.tasks[idx].type === 'planned') {
                const logId = await this.ensureLog(idx);
                if (!logId) { this.patch(idx, { loading: false }); return; }
            }

            const data = await this.post(this.actionUrl(this.tasks[idx], 'complete'));
            if (data.success) {
                this.applyResult(idx, data.log);
            } else {
                this.patch(idx, { loading: false });
                if (data.requires_document) this.docRequiredModal = { show: true, taskIdx: idx };
                if (data.requires_checklist) this.checklistRequiredModal = { show: true, taskIdx: idx };
            }
        },

        // ===== Принудительное закрытие: в обход документа и подпунктов, причина обязательна =====
        openForceClose(idx) {
            this.forceCloseModal = { show: true, taskIdx: idx, comment: '', error: '', saving: false };
        },

        closeForceClose() {
            this.forceCloseModal = { show: false, taskIdx: null, comment: '', error: '', saving: false };
        },

        async submitForceClose() {
            const idx = this.forceCloseModal.taskIdx;
            if (idx === null || this.forceCloseModal.saving) return;

            const comment = (this.forceCloseModal.comment ?? '').trim();
            if (!comment) {
                this.forceCloseModal.error = 'Укажите причину принудительного закрытия';
                return;
            }

            this.forceCloseModal.saving = true;
            this.forceCloseModal.error = '';
            this.patch(idx, { loading: true });

            const logId = await this.ensureLog(idx);
            if (!logId) {
                this.patch(idx, { loading: false });
                this.forceCloseModal.saving = false;
                this.forceCloseModal.error = 'Не удалось закрыть задачу, попробуйте ещё раз';
                return;
            }

            const data = await this.post(this.actionUrl(this.tasks[idx], 'force-complete'), { comment });
            if (data.success) {
                this.closeForceClose();
                this.applyResult(idx, data.log);
            } else {
                this.patch(idx, { loading: false });
                this.forceCloseModal.saving = false;
                this.forceCloseModal.error = data.message || 'Не удалось закрыть задачу, попробуйте ещё раз';
            }
        },

        async resetTask(idx) {
            this.patch(idx, { loading: true });
            const data = await this.post(this.actionUrl(this.tasks[idx], 'reset'));
            if (data.success) this.applyResult(idx, data.log);
            else this.patch(idx, { loading: false });
        },

        resetNewTask() {
            this.newTask = {
                source: 'custom', service_id: '',
                client_id: '', name: '', description: '', requires_review: false,
                employee_id: this.currentEmployeeId, due_date: '',
            };
            pendingFiles.delete('create');
            this.newTaskFileName = null;
        },

        // При выборе услуги из каталога подставляем её название (берём только имя).
        onCatalogPick() {
            const svc = this.catalog.find(s => String(s.id) === String(this.newTask.service_id));
            if (svc) this.newTask.name = svc.name;
        },

        // Необязательный документ к новой задаче (File держим вне реактивного state).
        selectCreateDocument(event) {
            const file = event.target.files[0];
            if (!file) return;
            pendingFiles.set('create', file);
            this.newTaskFileName = file.name;
            event.target.value = '';
        },

        async createTask() {
            this.createError = '';

            // Внеплановая задача сотруднику — в смету не пишем. Источник: своя или из каталога.
            if (!this.newTask.name.trim())  { this.createError = 'Введите название задачи'; return; }
            if (!this.newTask.employee_id)  { this.createError = 'Выберите сотрудника'; return; }
            if (!this.newTask.due_date)     { this.createError = 'Укажите дату'; return; }

            this.creating = true;

            try {
                // FormData — чтобы приложить необязательный документ в одном запросе.
                const fd = new FormData();
                fd.append('employee_id', this.newTask.employee_id);
                if (this.newTask.client_id) fd.append('client_id', this.newTask.client_id);
                if (this.newTask.source === 'catalog' && this.newTask.service_id) {
                    fd.append('service_id', this.newTask.service_id);
                }
                fd.append('name', this.newTask.name.trim());
                if (this.newTask.description.trim()) fd.append('description', this.newTask.description.trim());
                fd.append('requires_review', this.newTask.requires_review ? '1' : '0');
                fd.append('due_date', this.newTask.due_date);
                const file = pendingFiles.get('create');
                if (file) fd.append('file', file);

                const r = await fetch('/buhtasks/adhoc', {
                    method: 'POST',
                    headers: { 'X-CSRF-TOKEN': this.csrf(), 'Accept': 'application/json' },
                    body: fd,
                });
                const data = await r.json();

                if (data.success) {
                    pendingFiles.delete('create');
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
