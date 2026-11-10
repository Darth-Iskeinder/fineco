@extends('layouts.app')
@section('title', 'Закрытие месяца')
@section('page-title', 'Закрытие месяца')

@section('content')
@php
    use App\Http\Controllers\MonthClosingController as Page;
    use App\Services\AutoAudit\AutoAuditSources;
    use App\Services\MonthClosing\MonthClosingBoard as Board;

    $monthName = fn ($m) => Page::MONTHS[$m->month] . ($m->year !== $focus->year ? ' ' . $m->year : '');
    $title     = fn ($m) => Page::MONTHS[$m->month] . ' ' . $m->year;
    $lower     = fn ($m) => mb_strtolower(Page::MONTHS[$m->month]);
    $count     = fn (int $n, array $forms) => \App\Services\AutoAudit\AutoAuditClientBoard::count($n, $forms);

    $url = fn (array $change) => route('month-closing.index', array_filter(array_merge(['month' => $key, 'chief' => $chief], $change), fn ($v) => $v !== null));

    $pctColor = fn (?int $pct) => match (true) {
        $pct === null => 'text-slate-300',
        $pct >= 80    => 'text-emerald-600',
        $pct >= 50    => 'text-amber-600',
        default       => 'text-red-600',
    };
    $barColor = fn (?int $pct) => match (true) {
        $pct === null => 'bg-slate-200',
        $pct >= 80    => 'bg-emerald-600',
        $pct >= 50    => 'bg-amber-500',
        default       => 'bg-red-600',
    };
@endphp

