{{-- Таблица просрочек: 15 строк, остальные по кнопке. Ждёт: $rows. Обёртка задаёт x-data с all. --}}
@php
    use App\Http\Controllers\MicroworldController as Page;
@endphp

@if (count($rows) === 0)
    <p class="mt-2 text-sm text-slate-500">Просроченных задач нет.</p>
@else
    <div class="mt-3 overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-xs text-slate-400">
                    <th class="py-2 pr-3 font-medium">Задача</th>
                    <th class="py-2 pr-3 font-medium">Клиент</th>
                    <th class="py-2 pr-3 font-medium">Срок</th>
                    <th class="py-2 text-right font-medium">Опоздание</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-100">
                @foreach ($rows as $o)
                    <tr @if ($loop->index >= 15) x-show="all" x-cloak @endif>
                        <td class="py-2 pr-3 text-slate-800">
                            {{ $o['name'] }}
                            @if ($o['status'] === 'rework')
                                <span class="ml-1 rounded bg-amber-50 px-1.5 py-0.5 text-xs text-amber-700">на доработке</span>
                            @endif
                        </td>
                        <td class="py-2 pr-3 text-slate-600">{{ $o['client'] }}</td>
                        <td class="py-2 pr-3 whitespace-nowrap text-slate-600">{{ $o['due']->format('d.m.Y') }}</td>
                        <td class="py-2 text-right whitespace-nowrap font-semibold text-red-600">+{{ Page::plural($o['days'], ['день', 'дня', 'дней']) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
    @if (count($rows) > 15)
        <button type="button" class="mt-2 text-sm text-indigo-600 hover:text-indigo-800"
                x-show="!all" @click="all = true">Показать все {{ count($rows) }}</button>
    @endif
@endif
