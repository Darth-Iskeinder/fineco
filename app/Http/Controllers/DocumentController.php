<?php

namespace App\Http\Controllers;

use App\Models\BuhTaskDocument;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\Employee;
use App\Services\ClientTaskHistory;
use App\Services\SpreadsheetPreview;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Отдача документов с приватного диска.
 *
 * Раньше файлы лежали на публичном диске и раздавались напрямую по /storage/...,
 * то есть без авторизации и по угадываемому пути. Теперь единственный вход —
 * роуты этого контроллера: сессия сотрудника (auth:employee) плюс доступ к модулю.
 * Их два вида: сам файл (client/task) и разобранная таблица для просмотрщика
 * (clientSheet/taskSheet) — Excel браузер не рисует, поэтому его читает сервер.
 *
 * Права намеренно совпадают с видимостью списка: кто видит модуль задач, тот
 * видит и документы задач (главбух проверяет чужие задачи, руководитель и
 * аудитор смотрят закрытые). Внутри модуля разграничения до конкретной задачи нет.
 *
 * У документов задач есть второй вход — история задач на карточке клиента: там
 * ссылку видят и те, у кого модуля задачника нет. Для них доступ уже сужен до
 * своих клиентов, см. `task()`.
 */
class DocumentController extends Controller
{
    /**
     * Что безопасно показать во вкладке браузера, а не отдать файлом. Текст сюда
     * входит: браузер его не исполняет, а рисует как есть (плюс nosniff ниже).
     */
    private const INLINE_MIMES = ['application/pdf', 'text/plain'];

    /** Документ клиента: доступен тем, у кого есть модуль «Клиенты». */
    public function client(Request $request, ClientDocument $document): StreamedResponse
    {
        $this->authorizeModule('clients');

        return $this->serve(
            $request,
            $document->path,
            $document->original_name ?: $document->name,
            $document->mime_type,
        );
    }

    /**
     * Документ задачи (плановой или внеплановой). Два входа:
     *  - модуль «БухЗадачник» — те, кто эти задачи делает и проверяет;
     *  - история задач на карточке клиента — те, кто и так видит там эту задачу
     *    со ссылкой на документ (админ, руководитель, главбух своего клиента).
     * Без второй ветки руководитель без модуля задачника получал бы 403 по ссылке,
     * которую ему сама же система и показала.
     */
    public function task(Request $request, BuhTaskDocument $document, ClientTaskHistory $history): StreamedResponse
    {
        $this->authorizeTaskDocument($document, $history);

        return $this->serve($request, $document->path, $document->name);
    }

    /**
     * Содержимое таблицы клиента для просмотрщика. Доступ тот же, что и к файлу:
     * иначе через разбор можно было бы прочитать то, что скачать нельзя.
     */
    public function clientSheet(ClientDocument $document, SpreadsheetPreview $preview): JsonResponse
    {
        $this->authorizeModule('clients');

        return $this->sheet($preview, $document->path, $document->original_name ?: $document->name);
    }

    /** Содержимое таблицы, приложенной к задаче. */
    public function taskSheet(
        BuhTaskDocument $document,
        ClientTaskHistory $history,
        SpreadsheetPreview $preview,
    ): JsonResponse {
        $this->authorizeTaskDocument($document, $history);

        return $this->sheet($preview, $document->path, $document->name);
    }

    /**
     * Таблица клиента отдельной страницей. Нужна, чтобы открыть несколько файлов
     * в разных вкладках и сверять цифры глазами: в окне поверх карточки так не
     * получится, а сам .xlsx браузер не рисует.
     */
    public function clientSheetPage(ClientDocument $document, SpreadsheetPreview $preview): View
    {
        $this->authorizeModule('clients');

        return $this->sheetPage(
            $preview,
            $document->path,
            $document->original_name ?: $document->name,
            route('documents.client', $document),
        );
    }

    /** Таблица, приложенная к задаче, отдельной страницей. */
    public function taskSheetPage(
        BuhTaskDocument $document,
        ClientTaskHistory $history,
        SpreadsheetPreview $preview,
    ): View {
        $this->authorizeTaskDocument($document, $history);

        return $this->sheetPage(
            $preview,
            $document->path,
            $document->name,
            route('documents.task', $document),
        );
    }

    private function authorizeTaskDocument(BuhTaskDocument $document, ClientTaskHistory $history): void
    {
        $employee = auth('employee')->user();

        abort_unless(
            $employee && (
                $employee->hasAccessToModule('buhtasks')
                || $this->visibleInClientHistory($employee, $document, $history)
            ),
            403,
            'У вас нет доступа к этому документу',
        );
    }

