@extends('layouts.app')
@section('title', 'Автоаудит')
@section('page-title', 'Автоаудит')

@section('content')
@php
    use App\Models\AutoAuditResult;
    use App\Services\AutoAudit\AutoAuditRunner;

    $statusClasses = [
        AutoAuditResult::MATCHED          => 'bg-emerald-50 text-emerald-700',
        AutoAuditResult::MISMATCH         => 'bg-red-50 text-red-700',
        AutoAuditResult::MISSING_DOCUMENT => 'bg-amber-50 text-amber-700',
        AutoAuditResult::WRONG_DOCUMENT   => 'bg-amber-50 text-amber-700',
        AutoAuditResult::SCAN             => 'bg-slate-100 text-slate-600',
        AutoAuditResult::UNREADABLE       => 'bg-slate-100 text-slate-600',
    ];

    $money = fn ($value) => $value === null ? '' : number_format((float) $value, 2, ',', ' ');
@endphp

<div class="space-y-4" x-data="autoAuditDocs()" @keydown.escape.window="closeDocViewer()">

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50">
        <div class="px-6 py-4 flex items-start justify-between flex-wrap gap-4">
            <div>
                <h2 class="text-lg font-semibold text-slate-800">Автоаудит</h2>
                <p class="text-sm text-slate-500 mt-0.5">
                    Страницу видит только владелец системы, сотрудники фирмы её не видят.
                </p>
                <p class="text-sm text-slate-500 mt-0.5">
                    @if ($checkedAt)
                        Последняя проверка: {{ $checkedAt->format('d.m.Y H:i') }}@if (($state['status'] ?? null) === \App\Jobs\RunAutoAuditJob::DONE && isset($state['seconds'])), заняла {{ $state['seconds'] }} с@endif
                    @else
                        Проверки ещё не было
                    @endif
                </p>
            </div>

            <form method="POST" action="{{ route('auto-audit.run') }}"
                  x-data="{ busy: @js($running) }" @submit="busy = true">
                @csrf
                <button type="submit" :disabled="busy" @disabled($running)
                        class="inline-flex items-center px-4 py-2 bg-gradient-to-r from-violet-500 to-indigo-600 text-white text-sm font-medium rounded-lg shadow-sm disabled:opacity-60 transition">
                    {{-- x-text, а не x-show с x-cloak: правила [x-cloak] в проекте нет --}}
                    <span x-text="busy ? 'Проверка идёт…' : 'Проверить сейчас'">{{ $running ? 'Проверка идёт…' : 'Проверить сейчас' }}</span>
                </button>
            </form>
        </div>

        @if ($running)
            {{-- Прогон идёт после ответа браузеру: показываем это и обновляем страницу, пока не закончится. --}}
            <div class="mx-6 mb-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900">
                Идёт проверка с {{ \Carbon\CarbonImmutable::parse($state['started_at'])->setTimezone(config('app.timezone'))->format('H:i') }}.
                Ниже пока прежние результаты. Страница обновится сама.
            </div>
            <script>setTimeout(() => window.location.reload(), 15000);</script>
        @elseif (($state['status'] ?? null) === \App\Jobs\RunAutoAuditJob::FAILED)
            <div class="mx-6 mb-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">
                Последняя проверка упала и не записала результаты: {{ $state['error'] ?? 'причина неизвестна' }}.
                Ниже результаты предыдущей проверки.
            </div>
        @endif

        @if ($periods)
            <div class="px-6 pb-4">
                <form method="GET" action="{{ route('auto-audit.index') }}" class="flex items-center flex-wrap gap-3">
                    <label for="period" class="text-sm font-medium text-slate-700">Отчётный период</label>
                    <select id="period" name="period" onchange="this.form.submit()"
                            class="rounded-lg border border-slate-200 text-sm px-3 py-2">
                        @foreach ($periods as $key => $label)
                            <option value="{{ $key }}" @selected($period === $key)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <label for="rule" class="text-sm font-medium text-slate-700">Проверка</label>
                    <select id="rule" name="rule" onchange="this.form.submit()"
                            class="rounded-lg border border-slate-200 text-sm px-3 py-2">
                        <option value="">Все проверки</option>
                        @foreach (AutoAuditRunner::RULES as $number => $definition)
                            <option value="{{ $number }}" @selected($rule === (string) $number)>№{{ $number }} {{ $definition['name'] }}</option>
                        @endforeach
                    </select>
                    <label for="status" class="text-sm font-medium text-slate-700">Статус</label>
                    <select id="status" name="status" onchange="this.form.submit()"
                            class="rounded-lg border border-slate-200 text-sm px-3 py-2">
                        <option value="">Все статусы</option>
                        @foreach (AutoAuditResult::LABELS as $outcome => $label)
                            <option value="{{ $outcome }}" @selected($status === $outcome)>{{ $label }}</option>
                        @endforeach
                    </select>
                    <span class="text-sm text-slate-500">
                        @foreach (AutoAuditResult::LABELS as $outcome => $label)
                            {{ $label }}: {{ $counts[$outcome] ?? 0 }}{{ $loop->last ? '' : ';' }}
                        @endforeach
                    </span>
                </form>
                <p class="text-xs text-slate-400 mt-2">
                    Период, за который составлены документы, а не месяц задачи: отчёт за июль сдают в августе, и он здесь в июле.
                    Если файла нет или его не прочитать, период взят по задаче: месяц перед ней.
                </p>
            </div>
        @endif
    </div>

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-x-auto">
        @if ($results->isEmpty())
            <p class="px-6 py-10 text-center text-sm text-slate-500">
                Результатов нет. Нажмите «Проверить сейчас».
            </p>
        @else
            <table class="min-w-full text-sm">
                <thead class="bg-slate-50 text-left text-xs font-semibold text-slate-500 uppercase tracking-wider">
                    <tr>
                        <th class="px-4 py-3">Проверка</th>
                        <th class="px-4 py-3">Клиент</th>
                        <th class="px-4 py-3">Период</th>
                        <th class="px-4 py-3 text-right">ОСВ</th>
                        <th class="px-4 py-3 text-right">Документ</th>
                        <th class="px-4 py-3 text-right">Разница</th>
                        <th class="px-4 py-3">Документы</th>
                        <th class="px-4 py-3">Статус</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($results as $result)
                        <tr class="align-top">
                            {{-- У «нет документа» строка общая для всех проверок клиента: номера столбиком. --}}
                            <td class="px-4 py-3 text-slate-700 space-y-1">
                                @foreach ($result->ruleNumbers() as $number)
                                    <div>№{{ $number }} {{ AutoAuditRunner::RULES[$number]['name'] ?? '' }}</div>
                                @endforeach
                            </td>
                            <td class="px-4 py-3 font-medium text-slate-800">{{ $result->client?->name ?? 'клиент удалён' }}</td>
                            <td class="px-4 py-3 text-slate-700 whitespace-nowrap">
                                {{ $result->periodLabel() }}
                                {{-- Период не из документа, а по задаче: это надо видеть. --}}
                                @if ($result->periodFromTask())
                                    <p class="text-xs text-slate-400">по месяцу задачи</p>
                                @endif
                            </td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">{{ $money($result->left_value) }}</td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">{{ $money($result->right_value) }}</td>
                            <td @class([
                                'px-4 py-3 text-right whitespace-nowrap',
                                'font-semibold text-red-600' => $result->outcome === AutoAuditResult::MISMATCH,
                            ])>{{ $money($result->difference) }}</td>
                            <td class="px-4 py-3 text-xs space-y-1">
                                @foreach ($result->sources ?? [] as $source)
                                    @php $documentUrl = route('documents.task', $source['document_id']); @endphp
                                    <div>
                                        <span class="text-slate-400">{{ $source['label'] ?? ($source['side'] === 'osv' ? 'ОСВ' : 'Отчёт') }}, задача за {{ $source['task_month'] }}@if (!empty($source['employee'])), <span class="font-medium text-slate-500">{{ $source['employee'] }}</span>@endif:</span>
                                        {{-- Клик открывает просмотр на этой же странице. Ctrl-клик и средняя кнопка открывают вкладку, как у обычной ссылки. --}}
                                        <a href="{{ $documentUrl }}" target="_blank"
                                           @click="openDocFromLink($event, @js(['name' => $source['name'], 'url' => $documentUrl]))"
                                           class="text-indigo-600 hover:underline">{{ $source['name'] }}</a>
                                        @if ($source['value'] !== null)
                                            <span class="text-slate-500 whitespace-nowrap">{{ $money($source['value']) }}</span>
                                        @elseif ($source['reason'])
                                            <span class="text-slate-500">{{ $source['reason'] }}</span>
                                        @endif
                                    </div>
                                @endforeach
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold whitespace-nowrap {{ $statusClasses[$result->outcome] ?? 'bg-slate-100 text-slate-600' }}">
                                    {{ AutoAuditResult::LABELS[$result->outcome] ?? $result->outcome }}
                                </span>
                                @if ($result->reason)
                                    <p class="mt-1 text-xs text-slate-500">{{ $result->reason }}</p>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </div>

    {{-- Просмотрщик документа, как в БухЗадачнике. PDF и картинки рисует сам браузер по ссылке
         с ?inline=1. Excel он не умеет вовсе, поэтому таблицу разбирает сервер, а тут она
         превращается в обычную таблицу. --}}
    <div x-show="docViewer.show"
         x-transition:enter="ease-out duration-150"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         class="fixed inset-0 z-[60] flex flex-col bg-black/70 p-3 sm:p-6"
         @click.self="closeDocViewer()"
         style="display:none">
        <div class="w-full max-w-5xl mx-auto flex flex-col flex-1 min-h-0 bg-white rounded-2xl shadow-2xl overflow-hidden">
            <div class="flex items-center gap-3 px-4 py-3 border-b border-slate-100 flex-shrink-0">
                <svg x-show="!docViewer.sheet" class="w-5 h-5 text-slate-400 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/></svg>
                <svg x-show="docViewer.sheet" class="w-5 h-5 text-emerald-500 flex-shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z"/></svg>
                <p class="text-sm font-medium text-slate-700 truncate flex-1" x-text="docViewer.name"></p>
                <a :href="docViewer.url.replace('?inline=1', '')" title="Скачать"
                   class="flex-shrink-0 p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/></svg>
                </a>
                <a :href="docViewer.tabUrl" target="_blank" title="Открыть в новой вкладке"
                   class="flex-shrink-0 p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/></svg>
                </a>
                <button type="button" @click="closeDocViewer()" title="Закрыть"
                        class="flex-shrink-0 p-1.5 rounded-lg text-slate-400 hover:text-slate-600 hover:bg-slate-100 transition-colors">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/></svg>
                </button>
            </div>
            {{-- Содержимое создаём только когда просмотрщик открыт: иначе браузер тянет файл заранее --}}
            <template x-if="docViewer.show && !docViewer.sheet">
                <iframe :src="docViewer.url" :title="docViewer.name" class="flex-1 w-full min-h-0 border-0"></iframe>
            </template>
            <template x-if="docViewer.show && docViewer.sheet">
                @include('partials.sheet-preview', ['state' => 'sheetView'])
            </template>
        </div>
    </div>

