@extends('layouts.app')
@section('title', 'Аудит — ' . $audit->client?->name)
@section('page-title', 'Аудит')

@section('content')
@php
    $completed = $audit->isCompleted();
@endphp

<div x-data="auditPage({
        auditId: {{ $audit->id }},
        completed: {{ $completed ? 'true' : 'false' }},
        sections: {{ Js::from($sections) }},
        checklist: {{ Js::from($checklist) }},
        stats: {{ Js::from($stats) }},
     })" class="space-y-4">

    {{-- Шапка аудита --}}
    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 px-6 py-4 flex items-start justify-between gap-4 flex-wrap">
        <div>
            <div class="flex items-center gap-2 text-sm text-slate-400 mb-1">
                <a href="{{ route('audit.index') }}" class="hover:text-indigo-600 transition-colors">Аудит</a>
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                <span>{{ $audit->period_label }}</span>
            </div>
            <h2 class="text-lg font-semibold text-slate-800">{{ $audit->client?->name }}</h2>
            <p class="text-sm text-slate-500 mt-0.5">
                Аудитор: {{ $audit->auditor?->full_name }}
                <span class="mx-1 text-slate-300">·</span>
                <span @class([
                    'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
                    'bg-emerald-50 text-emerald-700' => $completed,
                    'bg-indigo-50 text-indigo-700'   => !$completed,
                ])>{{ $audit->status_label }}</span>
                @if($completed && $audit->completed_at)
                    <span class="text-xs text-slate-400 ml-1">завершён {{ $audit->completed_at->format('d.m.Y') }}</span>
                @endif
            </p>
        </div>

        <div class="flex items-center gap-3">
            {{-- Отдельной кнопки «сохранить» нет: вердикты и ячейки чек-листа уходят на сервер сразу --}}
            <span x-show="!completed" class="text-xs flex items-center gap-1.5"
                  :class="saveError ? 'text-red-600' : (busy ? 'text-slate-400' : 'text-emerald-600')">
                <template x-if="busy">
                    <svg class="w-3.5 h-3.5 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="3"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v3a5 5 0 00-5 5H4z"/>
                    </svg>
                </template>
                <template x-if="!busy && !saveError">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                </template>
                <span x-text="saveError ? saveError : (busy ? 'Сохраняю…' : (savedAt ? `Сохранено в ${savedAt}` : 'Всё сохраняется автоматически'))"></span>
            </span>

            @if($completed)
                <form method="POST" action="{{ route('audit.reopen', $audit) }}">
                    @csrf
                    <button class="px-4 py-2 border border-slate-200 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-100 transition-colors">
                        Вернуть в работу
                    </button>
                </form>
            @else
                <button @click="showComplete = true"
                        class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">
                    Завершить аудит
                </button>
            @endif
        </div>
    </div>

    @if($completed && $audit->summary)
        <div class="bg-emerald-50 border border-emerald-200/60 rounded-2xl px-6 py-4">
            <p class="text-xs font-medium text-emerald-700 uppercase tracking-wider mb-1">Резюме аудитора</p>
            <p class="text-sm text-emerald-900 whitespace-pre-line">{{ $audit->summary }}</p>
        </div>
    @endif

    {{-- Сводка --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-3 gap-4">
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 p-5">
            <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">Проверено БП</p>
            <p class="mt-2 text-2xl font-semibold text-slate-800">
                <span x-text="stats.tasks_reviewed"></span>
                <span class="text-base font-normal text-slate-400">/ <span x-text="stats.tasks_total"></span></span>
            </p>
            <div class="mt-3 h-1.5 bg-slate-100 rounded-full overflow-hidden">
                <div class="h-full bg-indigo-500 rounded-full transition-all" :style="`width:${pct(stats.tasks_reviewed, stats.tasks_total)}%`"></div>
            </div>
            <p class="mt-2 text-xs text-slate-400" x-text="`осталось ${Math.max(0, stats.tasks_total - stats.tasks_reviewed)}`"></p>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 p-5">
            <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">Замечания</p>
            <p class="mt-2 text-2xl font-semibold text-slate-800" x-text="stats.critical + stats.major + stats.minor"></p>
            <div class="mt-3 flex items-center gap-1.5 flex-wrap">
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-50 text-red-700" x-text="`${stats.critical} критич.`"></span>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700" x-text="`${stats.major} сущ.`"></span>
                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600" x-text="`${stats.minor} незнач.`"></span>
            </div>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 p-5">
            <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">Чек-лист</p>
            <p class="mt-2 text-2xl font-semibold text-slate-800">
                <span x-text="clClosed()"></span>
                <span class="text-base font-normal text-slate-400">/ <span x-text="checklist.length"></span></span>
            </p>
            <div class="mt-3 h-1.5 bg-slate-100 rounded-full overflow-hidden">
                <div class="h-full bg-emerald-500 rounded-full transition-all" :style="`width:${pct(clClosed(), checklist.length)}%`"></div>
            </div>
            <p class="mt-2 text-xs text-slate-400" x-text="`${count('err')} ошибок в контрольных точках`"></p>
        </div>

    </div>

    {{-- Вкладки --}}
    <div class="border-b border-slate-200">
        <nav class="-mb-px flex space-x-8">
            <button @click="tab = 'bp'"
                    :class="tab === 'bp' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'"
                    class="py-3 px-1 border-b-2 font-medium text-sm transition-colors inline-flex items-center gap-2">
                Секции и бизнес-процессы
                <span :class="tab === 'bp' ? 'bg-indigo-50 text-indigo-600' : 'bg-slate-100 text-slate-500'"
                      class="px-2 py-0.5 rounded-full text-xs font-semibold"
                      x-text="`${stats.tasks_reviewed} / ${stats.tasks_total}`"></span>
            </button>
            <button @click="tab = 'cl'"
                    :class="tab === 'cl' ? 'border-indigo-500 text-indigo-600' : 'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300'"
                    class="py-3 px-1 border-b-2 font-medium text-sm transition-colors inline-flex items-center gap-2">
                Чек-лист
                <span :class="tab === 'cl' ? 'bg-indigo-50 text-indigo-600' : 'bg-slate-100 text-slate-500'"
                      class="px-2 py-0.5 rounded-full text-xs font-semibold"
                      x-text="`${clClosed()} / ${checklist.length}`"></span>
            </button>
        </nav>
    </div>

    {{-- ================= ВКЛАДКА 1: БП по секциям ================= --}}
    <div x-show="tab === 'bp'" class="space-y-4">
        <template x-if="sections.length === 0">
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 px-6 py-10 text-center">
                <p class="text-slate-500">За этот период у клиента нет закрытых задач.</p>
                <p class="text-sm text-slate-400 mt-1">Проверьте границы периода — задачи попадают в аудит по месяцу закрытия.</p>
            </div>
        </template>

        <template x-for="sec in sections" :key="sec.name">
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
                <button @click="toggleSection(sec.name)"
                        class="w-full px-6 py-4 flex items-center gap-4 hover:bg-slate-50 transition-colors text-left">
                    <div class="w-10 h-10 rounded-xl bg-indigo-50 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-indigo-600" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                    </div>
                    <div class="min-w-0">
                        <p class="text-sm font-semibold text-slate-800" x-text="sec.name"></p>
                        <p class="text-xs text-slate-400 mt-0.5"
                           x-text="`${sec.tasks.length} закрытых БП · проверено ${sec.tasks.filter(t => t.verdict).length}`"></p>
                    </div>
                    <div class="ml-auto flex items-center gap-3">
                        <template x-if="sec.tasks.some(t => t.verdict === 'finding')">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-50 text-red-700"
                                  x-text="`${sec.tasks.filter(t => t.verdict === 'finding').length} замечание`"></span>
                        </template>
                        <template x-if="sec.tasks.every(t => t.verdict)">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700">Участок проверен</span>
                        </template>
                        <svg class="w-5 h-5 text-slate-300 transition-transform" :class="isOpen(sec.name) && 'rotate-180'"
                             fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/></svg>
                    </div>
                </button>

                <div x-show="isOpen(sec.name)" x-cloak class="border-t border-slate-100 divide-y divide-slate-100">
                    <template x-for="t in sec.tasks" :key="t.id">
                        <button @click="openTask(sec, t)"
                                class="w-full px-6 py-4 flex items-center gap-4 hover:bg-slate-50 transition-colors text-left">
                            <div class="w-6 h-6 rounded-full flex items-center justify-center flex-shrink-0"
                                 :class="t.verdict === 'ok' ? 'bg-emerald-50 text-emerald-600' : (t.verdict === 'finding' ? 'bg-red-50 text-red-600' : 'border border-dashed border-slate-300')">
                                <svg x-show="t.verdict === 'ok'" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="3" d="M5 13l4 4L19 7"/></svg>
                                <svg x-show="t.verdict === 'finding'" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v5m0 3h.01"/></svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <p class="text-sm font-medium text-slate-800">
                                    <span x-text="t.name"></span>
                                    <span class="text-slate-400 font-normal" x-text="` · ${t.period}`"></span>
                                </p>
                                <p class="text-xs text-slate-400 mt-0.5 flex items-center gap-1.5 flex-wrap">
                                    <span x-text="`Закрыл: ${t.who}`"></span>
                                    <span class="text-slate-300">·</span>
                                    <span x-text="t.closed || 'без даты'"></span>
                                    <template x-if="t.docs.length">
                                        <span class="text-slate-300">·</span>
                                    </template>
                                    <template x-if="t.docs.length">
                                        <span x-text="`${t.docs.length} док.`"></span>
                                    </template>
                                    <template x-if="t.forced">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-amber-50 text-amber-700">закрыт принудительно</span>
                                    </template>
                                    <template x-if="t.rework > 0">
                                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium bg-slate-100 text-slate-600" x-text="`возвратов: ${t.rework}`"></span>
                                    </template>
                                </p>
                            </div>
                            <template x-if="t.state && t.state !== 'draft'">
                                <span class="px-2.5 py-0.5 rounded-full text-xs font-medium flex-shrink-0"
                                      :class="{
                                        'bg-amber-50 text-amber-700': t.state === 'open',
                                        'bg-indigo-50 text-indigo-700': t.state === 'submitted',
                                        'bg-emerald-50 text-emerald-700': t.state === 'resolved'
                                      }"
                                      x-text="t.state_label"></span>
                            </template>
                            <span class="px-2.5 py-0.5 rounded-full text-xs font-medium flex-shrink-0"
                                  :class="t.verdict === 'ok' ? 'bg-emerald-50 text-emerald-700' : (t.verdict === 'finding' ? severityChip(t.severity) : 'bg-slate-100 text-slate-500')"
                                  x-text="verdictLabel(t)"></span>
                            <svg class="w-4 h-4 text-slate-300 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </button>
                    </template>
                </div>
            </div>
        </template>
    </div>

    {{-- ================= ВКЛАДКА 2: ЧЕК-ЛИСТ ================= --}}
    <div x-show="tab === 'cl'" x-cloak class="space-y-4">
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
            <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between flex-wrap gap-3">
                <div>
                    <h3 class="text-lg font-semibold text-slate-800">Чек-лист проверки</h3>
                    <p class="text-sm text-slate-500 mt-0.5">
                        @if($audit->template)
                            Скопирован из стандарта «{{ $audit->template->name }}». Правки действуют только в этом аудите.
                        @else
                            Чек-лист заполняется вручную.
                        @endif
                    </p>
                </div>
                <div class="flex items-center gap-2" x-show="!completed">
                    <button @click="startNewSection()"
                            class="inline-flex items-center px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">
                        <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Раздел учёта
                    </button>
                </div>
            </div>

            <div class="px-6 py-3 bg-slate-50 border-b border-slate-100 flex items-center gap-2 flex-wrap">
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700" x-text="`${count('ok')} проверено`"></span>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-red-50 text-red-700" x-text="`${count('err')} ошибок`"></span>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-amber-50 text-amber-700" x-text="`${count('ask')} нужны пояснения`"></span>
                <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600" x-text="`${count('na')} не применимо`"></span>
                <span class="ml-auto text-xs text-slate-400" x-text="`${count('')} пунктов ещё не проверены`"></span>
            </div>

            <div class="overflow-x-auto">
                <table class="w-full" style="min-width:1240px">
                    <colgroup>
                        <col class="w-14"><col class="w-24"><col class="w-72"><col>
                        <col class="w-44"><col class="w-48"><col class="w-72"><col class="w-12">
                    </colgroup>
                    <thead class="bg-slate-50 border-b border-slate-200">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">№</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Счёт 1С</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Контрольная точка</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Как проверить / источник</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Статус</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Документ</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Комментарий аудитора</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>

                    <template x-for="(g, gi) in groups()" :key="g">
                        <tbody class="divide-y divide-slate-100 border-b border-slate-200">
                            <tr class="bg-slate-50/70">
                                <td colspan="8" class="px-4 py-2.5">
                                    <div class="flex items-center gap-3">
                                        <span :contenteditable="!completed"
                                              @blur="renameSection(g, $event.target.innerText)"
                                              @keydown.enter.prevent="$event.target.blur()"
                                              class="text-sm font-semibold text-slate-700 px-2 py-0.5 -ml-2 rounded-lg focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/30"
                                              :class="!completed && 'hover:bg-white cursor-text'"
                                              x-text="g"></span>
                                        <span class="text-xs text-slate-400" x-text="sectionMeta(g)"></span>
                                        <div class="ml-auto flex items-center gap-1" x-show="!completed">
                                            <button @click="addRow(g)" class="p-1.5 text-slate-400 hover:text-indigo-600 hover:bg-indigo-50 rounded-lg transition-colors" title="Добавить пункт">
                                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                            </button>
                                            <template x-if="confirmSection !== g">
                                                <button @click="confirmSection = g" class="p-1.5 text-slate-400 hover:text-red-600 hover:bg-red-50 rounded-lg transition-colors" title="Удалить раздел">
                                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                                </button>
                                            </template>
                                            <template x-if="confirmSection === g">
                                                <div class="flex items-center gap-2 text-xs">
                                                    <span class="text-slate-500" x-text="`Удалить раздел и ${rowsOf(g).length} пункт(ов)?`"></span>
                                                    <button @click="deleteSection(g)" class="px-2.5 py-1 bg-red-600 text-white font-medium rounded-lg hover:bg-red-700 transition-colors">Удалить</button>
                                                    <button @click="confirmSection = null" class="px-2.5 py-1 border border-slate-200 text-slate-600 font-medium rounded-lg hover:bg-white transition-colors">Отмена</button>
                                                </div>
                                            </template>
                                        </div>
                                    </div>
                                </td>
                            </tr>

                            <template x-for="(row, ri) in rowsOf(g)" :key="row.id">
                                <tr class="hover:bg-slate-50/70 align-top group">
                                    <td class="px-4 py-3 text-sm text-slate-400 tabular-nums" x-text="`${gi + 1}.${ri + 1}`"></td>

                                    <td class="px-2 py-2">
                                        <div :contenteditable="!completed" data-ph="—"
                                             @blur="saveCell(row, 'account', $event.target.innerText)"
                                             @keydown.enter.prevent="$event.target.blur()"
                                             class="ed px-2 py-1 text-sm text-slate-600 tabular-nums rounded-lg focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/40"
                                             :class="!completed && 'hover:bg-white hover:ring-1 hover:ring-slate-200 cursor-text'"
                                             x-text="row.account"></div>
                                    </td>

                                    <td class="px-2 py-2">
                                        <div :contenteditable="!completed" data-ph="Что проверяем…"
                                             @blur="saveCell(row, 'point', $event.target.innerText)"
                                             class="ed px-2 py-1 text-sm font-medium text-slate-800 rounded-lg focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/40"
                                             :class="!completed && 'hover:bg-white hover:ring-1 hover:ring-slate-200 cursor-text'"
                                             x-text="row.point"></div>
                                    </td>

                                    <td class="px-2 py-2">
                                        <div :contenteditable="!completed" data-ph="Как проверить, источник…"
                                             @blur="saveCell(row, 'how', $event.target.innerText)"
                                             class="ed px-2 py-1 text-xs leading-relaxed text-slate-500 rounded-lg focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/40"
                                             :class="!completed && 'hover:bg-white hover:ring-1 hover:ring-slate-200 cursor-text'"
                                             x-text="row.how"></div>
                                    </td>

                                    <td class="px-4 py-3">
                                        <select x-model="row.status" :disabled="completed"
                                                @change="saveStatus(row)"
                                                class="w-full text-xs font-medium px-2.5 py-1.5 rounded-lg border cursor-pointer focus:outline-none focus:ring-2 focus:ring-indigo-500/20 disabled:cursor-default"
                                                :class="{
                                                    'bg-white border-slate-200 text-slate-500': !row.status,
                                                    'bg-emerald-50 border-emerald-200 text-emerald-700': row.status === 'ok',
                                                    'bg-red-50 border-red-200 text-red-700': row.status === 'err',
                                                    'bg-amber-50 border-amber-200 text-amber-700': row.status === 'ask',
                                                    'bg-slate-100 border-slate-200 text-slate-500': row.status === 'na'
                                                }">
                                            <option value="">Не проверено</option>
                                            <option value="ok">Проверено</option>
                                            <option value="err">Ошибка</option>
                                            <option value="ask">Нужны пояснения</option>
                                            <option value="na">Не применимо</option>
                                        </select>
                                    </td>

                                    <td class="px-2 py-2">
                                        <div class="flex items-start gap-1">
                                            <div :contenteditable="!completed" data-ph="+ ссылка на документ"
                                                 @blur="saveCell(row, 'doc_link', $event.target.innerText)"
                                                 @keydown.enter.prevent="$event.target.blur()"
                                                 class="ed flex-1 px-2 py-1 text-xs rounded-lg focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/40 break-all"
                                                 :class="[row.doc_link ? 'text-indigo-600' : 'text-slate-400', !completed && 'hover:bg-white hover:ring-1 hover:ring-slate-200 cursor-text']"
                                                 x-text="row.doc_link"></div>
                                            <template x-if="row.doc_link">
                                                <a :href="row.doc_link" target="_blank" rel="noopener"
                                                   class="p-1 mt-0.5 text-slate-300 hover:text-indigo-600 transition-colors" title="Открыть">
                                                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                                                </a>
                                            </template>
                                        </div>
                                    </td>

                                    <td class="px-2 py-2">
                                        <div :contenteditable="!completed" data-ph="Что нашли, расхождение…"
                                             @blur="saveCell(row, 'comment', $event.target.innerText)"
                                             class="ed px-2 py-1 text-xs leading-relaxed rounded-lg focus:bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/40"
                                             :class="[row.status === 'err' ? 'text-red-600' : 'text-slate-600', !completed && 'hover:bg-white hover:ring-1 hover:ring-slate-200 cursor-text']"
                                             x-text="row.comment"></div>
                                    </td>

                                    <td class="px-2 py-3 text-right">
                                        <button x-show="!completed" @click="deleteRow(row)"
                                                class="p-1.5 text-slate-300 opacity-0 group-hover:opacity-100 hover:text-red-600 hover:bg-red-50 rounded-lg transition-all" title="Удалить пункт">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                        </button>
                                    </td>
                                </tr>
                            </template>

                            <tr x-show="!completed">
                                <td colspan="8" class="px-4 py-2">
                                    <button @click="addRow(g)" class="inline-flex items-center text-xs font-medium text-slate-400 hover:text-indigo-600 transition-colors">
                                        <svg class="w-3.5 h-3.5 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                                        <span x-text="`Добавить контрольную точку в «${g}»`"></span>
                                    </button>
                                </td>
                            </tr>
                        </tbody>
                    </template>
                </table>
            </div>

            <div class="px-6 py-4 border-t border-slate-100" x-show="!completed">
                <template x-if="!newSectionOpen">
                    <button @click="startNewSection()" class="inline-flex items-center text-sm font-medium text-slate-500 hover:text-indigo-600 transition-colors">
                        <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                        Добавить раздел учёта
                    </button>
                </template>
                <template x-if="newSectionOpen">
                    <div class="flex items-center gap-2">
                        <input x-ref="newSection" x-model="newSectionName" list="audit-section-hints"
                               @keydown.enter="addSection()" @keydown.escape="newSectionOpen = false; newSectionName = ''"
                               type="text" placeholder="Название раздела: Банк, Касса, ОСВ…"
                               class="w-80 px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                        <datalist id="audit-section-hints">
                            @foreach($sectionHints as $hint)
                                <option value="{{ $hint }}"></option>
                            @endforeach
                        </datalist>
                        <button @click="addSection()" class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">Добавить</button>
                        <button @click="newSectionOpen = false; newSectionName = ''" class="px-4 py-2 border border-slate-200 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-100 transition-colors">Отмена</button>
                    </div>
                </template>
            </div>
        </div>

        <p class="text-xs text-slate-400 px-1" x-show="!completed">
            Любую ячейку можно нажать и отредактировать — изменения сохраняются автоматически и не влияют на стандарт чек-листа.
        </p>
    </div>

    {{-- ================= ПАНЕЛЬ ЗАДАЧИ ================= --}}
    <div x-show="drawer" x-transition.opacity @click="drawer = false" x-cloak class="fixed inset-0 bg-slate-900/40 z-40"></div>
    <aside x-show="drawer" x-cloak
           x-transition:enter="transition ease-out duration-200" x-transition:enter-start="translate-x-full" x-transition:enter-end="translate-x-0"
           x-transition:leave="transition ease-in duration-150" x-transition:leave-start="translate-x-0" x-transition:leave-end="translate-x-full"
           class="fixed top-0 right-0 h-full w-[480px] max-w-[92vw] bg-white shadow-2xl z-50 flex flex-col">
        <div class="px-6 py-4 border-b border-slate-100 flex items-start gap-3">
            <div class="min-w-0">
                <p class="text-xs font-semibold text-indigo-600" x-text="cur.section"></p>
                <h3 class="text-lg font-semibold text-slate-800 mt-0.5" x-text="cur.name"></h3>
            </div>
            <button @click="drawer = false" class="ml-auto p-2 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100 transition-colors">
                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            </button>
        </div>

        <div class="flex-1 overflow-y-auto px-6 py-5 space-y-5">
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">Закрыл</p>
                    <p class="text-sm text-slate-700 mt-1" x-text="cur.who"></p>
                </div>
                <div>
                    <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">Дата закрытия</p>
                    <p class="text-sm text-slate-700 mt-1" x-text="cur.closed || '—'"></p>
                </div>
                <div>
                    <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">Плановый срок</p>
                    <p class="text-sm text-slate-700 mt-1" x-text="cur.due || '—'"></p>
                </div>
                <div>
                    <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">Проверка главбуха</p>
                    <p class="text-sm text-slate-700 mt-1" x-text="cur.reviewed ? `пройдена ${cur.reviewed}` : 'не требовалась'"></p>
                </div>
            </div>

            <template x-if="cur.forced">
                <div class="px-3 py-2.5 bg-amber-50 border border-amber-200/60 rounded-xl text-sm text-amber-800">
                    Задача закрыта принудительно — документ и чек-лист могли быть пропущены.
                </div>
            </template>

            <div>
                <p class="text-xs font-medium text-slate-400 uppercase tracking-wider mb-2">Приложенные документы</p>
                <template x-if="!cur.docs || cur.docs.length === 0">
                    <p class="text-sm text-slate-400">Документов нет</p>
                </template>
                <template x-for="d in (cur.docs || [])" :key="d.url">
                    <a :href="d.url" target="_blank" rel="noopener"
                       class="flex items-center gap-3 px-3 py-2.5 mb-2 bg-slate-50 border border-slate-200/70 rounded-xl hover:bg-white hover:border-indigo-200 transition-colors">
                        <svg class="w-4 h-4 text-indigo-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M7 21h10a2 2 0 002-2V9l-6-6H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                        <span class="text-sm text-slate-700 truncate" x-text="d.name"></span>
                        <svg class="w-3.5 h-3.5 text-slate-300 ml-auto flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                    </a>
                </template>
            </div>

            <div x-show="cur.comment">
                <p class="text-xs font-medium text-slate-400 uppercase tracking-wider mb-2">Комментарий бухгалтера</p>
                <div class="px-3 py-2.5 bg-slate-50 border border-slate-200/70 rounded-xl text-sm text-slate-600 leading-relaxed" x-text="cur.comment"></div>
            </div>
        </div>

        <div class="border-t border-slate-100 px-6 py-5 space-y-4">
            <div class="flex items-center justify-between">
                <p class="text-xs font-medium text-slate-400 uppercase tracking-wider">Вердикт аудитора</p>
                <button x-show="!completed && cur.verdict" @click="clearVerdict()"
                        class="text-xs text-slate-400 hover:text-red-600 transition-colors">Снять вердикт</button>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <button @click="!completed && (cur.verdict = 'ok')" :disabled="completed"
                        :class="cur.verdict === 'ok' ? 'border-emerald-500 bg-emerald-50 text-emerald-700' : 'border-slate-200 text-slate-600 hover:bg-slate-50'"
                        class="inline-flex items-center justify-center gap-2 px-4 py-2.5 border-2 rounded-xl text-sm font-medium transition-colors disabled:opacity-60">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                    Норма
                </button>
                <button @click="!completed && (cur.verdict = 'finding', cur.severity = cur.severity || 'major')" :disabled="completed"
                        :class="cur.verdict === 'finding' ? 'border-red-500 bg-red-50 text-red-700' : 'border-slate-200 text-slate-600 hover:bg-slate-50'"
                        class="inline-flex items-center justify-center gap-2 px-4 py-2.5 border-2 rounded-xl text-sm font-medium transition-colors disabled:opacity-60">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M12 8v5m0 3h.01"/></svg>
                    Замечание
                </button>
            </div>

            <div x-show="cur.verdict === 'finding'" x-transition class="space-y-3">
                <div>
                    <p class="text-sm font-medium text-slate-700 mb-2">Уровень</p>
                    <div class="flex gap-2">
                        <template x-for="s in severities" :key="s.v">
                            <button @click="cur.severity = s.v" :disabled="completed"
                                    :class="cur.severity === s.v ? s.on : 'border-slate-200 text-slate-500 hover:bg-slate-50'"
                                    class="px-3 py-1.5 border rounded-lg text-xs font-medium transition-colors" x-text="s.label"></button>
                        </template>
                    </div>
                </div>
                <div>
                    <p class="text-sm font-medium text-slate-700 mb-2">Что не так и почему это риск</p>
                    <textarea x-model="cur.finding" rows="4" :disabled="completed"
                              class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 disabled:bg-slate-50"
                              placeholder="Опишите расхождение…"></textarea>
                </div>
            </div>

            <p x-show="error" x-text="error" class="text-sm text-red-600"></p>

            <div class="flex gap-3 pt-1" x-show="!completed">
                <button @click="drawer = false" class="flex-1 px-4 py-2 border border-slate-200 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-100 transition-colors">Отмена</button>
                <button @click="saveVerdict()" :disabled="saving || !cur.verdict"
                        class="flex-1 px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors disabled:opacity-50">
                    <span x-text="saving ? 'Сохраняю…' : 'Сохранить вердикт'"></span>
                </button>
            </div>
        </div>
    </aside>

    {{-- ================= ЗАВЕРШЕНИЕ АУДИТА ================= --}}
    <div x-show="showComplete" x-cloak class="fixed inset-0 z-50 overflow-y-auto">
        <div class="flex items-center justify-center min-h-screen px-4">
            <div class="fixed inset-0 bg-slate-500/75" @click="showComplete = false"></div>
            <div class="relative w-full max-w-lg bg-white rounded-2xl shadow-xl p-6 z-10">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-slate-900">Завершение аудита</h3>
                    <button @click="showComplete = false" class="p-2 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <div class="space-y-3 mb-4">
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-slate-500">Проверено БП</span>
                        <span class="font-medium text-slate-800" x-text="`${stats.tasks_reviewed} из ${stats.tasks_total}`"></span>
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-slate-500">Закрыто пунктов чек-листа</span>
                        <span class="font-medium text-slate-800" x-text="`${clClosed()} из ${checklist.length}`"></span>
                    </div>
                    <div class="flex items-center justify-between text-sm">
                        <span class="text-slate-500">Найдено замечаний</span>
                        <span class="font-medium text-slate-800" x-text="stats.critical + stats.major + stats.minor"></span>
                    </div>
                </div>

                {{-- Чек-лист закрыт не полностью — завершать нельзя, показываем где именно дыры --}}
                <template x-if="count('') > 0">
                    <div class="px-4 py-3 bg-amber-50 border border-amber-200 rounded-xl mb-4">
                        <div class="flex items-start gap-2.5">
                            <svg class="w-5 h-5 text-amber-600 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M5.07 19h13.86a2 2 0 001.74-3L13.74 4a2 2 0 00-3.48 0l-6.93 12a2 2 0 001.74 3z"/></svg>
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-amber-900" x-text="`Чек-лист проверен не полностью: осталось пунктов — ${count('')}`"></p>
                                <p class="text-xs text-amber-800 mt-1">
                                    Проставьте статус каждому пункту. Если пункт к этому клиенту не относится — «Не применимо».
                                </p>
                                <ul class="mt-2 space-y-0.5">
                                    <template x-for="s in unfinishedSections()" :key="s.name">
                                        <li class="text-xs text-amber-800">
                                            <span class="font-medium" x-text="s.name"></span>
                                            <span x-text="` — ${s.left}`"></span>
                                        </li>
                                    </template>
                                </ul>
                                <button type="button" @click="showComplete = false; tab = 'cl'"
                                        class="mt-2.5 text-xs font-semibold text-amber-900 underline underline-offset-2 hover:text-amber-700">
                                    Перейти к чек-листу
                                </button>
                            </div>
                        </div>
                    </div>
                </template>

                {{-- По БП вердикт не обязателен: предупреждаем, но не мешаем --}}
                <template x-if="count('') === 0 && stats.tasks_reviewed < stats.tasks_total">
                    <div class="px-4 py-3 bg-slate-50 border border-slate-200 rounded-xl text-sm text-slate-600 mb-4"
                         x-text="`По ${stats.tasks_total - stats.tasks_reviewed} закрытым БП вердикт не вынесен — они останутся непроверенными.`"></div>
                </template>

                <form method="POST" action="{{ route('audit.complete', $audit) }}">
                    @csrf
                    <input type="hidden" name="force" :value="count('') > 0 ? 1 : 0">

                    {{-- Передача замечаний бухгалтерам: одним пакетом по итогам аудита --}}
                    @if($transfer->isNotEmpty())
                        <div class="mb-5">
                            <p class="text-sm font-medium text-slate-700 mb-1">Передать на исправление</p>
                            <p class="text-xs text-slate-400 mb-3">
                                По каждому отмеченному замечанию бухгалтеру создастся задача в его списке.
                                Снимите галочку, если замечание остаётся наблюдением без исправления.
                            </p>

                            <div class="space-y-3 max-h-72 overflow-y-auto pr-1">
                                @foreach($transfer as $item)
                                    <div class="border border-slate-200 rounded-xl p-3">
                                        <label class="flex items-start gap-2.5 cursor-pointer">
                                            <input type="checkbox" name="findings[{{ $item['id'] }}][send]" value="1" checked
                                                   class="mt-0.5 w-4 h-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500/30">
                                            <span class="min-w-0">
                                                <span class="block text-sm font-medium text-slate-800">{{ $item['task_name'] }}</span>
                                                <span class="block text-xs text-slate-400 mt-0.5">
                                                    {{ $item['section'] }}
                                                    <span class="mx-1 text-slate-300">·</span>
                                                    <span @class([
                                                        'font-medium',
                                                        'text-red-600'   => $item['severity'] === 'critical',
                                                        'text-amber-600' => $item['severity'] === 'major',
                                                        'text-slate-500' => $item['severity'] === 'minor',
                                                    ])>{{ $item['severity_label'] }}</span>
                                                </span>
                                                @if($item['comment'])
                                                    <span class="block text-xs text-slate-500 mt-1 line-clamp-2">{{ $item['comment'] }}</span>
                                                @endif
                                            </span>
                                        </label>

                                        <div class="grid grid-cols-2 gap-2 mt-3 pl-6">
                                            <div>
                                                <label class="block text-xs text-slate-400 mb-1">Исполнитель</label>
                                                <select name="findings[{{ $item['id'] }}][assignee_id]"
                                                        class="block w-full px-2.5 py-1.5 border border-slate-200 rounded-lg text-xs bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
                                                    @foreach($employees as $employee)
                                                        <option value="{{ $employee->id }}" @selected($item['assignee_id'] === $employee->id)>{{ $employee->full_name }}</option>
                                                    @endforeach
                                                </select>
                                            </div>
                                            <div>
                                                <label class="block text-xs text-slate-400 mb-1">Срок</label>
                                                <input type="date" name="findings[{{ $item['id'] }}][due_date]" value="{{ $item['due_date'] }}"
                                                       class="block w-full px-2.5 py-1.5 border border-slate-200 rounded-lg text-xs focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </div>
                    @endif
                    <label class="block text-sm font-medium text-slate-700 mb-1">Резюме аудитора</label>
                    <textarea name="summary" rows="4"
                              class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                              placeholder="Главные выводы, что исправить в первую очередь…"></textarea>
                    <div class="flex gap-3 mt-5">
                        <button type="button" @click="showComplete = false"
                                class="flex-1 px-4 py-2 border border-slate-200 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-100 transition-colors">Отмена</button>
                        <button type="submit"
                                :class="count('') > 0 ? 'bg-amber-600 hover:bg-amber-700' : 'bg-indigo-600 hover:bg-indigo-700'"
                                class="flex-1 px-4 py-2 text-white text-sm font-medium rounded-lg transition-colors"
                                x-text="count('') > 0 ? 'Всё равно завершить' : 'Завершить аудит'"></button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

