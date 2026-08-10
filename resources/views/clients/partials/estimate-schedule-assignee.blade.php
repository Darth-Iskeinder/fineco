{{-- Срок выполнения + исполнитель строки сметы. Общий блок для тарифных БП и для
     доп. услуг из каталога — они ведут себя одинаково: срок с учётом индивидуального
     расписания клиента и назначаемый исполнитель.
     $row          — имя переменной строки в Alpine-скоупе (bp / extra);
     $assigneeShow — Alpine-условие показа блока исполнителя.
     Строки без расписания (своя услуга — она не привязана к БП) блок срока не показывают. --}}
<template x-if="{{ $row }}.schedule">
    <div class="flex items-center gap-1.5 mt-1 flex-wrap">
        <svg class="w-3.5 h-3.5 text-slate-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
        <span class="text-xs text-slate-500"
              x-text="({{ $row }}.schedule.labels && {{ $row }}.schedule.labels.length) ? {{ $row }}.schedule.labels.join(', ') : 'срок не задан'"></span>
        <span x-show="{{ $row }}.schedule.is_custom"
              class="inline-flex items-center px-1.5 py-0.5 rounded text-[10px] font-semibold bg-amber-100 text-amber-700">индивидуально</span>
        <button type="button" @click.stop="openSchedule({{ $row }})"
                class="inline-flex items-center gap-0.5 text-xs text-indigo-500 hover:text-indigo-700 font-medium">
            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/></svg>
            изменить
        </button>
    </div>
</template>

{{-- Исполнитель: селектор — только главбуху клиента/админу, остальным — текст.
     :selected на опциях обязателен: опции рендерятся x-for ПОСЛЕ привязки x-model,
     без него браузер молча показывает первую опцию вместо реального значения. --}}
<div class="flex items-center gap-1.5 mt-1.5" x-show="{{ $assigneeShow }}">
    <svg class="w-3.5 h-3.5 text-slate-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
    <span class="text-xs text-slate-500">Исполнитель:</span>
    <template x-if="canAssign">
        <select x-model.number="{{ $row }}.assignee_id" @click.stop
                class="text-xs border rounded-md pl-1.5 pr-6 py-0.5 focus:outline-none focus:ring-1 focus:ring-indigo-400"
                :class="{{ $row }}.assignee_id ? 'border-slate-200 bg-white' : 'border-amber-300 bg-amber-50 text-amber-700'">
            <option value="" disabled :selected="!{{ $row }}.assignee_id">— не назначен —</option>
            <template x-for="opt in assigneeOptions" :key="opt.id">
                <option :value="opt.id" :selected="opt.id === {{ $row }}.assignee_id"
                        x-text="opt.full_name + (opt.role ? ' · ' + opt.role : '')"></option>
            </template>
        </select>
    </template>
    <template x-if="!canAssign">
        <span class="text-xs font-medium"
              :class="{{ $row }}.assignee_id ? 'text-slate-700' : 'text-amber-600'"
              x-text="{{ $row }}.assignee_name || 'не назначен'"></span>
    </template>
</div>
