{{-- Карточка клиента за месяц: кусок страницы, приходит запросом по клику на строку.
     Кнопки работают через data-атрибуты, клики ловит страница (autoAuditClients). --}}
@php
    use App\Http\Controllers\AutoAuditClientsController as Page;
    use App\Models\AutoAuditFindingMessage;
    use App\Models\AutoAuditResult;
    use App\Services\AutoAudit\AutoAuditClientBoard as Board;
    use App\Services\AutoAudit\AutoAuditRunner;
    use App\Services\AutoAudit\AutoAuditSources;
    use App\Services\AutoAudit\DocumentPeriod;
    use Carbon\CarbonImmutable;

    $client     = $row['client'];
    $key        = $focus->format('Y-m');
    $status     = $cell['status'];
    $monthTitle = fn ($m) => DocumentPeriod::of($m->year, $m->month)->title();
    $days       = fn (int $n) => Board::count($n, ['день', 'дня', 'дней']);
    $money      = fn ($v) => $v === null ? '—' : number_format((float) $v, 2, ',', ' ');
    $short      = fn (?int $id) => $id ? AutoAuditSources::shortName($name($id)) : 'не назначен';

    $openTasks = array_filter($cell['tasks'], fn (array $t) => !$t['done']);
    $doneTasks = array_filter($cell['tasks'], fn (array $t) => $t['done']);
    $problems  = array_filter($cell['issues'], fn (array $i) => !in_array($i['turn'], [Board::TURN_MATCHED, Board::TURN_ACCEPTED], true));
    $fine      = array_filter($cell['issues'], fn (array $i) => in_array($i['turn'], [Board::TURN_MATCHED, Board::TURN_ACCEPTED], true));

    // Отчёт по ЕН квартальный: в первых двух месяцах квартала его сверки нет, она в третьем.
    $hasTaxTask  = collect($cell['tasks'])->contains('side', 'tax');
    $quarterEnd  = $focus->month % 3 ? $focus->addMonths(3 - $focus->month % 3) : null;
    $quarterNote = $row['hasTax'] && !$hasTaxTask && $quarterEnd ? 'Отчёт по ЕН квартальный, его сверка в ' . DocumentPeriod::of($quarterEnd->year, $quarterEnd->month)->title() . '.' : null;

    // «Не проверено» без единой строки сверки: ОСВ и отчёт сданы с файлами, а пары за один
    // период нет. Раньше карточка писала тут «Ничего не требует внимания» и ставила задачам
    // зелёные галочки, хотя сверки не было (Дипмаркет, 09.10.2026: ОСВ за август 2025).
    // Если сдана одна сторона, это не «Не проверено», а «Сверять нечего» (см. Board::NOTHING).
    $unpaired  = $status === Board::UNVERIFIED && !$cell['issues'] && !$openTasks;

    // Задачи, чьи файлы попали в открытый вопрос: номера проверок по задаче. Галочка у задачи
    // значит «сдана и по её файлам всё сошлось», а не только «закрыта с файлом» (ФинЭко,
    // 10.10.2026: №3 не сошлось, а ОСВ и отчёт по ЕН стояли в «В порядке»).
    $questioned = [];
    foreach ($problems as $issue) {
        foreach (collect($issue['result']->sources ?? [])->pluck('log_id')->filter()->unique() as $logId) {
            $questioned[(int) $logId] = array_unique(array_merge($questioned[(int) $logId] ?? [], $issue['result']->ruleNumbers()));
        }
    }
    $isQuestioned = fn (array $t) => $t['log'] && isset($questioned[$t['log']->id]);
    $askedTasks   = $unpaired ? [] : array_filter($doneTasks, $isQuestioned);
    $okTasks      = $unpaired ? [] : array_filter($doneTasks, fn (array $t) => !$isQuestioned($t));
    $readTitle = fn ($read) => $read?->period_from && $read?->period_to
        ? (new DocumentPeriod(CarbonImmutable::parse($read->period_from->toDateString()), CarbonImmutable::parse($read->period_to->toDateString())))->title()
        : null;

    // Где открытая задача: журнала нет или он не тронут, значит не начата.
    $taskState = fn ($log) => match ($log?->status) {
        'running' => 'в работе',
        'paused'  => 'на паузе',
        'rework'  => 'на доработке',
        default   => 'не начата',
    };

    // Кто делал задачи строки: исполнители из «откуда взято», иначе бухгалтер клиента.
    $doers = fn (AutoAuditResult $r) => collect($r->sources ?? [])->pluck('employee')->filter()->unique()->implode(', ') ?: $short($row['accountant']);
