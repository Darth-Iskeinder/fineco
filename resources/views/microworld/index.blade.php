@extends('layouts.app')
@section('title', 'Микромир')
@section('page-title', 'Микромир')

@section('content')
@php
    use App\Http\Controllers\MicroworldController as Page;

    $title = fn ($m) => Page::MONTHS[$m->month] . ' ' . $m->year;
    $url   = fn (array $change) => route('microworld.index', array_filter(array_merge([
        'month'    => $focus->format('Y-m'),
        'employee' => $vendor ? $employee->id : null,
        'view'     => $view === 'me' && $team ? 'me' : null,
    ], $change), fn ($v) => $v !== null));

@endphp

<div class="space-y-4">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <h2 class="text-lg font-semibold text-slate-900">{{ $employee->full_name }} · {{ $title($focus) }}</h2>
            <p class="text-sm text-slate-500">
                @if ($view === 'team')
                    Все задачи из сметы по клиентам, которых вы ведёте, кто бы их ни делал.
                @else
                    Только задачи из сметы, которые делаете вы.
                @endif
                Опоздание считается по дню сдачи, а не приёмки.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($vendor)
                {{-- Вендор смотрит глазами выбранного сотрудника --}}
                <form method="get" action="{{ route('microworld.index') }}">
                    <input type="hidden" name="month" value="{{ $focus->format('Y-m') }}">
                    <select name="employee" onchange="this.form.submit()"
                            class="rounded-lg border border-slate-200 bg-white px-2 py-1.5 text-sm text-slate-700">
                        @foreach ($choices as $c)
                            <option value="{{ $c->id }}" @selected($c->id === $employee->id)>{{ $c->full_name }}{{ $c->role ? ' · ' . $c->role->display_name : '' }}</option>
                        @endforeach
                    </select>
                </form>
            @endif

            <div class="inline-flex items-center rounded-lg border border-slate-200 bg-white text-sm">
                @if ($prev)
                    <a href="{{ $url(['month' => $prev->format('Y-m')]) }}" class="px-2.5 py-1.5 text-slate-500 hover:text-slate-900" aria-label="Предыдущий месяц">‹</a>
                @else
                    <span class="px-2.5 py-1.5 text-slate-300" aria-hidden="true">‹</span>
                @endif
                <span class="px-2 py-1.5 font-medium text-slate-800">{{ $title($focus) }}</span>
                @if ($next)
                    <a href="{{ $url(['month' => $next->format('Y-m')]) }}" class="px-2.5 py-1.5 text-slate-500 hover:text-slate-900" aria-label="Следующий месяц">›</a>
                @else
                    <span class="px-2.5 py-1.5 text-slate-300" aria-hidden="true">›</span>
                @endif
            </div>
        </div>
    </div>

    @if ($team)
        {{-- Вкладки у того, кто ведёт клиентов --}}
        <div class="flex flex-wrap gap-2" data-tabs>
            @foreach (['team' => 'Команда на моих клиентах', 'me' => 'Мои задачи'] as $key => $label)
                <a href="{{ $url(['view' => $key === 'me' ? 'me' : null]) }}"
                   @class(['rounded-full border px-4 py-1.5 text-sm font-medium transition-colors',
                           'border-slate-900 bg-slate-900 text-white' => $view === $key,
                           'border-slate-200 bg-white text-slate-700 hover:bg-slate-50' => $view !== $key])>{{ $label }}</a>
            @endforeach
        </div>
    @endif

    @if ($view === 'team')
        @include('microworld._month', ['m' => $team['month'], 'isCurrent' => $isCurrent, 'empty' => 'В этом месяце у ваших клиентов нет задач из сметы.'])
        @include('microworld._team', ['team' => $team])
        @include('microworld._history', ['history' => $team['history'], 'focus' => $focus, 'url' => $url, 'title' => 'Мои клиенты по месяцам'])
    @else
        @include('microworld._month', ['m' => $month, 'isCurrent' => $isCurrent, 'empty' => 'В этом месяце у вас нет задач из сметы.'])
        @include('microworld._history', ['history' => $history, 'focus' => $focus, 'url' => $url, 'title' => 'Как менялось по месяцам'])

        {{-- Просрочки сейчас, за все месяцы --}}
        <div class="rounded-2xl border border-slate-200 bg-white p-5" data-overdue x-data="{ all: false }">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <p class="text-sm font-medium text-slate-700">
                    Просрочено сейчас
                    @if (count($overdue) > 0)
                        <span class="ml-1 rounded-full bg-red-50 px-2 py-0.5 text-xs font-semibold text-red-700">{{ count($overdue) }}</span>
                    @endif
                </p>
                @if (count($overdue) > 0 && Route::has('buhtasks.index'))
                    <a href="{{ route('buhtasks.index') }}" class="text-sm text-indigo-600 hover:text-indigo-800">Открыть БухЗадачник →</a>
                @endif
            </div>
            @include('microworld._overdue', ['rows' => $overdue])
        </div>
    @endif
</div>
@endsection
