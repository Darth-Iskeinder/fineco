@extends('layouts.app')

@section('title', 'Смета — ' . $client->name)
@section('page-title', 'Смета')

@section('content')
<style>
[x-cloak]{display:none!important}
.toggle-btn { transition: background-color .2s; }
</style>

@php
$prevMonth  = $month === 1 ? 12 : $month - 1;
$prevYear   = $month === 1 ? $year - 1 : $year;
$nextMonth  = $month === 12 ? 1 : $month + 1;
$nextYear   = $month === 12 ? $year + 1 : $year;
$monthNames = ['', 'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];
@endphp

<div x-data="estimatePage(
    {{ $client->id }},
    {{ json_encode($tariffBPs) }},
    {{ json_encode($extras) }},
    {{ json_encode($estimate->notes ?? '') }},
    {{ json_encode($estimate->updated_at?->format('d.m.Y H:i')) }},
    {{ json_encode($allServices) }},
    {{ $year }},
    {{ $month }}
)">

    <!-- Хлебные крошки -->
    <div class="flex items-center gap-2 mb-6 text-sm text-slate-500">
        <a href="{{ route('clients.index') }}" class="hover:text-slate-700">Клиенты</a>
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        <a href="{{ route('clients.show', $client) }}" class="hover:text-slate-700">{{ $client->name }}</a>
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        <span class="text-slate-800 font-medium">Смета</span>
    </div>

    <!-- Шапка -->
    <div class="flex items-start justify-between mb-6">
        <div>
            <h1 class="text-2xl font-bold text-slate-900">{{ $client->name }}</h1>
            <p class="text-sm text-slate-500 mt-1">
                ИНН: {{ $client->inn }}
                @if($client->taxSystem) · {{ $client->taxSystem->name }} @endif
                @if($client->tariff) · Тариф: {{ $client->tariff->name }} @endif
            </p>
        </div>
        <div class="flex items-center gap-2 flex-wrap justify-end">

            <!-- Навигация по месяцам -->
            <div class="flex items-center bg-white border border-slate-200 rounded-xl px-1 py-1">
                <a href="/clients/{{ $client->id }}/estimate/edit?year={{ $prevYear }}&month={{ $prevMonth }}"
                   class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                </a>
                <span class="text-sm font-semibold text-slate-800 px-3 min-w-[130px] text-center">
                    {{ $monthNames[$month] }} {{ $year }}
                </span>
                <a href="/clients/{{ $client->id }}/estimate/edit?year={{ $nextYear }}&month={{ $nextMonth }}"
                   class="p-1.5 rounded-lg text-slate-400 hover:text-slate-700 hover:bg-slate-100 transition-colors">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                </a>
            </div>

            <a href="/clients/{{ $client->id }}/estimate/pdf?year={{ $year }}&month={{ $month }}" target="_blank"
               class="inline-flex items-center px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
                <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                PDF
            </a>
            <button @click="save()" :disabled="saving"
                    class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 disabled:opacity-60 transition-colors">
                <svg x-show="saving" class="w-4 h-4 mr-1.5 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                <span x-text="saving ? 'Сохранение...' : 'Сохранить'"></span>
            </button>
        </div>
    </div>

    <div class="space-y-4">

        <!-- Кнопка "Сформировать из прошлого месяца" -->
        @if($hasPrevious && !$estimateHasItems)
        <div class="bg-violet-50 border border-violet-200 rounded-2xl p-5 flex items-center justify-between">
            <div>
                <p class="text-sm font-semibold text-violet-900">Смета за {{ $monthNames[$month] }} {{ $year }} пустая</p>
                <p class="text-xs text-violet-600 mt-0.5">Перенести постоянные услуги из {{ $monthNames[$prevMonth] }} {{ $prevYear }}?</p>
            </div>
            <button type="button" @click="generate()" :disabled="generating"
                    class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-violet-600 rounded-lg hover:bg-violet-700 disabled:opacity-60 transition-colors flex-shrink-0 ml-4">
                <svg x-show="generating" class="w-4 h-4 mr-1.5 animate-spin" fill="none" viewBox="0 0 24 24">
                    <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                    <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                </svg>
                <span x-text="generating ? 'Формируем...' : 'Сформировать'"></span>
            </button>
        </div>
        @endif

        <!-- Блок: Услуги по тарифу (всегда постоянные) -->
        <template x-if="tariffBPs.length > 0">
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
                <div class="px-6 py-3 bg-slate-50 border-b border-slate-100 flex items-center gap-3">
                    <h2 class="text-sm font-semibold text-slate-600 uppercase tracking-wider">Услуги по тарифу</h2>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-700">Постоянные</span>
                </div>
                <div class="divide-y divide-slate-100">
                    <template x-for="(bp, bpIdx) in tariffBPs" :key="bp.service_id">
                        <div>
                            <!-- Строка БП -->
                            <div class="flex items-center gap-4 px-6 py-4"
                                 :class="bp.enabled ? '' : 'opacity-50'">
                                <!-- Toggle -->
                                <button type="button" @click="bp.enabled = !bp.enabled"
                                        class="toggle-btn flex-shrink-0 w-11 h-6 rounded-full relative"
                                        :class="bp.enabled ? 'bg-indigo-600' : 'bg-slate-200'">
                                    <span class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform duration-200"
                                          :style="bp.enabled ? 'transform: translateX(20px)' : ''"></span>
                                </button>

                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <p class="text-sm font-medium text-slate-800" x-text="bp.name"></p>
                                        <template x-if="bp.children.length > 0">
                                            <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-xs font-medium bg-indigo-50 text-indigo-500 flex-shrink-0">
                                                <svg class="w-3 h-3 transition-transform duration-200" :class="bp.enabled ? 'rotate-90' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                                                <span x-text="bp.children.length + ' подп.'"></span>
                                            </span>
                                        </template>
                                    </div>
                                    <p class="text-xs text-slate-400 mt-0.5" x-show="bp.periodicity" x-text="bp.periodicity"></p>
                                </div>

                                <template x-if="bp.allows_quantity && bp.enabled && !bp.children.some(c => c.enabled)">
                                    <div class="flex items-center gap-1.5">
                                        <span class="text-xs text-slate-500">Кол-во:</span>
                                        <div class="flex items-center border border-slate-200 rounded-lg overflow-hidden">
                                            <button type="button" @click="bp.quantity = Math.max(1, bp.quantity - 1)"
                                                    class="px-2 py-1 text-slate-500 hover:bg-slate-100 text-sm leading-none select-none">−</button>
                                            <span class="px-3 py-1 text-sm min-w-[2rem] text-center" x-text="bp.quantity"></span>
                                            <button type="button" @click="bp.quantity++"
                                                    class="px-2 py-1 text-slate-500 hover:bg-slate-100 text-sm leading-none select-none">+</button>
                                        </div>
                                    </div>
                                </template>

                                <div class="text-right flex-shrink-0">
                                    <template x-if="bp.children.length === 0">
                                        <span class="text-sm font-semibold text-slate-800" x-text="fmt(bp.cost * (bp.allows_quantity ? bp.quantity : 1))"></span>
                                    </template>
                                    <template x-if="bp.children.length > 0 && !bp.enabled">
                                        <span class="text-sm font-semibold text-slate-400">—</span>
                                    </template>
                                    <template x-if="bp.children.length > 0 && bp.enabled && !bp.children.some(c => c.enabled)">
                                        <span class="text-xs text-slate-400 italic">выберите подпункты</span>
                                    </template>
                                    <template x-if="bp.children.length > 0 && bp.enabled && bp.children.some(c => c.enabled)">
                                        <span class="text-sm font-semibold text-slate-800" x-text="fmt(bpTotal(bp))"></span>
                                    </template>
                                </div>
                            </div>

                            <!-- Подпункты -->
                            <template x-if="bp.enabled && bp.children.length > 0">
                                <div class="bg-slate-50/60 border-t border-slate-100 pl-14 pr-6 py-2 space-y-1">
                                    <template x-for="(child, cIdx) in bp.children" :key="child.service_id">
                                        <div class="flex items-center gap-4 py-2"
                                             :class="child.enabled ? '' : 'opacity-50'">
                                            <button type="button" @click="child.enabled = !child.enabled"
                                                    class="toggle-btn flex-shrink-0 w-9 h-5 rounded-full relative"
                                                    :class="child.enabled ? 'bg-indigo-500' : 'bg-slate-200'">
                                                <span class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform duration-200"
                                                      :style="child.enabled ? 'transform: translateX(16px)' : ''"></span>
                                            </button>

                                            <div class="flex-1 min-w-0">
                                                <p class="text-sm text-slate-700" x-text="child.name"></p>
                                                <p class="text-xs text-slate-400" x-show="child.periodicity" x-text="child.periodicity"></p>
                                            </div>

                                            <template x-if="child.allows_quantity && child.enabled">
                                                <div class="flex items-center gap-1.5">
                                                    <span class="text-xs text-slate-500">Кол-во:</span>
                                                    <div class="flex items-center border border-slate-200 rounded-lg overflow-hidden">
                                                        <button type="button" @click="child.quantity = Math.max(1, child.quantity - 1)"
                                                                class="px-2 py-1 text-slate-500 hover:bg-slate-100 text-sm leading-none select-none">−</button>
                                                        <span class="px-3 py-1 text-sm min-w-[2rem] text-center" x-text="child.quantity"></span>
                                                        <button type="button" @click="child.quantity++"
                                                                class="px-2 py-1 text-slate-500 hover:bg-slate-100 text-sm leading-none select-none">+</button>
                                                    </div>
                                                </div>
                                            </template>

                                            <span class="text-sm text-slate-600 flex-shrink-0"
                                                  x-text="fmt(child.cost * (child.allows_quantity ? child.quantity : 1))"></span>
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </div>
        </template>

        <template x-if="tariffBPs.length === 0">
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 px-6 py-8 text-center text-slate-400 text-sm">
                У клиента не выбран тариф или в тарифе нет бизнес-процессов.
            </div>
        </template>

        <!-- Блок: Дополнительные услуги -->
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
            <div class="px-6 py-3 bg-slate-50 border-b border-slate-100 flex items-center justify-between">
                <h2 class="text-sm font-semibold text-slate-600 uppercase tracking-wider">Дополнительные услуги</h2>
                <button type="button" @click="showExtraModal = true; catalogSearch = ''; newExtraType = 'one_time'"
                        class="inline-flex items-center text-xs text-indigo-600 hover:text-indigo-800 font-medium">
                    <svg class="w-3.5 h-3.5 mr-1" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/></svg>
                    Добавить
                </button>
            </div>

            <template x-if="extras.length === 0">
                <div class="px-6 py-6 text-center text-slate-400 text-sm">
                    Нет дополнительных услуг.
                    <button type="button" @click="showExtraModal = true; catalogSearch = ''; newExtraType = 'one_time'"
                            class="text-indigo-500 hover:text-indigo-700 font-medium ml-1">Добавить из каталога</button>
                </div>
            </template>

            <div class="divide-y divide-slate-100">
                <template x-for="(extra, eIdx) in extras" :key="eIdx">
                    <div>
                        <div class="flex items-center gap-3 px-6 py-4">

                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <p class="text-sm font-medium text-slate-800" x-text="extra.name"></p>
                                    <!-- Бейдж типа -->
                                    <button type="button"
                                            @click="extra.type = extra.type === 'recurring' ? 'one_time' : 'recurring'"
                                            class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium transition-colors cursor-pointer"
                                            :class="extra.type === 'recurring' ? 'bg-emerald-100 text-emerald-700 hover:bg-emerald-200' : 'bg-amber-100 text-amber-700 hover:bg-amber-200'"
                                            :title="extra.type === 'recurring' ? 'Постоянная — нажмите чтобы сделать временной' : 'Временная — нажмите чтобы сделать постоянной'"
                                            x-text="extra.type === 'recurring' ? 'Постоянная' : 'Временная'">
                                    </button>
                                </div>
                                <p class="text-xs text-slate-400 mt-0.5" x-show="extra.periodicity" x-text="extra.periodicity"></p>
                            </div>

                            <template x-if="extra.allows_quantity">
                                <div class="flex items-center gap-1.5">
                                    <span class="text-xs text-slate-500">Кол-во:</span>
                                    <div class="flex items-center border border-slate-200 rounded-lg overflow-hidden">
                                        <button type="button" @click="extra.quantity = Math.max(1, extra.quantity - 1)"
                                                class="px-2 py-1 text-slate-500 hover:bg-slate-100 text-sm leading-none select-none">−</button>
                                        <span class="px-3 py-1 text-sm min-w-[2rem] text-center" x-text="extra.quantity"></span>
                                        <button type="button" @click="extra.quantity++"
                                                class="px-2 py-1 text-slate-500 hover:bg-slate-100 text-sm leading-none select-none">+</button>
                                    </div>
                                </div>
                            </template>

                            <span class="text-sm font-semibold text-slate-800 flex-shrink-0"
                                  x-text="fmt(extra.cost * (extra.allows_quantity ? extra.quantity : 1))"></span>

                            <button type="button" @click="extras.splice(eIdx, 1)"
                                    class="p-1 text-slate-300 hover:text-red-500 transition-colors rounded flex-shrink-0">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>

                        <!-- Подпункты доп. услуги -->
                        <template x-if="extra.service_id && getCatalogChildren(extra.service_id).length > 0">
                            <div class="bg-slate-50/60 border-t border-slate-100 pl-14 pr-6 py-2 space-y-1">
                                <template x-for="(child, cIdx) in getCatalogChildren(extra.service_id)" :key="child.id">
                                    <div class="flex items-center gap-4 py-2"
                                         :class="extraChildEnabled(eIdx, child.id) ? '' : 'opacity-50'">
                                        <button type="button" @click="toggleExtraChild(eIdx, child)"
                                                class="toggle-btn flex-shrink-0 w-9 h-5 rounded-full relative"
                                                :class="extraChildEnabled(eIdx, child.id) ? 'bg-indigo-500' : 'bg-slate-200'">
                                            <span class="absolute top-0.5 left-0.5 w-4 h-4 bg-white rounded-full shadow transition-transform duration-200"
                                                  :style="extraChildEnabled(eIdx, child.id) ? 'transform: translateX(16px)' : ''"></span>
                                        </button>
                                        <div class="flex-1">
                                            <p class="text-sm text-slate-700" x-text="child.name"></p>
                                        </div>
                                        <template x-if="child.allows_quantity && extraChildEnabled(eIdx, child.id)">
                                            <div class="flex items-center gap-1.5">
                                                <span class="text-xs text-slate-500">Кол-во:</span>
                                                <div class="flex items-center border border-slate-200 rounded-lg overflow-hidden">
                                                    <button type="button" @click="setExtraChildQty(eIdx, child.id, Math.max(1, getExtraChildQty(eIdx, child.id) - 1))"
                                                            class="px-2 py-1 text-slate-500 hover:bg-slate-100 text-sm leading-none select-none">−</button>
                                                    <span class="px-3 py-1 text-sm min-w-[2rem] text-center" x-text="getExtraChildQty(eIdx, child.id)"></span>
                                                    <button type="button" @click="setExtraChildQty(eIdx, child.id, getExtraChildQty(eIdx, child.id) + 1)"
                                                            class="px-2 py-1 text-slate-500 hover:bg-slate-100 text-sm leading-none select-none">+</button>
                                                </div>
                                            </div>
                                        </template>
                                        <span class="text-sm text-slate-600 flex-shrink-0" x-text="fmt(child.cost * getExtraChildQty(eIdx, child.id))"></span>
                                    </div>
                                </template>
                            </div>
                        </template>
                    </div>
                </template>
            </div>
        </div>

        <!-- Примечания + итого -->
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 p-6">
            <div class="flex items-start gap-6">
                <div class="flex-1">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Примечания</label>
                    <textarea x-model="notes" rows="2" placeholder="Дополнительная информация..."
                              class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 resize-none"></textarea>
                </div>
                <div class="text-right flex-shrink-0 pt-6">
                    <p class="text-sm text-slate-500 mb-1">Итого по смете</p>
                    <p class="text-3xl font-bold text-slate-900" x-text="fmt(grandTotal())"></p>
                </div>
            </div>
        </div>
    </div>

    <p x-show="updatedAt" class="text-xs text-slate-400 mt-3 text-right">
        Последнее сохранение: <span x-text="updatedAt"></span>
    </p>

    <!-- Модал: добавить доп. услугу -->
    <div x-show="showExtraModal" class="fixed inset-0 z-50 overflow-y-auto" style="display:none">
        <div class="flex items-center justify-center min-h-screen px-4 py-8">
            <div @click="showExtraModal = false" class="fixed inset-0 bg-slate-500/75"></div>
            <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-lg p-6">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-semibold text-slate-900">Добавить услугу</h3>
                    <button @click="showExtraModal = false" class="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>

                <!-- Выбор типа: Временная / Постоянная -->
                <div class="flex items-center gap-1 p-1 bg-slate-100 rounded-xl mb-4">
                    <button type="button" @click="newExtraType = 'one_time'"
                            class="flex-1 py-2 px-3 rounded-lg text-xs font-semibold transition-all"
                            :class="newExtraType === 'one_time'
                                ? 'bg-white shadow text-amber-700 ring-1 ring-amber-200'
                                : 'text-slate-500 hover:text-slate-700'">
                        Временная
                        <span class="block text-xs font-normal mt-0.5 leading-tight"
                              :class="newExtraType === 'one_time' ? 'text-amber-500' : 'text-slate-400'">
                            только этот месяц
                        </span>
                    </button>
                    <button type="button" @click="newExtraType = 'recurring'"
                            class="flex-1 py-2 px-3 rounded-lg text-xs font-semibold transition-all"
                            :class="newExtraType === 'recurring'
                                ? 'bg-white shadow text-emerald-700 ring-1 ring-emerald-200'
                                : 'text-slate-500 hover:text-slate-700'">
                        Постоянная
                        <span class="block text-xs font-normal mt-0.5 leading-tight"
                              :class="newExtraType === 'recurring' ? 'text-emerald-500' : 'text-slate-400'">
                            переходит каждый месяц
                        </span>
                    </button>
                </div>

                <div class="flex gap-2 mb-3">
                    <input type="text" x-model="catalogSearch" placeholder="Поиск по каталогу..."
                           class="flex-1 px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                    <button type="button" @click="showCustomForm = !showCustomForm"
                            class="px-3 py-2 text-sm font-medium text-slate-600 bg-slate-100 rounded-lg hover:bg-slate-200 transition-colors"
                            x-text="showCustomForm ? 'Из каталога' : 'Своя'">
                    </button>
                </div>

                <!-- Из каталога -->
                <div x-show="!showCustomForm" class="space-y-1 max-h-64 overflow-y-auto">
                    <template x-for="svc in filteredExtraServices" :key="svc.id">
                        <button type="button" @click="addExtra(svc)"
                                class="w-full flex items-center justify-between px-4 py-3 rounded-xl hover:bg-indigo-50 border border-transparent hover:border-indigo-100 text-left transition-colors">
                            <div>
                                <p class="text-sm font-medium text-slate-800" x-text="svc.name"></p>
                                <p class="text-xs text-slate-400 mt-0.5">
                                    <span x-text="svc.periodicity || ''"></span>
                                    <span x-show="svc.children.length > 0" class="ml-1 text-indigo-400" x-text="svc.children.length + ' подпункта(ов)'"></span>
                                </p>
                            </div>
                            <span class="text-sm font-semibold text-slate-700 ml-4 flex-shrink-0" x-text="fmt(svc.cost)"></span>
                        </button>
                    </template>
                    <template x-if="filteredExtraServices.length === 0">
                        <p class="text-center text-sm text-slate-400 py-6">Не найдено</p>
                    </template>
                </div>

                <!-- Своя услуга -->
                <div x-show="showCustomForm" class="space-y-3">
                    <input type="text" x-model="customForm.name" placeholder="Название *"
                           class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                    <div class="grid grid-cols-2 gap-3">
                        <input type="number" x-model.number="customForm.cost" min="0" placeholder="Стоимость (сом)"
                               class="px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                        <select x-model="customForm.periodicity"
                                class="px-3 py-2 border border-slate-200 rounded-lg text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                            <option value="">— периодичность —</option>
                            <option value="Ежемесячный">Ежемесячный</option>
                            <option value="Ежеквартальный">Ежеквартальный</option>
                            <option value="Ежегодный">Ежегодный</option>
                            <option value="Разовый">Разовый</option>
                        </select>
                    </div>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" x-model="customForm.allows_quantity"
                               class="w-4 h-4 text-indigo-600 border-slate-300 rounded">
                        <span class="text-sm text-slate-700">Можно указывать количество</span>
                    </label>
                    <button type="button" @click="addCustomExtra()" :disabled="!customForm.name.trim()"
                            class="w-full px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 disabled:opacity-50 transition-colors">
                        Добавить
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Toast -->
    <div x-show="toastShow"
         x-transition:enter="ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="ease-in duration-200" x-transition:leave-start="opacity-100 translate-y-0" x-transition:leave-end="opacity-0 translate-y-2"
         class="fixed bottom-4 right-4 z-50" style="display:none">
        <div :class="toastType === 'success' ? 'bg-emerald-500' : 'bg-red-500'"
             class="px-4 py-3 rounded-lg text-white text-sm font-medium shadow-lg flex items-center gap-2">
            <svg x-show="toastType === 'success'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"/></svg>
            <svg x-show="toastType === 'error'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
            <span x-text="toastMsg"></span>
        </div>
    </div>
