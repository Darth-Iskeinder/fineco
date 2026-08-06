@extends('layouts.app')

@section('title', 'Проверка импорта')

@section('content')
{{-- Экран проверки: файл разобран, но в базе пока ничего не изменилось.
     Судьбу каждой строки считаем сразу для обоих режимов, поэтому галочка
     «обновлять существующих» пересчитывает счётчики мгновенно. --}}
<div class="max-w-6xl mx-auto"
     x-data="{
        updateExisting: false,
        rows: @js(collect($plan)->map(fn ($row) => [
            'line'      => $row['line'],
            'name'      => $row['name'],
            'inn'       => $row['inn'],
            'strict'    => \App\Services\ClientImportPlanner::verdict($row, false),
            'loose'     => \App\Services\ClientImportPlanner::verdict($row, true),
            'reason'    => $row['reason'],
            'dupReason' => $row['duplicate_reason'],
        ])),
        verdict(row) { return this.updateExisting ? row.loose : row.strict; },
        reason(row) { return (!this.updateExisting && row.dupReason) ? row.dupReason : row.reason; },
        count(kind) { return this.rows.filter(r => this.verdict(r) === kind).length; },
        get duplicates() { return this.rows.filter(r => r.dupReason).length; },
     }">

    <div class="mb-6">
        <a href="{{ route('clients.index') }}" class="text-sm text-slate-400 hover:text-slate-600 inline-flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            К списку клиентов
        </a>
        <h1 class="mt-2 text-xl font-semibold text-slate-800">Проверка импорта</h1>
        <p class="text-sm text-slate-500 mt-1">
            Файл <span class="font-medium text-slate-700">{{ $fileName }}</span> разобран.
            В базе пока ничего не изменилось — посмотрите, что получится, и подтвердите.
        </p>
    </div>

    {{-- Счётчики: главное на экране, поэтому крупно и сверху --}}
    <div class="grid grid-cols-3 gap-4 mb-6">
        <div class="bg-white rounded-2xl border border-slate-200/60 px-5 py-4">
            <p class="text-xs font-semibold text-emerald-600 uppercase tracking-wider">Создать</p>
            <p class="text-2xl font-semibold text-slate-800 mt-1" x-text="count('create')"></p>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200/60 px-5 py-4">
            <p class="text-xs font-semibold text-indigo-600 uppercase tracking-wider">Обновить</p>
            <p class="text-2xl font-semibold text-slate-800 mt-1" x-text="count('update')"></p>
        </div>
        <div class="bg-white rounded-2xl border border-slate-200/60 px-5 py-4">
            <p class="text-xs font-semibold text-rose-600 uppercase tracking-wider">Пропустить</p>
            <p class="text-2xl font-semibold text-slate-800 mt-1" x-text="count('error')"></p>
        </div>
    </div>

    {{-- Режим. Выключен по умолчанию: перезапись существующих клиентов —
         разрушительное действие, оно должно быть выбором, а не умолчанием. --}}
    <div class="bg-white rounded-2xl border border-slate-200/60 px-5 py-4 mb-6" x-show="duplicates > 0">
        <label class="flex items-start gap-3 cursor-pointer">
            <input type="checkbox" x-model="updateExisting"
                   class="mt-0.5 w-4 h-4 rounded border-slate-300 text-indigo-600 focus:ring-indigo-500/30">
            <span>
                <span class="text-sm font-medium text-slate-800">Обновлять существующих клиентов</span>
                <span class="block text-xs text-slate-500 mt-0.5">
                    <span x-text="duplicates"></span> <span x-text="duplicates === 1 ? 'строка совпала' : 'строк совпало'"></span>
                    по ИНН с клиентами в базе. Без галочки такие строки пропускаются, с галочкой — перезаписывают данные клиента.
                </span>
            </span>
        </label>
    </div>

    <div class="bg-white rounded-2xl border border-slate-200/60 overflow-hidden mb-6">
        <div class="overflow-x-auto max-h-[26rem] overflow-y-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50/80 sticky top-0">
                    <tr class="text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">
                        <th class="px-4 py-3 w-16">Строка</th>
                        <th class="px-4 py-3 w-28">Что будет</th>
                        <th class="px-4 py-3">Название</th>
                        <th class="px-4 py-3 w-40">ИНН</th>
                        <th class="px-4 py-3">Причина</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    <template x-for="row in rows" :key="row.line">
                        <tr :class="verdict(row) === 'error' ? 'bg-rose-50/40' : ''">
                            <td class="px-4 py-2.5 text-slate-400" x-text="row.line"></td>
                            <td class="px-4 py-2.5">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium"
                                      :class="{
                                        'bg-emerald-50 text-emerald-700': verdict(row) === 'create',
                                        'bg-indigo-50 text-indigo-700': verdict(row) === 'update',
                                        'bg-rose-50 text-rose-700': verdict(row) === 'error',
                                      }"
                                      x-text="{ create: 'создать', update: 'обновить', error: 'пропустить' }[verdict(row)]"></span>
                            </td>
                            <td class="px-4 py-2.5 text-slate-700" x-text="row.name || '—'"></td>
                            <td class="px-4 py-2.5 text-slate-500" x-text="row.inn || '—'"></td>
                            <td class="px-4 py-2.5 text-rose-600" x-text="reason(row) || ''"></td>
                        </tr>
                    </template>
                </tbody>
            </table>
        </div>
    </div>

    <div class="flex items-center gap-3">
        <form action="{{ route('clients.import.apply', $token) }}" method="POST">
            @csrf
            <input type="hidden" name="update_existing" :value="updateExisting ? 1 : 0">
            <button type="submit"
                    :disabled="count('create') + count('update') === 0"
                    class="inline-flex items-center px-5 py-2.5 bg-indigo-600 text-white text-sm font-medium rounded-xl hover:bg-indigo-700 disabled:opacity-40 disabled:cursor-not-allowed transition-colors">
                Импортировать
                <span class="ml-1.5 text-indigo-200" x-text="'(' + (count('create') + count('update')) + ')'"></span>
            </button>
        </form>

        <a x-show="count('error') > 0"
           :href="'{{ route('clients.import.errors', $token) }}' + (updateExisting ? '?update_existing=1' : '')"
           class="inline-flex items-center px-4 py-2.5 bg-white text-slate-600 text-sm font-medium rounded-xl border border-slate-200 hover:bg-slate-50 transition-colors">
            Скачать пропущенные
        </a>

        <a href="{{ route('clients.index') }}"
           class="ml-auto text-sm text-slate-500 hover:text-slate-700">Отмена</a>
    </div>
</div>
@endsection
