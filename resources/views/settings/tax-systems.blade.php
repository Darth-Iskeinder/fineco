@extends('settings.layout')
@section('page-title', 'Режимы налогообложения')

@section('settings-content')
{{--
    Справочная страница: только просмотр, без добавления, правки и удаления.
    Список режимов задаёт государство, а не бухфирма, поэтому менять его здесь
    нельзя. Удаление к тому же молча стирало привязки режима у всех БП — смета
    после этого переставала их подтягивать, и никакой ошибки на экране не было.
--}}
<div class="space-y-4">

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100">
            <div class="flex items-center gap-2">
                <h2 class="text-lg font-semibold text-slate-800">Режимы налогообложения</h2>
                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600">
                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/></svg>
                    Только просмотр
                </span>
            </div>
            <p class="text-sm text-slate-500 mt-0.5">Режимы налогообложения, применяемые к клиентам</p>
        </div>

        {{-- Подсказка сотруднику: почему тут нет кнопок и куда идти, если чего-то не хватает --}}
        <div class="px-6 py-4 bg-slate-50/70 border-b border-slate-100">
            <div class="flex gap-3">
                <svg class="w-5 h-5 text-slate-400 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                <div class="text-sm text-slate-600 space-y-1.5">
                    <p>Список режимов устанавливает государство, поэтому изменить его здесь нельзя. Он одинаковый для всех компаний и обновляется централизованно.</p>
                    <p>Режим выбирается в карточке клиента. От него зависит, какие бизнес-процессы попадут в смету: подтягиваются те, что отмечены для этого режима, плюс обязательные.</p>
                    <p class="text-slate-500">Если нужного режима нет в списке — напишите нам, добавим для всех сразу.</p>
                </div>
            </div>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200">
                <thead class="bg-slate-50">
                    <tr>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Название</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Код</th>
                        <th class="px-6 py-3 text-left text-xs font-medium text-slate-500 uppercase tracking-wider">Описание</th>
                        <th class="px-6 py-3 text-right text-xs font-medium text-slate-500 uppercase tracking-wider">Клиентов</th>
                    </tr>
                </thead>
                <tbody class="bg-white divide-y divide-slate-200">
                    @forelse ($taxSystems as $item)
                        <tr class="hover:bg-slate-50">
                            <td class="px-6 py-4 whitespace-nowrap text-sm font-medium text-slate-900">{{ $item->name }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-slate-500 font-mono">{{ $item->code }}</td>
                            <td class="px-6 py-4 text-sm text-slate-500">{{ $item->description ?: '—' }}</td>
                            <td class="px-6 py-4 whitespace-nowrap text-sm text-right {{ $item->clients_count ? 'text-slate-700 font-medium' : 'text-slate-300' }}">
                                {{ $item->clients_count }}
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="px-6 py-10 text-center text-slate-400">Нет данных</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>
@endsection