@endphp

<div class="border-b border-slate-100 px-6 py-5 space-y-3">
    <div class="flex items-start justify-between gap-3">
        <h2 class="text-lg font-semibold text-slate-800 text-balance">{{ $client->name }}</h2>
        <button type="button" data-card-close class="rounded-lg px-1.5 text-2xl leading-none text-slate-400 hover:bg-slate-100 hover:text-slate-900" aria-label="Закрыть">×</button>
    </div>

    <div class="flex items-center gap-3 rounded-xl px-3 py-2.5 {{ Page::STYLES[$status]['cell'] }}">
        <span class="inline-grid h-[30px] w-[30px] place-items-center rounded-md bg-white text-[15px] font-bold">{{ Board::STATUSES[$status]['letter'] }}</span>
        <div class="font-semibold">
            {{ Board::STATUSES[$status]['label'] }}, {{ $monthTitle($focus) }}
            @if ($cell['note'])
                <small class="block font-normal text-slate-500">{{ $cell['note'] }}@if ($cell['days'] !== null && in_array($status, [Board::ACCOUNTANT, Board::CHIEF], true)), висит {{ $days($cell['days']) }}@endif</small>
            @endif
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-1">
        @foreach ($months as $m)
            @php $mk = $m->format('Y-m'); $ms = $row['cells'][$mk]['status']; @endphp
            <button type="button" data-card-month="{{ $mk }}"
                    @class(['inline-grid h-[22px] w-[26px] place-items-center rounded-md text-[11px] font-bold', Page::STYLES[$ms]['cell'], 'ring-2 ring-slate-900 ring-offset-1' => $mk === $key])
                    title="{{ $monthTitle($m) }}: {{ Board::STATUSES[$ms]['label'] }}"
                    aria-label="{{ $monthTitle($m) }}: {{ Board::STATUSES[$ms]['label'] }}">{{ Board::STATUSES[$ms]['letter'] }}</button>
        @endforeach
    </div>

    <p class="text-[13px] text-slate-500">Бухгалтер {{ $short($row['accountant']) }} · главбух {{ $short($row['chief']) }}</p>
</div>