</div>

@push('scripts')
<script>
    /**
     * Просмотр документов прямо на странице автоаудита, чтобы сверять числа, не скачивая
     * файлы. Окно то же, что в БухЗадачнике, и права на файл проверяет тот же контроллер.
     */
    function autoAuditDocs() {
        return {
            docViewer: { show: false, name: '', url: '', tabUrl: '', sheet: false },
            sheetView: sheetPreview(),

            isSheetDoc(doc) {
                return /\.(xlsx?|xlsm|ods|csv)$/i.test(doc?.name || '');
            },

            /** Что умеем показать: PDF, текст и картинки рисует браузер, таблицы разбирает сервер. */
            canPreviewDoc(doc) {
                return /\.(pdf|txt|log|jpe?g|png|gif|webp|bmp)$/i.test(doc?.name || '') || this.isSheetDoc(doc);
            },

            /** «Открыть во вкладке»: таблице нужна наша страница, .xlsx во вкладке браузер просто скачает. */
            docTabUrl(doc) {
                return this.isSheetDoc(doc) ? doc.url + '/sheet/view' : doc.url + '?inline=1';
            },

            /**
             * Клик по имени документа. Ctrl/Cmd/Shift и среднюю кнопку не перехватываем: пусть
             * браузер откроет вкладку сам. То, что показать не можем, скачивается как раньше.
             */
            openDocFromLink(event, doc) {
                if (event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0 || !this.canPreviewDoc(doc)) {
                    return;
                }

                event.preventDefault();

                // inline=1: единственный режим, в котором контроллер отдаёт файл для показа, а не скачивания.
                this.docViewer = {
                    show: true,
                    name: doc.name,
                    url: doc.url + '?inline=1',
                    tabUrl: this.docTabUrl(doc),
                    sheet: this.isSheetDoc(doc),
                };

                if (this.docViewer.sheet) {
                    this.sheetView.load(doc);
                }
            },

            closeDocViewer() {
                this.docViewer = { show: false, name: '', url: '', tabUrl: '', sheet: false };
                this.sheetView.reset();
            },
        };
    }
</script>
@endpush
@endsection
