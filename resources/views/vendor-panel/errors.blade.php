@extends('layouts.vendor')

@section('title', 'Сбои')

@section('content')
<main class="max-w-5xl mx-auto px-6 py-10" x-data="{ open: null }">
    <div class="flex items-start justify-between gap-6">
        <div>
            <h1 class="text-2xl font-bold text-white">Журнал сбоев</h1>
            <p class="mt-1 text-sm text-slate-500">
                Всё, что сломалось у любой фирмы: исключения на сервере и ошибки в браузере
                у сотрудников. Одинаковые сбои собраны в одну строку со счётчиком повторов.
            </p>
        </div>

        <a href="{{ route('vendor.errors.index', $showResolved ? [] : ['resolved' => 1]) }}"
           class="shrink-0 px-4 py-2 rounded-lg text-xs font-semibold border border-slate-700 text-slate-300 hover:text-white hover:border-slate-500 transition-colors">
            {{ $showResolved ? 'Только неразобранные' : 'Показать разобранные' }}
        </a>
    </div>

    @if (session('status'))
        <div class="mt-6 rounded-xl bg-amber-500/10 border border-amber-500/30 px-4 py-3 text-sm text-amber-200">
            {{ session('status') }}
        </div>
    @endif

    <div class="mt-6 space-y-3">
        @forelse ($reports as $report)
            <div class="bg-slate-900 rounded-2xl border {{ $report->isResolved() ? 'border-slate-800' : 'border-slate-700' }} overflow-hidden">
                <div class="px-6 py-4">
                    <div class="flex items-start justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="px-2 py-0.5 rounded-full text-xs font-medium
                                    {{ $report->kind === \App\Models\ErrorReport::KIND_SERVER
                                        ? 'bg-rose-500/10 text-rose-300 border border-rose-500/20'
                                        : 'bg-sky-500/10 text-sky-300 border border-sky-500/20' }}">
                                    {{ $report->kind === \App\Models\ErrorReport::KIND_SERVER ? 'сервер' : 'браузер' }}
                                </span>

                                @if ($report->status)
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-slate-800 text-slate-400 border border-slate-700">
                                        {{ $report->status }}
                                    </span>
                                @endif

                                @if ($report->count > 1)
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-amber-500/10 text-amber-300 border border-amber-500/20">
                                        ×{{ $report->count }}
                                    </span>
                                @endif

                                @if ($report->isResolved())
                                    <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-emerald-500/10 text-emerald-300 border border-emerald-500/20">
                                        разобрано
                                    </span>
                                @endif
                            </div>

                            {{-- Текст ошибки приходит в том числе из браузера, поэтому только
                                 как текст: Blade экранирует его сам, и HTML отсюда не выполнится. --}}
                            <p class="mt-2 text-sm text-white break-words">{{ $report->message }}</p>

                            <dl class="mt-2 text-xs text-slate-500 space-y-0.5">
                                @if ($report->url)
                                    <div class="break-all"><span class="text-slate-600">Страница:</span> {{ $report->url }}</div>
                                @endif
                                @if ($report->source)
                                    <div class="break-all"><span class="text-slate-600">Место:</span> {{ $report->source }}</div>
                                @endif
                                <div>
                                    <span class="text-slate-600">Фирма:</span> {{ $report->tenant?->name ?? '—' }}
                                    <span class="text-slate-600 ml-3">Сотрудник:</span> {{ $report->employee?->full_name ?? '—' }}
                                </div>
                                <div>
                                    <span class="text-slate-600">Последний раз:</span> {{ $report->last_seen_at->format('d.m.Y H:i') }}
                                    @if ($report->count > 1)
                                        <span class="text-slate-600 ml-3">Впервые:</span> {{ $report->first_seen_at->format('d.m.Y H:i') }}
                                    @endif
                                </div>
                            </dl>
                        </div>

                        <form method="POST"
                              action="{{ $report->isResolved() ? route('vendor.errors.reopen', $report) : route('vendor.errors.resolve', $report) }}"
                              class="shrink-0">
                            @csrf
                            <button type="submit"
                                    class="px-3 py-1.5 rounded-lg text-xs font-semibold border border-slate-700 text-slate-300 hover:text-white hover:border-slate-500 transition-colors whitespace-nowrap">
                                {{ $report->isResolved() ? 'Вернуть в работу' : 'Разобрано' }}
                            </button>
                        </form>
                    </div>

                    @if ($report->context)
                        <button type="button" @click="open = (open === {{ $report->id }} ? null : {{ $report->id }})"
                                class="mt-3 text-xs text-slate-500 hover:text-slate-300 transition-colors">
                            <span x-text="open === {{ $report->id }} ? 'Скрыть подробности' : 'Подробности'">Подробности</span>
                        </button>

                        <pre x-show="open === {{ $report->id }}" x-cloak
                             class="mt-3 p-4 bg-slate-950 rounded-xl border border-slate-800 text-xs text-slate-400 overflow-x-auto whitespace-pre-wrap break-words max-h-80 overflow-y-auto">{{ $report->context }}</pre>
                    @endif
                </div>
            </div>
        @empty
            <div class="bg-slate-900 rounded-2xl border border-slate-800 px-6 py-16 text-center">
                <p class="text-slate-400">{{ $showResolved ? 'Записей нет' : 'Неразобранных сбоев нет' }}</p>
                <p class="mt-1 text-xs text-slate-600">Хороший знак: с момента последней чистки ничего не падало.</p>
            </div>
        @endforelse
    </div>

    <div class="mt-6">
        {{ $reports->links() }}
    </div>

    <p class="mt-6 text-xs text-slate-600">
        Сюда не попадают 404, отказы доступа и непрошедшая валидация — это обычная жизнь
        приложения, а не поломка. Полный текст со стеком по-прежнему пишется в файловый лог
        на сервере.
    </p>
</main>
@endsection
