<?php

namespace App\Http\Controllers;

use App\Support\ErrorReporter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Приёмник сбоев из браузера. Серверные ошибки приложение видит само, а всё,
 * что ломается на странице, до сих пор оставалось известно только тому, у кого
 * сломалось. Теперь страница присылает это сюда.
 *
 * Пишем строго то, что пришло, обрезав по длине (см. ErrorReporter). Никакого
 * доверия к содержимому: текст ошибки приходит из браузера и на экран журнала
 * попадает как текст, а не как разметка.
 */
class ErrorReportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => 'required|string|max:1000',
            'source'  => 'nullable|string|max:500',
            'url'     => 'nullable|string|max:500',
            'status'  => 'nullable|integer|min:0|max:999',
            'context' => 'nullable|string|max:5000',
        ]);

        ErrorReporter::browser(
            message: $data['message'],
            source:  $data['source'] ?? null,
            url:     $data['url'] ?? null,
            status:  isset($data['status']) ? (int) $data['status'] : null,
            context: $data['context'] ?? null,
        );

        // Ответ пустой по смыслу: странице от него ничего не нужно, а показывать
        // пользователю ошибку про отправку ошибки — совсем уж дурной тон.
        return response()->json(['success' => true]);
    }
}