<div class="min-h-0 flex-1 overflow-y-auto">
    @if ($openTasks || $problems)
        <section class="border-b border-slate-100 px-6 py-4 space-y-3">
            <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-500">Что не так</h3>

            @foreach ($openTasks as $task)
                @php $late = $task['due'] && $task['due']->lt($today); @endphp
                <div class="grid grid-cols-[18px_minmax(0,1fr)] gap-x-2.5">
                    <span @class(['text-center font-bold', 'text-red-600' => $late, 'text-slate-400' => !$late])>○</span>
                    <div>
                        <div class="font-medium text-slate-900">{{ $task['name'] }}</div>
                        <div @class(['text-[13px]', 'text-red-700' => $late, 'text-slate-500' => !$late])>
                            {{-- Как в БухЗадачнике: «не начата» и «начата и стоит на паузе» для руководителя разные вещи. --}}
                            {{ $short($task['assignee']) }} · {{ $taskState($task['log']) }}@if ($task['due']), срок {{ $late ? 'был ' : '' }}{{ $task['due']->format('d.m') }}@endif
                        </div>
                    </div>
                </div>
            @endforeach

            @foreach ($problems as $issue)
                @php
                    $result  = $issue['result'];
                    $finding = $issue['finding'];
                    $last    = $finding?->messages->last();
                    $answer  = $finding?->messages->whereIn('kind', [AutoAuditFindingMessage::EXPLAINED, AutoAuditFindingMessage::FIXED])->last();
                    $quarter = $result->period_from && !$result->period_from->isSameMonth($result->period_to);
                @endphp
                <div class="grid grid-cols-[18px_minmax(0,1fr)] gap-x-2.5">
                    <span @class(['text-center font-bold', 'text-amber-600' => $issue['turn'] !== Board::TURN_NOBODY, 'text-orange-500' => $issue['turn'] === Board::TURN_NOBODY])>{{ $result->outcome === AutoAuditResult::MISMATCH ? '≠' : '!' }}</span>
                    <div class="space-y-1">
                        @foreach ($result->ruleNumbers() as $number)
                            <div class="font-medium text-slate-900">№{{ $number }} {{ AutoAuditRunner::RULES[$number]['name'] ?? '' }}</div>
                        @endforeach
                        <div class="text-[13px] text-slate-500">
                            {{ AutoAuditResult::LABELS[$result->outcome] ?? $result->outcome }}@if ($quarter), {{ $result->periodLabel() }}@endif
                        </div>
                        @if ($result->outcome === AutoAuditResult::MISMATCH)
                            <div class="text-[13px] text-slate-500">
                                В учёте (ОСВ) <b class="font-semibold tabular-nums whitespace-nowrap text-slate-900">{{ $money($result->left_value) }}</b>,
                                в документе <b class="font-semibold tabular-nums whitespace-nowrap text-slate-900">{{ $money($result->right_value) }}</b>,
                                разница <b class="font-semibold tabular-nums whitespace-nowrap text-slate-900">{{ $money($result->difference) }}</b>
                            </div>
                        @elseif ($result->reason)
                            <div class="text-[13px] text-slate-500">{{ $result->reason }}</div>
                        @endif
                        @if ($quarter)
                            @include('auto-audit._quarter-sheets', ['result' => $result, 'money' => $money])
                        @endif

                        <div class="mt-1 rounded-lg border border-slate-100 bg-slate-50 px-3 py-2 text-[13px] text-slate-700 space-y-1">
                            @switch ($issue['turn'])
                                @case(Board::TURN_ACCOUNTANT)
                                    <div><span class="font-semibold text-amber-700">Ход бухгалтера {{ $doers($result) }}</span>, висит {{ $days($issue['days']) }}</div>
                                    @if ($last?->kind === AutoAuditFindingMessage::REJECTED)
                                        {{-- Не принять ответ может руководитель или вендор, не обязательно главбух клиента. --}}
                                        <div>Ответ не принят, {{ AutoAuditSources::shortName($last->authorName()) }}, {{ $last->created_at->copy()->setTimezone(config('app.display_timezone'))->format('d.m') }}: «{{ $last->body }}»</div>
                                    @endif
                                    @break
                                @case(Board::TURN_CHIEF)
                                    {{-- Решает руководитель на «Все сверки», а не главбух клиента: тот часто сам и отвечал.
                                         Ссылка открывает ту же строку: период, проверка и статус. --}}
                                    <div>
                                        <span class="font-semibold text-violet-700">Ход руководителя</span>, ответ висит {{ $days($issue['days']) }}.
                                        <a href="{{ route('auto-audit.index', array_filter(['period' => $result->periodKey(), 'rule' => $result->ruleNumbers()[0] ?? null, 'status' => $result->outcome])) }}"
                                           class="text-indigo-600 hover:underline">Решить на «Все сверки»</a>
                                    </div>
                                    @break
                                @case(Board::TURN_RUN)
                                    <div><span class="font-semibold text-sky-700">Бухгалтер исправил</span>, проверим ночью</div>
                                    @break
                                @default
                                    <div>Вопроса бухгалтеру нет: система сама не уверена. Сверить глазами.</div>
                            @endswitch
                            @if ($answer && $issue['turn'] !== Board::TURN_ACCOUNTANT)
                                <div><b class="font-semibold">{{ AutoAuditSources::shortName($answer->authorName()) }}</b>, {{ $answer->created_at->copy()->setTimezone(config('app.display_timezone'))->format('d.m') }}: «{{ $answer->body ?: AutoAuditFindingMessage::LABELS[$answer->kind] }}»</div>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach

            @foreach ($askedTasks as $task)
                @php $numbers = $questioned[$task['log']->id]; sort($numbers); @endphp
                <div class="grid grid-cols-[18px_minmax(0,1fr)] gap-x-2.5">
                    <span class="text-center font-bold text-slate-400">•</span>
                    <div>
                        <div class="font-medium text-slate-900">{{ $task['name'] }}</div>
                        <div class="text-[13px] text-slate-500">
                            {{ $short($task['assignee']) }} · сдана, но есть вопрос {{ implode(', ', array_map(fn ($n) => '№' . $n, $numbers)) }}
                        </div>
                    </div>
                </div>
            @endforeach
        </section>
    @elseif ($unpaired)
        <section class="border-b border-slate-100 px-6 py-4 space-y-3">
            <h3 class="text-xs font-semibold uppercase tracking-wider text-slate-500">Почему не проверено</h3>
            <p class="text-sm text-orange-700">
                Сверка не сложилась: ОСВ и отчёт есть, но периоды в документах не совпали. Проверьте, за какой период каждый файл.
            </p>

            @foreach ($doneTasks as $task)
                <div class="grid grid-cols-[18px_minmax(0,1fr)] gap-x-2.5">
                    <span class="text-center font-bold text-orange-500">?</span>
                    <div>
                        <div class="font-medium text-slate-900">{{ $task['name'] }}</div>
                        <div class="text-[13px] text-slate-500">{{ $short($task['assignee']) }}</div>
                        @if ($task['forced'])
                            <div class="text-[13px] text-slate-500">закрыта без файла: {{ $task['log']->forceCloseNote() ?? 'причина не указана' }}</div>
                        @endif
                        @forelse ($task['log']?->documents ?? [] as $document)
                            @php $period = $readTitle($reads->get($document->id)); @endphp
                            <div class="text-[13px] text-slate-500">
                                <b class="font-semibold text-slate-900">{{ $document->name }}</b>@if ($period), прочитан как {{ $period }}@elseif ($reads->has($document->id)), период не прочитан@endif
                            </div>
                        @empty
                            @unless ($task['forced'])
                                <div class="text-[13px] text-slate-500">файла нет</div>
                            @endunless
                        @endforelse
                    </div>
                </div>
            @endforeach
        </section>
    @elseif ($status === Board::NOTHING)
        <section class="border-b border-slate-100 px-6 py-4">
            <p class="text-sm text-slate-600">○ Сверять нечего: {{ $cell['note'] }}. Для сверки нужны ОСВ и отчёт по ЕН или форма 161 за один месяц. Ничего делать не нужно.</p>
        </section>
    @elseif ($status !== Board::NONE)
        <section class="border-b border-slate-100 px-6 py-4">
            <p class="text-sm text-emerald-700">✓ Ничего не требует внимания</p>
        </section>
    @endif

    @if ($quarterNote)
        <section class="border-b border-slate-100 px-6 py-4">
            <p class="text-[13px] text-slate-500">{{ $quarterNote }}</p>
        </section>
    @endif

    @if ($okTasks || $fine)
        <details class="border-b border-slate-100 px-6 py-4" @if (!$openTasks && !$problems) open @endif>
            <summary class="cursor-pointer font-medium text-indigo-600">
                ✓ В порядке: {{ Board::count(count($okTasks), ['задача', 'задачи', 'задач']) }}, {{ Board::count(count($fine), ['сверка', 'сверки', 'сверок']) }}
            </summary>
            <div class="mt-3 space-y-2">
                @foreach ($okTasks as $task)
                    @php $files = $task['log']?->documents?->pluck('name')->filter()->implode(', '); @endphp
                    <div class="grid grid-cols-[18px_minmax(0,1fr)] gap-x-2.5">
                        <span class="text-center font-bold text-emerald-600">✓</span>
                        <div>
                            <div class="font-medium text-slate-900">{{ $task['name'] }}</div>
                            <div class="text-[13px] text-slate-500">
                                {{ $short($task['assignee']) }} ·
                                @if ($task['forced'])
                                    закрыта без файла: {{ $task['log']->forceCloseNote() ?? 'причина не указана' }}
                                @else
                                    <b class="font-semibold text-slate-900">{{ $files ?: 'файла нет' }}</b>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
                @foreach ($fine as $issue)
                    @php $result = $issue['result']; $quarter = $result->period_from && !$result->period_from->isSameMonth($result->period_to); @endphp
                    <div class="grid grid-cols-[18px_minmax(0,1fr)_auto] gap-x-2.5">
                        <span class="text-center font-bold text-emerald-600">✓</span>
                        <div>
                            <div class="font-medium text-slate-900">№{{ $result->rule }} {{ $result->ruleName() }}</div>
                            @if ($issue['turn'] === Board::TURN_ACCEPTED)
                                <div class="text-[13px] text-slate-500">Разница {{ $money($result->difference) }}, объяснение принято</div>
                            @endif
                            @if ($quarter)
                                <div class="text-[13px] text-slate-500">{{ $result->periodLabel() }}</div>
                                @include('auto-audit._quarter-sheets', ['result' => $result, 'money' => $money])
                            @endif
                        </div>
                        <span class="text-[13px] tabular-nums text-slate-500">{{ $money($result->left_value) }}</span>
                    </div>
                @endforeach
            </div>
        </details>
    @endif

    <section class="px-6 py-4 text-[13px] text-slate-500 space-y-1">
        <p>Автоаудит смотрит только ОСВ, отчёт по ЕН и форму 161. Остальные задачи клиента здесь не видны.</p>
        @if ($checkedAt)
            <p>Последняя проверка {{ $checkedAt->format('d.m.Y H:i') }}.</p>
        @endif
    </section>
</div>
