{{--
    Таблица отдельной страницей — её открывают во вкладке, чтобы разложить рядом
    несколько файлов и сверять цифры. Поэтому здесь нет сайдбара: место уходит
    под саму таблицу, а первая строка и первый столбец закреплены, как «Закрепить
    области» в Excel — иначе на двухсотой строке уже не помнишь, что за колонка.

    Данные приходят готовыми из DocumentController::sheetPage — файл разбирает
    сервер, в браузер он не попадает.
--}}
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    {{-- Имя файла в заголовке вкладки: когда их открыто с десяток, только по нему и ориентируешься --}}
    <title>{{ $name }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/kubik-icon.svg') }}">
    @vite('resources/css/app.css')
    <style>
        /* border-collapse: separate — иначе у прилипших ячеек пропадают рамки */
        .sheet-table { border-collapse: separate; border-spacing: 0; }
        .sheet-table td { border-right: 1px solid #e2e8f0; border-bottom: 1px solid #e2e8f0; }
        .sheet-table tr:first-child td { position: sticky; top: 0; z-index: 2; }
        .sheet-table td:first-child { position: sticky; left: 0; z-index: 1; }
        .sheet-table tr:first-child td:first-child { z-index: 3; }
    </style>
</head>
<body class="bg-slate-100 h-screen flex flex-col overflow-hidden"
      x-data="{ active: 0 }">

    <header class="flex items-center gap-3 px-4 py-3 bg-white border-b border-slate-200 shrink-0">
        <svg class="w-5 h-5 text-emerald-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 7h6m0 10v-3m-3 3h.01M9 17h.01M9 14h.01M12 14h.01M15 11h.01M12 11h.01M9 11h.01M7 21h10a2 2 0 002-2V5a2 2 0 00-2-2H7a2 2 0 00-2 2v14a2 2 0 002 2z" />
        </svg>
        <p class="text-sm font-medium text-slate-700 truncate flex-1">{{ $name }}</p>

        @if ($data && count($data['sheets']) > 1)
            {{-- Вкладки листов: нужный в книге редко первый --}}
            <div class="hidden sm:flex items-center gap-1 overflow-x-auto max-w-md shrink-0">
                @foreach ($data['sheets'] as $index => $sheet)
                    <button type="button" @click="active = {{ $index }}"
                            :class="active === {{ $index }}
                                ? 'bg-emerald-50 text-emerald-700 border-emerald-200'
                                : 'text-slate-500 border-transparent hover:bg-slate-50'"
                            class="px-3 py-1 text-xs font-medium rounded-lg border whitespace-nowrap transition-colors">
                        {{ $sheet['name'] }}
                    </button>
                @endforeach
            </div>
        @endif

        <a href="{{ $downloadUrl }}" title="Скачать"
           class="shrink-0 inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-slate-600 bg-slate-100 hover:bg-slate-200 rounded-lg transition-colors">
            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4" />
            </svg>
            Скачать
        </a>
    </header>

    @if (!$data)
        <div class="flex-1 flex items-center justify-center p-6">
            <div class="text-center space-y-2">
                <p class="text-sm text-slate-600">Не удалось показать таблицу — скачайте файл, чтобы открыть его в Excel</p>
                @if ($reason)
                    <p class="text-xs text-slate-400 whitespace-pre-line">{{ $reason }}</p>
                @endif
            </div>
        </div>
    @else
        @foreach ($data['sheets'] as $index => $sheet)
            <div x-show="active === {{ $index }}" class="flex-1 min-h-0 overflow-auto"
                 @if ($index > 0) style="display:none" @endif>
                <table class="sheet-table bg-white text-xs">
                    <tbody>
                        @foreach ($sheet['rows'] as $rowIndex => $row)
                            <tr class="{{ $rowIndex === 0 ? 'bg-slate-50 font-semibold text-slate-700' : 'text-slate-600' }}">
                                @foreach ($row as $cellIndex => $cell)
                                    {{-- Фон обязателен: прилипшие ячейки иначе просвечивают --}}
                                    <td class="px-2.5 py-1.5 align-top whitespace-pre-wrap
                                               {{ $rowIndex === 0 ? 'bg-slate-100' : ($cellIndex === 0 ? 'bg-slate-50' : 'bg-white') }}"
                                        style="max-width: 26rem">{{ $cell }}</td>
                                @endforeach
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endforeach

        @if ($data['truncated'])
            <p class="px-4 py-2 text-xs text-amber-700 bg-amber-50 border-t border-amber-100 shrink-0">
                Показано начало таблицы — первые {{ $data['limits']['rows'] }} строк
                и {{ $data['limits']['columns'] }} столбцов. Целиком файл видно после скачивания.
            </p>
        @endif
    @endif

    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
</body>
</html>
