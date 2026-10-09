@extends('layouts.app')
@section('title', 'Автоаудит: по клиентам')
@section('page-title', 'Автоаудит')

@section('content')
@php
    use App\Http\Controllers\AutoAuditClientsController as Page;
    use App\Services\AutoAudit\AutoAuditClientBoard as Board;
    use App\Services\AutoAudit\DocumentPeriod;

    $monthTitle = fn ($m) => DocumentPeriod::of($m->year, $m->month)->title();
    $days       = fn (int $n) => Board::count($n, ['день', 'дня', 'дней']);

    // Все фильтры страницы: ссылки меняют один, остальные несут дальше.
    $filters = array_filter([
        'month'  => $key,
        'status' => $status,
        'all'    => $all ? 1 : null,
        'acc'    => $acc,
        'q'      => $q !== '' ? $q : null,
    ]);
    $with = fn (array $change) => array_filter(array_merge($filters, $change), fn ($v) => $v !== null && $v !== '');
@endphp

<div class="space-y-4" x-data="autoAuditClients()" @keydown.escape.window="close()">

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50">
        <div class="px-6 py-4 flex flex-wrap items-center justify-between gap-3">
            @include('auto-audit._tabs', ['active' => 'auto-audit.clients'])

            {{-- Месяц: стрелки листают по одному, список даёт окно таблицы. --}}
            <form method="GET" action="{{ route('auto-audit.clients') }}" class="flex items-center gap-2">
                @foreach ($with(['month' => null]) as $name_ => $value)
                    <input type="hidden" name="{{ $name_ }}" value="{{ $value }}">
                @endforeach
                <label for="month" class="text-sm font-medium text-slate-700"
                       title="Месяц, за который составлены документы. Работу за сентябрь делают в октябре, и она здесь в сентябре.">Период</label>
                <div class="inline-flex items-center rounded-lg border border-slate-200">
                    <a href="{{ route('auto-audit.clients', $with(['month' => $prev->format('Y-m')])) }}"
                       class="px-2.5 py-1.5 text-slate-500 hover:text-slate-900" aria-label="Предыдущий месяц">‹</a>
                    <select id="month" name="month" onchange="this.form.submit()" class="border-0 bg-transparent py-1.5 pl-1 pr-7 text-sm font-medium">
                        @foreach (array_reverse($months) as $m)
                            <option value="{{ $m->format('Y-m') }}" @selected($m->format('Y-m') === $key)>{{ mb_convert_case($monthTitle($m), MB_CASE_TITLE) }}</option>
                        @endforeach
                    </select>
                    @if ($next)
                        <a href="{{ route('auto-audit.clients', $with(['month' => $next->format('Y-m')])) }}"
                           class="px-2.5 py-1.5 text-slate-500 hover:text-slate-900" aria-label="Следующий месяц">›</a>
                    @else
                        <span class="px-2.5 py-1.5 text-slate-300" aria-hidden="true">›</span>
                    @endif
                </div>
            </form>
        </div>

        <div class="px-6 pb-4 text-sm text-slate-500 space-y-1">
            <p>
                Проверяем {{ Board::count($clientsCount, ['клиента', 'клиентов', 'клиентов']) }}: сверяем ОСВ с отчётом по ЕН и формой 161.
                Остальные задачи клиента автоаудит не смотрит.
                @if ($notConnected)
                    <span title="Ведём не всё (нет полного обслуживания) или в смете нет размеченных БП">Не подключены: {{ $notConnected }}.</span>
                @endif
            </p>
            <p>
                @if ($checkedAt)
                    Последняя проверка {{ $checkedAt->format('d.m.Y H:i') }}.
                @else
                    Проверок пока не было.
                @endif
                <a href="{{ route('docs.section', 'auto-audit') }}" target="_blank" class="text-indigo-600 hover:underline">Что значат буквы</a>
            </p>
            @if ($vendor)
                <p class="text-xs text-slate-400">{{ $openToManager ? 'Руководитель фирмы видит эту вкладку.' : 'Руководитель фирмы эту вкладку пока не видит.' }}</p>
            @endif
        </div>

        {{-- Плитки статусов за выбранный месяц. Клик выбирает статус, повторный снимает. --}}
        <div class="grid grid-cols-2 sm:grid-cols-3 xl:grid-cols-9 gap-px bg-slate-100 border-t border-slate-100 rounded-b-2xl overflow-hidden">
            @foreach (Board::STATUSES as $s => $def)
                @php $count = $counts[$s] ?? 0; @endphp
                <a href="{{ route('auto-audit.clients', $with(['status' => $status === $s ? null : $s])) }}"
                   title="{{ $def['hint'] }}" data-status="{{ $s }}" data-count="{{ $count }}"
                   @class(['flex flex-col justify-between px-4 py-3 transition-colors', 'bg-indigo-50' => $status === $s, 'bg-white hover:bg-slate-50' => $status !== $s])>
                    <div class="flex items-center gap-1.5 text-[13px] text-slate-500">
                        <span class="w-2 h-2 rounded-full flex-shrink-0 {{ Page::STYLES[$s]['dot'] }}"></span>
                        {{ $def['label'] }}
                    </div>
                    <div @class(['mt-1 text-2xl leading-none font-semibold', 'text-slate-900' => $count > 0, 'text-slate-300' => $count === 0])>{{ $count }}</div>
                </a>
            @endforeach
        </div>
    </div>

    {{-- Сводка по бухгалтерам. Клик по строке оставляет в таблице только его клиентов. --}}
    @if ($team)
        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-x-auto">
            <div class="px-6 py-4"><h2 class="text-[15px] font-semibold text-slate-800">Бухгалтеры, {{ $monthTitle($focus) }}</h2></div>
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">
                    <tr>
                        <th class="px-4 py-3">Бухгалтер</th>
                        <th class="px-4 py-3 text-right">Клиентов</th>
                        @foreach ([Board::ACCOUNTANT, Board::CHIEF, Board::OVERDUE] as $s)
                            <th class="px-4 py-3 text-right whitespace-nowrap" title="{{ Board::STATUSES[$s]['hint'] }}">
                                <span class="inline-grid place-items-center w-[18px] h-4 rounded text-[10px] font-bold mr-1 {{ Page::STYLES[$s]['cell'] }}">{{ Board::STATUSES[$s]['letter'] }}</span>{{ Board::STATUSES[$s]['label'] }}
                            </th>
                        @endforeach
                        <th class="px-4 py-3 text-right whitespace-nowrap" title="Сколько дней висит самый старый вопрос или ответ">Дольше всего</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($team as $t)
                        @php $selected = $acc !== null && $acc === $t['id']; @endphp
                        <tr @class(['cursor-pointer', 'bg-indigo-50' => $selected, 'hover:bg-slate-50' => !$selected])
                            onclick="window.location='{{ route('auto-audit.clients', $with(['acc' => $selected ? null : $t['id']])) }}'">
                            <td class="px-4 py-2.5 font-medium text-slate-900">{{ $t['name'] }}</td>
                            <td class="px-4 py-2.5 text-right tabular-nums text-slate-700">{{ $t['clients'] }}</td>
                            @foreach (['accountant', 'chief', 'overdue'] as $col)
                                <td @class(['px-4 py-2.5 text-right tabular-nums', 'text-slate-300' => !$t[$col], 'font-semibold text-slate-900' => $t[$col] && $col !== 'overdue', 'font-semibold text-red-700' => $t[$col] && $col === 'overdue'])>{{ $t[$col] }}</td>
                            @endforeach
                            <td @class(['px-4 py-2.5 text-right tabular-nums', 'text-slate-300' => !$t['oldest'], 'text-red-700' => $t['oldest'] >= 5, 'text-slate-700' => $t['oldest'] && $t['oldest'] < 5])>
                                {{ $t['oldest'] ? $days($t['oldest']) : '·' }}
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    {{-- Поиск и фильтры. Всё в адресе страницы. --}}
    <form method="GET" action="{{ route('auto-audit.clients') }}" class="flex flex-wrap items-center gap-2">
        @foreach ($with(['q' => null]) as $name_ => $value)
            <input type="hidden" name="{{ $name_ }}" value="{{ $value }}">
        @endforeach
        <input type="search" name="q" value="{{ $q }}" placeholder="Найти клиента или бухгалтера" aria-label="Поиск"
               class="flex-1 min-w-0 basis-56 rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm">
        @php $onlyAction = !$all && !$status; @endphp
        <a href="{{ route('auto-audit.clients', $with(['all' => $onlyAction ? 1 : null, 'status' => null])) }}"
           title="Просрочено, ждёт бухгалтера, ждёт главбуха, не проверено"
           @class(['rounded-lg border px-3 py-2 text-sm transition-colors', 'border-indigo-200 bg-indigo-50 text-indigo-700' => $onlyAction, 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50' => !$onlyAction])>
            Только требующие действия
        </a>
        @if ($acc)
            <a href="{{ route('auto-audit.clients', $with(['acc' => null])) }}"
               class="rounded-lg border border-indigo-200 bg-indigo-50 px-3 py-2 text-sm text-indigo-700">{{ $name($acc) }} ×</a>
        @endif
    </form>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-x-auto">
        @if ($rows->isEmpty())
            <p class="px-6 py-10 text-center text-sm text-slate-500">
                {{ $clientsCount ? 'По этому фильтру клиентов нет.' : 'Клиентов с задачами автоаудита пока нет.' }}
            </p>
        @else
            <table class="min-w-[760px] w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">
                    <tr>
                        <th class="w-11 px-4 py-3"></th>
                        <th class="px-4 py-3">Клиент</th>
                        <th class="px-4 py-3">Бухгалтер</th>
                        <th class="px-4 py-3">{{ $monthTitle($focus) }}</th>
                        @foreach ($months as $m)
                            <th @class(['w-12 px-0.5 py-3 text-center', 'text-slate-900' => $m->format('Y-m') === $key])>{{ Page::MONTHS_SHORT[$m->month] }}</th>
                        @endforeach
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($rows as $row)
                        @php $cell = $row['cells'][$key]; $client = $row['client']; @endphp
                        <tr class="cursor-pointer hover:bg-slate-50" :class="openId === {{ $client->id }} && 'bg-indigo-50'"
                            @click="open({{ $client->id }}, '{{ $key }}')">
                            <td class="px-4 py-2">
                                <span class="inline-grid place-items-center w-[26px] h-[22px] rounded-md text-[11px] font-bold {{ Page::STYLES[$cell['status']]['cell'] }}"
                                      title="{{ Board::STATUSES[$cell['status']]['label'] }}">{{ Board::STATUSES[$cell['status']]['letter'] }}</span>
                            </td>
                            <td class="px-4 py-2 font-medium text-slate-900 max-w-[300px] truncate" title="{{ $client->name }}">{{ $client->name }}</td>
                            <td class="px-4 py-2 text-slate-500 whitespace-nowrap">{{ $row['accountant'] ? \App\Services\AutoAudit\AutoAuditSources::shortName($name($row['accountant'])) : 'не назначен' }}</td>
                            <td class="px-4 py-2 text-[13px] whitespace-nowrap">
                                <span @class(['text-red-700 font-semibold' => $cell['status'] === Board::OVERDUE, 'text-slate-700' => in_array($cell['status'], [Board::ACCOUNTANT, Board::CHIEF], true), 'text-slate-500' => !in_array($cell['status'], [Board::OVERDUE, Board::ACCOUNTANT, Board::CHIEF], true)])>{{ $cell['note'] ?: Board::STATUSES[$cell['status']]['label'] }}</span>
                                @if ($cell['days'] !== null && in_array($cell['status'], [Board::ACCOUNTANT, Board::CHIEF], true))
                                    <span class="text-slate-500">· висит {{ $days($cell['days']) }}</span>
                                @endif
                            </td>
                            @foreach ($months as $m)
                                @php $mk = $m->format('Y-m'); $ms = $row['cells'][$mk]['status']; @endphp
                                <td @class(['px-0.5 py-2 text-center', 'bg-slate-50' => $mk === $key])>
                                    <button type="button" @click.stop="open({{ $client->id }}, '{{ $mk }}')"
                                            class="inline-grid place-items-center w-[26px] h-[22px] rounded-md text-[11px] font-bold {{ Page::STYLES[$ms]['cell'] }}"
                                            title="{{ $monthTitle($m) }}: {{ Board::STATUSES[$ms]['label'] }}"
                                            aria-label="{{ $monthTitle($m) }}: {{ Board::STATUSES[$ms]['label'] }}">{{ Board::STATUSES[$ms]['letter'] }}</button>
                                </td>
                            @endforeach
                        </tr>
                    @endforeach
                    @if ($total > $rows->count())
                        <tr>
                            <td colspan="{{ 4 + count($months) }}" class="px-4 py-3 text-center text-slate-500">
                                Показано {{ $rows->count() }} из {{ $total }} ·
                                <a href="{{ route('auto-audit.clients', $with(['limit' => $limit + Page::PER_PAGE])) }}" class="text-indigo-600 hover:underline">показать ещё</a>
                            </td>
                        </tr>
                    @endif
                </tbody>
            </table>
        @endif
    </div>

    {{-- Карточка клиента за месяц выезжает справа. Содержимое приходит с сервера по клику. --}}
    <template x-if="openId !== null">
        <div>
            <div class="fixed inset-0 z-40 bg-slate-900/30" @click="close()"></div>
            <aside class="fixed inset-y-0 right-0 z-50 flex w-full max-w-[480px] flex-col border-l border-slate-200 bg-white shadow-2xl"
                   role="dialog" aria-modal="true" @click="onCardClick($event)">
                <div x-show="loading" class="px-6 py-10 text-center text-sm text-slate-500">Загружаю…</div>
                <div x-show="error" class="px-6 py-10 text-center text-sm text-red-700" x-text="error"></div>
                <div x-show="!loading && !error" class="flex min-h-0 flex-1 flex-col" x-html="html"></div>
            </aside>
        </div>
    </template>
</div>
@endsection

@push('scripts')
<script>
    function autoAuditClients() {
        return {
            openId: null,
            html: '',
            loading: false,
            error: '',
            // Ответ на старый клик не должен затереть карточку, открытую позже.
            request: 0,

            async open(clientId, month) {
                const request = ++this.request;
                this.openId = clientId;
                this.loading = true;
                this.error = '';

                try {
                    const url = @json(route('auto-audit.clients.card', ['client' => '__ID__'])).replace('__ID__', clientId)
                        + '?month=' + encodeURIComponent(month);
                    const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });

                    if (!response.ok) {
                        throw new Error(response.status === 404 ? 'Клиент не найден или больше не проверяется' : 'Не удалось загрузить карточку');
                    }

                    const html = await response.text();

                    if (request === this.request) {
                        this.html = html;
                    }
                } catch (e) {
                    if (request === this.request) {
                        this.error = e.message || 'Не удалось загрузить карточку';
                    }
                } finally {
                    if (request === this.request) {
                        this.loading = false;
                    }
                }
            },

            close() {
                this.request++;
                this.openId = null;
                this.html = '';
            },

            // Кнопки внутри карточки приходят готовым HTML: ловим клики здесь, по data-атрибутам.
            onCardClick(event) {
                const target = event.target.closest('[data-card-month], [data-card-close]');

                if (!target) {
                    return;
                }

                if (target.dataset.cardClose !== undefined) {
                    this.close();
                } else {
                    this.open(this.openId, target.dataset.cardMonth);
                }
            },
        };
    }
</script>
@endpush