@push('styles')
<style>
    [x-cloak] { display: none !important; }
    .ed:empty::before { content: attr(data-ph); color: #cbd5e1; }
</style>
@endpush

<script>
function auditPage(init) {
    const csrf = document.querySelector('meta[name="csrf-token"]').content;

    return {
        auditId: init.auditId,
        completed: init.completed,
        sections: init.sections,
        checklist: init.checklist,
        stats: init.stats,

        tab: 'bp',
        open: init.sections.length ? [init.sections[0].name] : [],
        drawer: false,
        showComplete: false,
        confirmSection: null,
        newSectionOpen: false,
        newSectionName: '',
        saving: false,
        error: '',
        cur: {},
        curTask: null,

        // Индикатор автосохранения в шапке
        busy: 0,
        savedAt: null,
        saveError: '',

        /** Любой запрос к серверу идёт через api(): он же ведёт индикатор «Сохраняю…/Сохранено». */
        async api(url, body, method = 'POST') {
            this.busy++;
            this.saveError = '';
            try {
                const res = await fetch(url, {
                    method,
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
                    body: JSON.stringify(body || {}),
                });
                const data = await res.json().catch(() => ({}));
                if (!res.ok) throw new Error(data.message || 'Не удалось сохранить');
                this.savedAt = new Date().toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' });
                return data;
            } catch (e) {
                this.saveError = 'Не сохранено: ' + e.message;
                throw e;
            } finally {
                this.busy--;
            }
        },

        severities: [
            { v: 'critical', label: 'Критично',      on: 'border-red-500 bg-red-50 text-red-700' },
            { v: 'major',    label: 'Существенно',   on: 'border-amber-500 bg-amber-50 text-amber-700' },
            { v: 'minor',    label: 'Незначительно', on: 'border-slate-400 bg-slate-100 text-slate-700' },
        ],

        /* --- общее --- */
        pct(a, b) { return b ? Math.round(a / b * 100) : 0; },
        isOpen(name) { return this.open.includes(name); },
        toggleSection(name) {
            this.open = this.isOpen(name) ? this.open.filter(n => n !== name) : [...this.open, name];
        },

        /* --- вкладка БП --- */
        verdictLabel(t) {
            if (t.verdict === 'ok') return 'Норма';
            if (t.verdict !== 'finding') return 'Не проверено';
            const s = this.severities.find(x => x.v === t.severity);
            return s ? s.label : 'Замечание';
        },
        severityChip(severity) {
            if (severity === 'critical') return 'bg-red-50 text-red-700';
            if (severity === 'minor') return 'bg-slate-100 text-slate-600';
            return 'bg-amber-50 text-amber-700';
        },
        openTask(sec, t) {
            this.curTask = t;
            this.error = '';
            this.cur = { section: sec.name, ...t };
            this.drawer = true;
        },
        async saveVerdict() {
            if (!this.cur.verdict) return;
            this.saving = true;
            this.error = '';
            try {
                const data = await this.api(`/audit/${this.auditId}/verdict`, {
                    buh_task_log_id: this.cur.id,
                    verdict: this.cur.verdict,
                    severity: this.cur.verdict === 'finding' ? this.cur.severity : null,
                    comment: this.cur.finding || null,
                });
                Object.assign(this.curTask, {
                    verdict: this.cur.verdict,
                    severity: this.cur.verdict === 'finding' ? this.cur.severity : null,
                    finding: this.cur.finding || null,
                });
                this.stats = { ...this.stats, ...data.stats };
                this.drawer = false;
            } catch (e) {
                this.error = e.message;
            } finally {
                this.saving = false;
            }
        },
        async clearVerdict() {
            try {
                const data = await this.api(`/audit/${this.auditId}/verdict/delete`, { buh_task_log_id: this.cur.id });
                Object.assign(this.curTask, { verdict: null, severity: null, finding: null });
                this.stats = { ...this.stats, ...data.stats };
                this.drawer = false;
            } catch (e) {
                this.error = e.message;
            }
        },

        /* --- вкладка чек-листа --- */
        groups() {
            const out = [];
            this.checklist.forEach(r => { if (!out.includes(r.section)) out.push(r.section); });
            return out;
        },
        rowsOf(g) { return this.checklist.filter(r => r.section === g); },
        count(status) { return this.checklist.filter(r => (r.status || '') === status).length; },
        clClosed() { return this.checklist.filter(r => r.status).length; },
        /** Разделы, где остались непроверенные пункты — для предупреждения при завершении. */
        unfinishedSections() {
            return this.groups()
                .map(name => ({ name, left: this.rowsOf(name).filter(r => !r.status).length }))
                .filter(s => s.left > 0);
        },
        sectionMeta(g) {
            const list = this.rowsOf(g);
            const done = list.filter(r => r.status).length;
            const errs = list.filter(r => r.status === 'err').length;
            return `${done} / ${list.length} закрыто` + (errs ? ` · ${errs} ошиб.` : '');
        },
        async saveCell(row, field, value) {
            if (this.completed) return;
            const clean = typeof value === 'string' ? value.trim() : value;
            if ((row[field] || '') === (clean || '')) return;
            row[field] = clean;
            try {
                const data = await this.api(`/audit/${this.auditId}/checklist/${row.id}`, { [field]: clean ?? '' }, 'PUT');
                if (data.stats) this.stats = { ...this.stats, ...data.stats };
            } catch (e) {
                alert(e.message);
            }
        },
        /** Статус меняет x-model до вызова, поэтому шлём его отдельно, без сравнения «было/стало». */
        async saveStatus(row) {
            if (this.completed) return;
            try {
                const data = await this.api(`/audit/${this.auditId}/checklist/${row.id}`, { status: row.status || '' }, 'PUT');
                if (data.stats) this.stats = { ...this.stats, ...data.stats };
            } catch (e) {
                alert(e.message);
            }
        },
        async addRow(section) {
            try {
                const data = await this.api(`/audit/${this.auditId}/checklist`, { section });
                this.checklist.push({
                    id: data.item.id, section, account: '', point: '', how: '',
                    status: '', doc_link: '', comment: '',
                });
            } catch (e) {
                alert(e.message);
            }
        },
        async deleteRow(row) {
            try {
                const data = await this.api(`/audit/${this.auditId}/checklist/${row.id}`, {}, 'DELETE');
                this.checklist = this.checklist.filter(r => r.id !== row.id);
                if (data.stats) this.stats = { ...this.stats, ...data.stats };
            } catch (e) {
                alert(e.message);
            }
        },
        startNewSection() {
            this.newSectionOpen = true;
            this.$nextTick(() => this.$refs.newSection && this.$refs.newSection.focus());
        },
        async addSection() {
            const name = this.newSectionName.trim();
            if (!name) return;
            await this.addRow(name);
            this.newSectionName = '';
            this.newSectionOpen = false;
        },
        async renameSection(from, value) {
            const to = (value || '').trim();
            if (this.completed || !to || to === from) return;
            try {
                await this.api(`/audit/${this.auditId}/checklist/section/rename`, { from, to });
                this.checklist.forEach(r => { if (r.section === from) r.section = to; });
            } catch (e) {
                alert(e.message);
            }
        },
        async deleteSection(section) {
            try {
                const data = await this.api(`/audit/${this.auditId}/checklist/section/delete`, { section });
                this.checklist = this.checklist.filter(r => r.section !== section);
                this.confirmSection = null;
                if (data.stats) this.stats = { ...this.stats, ...data.stats };
            } catch (e) {
                alert(e.message);
            }
        },
    };
}
</script>
@endsection
