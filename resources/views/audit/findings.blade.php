@extends('layouts.app')
@section('title', 'Замечания аудита')
@section('page-title', 'Аудит')

@section('content')
@push('styles')
<style>[x-cloak] { display: none !important; }</style>
@endpush

@php
    $tabs = [
        'active'    => ['Активные',    $counts['active']],
        'draft'     => ['Не переданы', $counts['draft']],
        'submitted' => ['На проверке', $counts['submitted']],
        'overdue'   => ['Просрочены',  $counts['overdue']],
        'resolved'  => ['Устранены',   $counts['resolved']],
        'all'       => ['Все',         $counts['all']],
    ];
@endphp

<div x-data="{ open: null }" class="space-y-4">

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-hidden">
        <div class="px-6 py-4 border-b border-slate-100 flex items-center justify-between flex-wrap gap-3">
            <div>
                <h2 class="text-lg font-semibold text-slate-800">Замечания</h2>
                <p class="text-sm text-slate-500 mt-0.5">
                    Замечания живут дольше отчёта: аудит завершён, а исправление отслеживается здесь до закрытия
                </p>
            </div>
            <a href="{{ route('audit.index') }}"
               class="inline-flex items-center px-4 py-2 border border-slate-200 text-slate-600 text-sm font-medium rounded-lg hover:bg-slate-100 transition-colors">
                <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/></svg>
                К списку аудитов
            </a>
        </div>

        <div class="px-6 border-b border-slate-100">
            <nav class="-mb-px flex space-x-6 overflow-x-auto">
                @foreach($tabs as $key => [$label, $count])
                    <a href="{{ route('audit.findings', ['filter' => $key]) }}"
                       @class([
                           'py-3 px-1 border-b-2 font-medium text-sm transition-colors inline-flex items-center gap-2 whitespace-nowrap',
                           'border-indigo-500 text-indigo-600' => $filter === $key,
                           'border-transparent text-slate-500 hover:text-slate-700 hover:border-slate-300' => $filter !== $key,
                       ])>
                        {{ $label }}
                        <span @class([
                            'px-2 py-0.5 rounded-full text-xs font-semibold',
                            'bg-indigo-50 text-indigo-600' => $filter === $key,
                            'bg-red-50 text-red-600'       => $filter !== $key && $key === 'overdue' && $count > 0,
                            'bg-slate-100 text-slate-500'  => $filter !== $key && !($key === 'overdue' && $count > 0),
                        ])>{{ $count }}</span>
                    </a>
                @endforeach
            </nav>
        </div>

        <div class="divide-y divide-slate-100">
            @forelse($reviews as $review)
                @php
                    $state = $review->state;
                    $adhoc = $review->adhocTask;
                @endphp
                <div class="px-6 py-4">
                    <div class="flex items-start gap-4">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span @class([
                                    'inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium',
                                    'bg-red-50 text-red-700'      => $review->severity === 'critical',
                                    'bg-amber-50 text-amber-700'  => $review->severity === 'major',
                                    'bg-slate-100 text-slate-600' => $review->severity === 'minor',
                                ])>{{ \App\Models\Audit::$severities[$review->severity] ?? '—' }}</span>

                                <span @class([
                                    'inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium',
                                    'bg-slate-100 text-slate-600'   => $state === 'draft',
                                    'bg-amber-50 text-amber-700'    => $state === 'open',
                                    'bg-indigo-50 text-indigo-700'  => $state === 'submitted',
                                    'bg-emerald-50 text-emerald-700'=> $state === 'resolved',
                                ])>{{ $review->state_label }}</span>

                                @if($review->is_overdue)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-red-50 text-red-700">
                                        просрочено с {{ $review->due_date->format('d.m.Y') }}
                                    </span>
                                @endif

                                @if($review->returns_count > 0)
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-slate-100 text-slate-600">
                                        возвратов: {{ $review->returns_count }}
                                    </span>
                                @endif
                            </div>

                            <p class="text-sm font-medium text-slate-800 mt-1.5">{{ $review->task_name }}</p>

                            <p class="text-xs text-slate-400 mt-0.5">
                                {{ $review->audit?->client?->name }}
                                <span class="mx-1 text-slate-300">·</span>{{ $review->audit?->period_label }}
                                @if($review->section)
                                    <span class="mx-1 text-slate-300">·</span>{{ $review->section }}
                                @endif
                                @if($review->assignee)
                                    <span class="mx-1 text-slate-300">·</span>исправляет {{ $review->assignee->full_name }}
                                @endif
                                @if($review->due_date && $state !== 'resolved')
                                    <span class="mx-1 text-slate-300">·</span>срок {{ $review->due_date->format('d.m.Y') }}
                                @endif
                                @if($state === 'resolved' && $review->resolved_at)
                                    <span class="mx-1 text-slate-300">·</span>закрыто {{ $review->resolved_at->format('d.m.Y') }}
                                @endif
                            </p>

                            @if($review->comment)
                                <p class="text-sm text-slate-600 mt-2 bg-slate-50 border border-slate-200/70 rounded-xl px-3 py-2">{{ $review->comment }}</p>
                            @endif

                            @if($adhoc?->employee_comment)
                                <div class="mt-2 bg-indigo-50/60 border border-indigo-100 rounded-xl px-3 py-2">
                                    <p class="text-xs font-medium text-indigo-700">Что сделал бухгалтер</p>
                                    <p class="text-sm text-slate-700 mt-0.5">{{ $adhoc->employee_comment }}</p>
                                </div>
                            @endif
                        </div>

                        <div class="flex flex-col items-end gap-2 flex-shrink-0">
                            @if($state === 'submitted')
                                <form method="POST" action="{{ route('audit.findings.resolve', $review) }}">
                                    @csrf
                                    <button class="inline-flex items-center px-3 py-1.5 bg-emerald-600 text-white text-xs font-medium rounded-lg hover:bg-emerald-700 transition-colors">
                                        <svg class="w-3.5 h-3.5 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/></svg>
                                        Устранено
                                    </button>
                                </form>
                                <button @click="open = open === {{ $review->id }} ? null : {{ $review->id }}"
                                        class="px-3 py-1.5 border border-slate-200 text-slate-600 text-xs font-medium rounded-lg hover:bg-slate-100 transition-colors">
                                    Вернуть на доработку
                                </button>
                            @elseif($state === 'draft')
                                <button @click="open = open === {{ $review->id }} ? null : {{ $review->id }}"
                                        class="inline-flex items-center px-3 py-1.5 bg-indigo-600 text-white text-xs font-medium rounded-lg hover:bg-indigo-700 transition-colors">
                                    Передать на исправление
                                </button>
                            @elseif($state === 'open')
                                <button @click="open = open === {{ $review->id }} ? null : {{ $review->id }}"
                                        class="px-3 py-1.5 border border-slate-200 text-slate-600 text-xs font-medium rounded-lg hover:bg-slate-100 transition-colors">
                                    Сменить исполнителя
                                </button>
                            @endif

                            <a href="{{ route('audit.show', $review->audit_id) }}"
                               class="text-xs text-slate-400 hover:text-indigo-600 transition-colors">К аудиту</a>
                        </div>
                    </div>

                    {{-- Формы действий: разворачиваются по кнопке --}}
                    <div x-show="open === {{ $review->id }}" x-cloak class="mt-3 pt-3 border-t border-slate-100">
                        @if($state === 'submitted')
                            <form method="POST" action="{{ route('audit.findings.return', $review) }}" class="flex items-end gap-2">
                                @csrf
                                <div class="flex-1">
                                    <label class="block text-xs text-slate-500 mb-1">Что осталось не так</label>
                                    <input type="text" name="comment" required placeholder="Причина возврата — её увидит бухгалтер"
                                           class="block w-full px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20 focus:border-indigo-500">
                                </div>
                                <button class="px-4 py-2 bg-amber-600 text-white text-sm font-medium rounded-lg hover:bg-amber-700 transition-colors">Вернуть</button>
                            </form>
                        @elseif($state === 'draft')
                            <form method="POST" action="{{ route('audit.findings.send', $review) }}" class="flex items-end gap-2 flex-wrap">
                                @csrf
                                <div>
                                    <label class="block text-xs text-slate-500 mb-1">Исполнитель</label>
                                    <select name="assignee_id" required
                                            class="block w-64 px-3 py-2 border border-slate-200 rounded-lg text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
                                        @foreach($employees as $employee)
                                            <option value="{{ $employee->id }}">{{ $employee->full_name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs text-slate-500 mb-1">Срок</label>
                                    <input type="date" name="due_date" required value="{{ now()->addWeekdays(10)->toDateString() }}"
                                           class="block px-3 py-2 border border-slate-200 rounded-lg text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
                                </div>
                                <button class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">Передать</button>
                            </form>
                        @elseif($state === 'open')
                            <form method="POST" action="{{ route('audit.findings.reassign', $review) }}" class="flex items-end gap-2">
                                @csrf
                                <div>
                                    <label class="block text-xs text-slate-500 mb-1">Новый исполнитель</label>
                                    <select name="assignee_id" required
                                            class="block w-64 px-3 py-2 border border-slate-200 rounded-lg text-sm bg-white focus:outline-none focus:ring-2 focus:ring-indigo-500/20">
                                        @foreach($employees as $employee)
                                            <option value="{{ $employee->id }}" @selected($review->assignee_id === $employee->id)>{{ $employee->full_name }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <button class="px-4 py-2 bg-indigo-600 text-white text-sm font-medium rounded-lg hover:bg-indigo-700 transition-colors">Переназначить</button>
                            </form>
                        @endif
                    </div>
                </div>
            @empty
                <div class="px-6 py-12 text-center">
                    <p class="text-slate-500">Замечаний в этом разделе нет.</p>
                    <p class="text-sm text-slate-400 mt-1">Замечания появляются здесь после того, как аудитор вынес вердикт по задаче.</p>
                </div>
            @endforelse
        </div>
    </div>
</div>
@endsection
