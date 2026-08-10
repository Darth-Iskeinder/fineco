{{-- Строки БП сметы. Параметр $list — имя массива в Alpine-скоупе (regularBPs / pvtBPs). --}}
<template x-for="(bp, bpIdx) in {{ $list }}" :key="bp.row_key">
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
                    <template x-if="bp.branch_label">
                        <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-700 flex-shrink-0">
                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0h2m-2 0h-5m-9 0H3m2 0h5M9 7h1m-1 4h1m4-4h1m-1 4h1m-5 6h4"/></svg>
                            <span x-text="bp.branch_label"></span>
                        </span>
                    </template>
                    <template x-if="bp.children.length > 0">
                        <span class="inline-flex items-center gap-0.5 px-1.5 py-0.5 rounded text-xs font-medium bg-indigo-50 text-indigo-500 flex-shrink-0">
                            <svg class="w-3 h-3 transition-transform duration-200" :class="bp.enabled ? 'rotate-90' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                            <span x-text="bp.children.length + ' подп.'"></span>
                        </span>
                    </template>
                </div>
                <p class="text-xs text-slate-400 mt-0.5" x-show="bp.periodicity" x-text="bp.periodicity"></p>

                {{-- Срок выполнения (с учётом индивидуального расписания клиента) + исполнитель --}}
                @include('clients.partials.estimate-schedule-assignee', ['row' => 'bp', 'assigneeShow' => 'bp.enabled'])
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
                    <span class="text-xs text-slate-400" x-show="bp.unit" x-text="bp.unit"></span>
                </div>
            </template>

            <template x-if="bp.children.length > 0 && bp.enabled && !bp.children.some(c => c.enabled)">
                <span class="text-xs text-slate-400 italic flex-shrink-0">выберите подпункты</span>
            </template>

            {{-- Сумма строки (цена×кол-во), для платных БП без подпунктов --}}
            <template x-if="bp.enabled && bp.cost > 0 && bp.children.length === 0">
                <span class="text-sm font-semibold text-slate-700 flex-shrink-0 tabular-nums" x-text="fmt(bpTotal(bp))"></span>
            </template>
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
                    </div>
                </template>
            </div>
        </template>
    </div>
</template>
