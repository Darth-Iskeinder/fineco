{{-- Карточка клиента за месяц: кусок страницы, приходит запросом по клику на клетку.
     Кнопки работают через data-атрибуты, клики ловит страница (monthClosingPage). --}}
@php
    use App\Http\Controllers\MonthClosingController as Page;
    use App\Services\AutoAudit\AutoAuditClientBoard as AuditBoard;
    use App\Services\AutoAudit\AutoAuditSources;
    use App\Services\MonthClosing\MonthClosingBoard as Board;

    $client = $row['client'];
    $key    = $focus->format('Y-m');
    $status = $cell['status'];
    $title  = fn ($m) => Page::MONTHS[$m->month] . ' ' . $m->year;
    $short  = fn (?int $id) => $id ? AutoAuditSources::shortName($name($id)) : 'не назначен';

    $open = array_values(array_filter($cell['tasks'], fn (array $t) => !$t['done']));
    // Налоги и отчёты идут своим блоком, в общем списке открытых их не повторяем.
    $taxOpen   = $taxes === 'on' ? array_values(array_filter($open, fn (array $t) => $t['tax'])) : [];
    $otherOpen = $taxes === 'on' ? array_values(array_filter($open, fn (array $t) => !$t['tax'])) : $open;
    $done = array_values(array_filter($cell['tasks'], fn (array $t) => $t['done']));
    $byDue = fn ($a, $b) => ($a['due']?->timestamp ?? PHP_INT_MAX) <=> ($b['due']?->timestamp ?? PHP_INT_MAX);
    usort($taxOpen, $byDue);
    usort($otherOpen, $byDue);

    // Где открытая задача, словами БухЗадачника.
    $taskState = fn ($log) => match ($log?->status) {
        'running' => 'в работе',
        'paused'  => 'на паузе',
        'rework'  => 'на доработке',
        'review'  => 'на проверке у главбуха',
        default   => 'не начата',
    };

    $audit = $cell['audit'];
@endphp

<div class="border-b border-slate-100 px-6 py-5 space-y-3">
    <div class="flex items-start justify-between gap-3">
        <div>
            <p class="text-xs font-semibold uppercase tracking-wider text-slate-500">Месяц клиента</p>
            <h2 class="mt-1 text-lg font-semibold text-slate-800 text-balance">{{ $client->name }} · {{ Page::MONTHS[$focus->month] }}</h2>
        </div>
        <button type="button" data-card-close class="rounded-lg px-1.5 text-2xl leading-none text-slate-400 hover:bg-slate-100 hover:text-slate-900" aria-label="Закрыть">×</button>
    </div>

    <p class="text-[13px] text-slate-500">
        @php
            $monthChief = $cell['chiefUnknown'] ? 'не определён' : ($cell['chief'] ? $short($cell['chief']) : 'не назначен');
            $nowChief   = $row['chief'] ? $short($row['chief']) : 'не назначен';
        @endphp
        Главбух {{ $monthChief }}@if ($monthChief !== $nowChief), сейчас {{ $nowChief }}@endif @if ($cell['deadline']) · срок закрытия {{ $cell['deadline']->format('d.m') }}@endif
    </p>

    <span class="inline-block rounded-full px-3 py-1 text-sm font-semibold {{ Page::STYLES[$status]['badge'] }}">{{ Board::STATUSES[$status]['label'] }}</span>

    <div class="flex flex-wrap items-center gap-1">
        @foreach ($months as $m)
            @php $mk = $m->format('Y-m'); $ms = $row['cells'][$mk]['status']; @endphp
            @if ($ms === Board::NONE)
                <span class="inline-grid h-[22px] w-[26px] place-items-center text-[11px] text-slate-300" title="{{ $title($m) }}: нет задач">·</span>
            @else
                <button type="button" data-card-month="{{ $mk }}"
                        @class(['inline-grid h-[22px] w-[26px] place-items-center rounded-md text-[11px] font-bold', Page::STYLES[$ms]['cell'], 'ring-2 ring-slate-900 ring-offset-1' => $mk === $key])
                        title="{{ $title($m) }}: {{ Board::STATUSES[$ms]['label'] }}"
                        aria-label="{{ $title($m) }}: {{ Board::STATUSES[$ms]['label'] }}">{{ Board::STATUSES[$ms]['letter'] }}</button>
            @endif
        @endforeach
    </div>
</div>

