{{--
    Просмотр Excel-таблицы внутри окна документа.

    Браузер .xls/.xlsx не рисует, поэтому файл разбирает сервер
    (DocumentController::sheet) и присылает текст ячеек, а тут из него рисуется
    обычная таблица. Чужой файл в браузер сотрудника не попадает вовсе.

    $state — имя объекта sheetPreview() в компоненте, который включает партиал:
    у карточки клиента и у задачника окна разные, а начинка одна.
--}}
@php $state = $state ?? 'sheetView'; @endphp

<div class="flex flex-col flex-1 min-h-0 overflow-hidden bg-white">
    <div x-show="{{ $state }}.loading" class="flex-1 flex items-center justify-center gap-2 text-sm text-slate-400">
        <svg class="w-4 h-4 animate-spin" fill="none" viewBox="0 0 24 24">
            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v4a4 4 0 00-4 4H4z"></path>
        </svg>
        Читаем таблицу…
    </div>

    <div x-show="!{{ $state }}.loading && {{ $state }}.error" class="flex-1 flex items-center justify-center p-6">
        <p class="text-sm text-slate-500 text-center whitespace-pre-line" x-text="{{ $state }}.error"></p>
    </div>

    <template x-if="!{{ $state }}.loading && !{{ $state }}.error && {{ $state }}.sheets.length > 0">
        <div class="flex flex-col flex-1 min-h-0">
            {{-- Вкладки листов: в книге их обычно несколько, и нужный редко первый --}}
            <div x-show="{{ $state }}.sheets.length > 1"
                 class="flex items-center gap-1 px-3 py-2 border-b border-slate-100 overflow-x-auto shrink-0">
                <template x-for="(s, i) in {{ $state }}.sheets" :key="i">
                    <button type="button" @click="{{ $state }}.active = i"
                            :class="{{ $state }}.active === i
                                ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                                : 'text-slate-500 border-transparent hover:bg-slate-50'"
                            class="px-3 py-1 text-xs font-medium rounded-lg border whitespace-nowrap transition-colors"
                            x-text="s.name"></button>
                </template>
            </div>

            <div class="flex-1 min-h-0 overflow-auto bg-slate-50">
                <table class="border-collapse bg-white text-xs">
                    <tbody>
                        <template x-for="(row, r) in ({{ $state }}.current?.rows ?? [])" :key="r">
                            <tr :class="r === 0 ? 'bg-slate-50 font-semibold text-slate-700' : 'text-slate-600'">
                                <template x-for="(cell, c) in row" :key="c">
                                    <td class="border border-slate-200 px-2.5 py-1.5 align-top whitespace-pre-wrap"
                                        style="max-width: 22rem" x-text="cell"></td>
                                </template>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>

            <p x-show="{{ $state }}.truncated"
               class="px-4 py-2 text-xs text-amber-700 bg-amber-50 border-t border-amber-100 shrink-0">
                Показано начало таблицы — первые <span x-text="{{ $state }}.limits.rows"></span> строк
                и <span x-text="{{ $state }}.limits.columns"></span> столбцов. Целиком файл видно после скачивания.
            </p>
        </div>
    </template>
</div>

{{-- Скрипт уезжает в конец страницы: партиал включают внутри <template x-if>, а
     скрипт внутри template браузер не выполняет. --}}
@once
    @push('scripts')
    <script>
        /**
         * Состояние просмотра таблицы. Файл не качаем — просим сервер разобрать его
         * и отдать содержимое, поэтому просмотр работает одинаково для .xls и .xlsx.
         */
        function sheetPreview() {
            return {
                loading: false,
                error: '',
                sheets: [],
                active: 0,
                truncated: false,
                limits: { rows: 0, columns: 0 },

                get current() {
                    return this.sheets[this.active] ?? null;
                },

                async load(doc) {
                    this.reset();
                    this.loading = true;

                    try {
                        const response = await fetch(doc.url + '/sheet', {
                            headers: { 'Accept': 'application/json' },
                        });
                        const data = await response.json().catch(() => ({}));

                        if (!response.ok) {
                            // reason приходит только при APP_DEBUG: настоящая причина отказа
                            this.error = [data.message || 'Не удалось открыть таблицу — скачайте файл', data.reason]
                                .filter(Boolean)
                                .join('\n\n');
                            return;
                        }

                        this.sheets = data.sheets ?? [];
                        this.truncated = !!data.truncated;
                        this.limits = data.limits ?? this.limits;

                        if (this.sheets.every(sheet => sheet.rows.length === 0)) {
                            this.error = 'Таблица пустая';
                        }
                    } catch (e) {
                        this.error = 'Не удалось загрузить таблицу';
                    } finally {
                        this.loading = false;
                    }
                },

                reset() {
                    this.loading = false;
                    this.error = '';
                    this.sheets = [];
                    this.active = 0;
                    this.truncated = false;
                },
            };
        }
    </script>
    @endpush
@endonce