<div class="space-y-4" x-data="monthClosingPage()" @keydown.escape.window="close()">

    <div class="flex flex-wrap items-start justify-between gap-3">
        <p class="max-w-3xl text-sm text-slate-500">
            Месяц клиента закрыт, когда все его сметные задачи приняты{{ $autoAudit ? ' и по автоаудиту нет вопросов' : '' }}.
            Работа за месяц идёт в задачах следующего: сентябрь закрывают в октябре.
            Срок закрытия пока по последнему сроку задач месяца. Ручной аудит к закрытию пока не подключён.
            <span class="block mt-1">
                Месяц засчитывается главбуху, который вёл клиента в срок закрытия.
                Передачи клиентов {{ $historySince ? 'записываются с ' . $historySince->format('d.m.Y') : 'пока не записывались' }}, более ранние не учтены:
                до них главбухом считается нынешний ответственный, но не тот, кто появился в системе позже срока.
            </span>
        </p>

        <div class="inline-flex items-center rounded-lg border border-slate-200 bg-white text-sm">
            <a href="{{ $url(['month' => $prev->format('Y-m')]) }}" class="px-2.5 py-1.5 text-slate-500 hover:text-slate-900" aria-label="Предыдущий месяц">‹</a>
            <span class="px-2 py-1.5 font-medium text-slate-800">{{ $title($focus) }}</span>
            @if ($next)
                <a href="{{ $url(['month' => $next->format('Y-m')]) }}" class="px-2.5 py-1.5 text-slate-500 hover:text-slate-900" aria-label="Следующий месяц">›</a>
            @else
                <span class="px-2.5 py-1.5 text-slate-300" aria-hidden="true">›</span>
            @endif
        </div>
    </div>

    @if ($vendor)
        <p class="text-xs text-slate-400">{{ $openToManager ? 'Руководитель фирмы видит эту страницу.' : 'Руководитель фирмы эту страницу пока не видит.' }}</p>
    @endif

    {{-- Чипы по главбухам: оставляют в таблице только его клиентов. --}}
    <div class="flex flex-wrap gap-2" data-chips>
        <a href="{{ $url(['chief' => null]) }}"
           @class(['rounded-full border px-4 py-1.5 text-sm font-medium transition-colors', 'border-slate-900 bg-slate-900 text-white' => $chief === null, 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50' => $chief !== null])>Все</a>
        @foreach ($chiefs as $c)
            @php $value = $c['key']; $active = $chief === $value; @endphp
            <a href="{{ $url(['chief' => $active ? null : $value]) }}"
               @class(['rounded-full border px-4 py-1.5 text-sm font-medium transition-colors', 'border-slate-900 bg-slate-900 text-white' => $active, 'border-slate-200 bg-white text-slate-700 hover:bg-slate-50' => !$active])>{{ $c['name'] }}</a>
        @endforeach
    </div>

    {{-- Плитки: доля клиентов, закрывших выбранный месяц. Клик по главбуху как чип. --}}
    <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-4" data-tiles>
        @foreach ([['id' => null, 'name' => $firmName ? 'Вся ' . $firmName : 'Вся фирма', 'all' => true] + $firm, ...$chiefs->map(fn ($c) => $c + ['all' => false])] as $tile)
            @php
                $value  = $tile['all'] ? null : $tile['key'];
                $active = $tile['all'] ? $chief === null : $chief === $value;
            @endphp
            <a href="{{ $url(['chief' => $tile['all'] || $active ? null : $value]) }}"
               @class(['block rounded-2xl border bg-white p-5 shadow-sm transition-colors', 'border-indigo-300 ring-1 ring-indigo-200' => $active && !$tile['all'], 'border-slate-200/50 hover:border-slate-300' => !$active || $tile['all']])>
                <div class="text-sm text-slate-600 truncate">{{ $tile['name'] }}</div>
                <div class="mt-2 flex items-baseline gap-2">
                    <span class="text-3xl font-bold tabular-nums {{ $pctColor($tile['pct']) }}">{{ $tile['pct'] === null ? '·' : $tile['pct'] . '%' }}</span>
                    <span class="text-sm text-slate-500">клиентов закрыли {{ $lower($focus) }}</span>
                </div>
                <div class="mt-3 h-1.5 rounded-full bg-slate-100 overflow-hidden">
                    <div class="h-full rounded-full {{ $barColor($tile['pct']) }}" style="width: {{ (int) $tile['pct'] }}%"></div>
                </div>
                <div class="mt-2 text-[13px] text-slate-500">
                    {{ $tile['closed'] }} из {{ $tile['total'] }} закрыто
                    @if ($tile['overdue'])
                        · <span class="text-red-700">срок прошёл {{ $tile['overdue'] }}</span>
                    @endif
                    @if ($tile['open'])
                        · открыто {{ $tile['open'] }}
                    @endif
                </div>
            </a>
        @endforeach
    </div>

    <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_380px] gap-4 items-start">

        <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-x-auto">
            <div class="flex flex-wrap items-center gap-x-5 gap-y-2 px-6 py-4">
                <h2 class="text-[15px] font-semibold text-slate-800 mr-auto">Клиенты × месяцы</h2>
                @foreach ([Board::CLOSED, Board::OVERDUE, Board::OPEN] as $s)
                    <span class="inline-flex items-center gap-1.5 text-[13px] text-slate-600" title="{{ Board::STATUSES[$s]['hint'] }}">
                        <span class="w-3 h-3 rounded {{ Page::STYLES[$s]['dot'] }}"></span>{{ mb_strtolower(Board::STATUSES[$s]['label']) }}
                    </span>
                @endforeach
            </div>

            @if ($rows->isEmpty())
                <p class="px-6 py-10 text-center text-sm text-slate-500">Клиентов с задачами по смете за эти месяцы нет.</p>
            @else
                <table class="min-w-[640px] w-full text-sm">
                    <thead class="text-left text-[13px] text-slate-500">
                        <tr>
                            <th class="px-6 py-2 font-medium">Клиент</th>
                            <th class="px-4 py-2 font-medium">Главбух</th>
                            @foreach ($months as $m)
                                <th @class(['w-16 px-1 py-2 text-center font-medium', 'text-slate-900' => $m->format('Y-m') === $key])>{{ $monthName($m) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            @php $client = $row['client']; @endphp
                            <tr class="hover:bg-slate-50" data-client="{{ $client->id }}">
                                <td class="px-6 py-1.5 font-medium text-slate-900 max-w-[280px] truncate" title="{{ $client->name }}">{{ $client->name }}</td>
                                @php
                                    // Главбух месяца, а если клиента с тех пор передали, то и нынешний.
                                    $monthKey = $keyOf($row);
                                    $nowKey   = (string) ($row['chief'] ?? 'none');
                                @endphp
                                <td class="px-4 py-1.5 text-slate-500 whitespace-nowrap"
                                    title="{{ $monthKey === 'unknown' ? 'Ответственный сейчас появился в системе позже срока закрытия этого месяца, а кто вёл клиента тогда, неизвестно' : '' }}">
                                    {{ in_array($monthKey, ['none', 'unknown'], true) ? mb_strtolower($label($monthKey)) : $label($monthKey) }}@if ($monthKey !== $nowKey) <span class="text-slate-400">→ {{ $nowKey === 'none' ? 'без главбуха' : $label($nowKey) }}</span>@endif
                                </td>
                                @foreach ($months as $m)
                                    @php $mk = $m->format('Y-m'); $c = $row['cells'][$mk]; $s = $c['status']; @endphp
                                    <td @class(['px-1 py-1.5 text-center', 'bg-slate-50' => $mk === $key])>
                                        @if ($s === Board::NONE)
                                            <span class="inline-block w-11 text-slate-200" title="{{ $title($m) }}: нет задач">·</span>
                                        @else
                                            <button type="button" @click="open({{ $client->id }}, '{{ $mk }}')"
                                                    class="inline-grid place-items-center w-11 h-8 rounded-lg text-sm font-bold {{ Page::STYLES[$s]['cell'] }}"
                                                    :class="openKey === '{{ $client->id }}:{{ $mk }}' && 'ring-2 ring-slate-900 ring-offset-1'"
                                                    title="{{ $title($m) }}: {{ Board::STATUSES[$s]['label'] }}{{ $c['reasons'] ? ', ' . implode(', ', $c['reasons']) : '' }}"
                                                    aria-label="{{ $title($m) }}: {{ Board::STATUSES[$s]['label'] }}">{{ Board::STATUSES[$s]['letter'] }}</button>
                                        @endif
                                    </td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif

            <p class="px-6 py-4 text-[13px] text-slate-500">Нажмите на клетку: справа появится, что мешает закрыть месяц.</p>
        </div>

        {{-- Карточка клиента за месяц. Содержимое приходит с сервера по клику. --}}
        <aside class="bg-white rounded-2xl shadow-sm border border-slate-200/50 xl:sticky xl:top-4 xl:max-h-[calc(100vh-2rem)] flex flex-col overflow-hidden" x-ref="card">
            <div x-show="!openKey" class="px-6 py-10 text-center text-sm text-slate-500">Выберите клетку в таблице.</div>
            <div x-show="openKey && loading" style="display: none" class="px-6 py-10 text-center text-sm text-slate-500">Загружаю…</div>
            <div x-show="openKey && error" style="display: none" class="px-6 py-10 text-center text-sm text-red-700" x-text="error"></div>
            <div x-show="openKey && !loading && !error" style="display: none" class="flex min-h-0 flex-1 flex-col" x-html="html" @click="onCardClick($event)"></div>
        </aside>
    </div>
</div>
@endsection

@push('scripts')
<script>
    function monthClosingPage() {
        return {
            openKey: null,
            openClient: null,
            html: '',
            loading: false,
            error: '',
            // Ответ на старый клик не должен затереть карточку, открытую позже.
            request: 0,

            async open(clientId, month) {
                const request = ++this.request;
                this.openKey = clientId + ':' + month;
                this.openClient = clientId;
                this.loading = true;
                this.error = '';

                // На узком экране карточка под таблицей: прокручиваем к ней.
                if (window.innerWidth < 1280) {
                    this.$nextTick(() => this.$refs.card.scrollIntoView({ behavior: 'smooth', block: 'start' }));
                }

                try {
                    const url = @json(route('month-closing.card', ['client' => '__ID__'])).replace('__ID__', clientId)
                        + '?month=' + encodeURIComponent(month);
                    const response = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });

                    if (!response.ok) {
                        throw new Error(response.status === 404 ? 'Клиент не найден' : 'Не удалось загрузить карточку');
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
                this.openKey = null;
                this.openClient = null;
                this.html = '';
            },

            // Кнопки внутри карточки приходят готовым HTML: ловим клики по data-атрибутам.
            onCardClick(event) {
                const target = event.target.closest('[data-card-month], [data-card-close]');

                if (!target) {
                    return;
                }

                if (target.dataset.cardClose !== undefined) {
                    this.close();
                } else {
                    this.open(this.openClient, target.dataset.cardMonth);
                }
            },
        };
    }
</script>
@endpush
