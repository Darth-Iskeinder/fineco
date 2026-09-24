@extends('layouts.app')
@section('title', 'Автоаудит')
@section('page-title', 'Автоаудит')

@section('content')
@php
    use App\Models\AutoAuditFinding;
    use App\Models\AutoAuditFindingMessage;
    use App\Models\AutoAuditResult;
    use App\Services\AutoAudit\AutoAuditRunner;

    $statusClasses = [
        AutoAuditResult::MATCHED          => 'bg-emerald-50 text-emerald-700',
        AutoAuditResult::MISMATCH         => 'bg-red-50 text-red-700',
        AutoAuditResult::UNVERIFIED       => 'bg-orange-50 text-orange-700',
        AutoAuditResult::MISSING_DOCUMENT => 'bg-amber-50 text-amber-700',
        AutoAuditResult::WRONG_DOCUMENT   => 'bg-amber-50 text-amber-700',
        AutoAuditResult::SCAN             => 'bg-slate-100 text-slate-600',
        AutoAuditResult::UNREADABLE       => 'bg-slate-100 text-slate-600',
    ];

    // Цвет точки на плитке-счётчике: та же гамма, что у плашек статуса в таблице.
    $statusDots = [
        AutoAuditResult::MATCHED          => 'bg-emerald-600',
        AutoAuditResult::MISMATCH         => 'bg-red-600',
        AutoAuditResult::UNVERIFIED       => 'bg-orange-500',
        AutoAuditResult::MISSING_DOCUMENT => 'bg-amber-600',
        AutoAuditResult::WRONG_DOCUMENT   => 'bg-amber-600',
        AutoAuditResult::SCAN             => 'bg-slate-300',
        AutoAuditResult::UNREADABLE       => 'bg-slate-300',
    ];

    // Подсказка при наведении на плитку: что значит статус и что с ним делать.
    $statusHints = [
        AutoAuditResult::MATCHED          => 'Суммы в двух документах сошлись',
        AutoAuditResult::MISMATCH         => 'Суммы разошлись. Нужно пояснение бухгалтера',
        AutoAuditResult::UNVERIFIED       => 'Система не уверена в числах и вердикт не выносит. Сверить глазами',
        AutoAuditResult::MISSING_DOCUMENT => 'Задача закрыта, а нужного документа к ней не приложили',
        AutoAuditResult::WRONG_DOCUMENT   => 'К задаче приложен не тот документ или документ другой фирмы',
        AutoAuditResult::SCAN             => 'Приложен скан или фото, система такие не читает',
        AutoAuditResult::UNREADABLE       => 'Файл повреждён или пропал',
    ];

    $money = fn ($value) => $value === null ? '' : number_format((float) $value, 2, ',', ' ');

    // Русский плюрал: формы [1, 2–4, 5+] (локаль приложения en, trans_choice не подходит)
    $plural = fn (int $n, array $f) => $f[($n % 10 === 1 && $n % 100 !== 11) ? 0 : (($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 10 || $n % 100 >= 20)) ? 1 : 2)];

    // Все фильтры страницы, кроме статуса: их несут плитки и ссылка «Без ответа».
    $filters = array_filter(['period' => $period, 'rule' => $rule, 'answer' => $unanswered ? 'none' : null]);
@endphp

