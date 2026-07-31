<?php

namespace App\Http\Controllers;

use App\Models\BuhTaskDocument;
use App\Models\ClientDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Отдача документов с приватного диска.
 *
 * Раньше файлы лежали на публичном диске и раздавались напрямую по /storage/...,
 * то есть без авторизации и по угадываемому пути. Теперь единственный вход —
 * эти два роута: сессия сотрудника (auth:employee) плюс доступ к модулю.
 *
 * Права намеренно совпадают с видимостью списка: кто видит модуль задач, тот
 * видит и документы задач (главбух проверяет чужие задачи, руководитель и
 * аудитор смотрят закрытые). Разграничение до уровня конкретной задачи здесь
 * не вводится — это отдельное решение, а не побочный эффект переезда на диск.
 */
class DocumentController extends Controller
{
    /** Что безопасно показать во вкладке браузера, а не отдать файлом. */
    private const INLINE_MIMES = ['application/pdf'];

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

    /** Документ задачи (плановой или внеплановой): модуль «БухЗадачник». */
    public function task(Request $request, BuhTaskDocument $document): StreamedResponse
    {
        $this->authorizeModule('buhtasks');

        return $this->serve($request, $document->path, $document->name);
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