</div>

<script>
function estimatePage(clientId, tariffBPs, extras, initialNotes, initialUpdatedAt, allServices, year, month) {
    return {
        clientId,
        tariffBPs,
        extras,
        notes: initialNotes,
        updatedAt: initialUpdatedAt,
        allServices,
        year,
        month,

        saving: false,
        generating: false,
        toastShow: false,
        toastMsg: '',
        toastType: 'success',

        showExtraModal: false,
        catalogSearch: '',
        showCustomForm: false,
        newExtraType: 'one_time',
        customForm: { name: '', cost: 0, periodicity: '', allows_quantity: false },

        get filteredExtraServices() {
            const q = this.catalogSearch.toLowerCase();
            return q
                ? this.allServices.filter(s => s.name.toLowerCase().includes(q))
                : this.allServices;
        },

        getCatalogChildren(serviceId) {
            const svc = this.allServices.find(s => s.id === serviceId);
            return svc ? (svc.children || []) : [];
        },

        extraChildEnabled(eIdx, childServiceId) {
            const extra = this.extras[eIdx];
            return (extra.children || []).some(c => c.service_id === childServiceId);
        },

        toggleExtraChild(eIdx, child) {
            const extra = this.extras[eIdx];
            if (!extra.children) extra.children = [];
            const idx = extra.children.findIndex(c => c.service_id === child.id);
            if (idx === -1) {
                extra.children.push({
                    service_id: child.id,
                    name: child.name,
                    periodicity: child.periodicity,
                    cost: child.cost,
                    quantity: 1,
                    allows_quantity: child.allows_quantity,
                    enabled: true,
                });
            } else {
                extra.children.splice(idx, 1);
            }
        },

        getExtraChildQty(eIdx, childServiceId) {
            const extra = this.extras[eIdx];
            const child = (extra.children || []).find(c => c.service_id === childServiceId);
            return child ? child.quantity : 1;
        },

        setExtraChildQty(eIdx, childServiceId, val) {
            const extra = this.extras[eIdx];
            const child = (extra.children || []).find(c => c.service_id === childServiceId);
            if (child) child.quantity = parseInt(val) || 1;
        },

        bpTotal(bp) {
            if (!bp.enabled) return 0;
            if (bp.children && bp.children.length > 0) {
                return (bp.children || [])
                    .filter(c => c.enabled)
                    .reduce((s, c) => s + c.cost * (c.allows_quantity ? c.quantity : 1), 0);
            }
            return bp.cost * (bp.allows_quantity ? bp.quantity : 1);
        },

        grandTotal() {
            const tariffSum = this.tariffBPs.reduce((s, bp) => s + this.bpTotal(bp), 0);
            const extraSum = this.extras.reduce((s, ex) => {
                const childSum = (ex.children || []).reduce((cs, c) => cs + c.cost * (c.allows_quantity ? c.quantity : 1), 0);
                return s + (childSum > 0 ? childSum : ex.cost * (ex.allows_quantity ? ex.quantity : 1));
            }, 0);
            return tariffSum + extraSum;
        },

        addExtra(svc) {
            this.extras.push({
                service_id:      svc.id,
                type:            this.newExtraType,
                name:            svc.name,
                periodicity:     svc.periodicity,
                cost:            svc.cost,
                quantity:        1,
                allows_quantity: svc.allows_quantity,
                children:        [],
            });
            this.showExtraModal = false;
            this.catalogSearch = '';
        },

        addCustomExtra() {
            this.extras.push({
                service_id:      null,
                type:            this.newExtraType,
                name:            this.customForm.name,
                periodicity:     this.customForm.periodicity,
                cost:            this.customForm.cost,
                quantity:        1,
                allows_quantity: this.customForm.allows_quantity,
                children:        [],
            });
            this.customForm = { name: '', cost: 0, periodicity: '', allows_quantity: false };
            this.showCustomForm = false;
            this.showExtraModal = false;
        },

        async generate() {
            this.generating = true;
            try {
                const res = await fetch('/clients/' + this.clientId + '/estimate/generate', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({ year: this.year, month: this.month }),
                });
                const data = await res.json();
                if (data.success) {
                    window.location.reload();
                } else {
                    this.showToast(data.message || 'Ошибка', 'error');
                }
            } catch(e) {
                this.showToast('Ошибка: ' + e.message, 'error');
            }
            this.generating = false;
        },

        async save() {
            this.saving = true;
            try {
                const res = await fetch('/clients/' + this.clientId + '/estimate', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        year:       this.year,
                        month:      this.month,
                        notes:      this.notes,
                        tariff_bps: this.tariffBPs,
                        extras:     this.extras,
                    }),
                });
                const data = await res.json();
                if (data.success) {
                    this.updatedAt = data.updated_at;
                    this.showToast('Смета сохранена', 'success');
                } else {
                    this.showToast(data.message || 'Ошибка', 'error');
                }
            } catch(e) {
                this.showToast('Ошибка: ' + e.message, 'error');
            }
            this.saving = false;
        },

        showToast(msg, type) {
            this.toastMsg = msg; this.toastType = type; this.toastShow = true;
            setTimeout(() => { this.toastShow = false; }, 3500);
        },

        fmt(n) {
            return new Intl.NumberFormat('ru-RU').format(Math.round(n || 0)) + ' сом';
        },
    };
}
</script>
@endsection