<div class="space-y-4" x-data="autoAuditDocs()" @keydown.escape.window="closeDocViewer()">

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50">
        <div class="px-6 py-4">
            <p class="text-sm text-slate-600">
                Система сама сверяет суммы в документах, которые бухгалтеры прикладывают к задачам.
                «Не совпало» не значит ошибку: это повод попросить у бухгалтера пояснение.
                <a href="{{ route('docs.section', 'auto-audit') }}" target="_blank" class="text-indigo-600 hover:underline">Подробнее о проверках и статусах</a>
            </p>
            <p class="text-sm text-slate-500 mt-1">
                @if ($checkedAt)
                    Данные на {{ $checkedAt->format('d.m.Y H:i') }}
                @else
                    Проверок пока не было
                @endif
            </p>
            @if ($vendor)
                {{-- Подсказка вендору: руководитель видит то же самое или нет. --}}
                <p class="text-xs text-slate-400 mt-1">
                    {{ $openToManager ? 'Руководитель фирмы видит эту страницу.' : 'Руководитель фирмы эту страницу пока не видит.' }}
                </p>
            @endif
        </div>

        @if ($running)
            {{-- Прогон идёт в терминале: показываем это и обновляем страницу, пока не закончится. --}}
            <div class="mx-6 mb-4 rounded-xl bg-amber-50 px-4 py-3 text-sm text-amber-900">
                Идёт проверка с {{ \Carbon\CarbonImmutable::parse($state['started_at'])->setTimezone(config('app.timezone'))->format('H:i') }}.
                Ниже пока прежние результаты. Страница обновится сама.
            </div>
            <script>setTimeout(() => window.location.reload(), 15000);</script>
        @elseif (($state['status'] ?? null) === \App\Jobs\RunAutoAuditJob::FAILED)
            <div class="mx-6 mb-4 rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">
                Последняя проверка не прошла, ниже результаты предыдущей.
                @if ($vendor)
                    Причина: {{ $state['error'] ?? 'неизвестна' }}.
                @endif
            </div>
        @endif

        @if ($periods)
            <div class="px-6 pb-4">
                <form method="GET" action="{{ route('auto-audit.index') }}" class="flex items-center flex-wrap gap-3">
                    @if ($status)
                        <input type="hidden" name="status" value="{{ $status }}">
                    @endif
                    @if ($unanswered)
                        <input type="hidden" name="answer" value="none">
                    @endif
                    <label for="period" class="text-sm font-medium text-slate-700"
                           title="Период, за который составлены документы. Отчёт за июль сдают в августе, и он здесь в июле.">Период</label>
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
                    @if ($showFindings)
                        {{-- Строки, по которым бухгалтер не ответил или ответ не приняли. Повторный клик снимает фильтр. --}}
                        @php $answerFilters = array_filter(['period' => $period, 'rule' => $rule, 'status' => $status]); @endphp
                        <a href="{{ route('auto-audit.index', $unanswered ? $answerFilters : $answerFilters + ['answer' => 'none']) }}"
                           title="Бухгалтер не ответил, или ответ не приняли"
                           @class(['rounded-lg border text-sm px-3 py-2 transition-colors', 'border-indigo-200 bg-indigo-50 text-indigo-700' => $unanswered, 'border-slate-200 text-slate-700 hover:bg-slate-50' => !$unanswered])>
                            Без ответа: {{ $awaitingCount }}
                        </a>
                    @endif
                </form>
            </div>

            {{-- Плитки-счётчики, как на странице руководителя. Клик выбирает статус, повторный
                 клик по выбранной снимает фильтр. Цвет несёт точка, цифры чернильные. --}}
            <div class="grid grid-cols-2 sm:grid-cols-4 xl:grid-cols-8 gap-px bg-slate-100 border-t border-slate-100 rounded-b-2xl overflow-hidden">
                <a href="{{ route('auto-audit.index', $filters) }}"
                   @class(['flex flex-col justify-between px-4 py-3 transition-colors', 'bg-indigo-50' => $status === null, 'bg-white hover:bg-slate-50' => $status !== null])>
                    <div class="text-[13px] text-slate-500">Все</div>
                    <div class="mt-1 text-2xl leading-none font-semibold text-slate-900">{{ $counts->sum() }}</div>
                </a>
                @foreach (AutoAuditResult::LABELS as $outcome => $label)
                    @php $count = $counts[$outcome] ?? 0; @endphp
                    <a href="{{ route('auto-audit.index', $status === $outcome ? $filters : $filters + ['status' => $outcome]) }}"
                       title="{{ $statusHints[$outcome] ?? '' }}" data-status="{{ $outcome }}" data-count="{{ $count }}"
                       @class(['flex flex-col justify-between px-4 py-3 transition-colors', 'bg-indigo-50' => $status === $outcome, 'bg-white hover:bg-slate-50' => $status !== $outcome])>
                        <div class="flex items-center gap-1.5 text-[13px] text-slate-500">
                            <span class="w-2 h-2 rounded-full flex-shrink-0 {{ $statusDots[$outcome] ?? 'bg-slate-300' }}"></span>
                            {{ $label }}
                        </div>
                        <div @class(['mt-1 text-2xl leading-none font-semibold', 'text-slate-900' => $count > 0, 'text-slate-300' => $count === 0])>{{ $count }}</div>
                    </a>
                @endforeach
            </div>
        @endif
    </div>

    @if ($errors->has('body'))
        <div class="rounded-xl bg-red-50 px-4 py-3 text-sm text-red-700">{{ $errors->first('body') }}</div>
    @endif

    <div class="bg-white rounded-2xl shadow-sm border border-slate-200/50 overflow-x-auto">
        @if ($results->isEmpty())
            <p class="px-6 py-10 text-center text-sm text-slate-500">
                {{ $periods ? 'Под выбранные фильтры строк нет.' : 'Проверок пока не было.' }}
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
                        @if ($showFindings)
                            <th class="px-4 py-3">Ответ</th>
                        @endif
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @foreach ($results as $result)
                        @php
                            $finding = $findings[$result->key()] ?? null;
                            $findingState = $finding?->state($result);
                        @endphp
                        {{-- Принятое остаётся на странице, но серым: смотреть на него больше не нужно. --}}
                        <tr @class(['align-top', 'opacity-60' => $findingState === AutoAuditFinding::ACCEPTED])>
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
                                    <p class="text-xs text-slate-400" title="Документ не прочитан, поэтому период взят по задаче: месяц перед ней">по месяцу задачи</p>
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
                                    @if (empty($source['document_id']))
                                        {{-- Задача закрыта без файла: открывать нечего, видно только чья. --}}
                                        <div class="text-slate-400">
                                            <span title="Задача за {{ $source['task_month'] }}">{{ $source['label'] }}@if (!empty($source['employee'])), <span class="font-medium text-slate-500">{{ $source['employee'] }}</span>@endif:</span>
                                            файл не приложен
                                        </div>
                                        @continue
                                    @endif
                                    @php $documentUrl = route('documents.task', $source['document_id']); @endphp
                                    <div>
                                        {{-- Месяц задачи нужен редко: при наведении, чтобы не шуметь в каждой строке. --}}
                                        <span class="text-slate-400" title="Задача за {{ $source['task_month'] }}">{{ $source['label'] ?? ($source['side'] === 'osv' ? 'ОСВ' : 'Отчёт') }}@if (!empty($source['employee'])), <span class="font-medium text-slate-500">{{ $source['employee'] }}</span>@endif:</span>
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
                                    {{-- Причина часто начинается словами плашки, «Не удалось проверить: …». Второй раз их не пишем. --}}
                                    @php
                                        $label  = AutoAuditResult::LABELS[$result->outcome] ?? '';
                                        $reason = $label !== '' && str_starts_with($result->reason, $label . ': ')
                                            ? mb_substr($result->reason, mb_strlen($label) + 2)
                                            : $result->reason;
                                    @endphp
                                    <p class="mt-1 text-xs text-slate-500">{{ mb_strtoupper(mb_substr($reason, 0, 1)) . mb_substr($reason, 1) }}</p>
                                @endif
                            </td>
                            @if ($showFindings)
                                <td class="px-4 py-3 text-xs space-y-1 min-w-[16rem]">
                                    @if ($finding)
                                        @if ($findingState === AutoAuditFinding::ACCEPTED)
                                            <span class="inline-block px-2 py-0.5 rounded-full text-xs font-semibold whitespace-nowrap bg-slate-100 text-slate-600">Объяснено</span>
                                        @else
                                            @php $days = $finding->daysOpen(); @endphp
                                            <p class="text-slate-500" title="С {{ $finding->opened_at->format('d.m.Y') }}">
                                                {{ $days === 0 ? 'Висит с сегодня' : 'Висит ' . $days . ' ' . $plural($days, ['день', 'дня', 'дней']) }}
                                            </p>
                                        @endif

                                        @forelse ($finding->messages as $message)
                                            <div>
                                                <span class="font-medium text-slate-600">{{ AutoAuditFindingMessage::LABELS[$message->kind] ?? $message->kind }}</span><span class="text-slate-400">, {{ $message->authorName() }}, {{ $message->created_at->format('d.m') }}</span>@if ($message->result_id !== $result->id)<span class="text-slate-400" title="Сообщение относится к прежнему итогу строки, до замены файла или исправления">, к прежнему итогу</span>@endif
                                                @if ($message->body)
                                                    <p class="text-slate-700 whitespace-pre-line">{{ $message->body }}</p>
                                                @endif
                                            </div>
                                        @empty
                                            <p class="text-slate-400">Ответа пока нет</p>
                                        @endforelse

                                        @if ($finding->explanationOutdated($result))
                                            <p class="text-amber-700">После объяснения цифры изменились, ждём нового ответа</p>
                                        @elseif ($finding->fixDidNotHelp($result))
                                            <p class="text-amber-700">После замены файла проверка прошла, но всё ещё не сходится</p>
                                        @elseif ($findingState === AutoAuditFinding::ANSWERED && $finding->messages->last()?->kind === AutoAuditFindingMessage::FIXED)
                                            <p class="text-slate-400">Файл заменён, ждёт следующей проверки</p>
                                        @endif

                                        @if ($findingState !== AutoAuditFinding::ACCEPTED)
                                            <div class="flex items-start gap-2 pt-1">
                                                <form method="POST" action="{{ route('auto-audit.findings.accept', $finding) }}">
                                                    @csrf
                                                    <input type="hidden" name="result_id" value="{{ $result->id }}">
                                                    <button type="submit" class="px-2 py-1 rounded-lg bg-emerald-50 text-emerald-700 font-semibold hover:bg-emerald-100 transition-colors">Принять</button>
                                                </form>
                                                {{-- Комментарий обязателен: бухгалтеру надо понять, что не так. --}}
                                                <details class="flex-1">
                                                    <summary class="inline-block px-2 py-1 rounded-lg bg-red-50 text-red-700 font-semibold cursor-pointer" style="list-style: none">Не принято</summary>
                                                    <form method="POST" action="{{ route('auto-audit.findings.reject', $finding) }}" class="mt-2 space-y-2">
                                                        @csrf
                                                        <input type="hidden" name="result_id" value="{{ $result->id }}">
                                                        <textarea name="body" rows="3" required maxlength="2000" placeholder="Что не так с ответом"
                                                                  class="w-full rounded-lg border border-slate-200 text-xs px-2 py-1"></textarea>
                                                        <button type="submit" class="px-2 py-1 rounded-lg bg-indigo-600 text-white font-semibold hover:bg-indigo-700 transition-colors">Отправить бухгалтеру</button>
                                                    </form>
                                                </details>
                                            </div>
                                        @endif
                                    @endif
                                </td>
                            @endif
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
