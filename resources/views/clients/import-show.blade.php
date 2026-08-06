@extends('layouts.app')

@section('title', 'Загрузка клиентов')
@section('page-title', 'Загрузка клиентов')

@section('content')
<div class="max-w-4xl mx-auto">
    <div class="mb-6">
        <a href="{{ route('clients.imports.index') }}" class="text-sm text-slate-400 hover:text-slate-600 inline-flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            К истории импортов
        </a>
        <p class="text-sm text-slate-500 mt-2">
            Файл <span class="font-medium text-slate-700">{{ $import->file_name }}</span>,
            загрузил {{ $import->employee?->full_name ?? 'сотрудник удалён' }}
            {{ $import->created_at->format('d.m.Y') }} в {{ $import->created_at->format('H:i') }}.
            @if ($import->update_existing)
                Совпавшие по ИНН клиенты обновлялись.
            @endif
        </p>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50/80 border-b border-slate-200/50">
                    <tr class="text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">
                        <th class="px-6 py-4 w-32">Что сделано</th>
                        <th class="px-6 py-4">Клиент</th>
                        <th class="px-6 py-4 w-40">ИНН</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($rows as $row)
                        <tr class="hover:bg-slate-50/60 transition-colors">
                            <td class="px-6 py-3.5">
                                @if ($row->action === \App\Models\ClientImportRow::ACTION_CREATED)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-50 text-emerald-700">создан</span>
                                @else
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-indigo-50 text-indigo-700">обновлён</span>
                                @endif
                            </td>
                            <td class="px-6 py-3.5">
                                @if ($row->client)
                                    <a href="{{ route('clients.show', $row->client) }}"
                                       class="text-indigo-600 hover:text-indigo-800 font-medium">{{ $row->client->name }}</a>
                                @else
                                    {{-- Клиента удалили уже после загрузки — строка журнала остаётся. --}}
                                    <span class="text-slate-400 italic">клиент удалён</span>
                                @endif
                            </td>
                            <td class="px-6 py-3.5 text-slate-500">{{ $row->client?->inn ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="3" class="px-6 py-10 text-center text-slate-500">
                                Загрузка не создала и не обновила ни одного клиента
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($rows->hasPages())
            <div class="px-6 py-3 border-t border-slate-100 flex items-center justify-between gap-3">
                <span class="text-xs text-slate-400">
                    Страница {{ $rows->currentPage() }} из {{ $rows->lastPage() }}
                </span>
                <div class="flex items-center gap-1">
                    @if ($rows->previousPageUrl())
                        <a href="{{ $rows->previousPageUrl() }}"
                           class="px-3 py-1.5 rounded-lg text-xs font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 transition-colors">Назад</a>
                    @endif
                    @if ($rows->nextPageUrl())
                        <a href="{{ $rows->nextPageUrl() }}"
                           class="px-3 py-1.5 rounded-lg text-xs font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 transition-colors">Вперёд</a>
                    @endif
                </div>
            </div>
        @endif
    </div>

    @if ($import->skipped_count > 0)
        <p class="text-xs text-slate-400 mt-4">
            Пропущенных строк: {{ $import->skipped_count }}. Их содержимое не сохраняется — файл удаляется сразу после загрузки.
        </p>
    @endif
</div>
@endsection