<div class="min-h-0 flex-1 overflow-y-auto px-4 py-4 space-y-3">
    @if ($status === Board::NONE)
        <p class="px-2 py-6 text-center text-sm text-slate-500">За {{ mb_strtolower(Page::MONTHS[$focus->month]) }} у клиента нет задач по смете.</p>
    @else
        {{-- Задачи по смете --}}
        <section @class(['rounded-xl px-4 py-3', 'bg-slate-50' => !$open, 'bg-red-50/60' => $open && $cell['late'], 'bg-amber-50/60' => $open && !$cell['late']]) data-section="tasks">
            <div class="flex gap-3">
                <span @class(['mt-0.5 inline-grid h-5 w-5 flex-shrink-0 place-items-center rounded-full text-[11px] font-bold', 'bg-emerald-100 text-emerald-700' => !$open, 'bg-red-100 text-red-700' => $open && $cell['late'], 'bg-amber-100 text-amber-700' => $open && !$cell['late']])>{{ $open ? '!' : '✓' }}</span>
                <div class="min-w-0 flex-1">
                    <div class="font-semibold text-slate-900">{{ $open ? 'Не все сметные задачи закрыты' : 'Сметные задачи закрыты' }}</div>
                    <div class="text-[13px] text-slate-600">
                        Принято {{ count($done) }} из {{ count($cell['tasks']) }}@if ($cell['late']), просрочено {{ $cell['late'] }}@endif
                    </div>
                </div>
            </div>

            @if ($otherOpen)
                @include('month-closing._open-tasks', ['tasks' => $otherOpen])
            @elseif ($taxOpen)
                <p class="mt-2 pl-8 text-[13px] text-slate-500">Открыты только налоги и отчёты, они ниже</p>
            @endif
        </section>

        {{-- Налоги и отчётность: задачи БП этой группы. Сдано = принята. --}}
        @if ($taxes === 'on')
            @php
                $tax    = $cell['taxes'];
                $taxOk  = !$taxOpen;
                $taxLate = (bool) array_filter($taxOpen, fn (array $t) => $t['due'] && $t['due']->lt($today));
            @endphp
            <section @class(['rounded-xl px-4 py-3', 'bg-slate-50' => $taxOk, 'bg-red-50/60' => !$taxOk && $taxLate, 'bg-amber-50/60' => !$taxOk && !$taxLate]) data-section="taxes">
                <div class="flex gap-3">
                    <span @class(['mt-0.5 inline-grid h-5 w-5 flex-shrink-0 place-items-center rounded-full text-[11px] font-bold', 'bg-emerald-100 text-emerald-700' => $taxOk, 'bg-red-100 text-red-700' => !$taxOk && $taxLate, 'bg-amber-100 text-amber-700' => !$taxOk && !$taxLate])>{{ $taxOk ? '✓' : '!' }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="font-semibold text-slate-900">{{ $taxOk ? 'Налоги и отчёты сданы' : 'Не все налоги и отчёты сданы' }}</div>
                        <div class="text-[13px] text-slate-600">
                            @if ($tax['total'])
                                Сдано {{ $tax['done'] }} из {{ $tax['total'] }}
                            @else
                                В этом месяце отчётов и налогов нет
                            @endif
                        </div>
                    </div>
                </div>

                @if ($taxOpen)
                    @include('month-closing._open-tasks', ['tasks' => $taxOpen])
                @endif
            </section>
        @elseif ($taxes === 'missing')
            @include('month-closing._not-connected', ['section' => 'taxes', 'title' => 'Налоги и отчетность', 'text' => 'Ни в одном БП нет группы «Налоги и отчетность»'])
        @endif

        {{-- Автоаудит: в условии только у фирм, где его страница открыта руководителю. --}}
        @if ($autoAudit)
            @php
                $auditStatus = $audit['status'] ?? null;
                $auditOk     = !$cell['auditBlocks'];
            @endphp
            <section @class(['rounded-xl px-4 py-3', 'bg-slate-50' => $auditOk, 'bg-violet-50/60' => !$auditOk]) data-section="auto-audit">
                <div class="flex gap-3">
                    <span @class(['mt-0.5 inline-grid h-5 w-5 flex-shrink-0 place-items-center rounded-full text-[11px] font-bold', 'bg-emerald-100 text-emerald-700' => $auditOk, 'bg-violet-100 text-violet-700' => !$auditOk])>{{ $auditOk ? '✓' : '!' }}</span>
                    <div class="min-w-0 flex-1">
                        <div class="font-semibold text-slate-900">{{ $auditOk ? 'Вопросов автоаудита нет' : 'Есть вопросы автоаудита' }}</div>
                        <div class="text-[13px] text-slate-600">
                            @if ($auditStatus === null || $auditStatus === AuditBoard::NONE)
                                Автоаудит этот месяц клиента не проверяет
                            @else
                                {{ AuditBoard::STATUSES[$auditStatus]['label'] }}@if (!empty($audit['note'])): {{ $audit['note'] }}@endif
                            @endif
                        </div>
                        @if ($auditLink && $auditStatus !== null && $auditStatus !== AuditBoard::NONE)
                            <a href="{{ route('auto-audit.clients', ['month' => $key, 'q' => $client->name, 'all' => 1]) }}"
                               class="mt-1 inline-block text-[13px] text-indigo-600 hover:underline">Открыть в автоаудите</a>
                        @endif
                    </div>
                </div>
            </section>
        @else
            @include('month-closing._not-connected', ['section' => 'auto-audit', 'title' => 'Автоаудит', 'text' => 'Для фирмы не включён, на закрытие месяца не влияет'])
        @endif

        @include('month-closing._not-connected', ['section' => 'manual-audit', 'title' => 'Ручной аудит', 'text' => 'Пока не подключён к закрытию месяца: модуль в разработке'])

    @endif
</div>
