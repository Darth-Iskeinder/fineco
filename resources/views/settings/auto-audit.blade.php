@extends('settings.layout')
@section('page-title', 'Автоаудит')

@section('settings-content')
{{--
    Какие проверки идут в фирме и какие счета ведомости они берут. Счета у проверки
    руководитель меняет в окне «Изменить», переключателем проверку выключает и включает.
    Выключение срабатывает со следующего прогона, поэтому перед ним спрашиваем, сколько
    вопросов бухгалтерам закроется.

    Окно одно на страницу, форма обычная (без fetch): после сохранения страница
    перерисовывается целиком. Ошибка ввода возвращает на страницу с old('rule'), и окно
    открывается снова с тем, что человек ввёл.
--}}
<div class="space-y-4"
     x-data="{
        rules: @js(collect($rows)->keyBy('number')->map(fn ($row) => [
            'name'     => $row['name'],
            'accounts' => implode(', ', $row['accounts']),
            'default'  => $row['default'],
            'changed'  => (bool) $row['changed'],
        ])),
        rule: @js(old('rule') !== null ? (int) old('rule') : null),
        accounts: @js(old('accounts', '')),
        open(number) { this.rule = number; this.accounts = this.rules[number].accounts; },
     }">

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <div class="flex items-center gap-2">
                <h2 class="text-lg font-semibold text-slate-800">Автоаудит</h2>
            </div>
            <p class="text-sm text-slate-500 mt-0.5">Какие проверки идут в вашей фирме и какие счета ведомости они берут. Номера и названия проверок общие для всех фирм.</p>
        </div>

        <div class="px-6 py-4 bg-slate-50/70 border-b border-slate-100 flex flex-wrap gap-x-10 gap-y-3">
            <div>
                <div class="text-xs text-slate-500">Проверки начинаются с</div>
                <div class="text-sm font-semibold text-slate-800">{{ $start ?? 'всех месяцев' }}</div>
            </div>
            <div>
                <div class="text-xs text-slate-500">Прогон</div>
                <div class="text-sm font-semibold text-slate-800">{{ $nightly ? 'каждую ночь' : 'вручную' }}</div>
            </div>
            <div>
                <div class="text-xs text-slate-500">Включено проверок</div>
                <div class="text-sm font-semibold text-slate-800">{{ collect($rows)->whereNull('off')->count() }} из {{ count($rows) }}</div>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Включена</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">№</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Проверка</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Счета ОСВ</th>
                        <th class="px-6 py-3"></th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-slate-200">
                    @foreach ($rows as $row)
                        <tr class="align-top {{ $row['off'] ? 'bg-slate-50' : '' }}">
                            <td class="px-6 py-4">
                                @php
                                    $confirm = $row['questions']
                                        ? "Выключить проверку №{$row['number']}? После следующего прогона её строки уйдут в историю, и закроются вопросы бухгалтерам: {$row['questions']}."
                                        : "Выключить проверку №{$row['number']}? После следующего прогона её строки уйдут в историю.";
                                @endphp
                                <form method="POST" action="{{ route('settings.auto-audit.toggle', $row['number']) }}"
                                      @if (!$row['off']) x-on:submit="if (!confirm(@js($confirm))) $event.preventDefault()" @endif>
                                    @csrf
                                    <input type="hidden" name="enabled" value="{{ $row['off'] ? 1 : 0 }}">
                                    <button type="submit" role="switch" aria-checked="{{ $row['off'] ? 'false' : 'true' }}"
                                            title="{{ $row['off'] ? 'Включить' : 'Выключить' }}"
                                            class="relative inline-flex h-6 w-11 flex-shrink-0 rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out {{ $row['off'] ? 'bg-slate-200' : 'bg-indigo-600' }}">
                                        <span class="pointer-events-none inline-block h-5 w-5 transform rounded-full bg-white shadow ring-0 transition duration-200 ease-in-out {{ $row['off'] ? 'translate-x-0' : 'translate-x-5' }}"></span>
                                    </button>
                                </form>
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-slate-700">{{ $row['number'] }}</td>
                            <td class="px-6 py-4 text-sm">
                                <div class="font-medium text-slate-900">{{ $row['name'] }}</div>
                                @if ($row['off'])
                                    <div class="text-xs text-slate-500 mt-0.5">
                                        <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600">выключена</span>
                                        {{ $row['off']['at'] ? \Carbon\Carbon::parse($row['off']['at'])->format('d.m.Y') : '' }}{{ $row['off']['by'] ? ', ' . $row['off']['by'] : '' }}
                                    </div>
                                @endif
                                <div class="text-xs text-slate-500 mt-0.5">{{ $row['hint'] }}</div>
                                @if ($row['from'])
                                    <div class="text-xs text-slate-500 mt-0.5">Проверяет с периода: {{ $row['from'] }}</div>
                                @endif
                                @if ($row['missing'])
                                    {{-- Без отмеченного БП проверка в прогон не идёт: говорим прямо, а не делаем вид, что работает. --}}
                                    <div class="text-xs text-amber-700 mt-1">Не идёт: в фирме не отмечен бизнес-процесс с документом «{{ implode('», «', $row['missing']) }}»</div>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-sm">
                                <div class="flex flex-wrap items-center gap-1.5">
                                    @foreach ($row['accounts'] as $account)
                                        <span class="px-2 py-0.5 rounded-md bg-slate-100 border border-slate-200 font-mono text-slate-800">{{ $account }}</span>
                                    @endforeach
                                    @if ($row['changed'])
                                        <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-amber-100 text-amber-800">изменено</span>
                                    @endif
                                </div>
                                @if ($row['changed'])
                                    <div class="text-xs text-slate-500 mt-1">
                                        Общий счёт: <span class="font-mono">{{ $row['default'] }}</span>.
                                        Задано{{ $row['changed']['at'] ? ' ' . \Carbon\Carbon::parse($row['changed']['at'])->format('d.m.Y') : '' }}{{ $row['changed']['by'] ? ', ' . $row['changed']['by'] : '' }}
                                    </div>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right whitespace-nowrap">
                                <button type="button" @click="open({{ $row['number'] }})"
                                        class="px-3 py-1.5 text-sm font-medium text-indigo-600 rounded-lg hover:bg-indigo-50">
                                    Изменить
                                </button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="px-6 py-4 border-t border-slate-100 text-sm text-slate-500 space-y-1">
            <p>Если у проверки несколько счетов, их обороты складываются.</p>
            <p>Новые счета действуют на все месяцы: следующий прогон пересчитает проверку с её начала.</p>
            <p>Выключенная проверка уходит после следующего прогона: её строки остаются в истории, вопросы бухгалтерам закрываются. Включили обратно: прогон пересчитает её за все месяцы, и вопросы откроются заново.</p>
        </div>
    </div>

    <template x-teleport="body">
        <div x-show="rule !== null" x-cloak class="fixed inset-0 z-50 overflow-y-auto">
            <div class="flex items-center justify-center min-h-screen px-4">
                <div class="fixed inset-0 bg-slate-500/75" @click="rule = null"></div>
                <div class="relative w-full max-w-lg bg-white rounded-2xl shadow-xl p-6 z-10">
                    <template x-if="rule !== null">
                        <form method="POST" :action="'{{ url('/settings/auto-audit') }}/' + rule">
                            @csrf
                            <div class="flex items-center justify-between mb-6">
                                <h3 class="text-lg font-semibold text-slate-900" x-text="'Проверка №' + rule"></h3>
                                <button type="button" @click="rule = null" class="p-2 text-slate-400 hover:text-slate-600 rounded-lg hover:bg-slate-100">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                            </div>
                            <div class="space-y-4">
                                <p class="text-sm text-slate-700" x-text="rules[rule].name"></p>
                                <div>
                                    <label class="block text-sm font-medium text-slate-700 mb-1">Счета ОСВ</label>
                                    <input type="text" name="accounts" x-model="accounts" required
                                           class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500"
                                           placeholder="например: 3410, 3490">
                                    <p class="mt-1 text-xs text-slate-400">Через запятую. Обороты по счетам складываются.</p>
                                    @error('accounts')
                                        <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                                    @enderror
                                </div>
                                <p class="text-xs text-slate-500">
                                    Общий счёт: <span class="font-mono" x-text="rules[rule].default"></span>.
                                    Следующий прогон пересчитает проверку за все месяцы с её начала.
                                </p>
                            </div>
                            <div class="flex items-center justify-between gap-3 mt-6 pt-6 border-t border-slate-100">
                                {{-- Вернуть общий счёт: отдельная кнопка, а не пустое поле, чтобы случайно стёртое поле не сохранялось. --}}
                                <button type="submit" name="reset" value="1" formnovalidate x-show="rules[rule].changed"
                                        class="px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50">
                                    Вернуть как было
                                </button>
                                <div class="flex gap-3 ml-auto">
                                    <button type="button" @click="rule = null" class="px-4 py-2 text-sm font-medium text-slate-700 bg-white border border-slate-300 rounded-lg hover:bg-slate-50">Отмена</button>
                                    <button type="submit" class="px-4 py-2 text-sm font-medium text-white bg-indigo-600 rounded-lg hover:bg-indigo-700">Сохранить</button>
                                </div>
                            </div>
                        </form>
                    </template>
                </div>
            </div>
        </div>
    </template>

</div>
@endsection
