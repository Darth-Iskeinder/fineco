@extends('layouts.app')

@section('title', 'История импортов')
@section('page-title', 'История импортов')

@section('content')
<div class="max-w-5xl mx-auto">
    <div class="mb-6">
        <a href="{{ route('clients.index') }}" class="text-sm text-slate-400 hover:text-slate-600 inline-flex items-center gap-1">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
            К списку клиентов
        </a>
        <p class="text-sm text-slate-500 mt-2">
            Кто и когда загружал клиентов из файла. Нажмите на строку, чтобы увидеть, кого именно затронула загрузка.
        </p>
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50/80 border-b border-slate-200/50">
                    <tr class="text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">
                        <th class="px-6 py-4">Когда</th>
                        <th class="px-6 py-4">Кто</th>
                        <th class="px-6 py-4">Файл</th>
                        <th class="px-6 py-4">Результат</th>
                        <th class="px-6 py-4"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($imports as $import)
                        <tr class="hover:bg-slate-50/60 transition-colors">
                            <td class="px-6 py-4 whitespace-nowrap text-slate-700">
                                {{ $import->created_at->format('d.m.Y H:i') }}
                            </td>
                            <td class="px-6 py-4 text-slate-700">
                                {{ $import->employee?->full_name ?? 'сотрудник удалён' }}
                            </td>
                            <td class="px-6 py-4 text-slate-500 max-w-[16rem] truncate" title="{{ $import->file_name }}">
                                {{ $import->file_name }}
                            </td>
                            <td class="px-6 py-4 whitespace-nowrap">
                                <span class="text-emerald-600 font-medium">{{ $import->created_count }}</span>
                                <span class="text-slate-400"> создано · </span>
                                <span class="text-indigo-600 font-medium">{{ $import->updated_count }}</span>
                                <span class="text-slate-400"> обновлено</span>
                                @if ($import->skipped_count > 0)
                                    <span class="text-slate-400"> · </span>
                                    <span class="text-slate-500">{{ $import->skipped_count }} пропущено</span>
                                @endif
                            </td>
                            <td class="px-6 py-4 text-right whitespace-nowrap">
                                <a href="{{ route('clients.imports.show', $import) }}"
                                   class="text-sm text-indigo-600 hover:text-indigo-800 font-medium">
                                    Клиенты ({{ $import->rows_count }})
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="5" class="px-6 py-12 text-center">
                                <p class="text-slate-500">Клиентов из файла ещё не загружали</p>
                                <p class="text-sm text-slate-400 mt-1">
                                    Это делается кнопкой «Импорт» на странице клиентов.
                                </p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($imports->hasPages())
            {{-- Своя разметка вместо $imports->links(): шаблон пагинации живёт
                 в vendor, и Vite не собирает его классы — вышли бы голые ссылки. --}}
            <div class="px-6 py-3 border-t border-slate-100 flex items-center justify-between gap-3">
                <span class="text-xs text-slate-400">
                    Страница {{ $imports->currentPage() }} из {{ $imports->lastPage() }}
                </span>
                <div class="flex items-center gap-1">
                    @if ($imports->previousPageUrl())
                        <a href="{{ $imports->previousPageUrl() }}"
                           class="px-3 py-1.5 rounded-lg text-xs font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 transition-colors">Назад</a>
                    @endif
                    @if ($imports->nextPageUrl())
                        <a href="{{ $imports->nextPageUrl() }}"
                           class="px-3 py-1.5 rounded-lg text-xs font-medium text-slate-600 border border-slate-200 hover:bg-slate-50 transition-colors">Вперёд</a>
                    @endif
                </div>
            </div>
        @endif
    </div>
</div>
@endsection
