@extends('settings.layout')
@section('page-title', 'Автоаудит')

@section('settings-content')
{{--
    Только просмотр: какие проверки идут в фирме и какие счета ведомости они берут.
    Счета фирме задаёт вендор командой autoaudit:accounts. Правка здесь и выключатель
    проверки будут следующими шагами, поэтому кнопок пока нет совсем: страница не должна
    обещать то, чего не делает.
--}}
<div class="space-y-4">

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <div class="flex items-center gap-2">
                <h2 class="text-lg font-semibold text-slate-800">Автоаудит</h2>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600">
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    Только просмотр
                </span>
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
                <div class="text-xs text-slate-500">Проверок</div>
                <div class="text-sm font-semibold text-slate-800">{{ count($rows) }}</div>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">№</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Проверка</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Счета ОСВ</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-slate-200">
                    @foreach ($rows as $row)
                        <tr class="align-top">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-semibold text-slate-700">{{ $row['number'] }}</td>
                            <td class="px-6 py-4 text-sm">
                                <div class="font-medium text-slate-900">{{ $row['name'] }}</div>
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
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="px-6 py-4 border-t border-slate-100 text-sm text-slate-500 space-y-1">
            <p>Если у проверки несколько счетов, их обороты складываются.</p>
            <p>Нужно поменять счета: напишите нам, поменяем.</p>
        </div>
    </div>

</div>
@endsection
