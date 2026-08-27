@props([
    'label',
    'value',              // выражение Alpine со значением поля
    'field',              // выражение Alpine с именем поля — по нему подсвечивается «Скопировано»
    'secret' => true,     // пароль прячем под точками, логин показываем как есть
    'labelClass' => 'text-xs font-medium text-slate-500',
])
{{--
    Поле с доступом: значение и кнопка «скопировать».

    Копирование не требует раскрытия — ради него глазик и нажимали чаще всего.
    Пустое поле показывается прочерком даже под точками: иначе не отличить
    «пароль есть, но скрыт» от «пароля нет».
--}}
<div>
    <dt class="{{ $labelClass }}">{{ $label }}</dt>
    <dd class="mt-1 flex items-center gap-1.5">
        @if ($secret)
            <span class="text-sm font-mono" :class="showPasswords || !hasValue({{ $value }}) ? 'text-slate-900' : 'text-slate-400'" x-text="secretText({{ $value }})"></span>
        @else
            <span class="text-sm font-mono text-slate-900" x-text="hasValue({{ $value }}) ? {{ $value }} : '—'"></span>
        @endif
        <button type="button" x-show="hasValue({{ $value }})" @click="copyValue({{ $value }}, {{ $field }})" class="p-1 text-slate-300 hover:text-indigo-600 hover:bg-indigo-50 rounded transition-all" :title="copiedField === {{ $field }} ? 'Скопировано' : 'Скопировать'">
            <svg x-show="copiedField !== {{ $field }}" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z" />
            </svg>
            <svg x-show="copiedField === {{ $field }}" class="w-3.5 h-3.5 text-emerald-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
            </svg>
        </button>
    </dd>
</div>
