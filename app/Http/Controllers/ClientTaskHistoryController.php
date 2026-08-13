<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Services\ClientTaskHistory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * История выполненных задач клиента — данные для секции внизу карточки клиента.
 * Только чтение. Список грузится отдельным запросом при раскрытии секции, чтобы
 * не утяжелять и без того большую карточку.
 */
class ClientTaskHistoryController extends Controller
{
    public function index(Request $request, Client $client, ClientTaskHistory $history): JsonResponse
    {
        $this->authorizeHistory($client, $history);

        $validated = $request->validate([
            'docs' => 'nullable|string|in:' . implode(',', ClientTaskHistory::DOCS_FILTERS),
            'page' => 'nullable|integer|min:1',
        ]);

        return response()->json($history->page(
            $client,
            $validated['docs'] ?? ClientTaskHistory::DOCS_ALL,
            (int) ($validated['page'] ?? 1),
        ));
    }

    /** Карточка одной выполненной задачи — грузится по клику на строку истории. */
    public function show(Client $client, string $type, int $id, ClientTaskHistory $history): JsonResponse
    {
        $this->authorizeHistory($client, $history);

        abort_unless(in_array($type, ['planned', 'adhoc'], true), 404);

        $details = $history->details($client, $type, $id);

        abort_if($details === null, 404, 'Задача не найдена');

        return response()->json($details);
    }

    private function authorizeHistory(Client $client, ClientTaskHistory $history): void
    {
        $me = auth('employee')->user();

        abort_unless(
            $me && $history->canView($me, $client),
            403,
            'Нет доступа к истории задач этого клиента',
        );
    }
}