    /**
     * Виден ли документ через историю задач на карточке клиента. Клиента берём
     * у самой задачи: у внеплановой его может не быть (внутреннее поручение) —
     * тогда карточки, через которую документ был бы виден, не существует.
     */
    private function visibleInClientHistory(
        Employee $employee,
        BuhTaskDocument $document,
        ClientTaskHistory $history,
    ): bool {
        if (!$employee->hasAccessToModule('clients')) {
            return false;
        }

        $clientId = $document->documentable?->client_id;
        if (!$clientId) {
            return false;
        }

        $client = Client::find($clientId);

        return $client !== null && $history->canView($employee, $client);
    }

    private function authorizeModule(string $module): void
    {
        $employee = auth('employee')->user();

        abort_unless($employee && $employee->hasAccessToModule($module), 403,
            'У вас нет доступа к этому модулю');
    }

    private function serve(Request $request, string $path, string $name, ?string $mime = null): StreamedResponse
    {
        // Путь приходит из БД и формируется нами, но выход за пределы диска
        // ценой одной проверки закрываем явно.
        abort_if(str_contains($path, '..'), 404);

        $disk = Storage::disk('local');

        abort_unless($disk->exists($path), 404, 'Файл не найден');

        $mime = $mime ?: ($disk->mimeType($path) ?: 'application/octet-stream');

        // nosniff обязателен: без него браузер может «додумать» тип и выполнить
        // содержимое в домене приложения, где лежит сессия сотрудника.
        $headers = [
            'Content-Type'            => $mime,
            'X-Content-Type-Options'  => 'nosniff',
        ];

        if ($request->boolean('inline') && $this->canInline($mime)) {
            return $disk->response($path, $name, $headers, 'inline');
        }

        return $disk->download($path, $name, $headers);
    }

    /**
     * Разбираем таблицу и отдаём её текстом. Сам файл наружу тут не уходит:
     * фронт рисует из этого обычную HTML-таблицу.
     */
    private function sheet(SpreadsheetPreview $preview, string $path, string $name): JsonResponse
    {
        abort_if(str_contains($path, '..'), 404);
        abort_unless($preview->supports($name), 415, 'Этот файл не таблица');

        $disk = Storage::disk('local');

        abort_unless($disk->exists($path), 404, 'Файл не найден');

        try {
            $data = $preview->read($disk->path($path), $name);
        } catch (\Throwable $e) {
            // Битый или слишком тяжёлый файл — не повод для пятисотки: показываем
            // причину, скачать его человек всё равно сможет. В лог кладём имя файла:
            // без него «не удалось показать таблицу» невозможно разобрать постфактум.
            Log::error('Не удалось разобрать таблицу для просмотра', [
                'file'  => $name,
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Не удалось показать таблицу — скачайте файл, чтобы открыть его в Excel',
                // В отладке показываем причину прямо в окне: иначе до неё добираться через логи.
                'reason'  => config('app.debug') ? $e->getMessage() : null,
            ], 422);
        }

        return response()->json($data + ['name' => $name]);
    }

    /**
     * Страница просмотра. Ошибку показываем на ней же: человек пришёл сюда по
     * ссылке из списка документов, и пустая страница с 500 ему ничего не скажет.
     */
    private function sheetPage(
        SpreadsheetPreview $preview,
        string $path,
        string $name,
        string $downloadUrl,
    ): View {
        abort_if(str_contains($path, '..'), 404);
        abort_unless($preview->supports($name), 415, 'Этот файл не таблица');

        $disk = Storage::disk('local');

        abort_unless($disk->exists($path), 404, 'Файл не найден');

        $data = null;
        $reason = null;

        try {
            $data = $preview->read(
                $disk->path($path),
                $name,
                SpreadsheetPreview::PAGE_ROWS,
                SpreadsheetPreview::PAGE_COLUMNS,
            );
        } catch (\Throwable $e) {
            Log::error('Не удалось разобрать таблицу для просмотра', [
                'file'  => $name,
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);

            // Причину показываем только в отладке — на боевом она остаётся в логе.
            $reason = config('app.debug') ? $e->getMessage() : null;
        }

        return view('documents.sheet', [
            'name'        => $name,
            'downloadUrl' => $downloadUrl,
            'data'        => $data,
            'reason'      => $reason,
        ]);
    }

    /**
     * Открывать во вкладке можно только то, что браузер не исполнит.
     * SVG исключён намеренно: это XML, внутри может быть скрипт.
     */
    private function canInline(string $mime): bool
    {
        if ($mime === 'image/svg+xml') {
            return false;
        }

        return in_array($mime, self::INLINE_MIMES, true) || str_starts_with($mime, 'image/');
    }
}
