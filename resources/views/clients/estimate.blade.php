@extends('layouts.app')

@section('title', 'Смета — ' . $client->name)
@section('page-title', 'Смета — ' . $client->name)

@section('content')
<style>
[x-cloak]{display:none!important}
.toggle-btn { transition: background-color .2s; }
</style>

<div x-data="estimatePage(
    {{ $client->id }},
    {{ json_encode($tariffBPs) }},
    {{ json_encode($extras) }},
    {{ json_encode($estimate->notes ?? '') }},
    {{ json_encode($estimate->updated_at?->format('d.m.Y H:i')) }},
    {{ json_encode($allServices) }},
    {{ json_encode($specialFlags) }},
    {{ json_encode($periodicities) }},
    {{ json_encode($canAssign) }},
    {{ json_encode($assigneeOptions) }}
)">

    <!-- Хлебные крошки -->
    <div class="flex items-center gap-2 mb-6 text-sm text-slate-500">
        <a href="{{ route('clients.index') }}" class="hover:text-slate-700">Клиенты</a>
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        <a href="{{ route('clients.show', $client) }}" class="hover:text-slate-700">{{ $client->name }}</a>
        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
        <span class="text-slate-800 font-medium">Смета</span>
    </div>

    {{-- Шапка липкая: список услуг длинный, и при прокрутке терялось, чья это смета
         и на какую сумму она вышла. top-16 — высота шапки layout'а (h-16), иначе
         панель уезжает под неё. --}}
    <div class="sticky top-16 z-20 -mx-6 px-6 py-3 mb-6 bg-white/90 backdrop-blur-xl border-b border-slate-200/70">
        <div class="flex items-center justify-between gap-4">
            <div class="min-w-0">
                <h1 class="text-xl font-bold text-slate-900 truncate" title="{{ $client->name }}">{{ $client->name }}</h1>
                <p class="text-xs text-slate-500 mt-0.5 truncate">
                    ИНН: {{ $client->inn }}
                    @if($client->taxSystem) · {{ $client->taxSystem->name }} @endif
                    @if($client->tariff) · Тариф: {{ $client->tariff->name }} @endif
                </p>
            </div>

            <div class="flex items-center gap-3 flex-shrink-0">
                <div class="text-right">
                    <p class="text-[11px] font-semibold text-slate-400 uppercase tracking-wider leading-none">Итого</p>
                    <p class="text-lg font-bold text-slate-900 leading-tight" x-text="fmt(grandTotal())"></p>
                </div>
                <button type="button" @click="save()" :disabled="saving"
                        class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 disabled:opacity-60 transition-colors">
                    <svg x-show="saving" class="w-4 h-4 mr-1.5 animate-spin" fill="none" viewBox="0 0 24 24">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                    </svg>
                    <span x-text="saving ? 'Сохранение...' : 'Сохранить'"></span>
                </button>
            </div>
        </div>
    </div>

    @if(!$client->responsible_employee_id)
    <div class="mb-6 flex items-start gap-3 rounded-xl border border-amber-300 bg-amber-50 px-4 py-3">
        <svg class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
        <div class="text-sm text-amber-800">
            <span class="font-semibold">У клиента не выбран ответственный (главбух).</span>
            Задачи из этой сметы не попадут ни в чей список, и назначить исполнителей нельзя.
            <a href="{{ route('clients.show', $client) }}" class="font-medium underline hover:text-amber-900">Открыть карточку клиента</a>
        </div>
    </div>
    @endif

    {{-- ШАГ 1. Каркас: две колонки ~70% / 30%. Правая — sticky. --}}
    <div class="grid grid-cols-1 lg:grid-cols-[minmax(0,1fr)_340px] gap-6 items-start">

        {{-- ШАГ 2. Левая колонка — список услуг с тумблерами --}}
        <div class="space-y-4 min-w-0">

            <!-- Блок: Услуги по тарифу (всегда постоянные) -->
            <template x-if="regularBPs.length > 0">
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
                    <div class="px-6 py-3 bg-slate-50 border-b border-slate-100 flex items-center gap-3">
                        <h2 class="text-sm font-semibold text-slate-600 uppercase tracking-wider">Услуги по тарифу</h2>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-100 text-emerald-700">Постоянные</span>
                    </div>
                    @include('clients.partials.estimate-bp-grouped', ['groups' => 'regularBPsGrouped'])
                </div>
            </template>

            <!-- Блоки: БП по особым условиям клиента (ПВТ, ПКИ, Маркетплейсы, ...) -->
            <template x-for="f in specialFlags" :key="f.key">
                <template x-if="flagBPs(f.key).length > 0">
                    <div class="bg-white rounded-2xl shadow-sm border overflow-hidden" :class="'border-' + f.color + '-200'">
                        <div class="px-6 py-3 border-b flex items-center gap-3" :class="'bg-' + f.color + '-50 border-' + f.color + '-100'">
                            <h2 class="text-sm font-semibold uppercase tracking-wider" :class="'text-' + f.color + '-700'" x-text="f.label"></h2>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium" :class="'bg-' + f.color + '-100 text-' + f.color + '-700'">Постоянные</span>
                            <span class="text-xs" :class="'text-' + f.color + '-500'" x-text="'подтянуто по условию: ' + f.label"></span>
                        </div>
                        @include('clients.partials.estimate-bp-grouped', ['groups' => 'flagBPsGrouped(f.key)'])
                    </div>
                </template>
            </template>

            <template x-if="tariffBPs.length === 0">
                <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 px-6 py-8 text-center text-slate-400 text-sm">
                    У клиента не выбран тариф или в тарифе нет бизнес-процессов.
                    @if (!empty($serviceScopeLabels))
                        {{-- Пустой список у клиента с урезанным обслуживанием почти всегда
                             означает не поломку, а неразмеченный каталог. --}}
                        <div class="mt-2 text-slate-500">
                            Обслуживание сужено: {{ implode(' + ', $serviceScopeLabels) }}.
                            Подтягиваются только процессы этих типов и процессы без типа.
                        </div>
                    @endif
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

                                    {{-- Постоянная доп. услуга = обычная строка сметы: срок и исполнитель
                                         как у тарифных БП. У временной («только этот месяц») их нет. --}}
                                    <template x-if="extra.type === 'recurring'">
                                        <div>
                                            @include('clients.partials.estimate-schedule-assignee', ['row' => 'extra', 'assigneeShow' => 'extra.schedule'])
                                        </div>
                                    </template>
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
                                        </div>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </template>
                </div>
            </div>

            <!-- Примечания -->
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 p-6">
                <label class="block text-sm font-medium text-slate-700 mb-1">Примечания</label>
                <textarea x-model="notes" rows="2" placeholder="Дополнительная информация..."
                          class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 resize-none"></textarea>
            </div>
        </div>

        {{-- ШАГ 3 + 4. Правая колонка — карточка «Смета» (sticky), связанная с тумблерами слева --}}
        {{-- top-40: шапка layout'а (64px) + липкая панель сметы, иначе заголовок карточки прячется --}}
        <aside class="lg:sticky lg:top-40">
            <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">

                <!-- Заголовок -->
                <div class="px-5 py-4 bg-slate-50 border-b border-slate-100 flex items-center justify-between">
                    <h2 class="text-base font-bold text-slate-900">Смета</h2>
                    <span class="text-xs text-slate-400" x-show="summaryItems.length > 0"
                          x-text="summaryItems.length + ' поз.'"></span>
                </div>

                <!-- Позиции -->
                <div class="max-h-[55vh] overflow-y-auto">
                    <template x-if="summaryItems.length === 0">
                        <div class="px-5 py-12 text-center text-sm text-slate-400">
                            <svg class="w-10 h-10 mx-auto mb-3 text-slate-200" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5l7 7-7 7"/></svg>
                            Выберите услуги слева
                        </div>
                    </template>

                    <div class="divide-y divide-slate-100">
                        <template x-for="item in summaryItems" :key="item.key">
                            <div class="flex items-center gap-2.5 px-5 py-3">
                                <div class="flex-1 min-w-0">
                                    <p class="text-sm font-medium text-slate-800 truncate" x-text="item.name" :title="item.name"></p>
                                    <p class="text-xs text-slate-400 truncate" x-show="item.periodicity" x-text="item.periodicity"></p>
                                </div>
                                <span class="text-sm font-semibold text-slate-800 flex-shrink-0" x-text="fmt(item.total)"></span>
                                <!-- Корзина: удаляет из сметы И гасит тумблер слева -->
                                <button type="button" @click="item.remove()"
                                        title="Удалить из сметы"
                                        class="p-1 text-slate-300 hover:text-red-500 transition-colors rounded flex-shrink-0">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                            </div>
                        </template>
                    </div>
                </div>

                <!-- Разделитель + ИТОГО -->
                <div class="border-t border-slate-200 px-5 py-4 flex items-center justify-between bg-slate-50/60">
                    <span class="text-sm font-semibold text-slate-600 uppercase tracking-wider">Итого</span>
                    <span class="text-2xl font-bold text-slate-900" x-text="fmt(grandTotal())"></span>
                </div>

                <!-- Кнопки -->
                <div class="px-5 pb-5 pt-1 space-y-2">
                    <a href="/clients/{{ $client->id }}/estimate/pdf" target="_blank"
                       class="w-full inline-flex items-center justify-center px-3 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50 transition-colors">
                        <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                        PDF
                    </a>
                    <button @click="save()" :disabled="saving"
                            class="w-full inline-flex items-center justify-center px-4 py-2.5 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 disabled:opacity-60 transition-colors">
                        <svg x-show="saving" class="w-4 h-4 mr-1.5 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                        </svg>
                        <span x-text="saving ? 'Сохранение...' : 'Сохранить'"></span>
                    </button>
                </div>
            </div>

            <p x-show="updatedAt" class="text-xs text-slate-400 mt-3 text-center">
                Последнее сохранение: <span x-text="updatedAt"></span>
            </p>
        </aside>
    </div>

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

    <!-- Модал: индивидуальное расписание БП -->
    <div x-show="showScheduleModal" class="fixed inset-0 z-50 overflow-y-auto" style="display:none">
        <div class="flex items-center justify-center min-h-screen px-4 py-8">
            <div @click="showScheduleModal = false" class="fixed inset-0 bg-slate-500/75"></div>
            <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-lg p-6">
                <div class="flex items-start justify-between mb-1">
                    <div class="min-w-0 pr-4">
                        <h3 class="text-lg font-semibold text-slate-900">Расписание БП</h3>
                        <p class="text-sm text-slate-500 mt-0.5 truncate" x-text="scheduleBp ? scheduleBp.name : ''"></p>
                    </div>
                    <button @click="showScheduleModal = false" class="p-1.5 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100 flex-shrink-0">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
                <p class="text-xs text-slate-400 mb-4">Индивидуально для этого клиента — перекрывает дефолтное расписание БП целиком.</p>

                <!-- Периодичность -->
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 mb-1">Периодичность</label>
                    <select x-model="scheduleForm.periodicity" @change="onSchedulePeriodicityChange()"
                            class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 bg-white">
                        <option value="">— не указана —</option>
                        <template x-for="p in periodicities" :key="p.name">
                            <option :value="p.name" x-text="p.name"></option>
                        </template>
                    </select>
                </div>

                <!-- Месяц: теги месяцев для ежеквартально/ежегодно -->
                <div class="mb-4">
                    <label class="block text-sm font-medium text-slate-700 mb-1">
                        Месяц
                        <span x-show="monthFieldEnabled && monthMultiple" class="text-slate-400 font-normal">(можно выбрать несколько)</span>
                    </label>
                    <template x-if="monthFieldEnabled">
                        <div class="flex flex-wrap gap-1.5">
                            <template x-for="(m, i) in months" :key="i">
                                <button type="button" @click="toggleMonth(i + 1)"
                                        :class="isMonthSelected(i + 1) ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50'"
                                        class="px-2.5 py-1 rounded-lg border text-xs font-medium transition-colors" x-text="m"></button>
                            </template>
                        </div>
                    </template>
                    <template x-if="!monthFieldEnabled">
                        <div class="flex items-center gap-2 px-3 py-2 bg-slate-50 border border-slate-200 rounded-lg text-sm text-slate-400">
                            <svg class="w-4 h-4 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>
                            <span x-text="monthDisabledHint"></span>
                        </div>
                    </template>
                </div>

                <!-- День: дни недели для еженедельно, иначе день месяца -->
                <div class="mb-5">
                    <label class="block text-sm font-medium text-slate-700 mb-1">
                        День
                        <span x-show="dayIsWeekday" class="text-slate-400 font-normal">(дни недели, можно несколько)</span>
                    </label>
                    <template x-if="dayIsWeekday">
                        <div class="flex flex-wrap gap-1.5">
                            <template x-for="w in weekdays" :key="w.n">
                                <button type="button" @click="toggleWeekday(w.n)"
                                        :class="isWeekdaySelected(w.n) ? 'bg-indigo-600 text-white border-indigo-600' : 'bg-white text-slate-600 border-slate-200 hover:bg-slate-50'"
                                        class="px-3 py-1 rounded-lg border text-xs font-medium transition-colors" x-text="w.label"></button>
                            </template>
                        </div>
                    </template>
                    <template x-if="!dayIsWeekday">
                        <div class="relative">
                            <button type="button" @click="showStartDayPicker = !showStartDayPicker"
                                    class="w-full flex items-center justify-between px-3 py-2 border border-slate-200 rounded-lg text-sm bg-white hover:border-indigo-400 focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500 transition-colors">
                                <span :class="dayOfMonth ? 'text-slate-800' : 'text-slate-400'" x-text="dayOfMonth ? dayOfMonth + ' число' : '— выберите число —'"></span>
                                <svg class="w-4 h-4 text-slate-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                            </button>
                            <div x-show="showStartDayPicker" @click.away="showStartDayPicker = false"
                                 class="absolute z-50 mt-1 w-full bg-white border border-slate-200 rounded-xl shadow-xl p-3" style="display:none">
                                <div class="flex items-center justify-between mb-2 px-1">
                                    <span class="text-xs font-semibold text-slate-500 uppercase tracking-wide">Выберите число</span>
                                    <button type="button" @click="dayOfMonth = ''; showStartDayPicker = false" class="text-xs text-slate-400 hover:text-red-500 transition-colors">Очистить</button>
                                </div>
                                <div class="grid grid-cols-7 gap-0.5">
                                    <template x-for="day in Array.from({length: 31}, (_, i) => i + 1)" :key="day">
                                        <button type="button" @click="dayOfMonth = day; showStartDayPicker = false"
                                                :class="dayOfMonth === day ? 'bg-indigo-600 text-white font-semibold' : 'text-slate-700 hover:bg-indigo-50 hover:text-indigo-600'"
                                                class="h-8 w-full rounded-lg text-sm transition-colors" x-text="day"></button>
                                    </template>
                                    <template x-for="_ in [1,2,3,4]" :key="'e' + _"><div></div></template>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>

                <!-- Footer -->
                <div class="flex items-center gap-2 pt-1">
                    <button type="button" x-show="scheduleBp && scheduleBp.schedule && scheduleBp.schedule.is_custom"
                            @click="resetSchedule()" :disabled="scheduleSaving"
                            class="text-sm font-medium text-red-500 hover:text-red-700 disabled:opacity-50 transition-colors">
                        Сбросить к дефолту
                    </button>
                    <div class="flex items-center gap-2 ml-auto">
                        <button type="button" @click="showScheduleModal = false"
                                class="px-4 py-2 text-sm font-medium text-slate-600 bg-slate-100 rounded-lg hover:bg-slate-200 transition-colors">Отмена</button>
                        <button type="button" @click="saveSchedule()" :disabled="scheduleSaving"
                                class="inline-flex items-center px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 disabled:opacity-60 transition-colors">
                            <svg x-show="scheduleSaving" class="w-4 h-4 mr-1.5 animate-spin" fill="none" viewBox="0 0 24 24">
                                <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"/>
                                <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/>
                            </svg>
                            <span x-text="scheduleSaving ? 'Сохранение...' : 'Сохранить'"></span>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    {{-- Предупреждение перед сохранением: по добавленным БП задачи пойдут не сейчас,
         а с 1 числа следующего месяца. Показывается только когда что-то добавили. --}}
    <div x-show="showTasksStartModal" class="fixed inset-0 z-50 overflow-y-auto" style="display:none">
        <div class="flex items-center justify-center min-h-screen px-4 py-8">
            <div @click="showTasksStartModal = false" class="fixed inset-0 bg-slate-500/75"></div>
            <div class="relative bg-white rounded-2xl shadow-xl w-full max-w-lg p-6">
                <div class="flex items-start gap-3 mb-4">
                    <div class="flex-shrink-0 w-10 h-10 rounded-full bg-amber-100 flex items-center justify-center">
                        <svg class="w-5 h-5 text-amber-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <h3 class="text-lg font-semibold text-slate-900">
                            Задачи по новым БП начнутся с {{ $tasksStartLabel }}
                        </h3>
                        <p class="text-sm text-slate-500 mt-1">В текущем месяце задач по ним не будет.</p>
                    </div>
                </div>

                <ul class="mb-5 max-h-56 overflow-y-auto rounded-xl border border-slate-200 divide-y divide-slate-100">
                    <template x-for="name in newlyAddedNames" :key="name">
                        <li class="px-3 py-2 text-sm text-slate-700" x-text="name"></li>
                    </template>
                </ul>

                <div class="flex items-center justify-end gap-2">
                    <button type="button" @click="showTasksStartModal = false"
                            class="px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-200 rounded-lg hover:bg-slate-50 transition-colors">
                        Отмена
                    </button>
                    <button type="button" @click="confirmTasksStart()" :disabled="saving"
                            class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700 disabled:opacity-60 transition-colors">
                        Сохранить
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
function estimatePage(clientId, tariffBPs, extras, initialNotes, initialUpdatedAt, allServices, specialFlags, periodicities, canAssign, assigneeOptions) {
    return {
        clientId,
        tariffBPs,
        extras,
        notes: initialNotes,
        updatedAt: initialUpdatedAt,
        allServices,
        specialFlags,
        periodicities,
        canAssign,
        assigneeOptions,

        saving: false,
        toastShow: false,
        toastMsg: '',
        toastType: 'success',

        // --- Редактор индивидуального расписания БП ---
        months: ['Январь','Февраль','Март','Апрель','Май','Июнь','Июль','Август','Сентябрь','Октябрь','Ноябрь','Декабрь'],
        weekdays: [{n:1,label:'Пн'},{n:2,label:'Вт'},{n:3,label:'Ср'},{n:4,label:'Чт'},{n:5,label:'Пт'},{n:6,label:'Сб'},{n:7,label:'Вс'}],
        showScheduleModal: false,
        showStartDayPicker: false,
        scheduleBp: null,
        scheduleSaving: false,
        scheduleForm: { periodicity: '', start_month: [], start_day: [] },

        // Снимок состояния тумблеров на момент загрузки: по нему отличаем БП,
        // включённые прямо сейчас, от тех, что уже стояли в смете. Нужен для
        // предупреждения — задачи по новым пойдут только со следующего месяца.
        savedEnabled: {},
        showTasksStartModal: false,

        init() {
            this.rememberEnabled();
        },

        rememberEnabled() {
            const snapshot = {};
            this.tariffBPs.forEach(bp => { snapshot[bp.row_key] = !!bp.enabled; });
            this.savedEnabled = snapshot;
            // Доп. услуги пришли с сервера — значит уже сохранены и новыми не считаются.
            this.extras.forEach(ex => { ex.is_new = false; });
        },

        /** Что добавили в этой сессии: включённые тумблеры и новые постоянные доп. услуги. */
        get newlyAddedNames() {
            const names = this.tariffBPs
                .filter(bp => bp.enabled && !this.savedEnabled[bp.row_key])
                .map(bp => bp.branch_label ? (bp.name + ' — ' + bp.branch_label) : bp.name);

            // Временная (разовая) доп. услуга задачу выдаёт сразу — её не откладываем
            // и в предупреждении не показываем.
            this.extras
                .filter(ex => ex.is_new && ex.type === 'recurring')
                .forEach(ex => names.push(ex.name));

            return names;
        },

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

        // Первое (по порядку конфига) условие, которым помечен БП, иначе null
        primaryFlag(bp) {
            for (const f of this.specialFlags) {
                if (bp[f.key]) return f.key;
            }
            return null;
        },
        get regularBPs() { return this.tariffBPs.filter(bp => !this.primaryFlag(bp)); },
        flagBPs(key) { return this.tariffBPs.filter(bp => this.primaryFlag(bp) === key); },

        // Группировка БП по сфере. Срок выполнения показывается в самой строке БП,
        // поэтому отдельной подгруппы по датам нет (иначе дата дублируется).
        // Возвращает [{ sphere, bps: [...] }]. Порядок сфер — по первому появлению.
        groupBySphere(list) {
            const sphereMap = {};
            const spheres = [];
            list.forEach(bp => {
                const sphere = bp.sphere || 'Без сферы';
                if (!sphereMap[sphere]) {
                    sphereMap[sphere] = { sphere, bps: [] };
                    spheres.push(sphereMap[sphere]);
                }
                sphereMap[sphere].bps.push(bp);
            });
            return spheres;
        },
        get regularBPsGrouped() { return this.groupBySphere(this.regularBPs); },
        flagBPsGrouped(key) { return this.groupBySphere(this.flagBPs(key)); },

        bpTotal(bp) {
            if (!bp.enabled) return 0;
            if (bp.children && bp.children.length > 0) {
                return (bp.children || [])
                    .filter(c => c.enabled)
                    .reduce((s, c) => s + c.cost * (c.allows_quantity ? c.quantity : 1), 0);
            }
            return bp.cost * (bp.allows_quantity ? bp.quantity : 1);
        },

        extraTotal(ex) {
            const childSum = (ex.children || []).reduce((cs, c) => cs + c.cost * (c.allows_quantity ? c.quantity : 1), 0);
            return childSum > 0 ? childSum : ex.cost * (ex.allows_quantity ? ex.quantity : 1);
        },

        grandTotal() {
            const tariffSum = this.tariffBPs.reduce((s, bp) => s + this.bpTotal(bp), 0);
            const extraSum = this.extras.reduce((s, ex) => s + this.extraTotal(ex), 0);
            return tariffSum + extraSum;
        },

        // ШАГ 4. Живой список позиций для карточки «Смета» справа.
        // Тянет все включённые тумблером БП и все доп. услуги; remove() гасит источник.
        get summaryItems() {
            const items = [];
            this.tariffBPs.forEach(bp => {
                if (!bp.enabled) return;
                items.push({
                    key: 'bp-' + bp.row_key,
                    name: bp.branch_label ? (bp.name + ' — ' + bp.branch_label) : bp.name,
                    periodicity: bp.periodicity,
                    total: this.bpTotal(bp),
                    remove: () => { bp.enabled = false; },   // гасит тумблер слева
                });
            });
            this.extras.forEach((ex, i) => {
                items.push({
                    key: 'ex-' + i,
                    name: ex.name,
                    periodicity: ex.periodicity,
                    total: this.extraTotal(ex),
                    remove: () => {
                        const idx = this.extras.indexOf(ex);
                        if (idx !== -1) this.extras.splice(idx, 1);
                    },
                });
            });
            return items;
        },

        addExtra(svc) {
            this.extras.push({
                is_new:          true,
                service_id:      svc.id,
                type:            this.newExtraType,
                name:            svc.name,
                periodicity:     svc.periodicity,
                cost:            svc.cost,
                quantity:        1,
                allows_quantity: svc.allows_quantity,
                // Копия, а не ссылка: правка расписания у одной строки не должна
                // молча менять подписи у других строк того же БП в каталоге.
                schedule:        svc.schedule ? { ...svc.schedule } : null,
                assignee_id:     svc.assignee_id,
                assignee_name:   svc.assignee_name,
                children:        [],
            });
            this.showExtraModal = false;
            this.catalogSearch = '';
        },

        addCustomExtra() {
            this.extras.push({
                is_new:          true,
                service_id:      null,
                type:            this.newExtraType,
                name:            this.customForm.name,
                periodicity:     this.customForm.periodicity,
                cost:            this.customForm.cost,
                quantity:        1,
                allows_quantity: this.customForm.allows_quantity,
                // Своя услуга не привязана к БП — расписания у неё нет (срок не показываем)
                schedule:        null,
                assignee_id:     null,
                assignee_name:   null,
                children:        [],
            });
            this.customForm = { name: '', cost: 0, periodicity: '', allows_quantity: false };
            this.showCustomForm = false;
            this.showExtraModal = false;
        },

        // --- Индивидуальное расписание БП (override дефолта) ---
        openSchedule(bp) {
            this.scheduleBp = bp;
            const s = bp.schedule || {};
            this.scheduleForm = {
                periodicity: s.periodicity || '',
                start_month: Array.isArray(s.start_month) ? [...s.start_month] : [],
                start_day:   Array.isArray(s.start_day) ? [...s.start_day] : [],
            };
            this.showStartDayPicker = false;
            this.showScheduleModal = true;
        },

        // Тип выбранной периодичности (kind) управляет полями «Месяц»/«День»
        get selectedKind() {
            const p = this.periodicities.find(x => x.name === this.scheduleForm.periodicity);
            return p ? p.kind : null;
        },
        get monthFieldEnabled() { return ['quarterly','yearly'].includes(this.selectedKind); },
        get monthMultiple() { return this.selectedKind === 'quarterly'; },
        get dayIsWeekday() { return this.selectedKind === 'weekly'; },
        get monthDisabledHint() {
            if (!this.scheduleForm.periodicity) return 'Сначала выберите периодичность';
            if (this.selectedKind === 'monthly') return 'Недоступно для «Ежемесячно»';
            if (this.selectedKind === 'weekly') return 'Недоступно для «Еженедельно»';
            return 'Недоступно для этой периодичности';
        },
        isMonthSelected(n) { return (this.scheduleForm.start_month || []).includes(n); },
        toggleMonth(n) {
            const arr = this.scheduleForm.start_month || [];
            if (this.monthMultiple) {
                this.scheduleForm.start_month = arr.includes(n) ? arr.filter(x => x !== n) : [...arr, n].sort((a, b) => a - b);
            } else {
                this.scheduleForm.start_month = arr.includes(n) ? [] : [n];
            }
        },
        isWeekdaySelected(n) { return (this.scheduleForm.start_day || []).includes(n); },
        toggleWeekday(n) {
            const arr = this.scheduleForm.start_day || [];
            this.scheduleForm.start_day = arr.includes(n) ? arr.filter(x => x !== n) : [...arr, n].sort((a, b) => a - b);
        },
        get dayOfMonth() { return (this.scheduleForm.start_day && this.scheduleForm.start_day.length) ? this.scheduleForm.start_day[0] : ''; },
        set dayOfMonth(v) { this.scheduleForm.start_day = v ? [parseInt(v)] : []; },
        onSchedulePeriodicityChange() { this.scheduleForm.start_month = []; this.scheduleForm.start_day = []; },

        // Расписание хранится на паре (клиент, БП), поэтому обновляем ВСЕ строки этого БП:
        // филиальные копии и доп. услугу из того же каталога — иначе у них останется
        // старая подпись срока до перезагрузки страницы.
        applyScheduleResponse(data) {
            if (!this.scheduleBp) return;
            const serviceId = this.scheduleBp.service_id;
            const schedule = {
                is_custom:   data.is_custom,
                periodicity: data.schedule.periodicity || '',
                start_month: data.schedule.start_month || [],
                start_day:   data.schedule.start_day || [],
                labels:      data.labels || [],
            };
            [...this.tariffBPs, ...this.extras].forEach(row => {
                if (row.service_id === serviceId && row.schedule) {
                    row.schedule = { ...schedule };
                }
            });
        },

        async saveSchedule() {
            if (!this.scheduleBp) return;
            this.scheduleSaving = true;
            try {
                const res = await fetch('/clients/' + this.clientId + '/services/' + this.scheduleBp.service_id + '/schedule', {
                    method: 'PUT',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify(this.scheduleForm),
                });
                const data = await res.json();
                if (data.success) {
                    this.applyScheduleResponse(data);
                    this.showScheduleModal = false;
                    this.showToast('Индивидуальное расписание сохранено', 'success');
                } else {
                    this.showToast(data.message || 'Ошибка', 'error');
                }
            } catch(e) {
                this.showToast('Ошибка: ' + e.message, 'error');
            }
            this.scheduleSaving = false;
        },

        async resetSchedule() {
            if (!this.scheduleBp) return;
            this.scheduleSaving = true;
            try {
                const res = await fetch('/clients/' + this.clientId + '/services/' + this.scheduleBp.service_id + '/schedule', {
                    method: 'DELETE',
                    headers: {
                        'X-CSRF-TOKEN': document.querySelector('meta[name=csrf-token]').content,
                        'Accept': 'application/json',
                    },
                });
                const data = await res.json();
                if (data.success) {
                    this.applyScheduleResponse(data);
                    this.showScheduleModal = false;
                    this.showToast('Сброшено к дефолтному расписанию', 'success');
                } else {
                    this.showToast(data.message || 'Ошибка', 'error');
                }
            } catch(e) {
                this.showToast('Ошибка: ' + e.message, 'error');
            }
            this.scheduleSaving = false;
        },

        /** Кнопка «Сохранить»: сперва предупреждаем про новые БП, если они есть. */
        save() {
            if (this.newlyAddedNames.length > 0) {
                this.showTasksStartModal = true;
                return;
            }
            return this.doSave();
        },

        confirmTasksStart() {
            this.showTasksStartModal = false;
            return this.doSave();
        },

        async doSave() {
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
                        notes:      this.notes,
                        tariff_bps: this.tariffBPs,
                        extras:     this.extras,
                    }),
                });
                const data = await res.json();
                if (data.success) {
                    this.updatedAt = data.updated_at;
                    // Сохранённое стало прежним: второй раз про те же БП не предупреждаем.
                    this.rememberEnabled();
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
