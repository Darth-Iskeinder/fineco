<?php

namespace App\Services;

use App\Models\BuhAdhocTask;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\EstimateItem;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * История выполненных задач клиента — для секции внизу карточки клиента.
 *
 * Зачем отдельный сервис, а не задачник: `BuhTasksController@index` строит ЛИЧНЫЙ
 * рабочий список сотрудника (свои клиенты + клиенты своих бухгалтеров) и идёт сверху
 * вниз от сметы, разворачивая расписание. Админу он покажет пустую страницу, а история
 * по клиенту ему нужна целиком. Поэтому здесь обратный ход — от готовых логов задач,
 * без расписаний и горизонтов. Задачник этот сервис не использует и не меняется.
 *
 * Только чтение и только выполненное: `status = 'completed'` — терминальный статус
 * в обеих ветках, и с проверкой, и без.
 *
 * Объём: выборка всегда по одному клиенту, это сотни строк за год — поэтому оба
 * источника сливаются и фильтруются в PHP, без UNION. Фильтр по документам всё равно
 * требует счётчиков по всем строкам, так что срез страницы делается последним.
 */
class ClientTaskHistory
{
    public const PER_PAGE = 20;

    /** Значения фильтра по документам. */
    public const DOCS_ALL     = 'all';
    public const DOCS_WITH    = 'with';
    public const DOCS_WITHOUT = 'without';

    public const DOCS_FILTERS = [self::DOCS_ALL, self::DOCS_WITH, self::DOCS_WITHOUT];

    /**
     * Кто видит историю задач клиента: админ и руководитель — по всем клиентам,
     * главбух — по тем, где он ответственный. Формула та же, что у проверки задач
     * в `BuhTasksController::authorizeReview()`, новой модели прав не заводим.
     */
    public function canView(Employee $me, Client $client): bool
    {
        return $me->isAdmin()
            || $me->isManager()
            || (int) $client->responsible_employee_id === (int) $me->id;
    }

    /**
     * Страница истории. Возвращает готовые для JSON строки + мета пагинации.
     *
     * @return array{items: array<int, array<string, mixed>>, page: int, per_page: int, total: int, last_page: int}
     */
    public function page(
        Client $client,
        string $docsFilter = self::DOCS_ALL,
        int $page = 1,
        int $perPage = self::PER_PAGE,
    ): array {
        $docsFilter = in_array($docsFilter, self::DOCS_FILTERS, true) ? $docsFilter : self::DOCS_ALL;
        $page       = max(1, $page);
        $perPage    = max(1, $perPage);

        $rows = $this->plannedRows($client)
            ->concat($this->adhocRows($client))
            ->pipe(fn (Collection $all) => $this->applyDocsFilter($all, $docsFilter))
            // Свежие сверху. Вторичный ключ по uid — чтобы порядок не «дышал» между
            // страницами у задач, закрытых в одну и ту же секунду.
            ->sortBy([
                fn ($a, $b) => ($b['completed_at_sort'] ?? '') <=> ($a['completed_at_sort'] ?? ''),
                fn ($a, $b) => $b['uid'] <=> $a['uid'],
            ])
            ->values();

        $total    = $rows->count();
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page     = min($page, $lastPage);

        $items = $rows
            ->slice(($page - 1) * $perPage, $perPage)
            ->map(function (array $row) {
                unset($row['completed_at_sort']);
                return $row;
            })
            ->values()
            ->all();

        return [
            'items'     => $items,
            'page'      => $page,
            'per_page'  => $perPage,
            'total'     => $total,
            'last_page' => $lastPage,
        ];
    }

    /**
     * Карточка одной выполненной задачи для попапа: подпункты, документы и комментарии.
     * Грузится отдельным запросом по клику — в списке эти данные не нужны.
     *
     * Возвращает null, если задачи нет, она принадлежит другому клиенту или ещё не
     * закрыта: истории у неё пока не существует.
     *
     * @return array<string, mixed>|null
     */
    public function details(Client $client, string $type, int $id): ?array
    {
        return $type === 'adhoc'
            ? $this->adhocDetails($client, $id)
            : $this->plannedDetails($client, $id);
    }

    /** @return array<string, mixed>|null */
    private function plannedDetails(Client $client, int $id): ?array
    {
        $log = BuhTaskLog::query()
            ->whereKey($id)
            ->where('client_id', $client->id)
            ->where('status', 'completed')
            ->with([
                'estimateItem:id,parent_id,service_id,name,branch_label,periodicity',
                'estimateItem.service:id,description,comment,requires_document',
                'estimateItem.children:id,parent_id,service_id,name',
                'estimateItem.children.service:id,requires_document',
                'employee:id,full_name',
                'reviewer:id,full_name',
                'documents',
            ])
            ->first();

        if (!$log || !$log->estimateItem || $log->estimateItem->parent_id !== null) {
            return null;
        }

        $item = $log->estimateItem;

        return [
            'uid'                 => 'planned_' . $log->id,
            'type'                => 'planned',
            'name'                => $item->name,
            'branch_label'        => $item->branch_label,
            'periodicity'         => $item->periodicity,
            'reporting_period'    => $this->reportingPeriod($item, $log->year, $log->month, CarbonImmutable::now()->year),
            'due_date'            => $log->due_date?->toDateString(),
            'completed_at'        => $log->completed_at?->toDateTimeString(),
            'doer_name'           => $log->employee?->full_name,
            'reviewer_name'       => $log->reviewer?->full_name,
            'reviewed_at'         => $log->reviewed_at?->toDateTimeString(),
            'rework_count'        => (int) $log->rework_count,
            'force_closed'        => (bool) $log->force_closed,
            'force_close_comment' => $log->force_close_comment,
            'employee_comment'    => $log->employee_comment,
            'review_comment'      => $log->review_comment,
            'description'         => $item->service?->description,
            'comment'             => $item->service?->comment,
            'requires_document'   => (bool) $item->service?->requires_document,
            'documents'           => $this->documentsOf($log),
            'children'            => $this->plannedChildren($client, $log),
        ];
    }

    /**
     * Подпункты плановой задачи: позиции-дети сметы вместе со своими логами того же
     * периода. Лог подпункта может отсутствовать (до него не дошли) — показываем как
     * невыполненный, чтобы в истории было видно, что именно осталось неотмеченным.
     *
     * @return array<int, array<string, mixed>>
     */
    private function plannedChildren(Client $client, BuhTaskLog $log): array
    {
        $children = $log->estimateItem->children;
        if ($children->isEmpty()) {
            return [];
        }

        $childLogs = BuhTaskLog::query()
            ->where('client_id', $client->id)
            ->whereIn('estimate_item_id', $children->pluck('id'))
            ->with('documents')
            ->get()
            // Тот же период (+ дата слота у еженедельных), исполнитель не важен —
            // подпункт мог остаться за прежним бухгалтером после переназначения.
            ->filter(fn (BuhTaskLog $child) => (int) $child->year === (int) $log->year
                && (int) $child->month === (int) $log->month
                && $child->due_date?->toDateString() === $log->due_date?->toDateString())
            ->keyBy('estimate_item_id');

        return $children->map(function (EstimateItem $child) use ($childLogs) {
            $childLog = $childLogs->get($child->id);

            return [
                'id'                => $child->id,
                'name'              => $child->name,
                'status'            => $childLog?->status ?? 'pending',
                'requires_document' => (bool) $child->service?->requires_document,
                'documents'         => $childLog ? $this->documentsOf($childLog) : [],
            ];
        })->values()->all();
    }

    /** @return array<string, mixed>|null */
    private function adhocDetails(Client $client, int $id): ?array
    {
        $task = BuhAdhocTask::query()
            ->whereKey($id)
            ->where('client_id', $client->id)
            ->where('status', 'completed')
            ->with(['employee:id,full_name', 'reviewer:id,full_name', 'creator:id,full_name', 'documents'])
            ->first();

        if (!$task) {
            return null;
        }

        return [
            'uid'                 => 'adhoc_' . $task->id,
            'type'                => 'adhoc',
            'name'                => $task->name,
            'branch_label'        => null,
            'periodicity'         => null,
            'reporting_period'    => null,
            'due_date'            => $this->adhocDueDate($task),
            'completed_at'        => $task->completed_at?->toDateTimeString(),
            'doer_name'           => $task->employee?->full_name,
            'reviewer_name'       => $task->reviewer?->full_name,
            'reviewed_at'         => $task->reviewed_at?->toDateTimeString(),
            'rework_count'        => (int) $task->rework_count,
            'force_closed'        => false,
            'force_close_comment' => null,
            'employee_comment'    => $task->employee_comment,
            'review_comment'      => $task->review_comment,
            'description'         => $task->description,
            'comment'             => $task->clarification,
            'requires_document'   => false,
            // Кто поручил — только если задачу завёл кто-то другой.
            'assigned_by_name'    => $task->isAssignment() ? $task->creator?->full_name : null,
            'documents'           => $this->documentsOf($task),
            // У внеплановой подпункты — снимок чеклиста внутри самой задачи,
            // своих логов и документов у них нет.
            'children'            => array_map(
                fn (array $c) => $c + ['requires_document' => false, 'documents' => []],
                $task->checklistForView(),
            ),
        ];
    }

    /** @return array<int, array{id: int, name: string, url: string}> */
    private function documentsOf(BuhTaskLog|BuhAdhocTask $task): array
    {
        return $task->documents
            ->map(fn ($doc) => ['id' => $doc->id, 'name' => $doc->name, 'url' => $doc->url])
            ->values()
            ->all();
    }

    /**
     * Плановые задачи из сметы. Берём только корневые логи: подпункт чеклиста —
     * не самостоятельная задача, он живёт внутри своей.
     */
    private function plannedRows(Client $client): Collection
    {
        $logs = BuhTaskLog::query()
            ->where('client_id', $client->id)
            ->where('status', 'completed')
            ->with([
                'estimateItem:id,parent_id,service_id,name,branch_label,periodicity',
                'estimateItem.service:id,requires_document',
                'employee:id,full_name',
                'reviewer:id,full_name',
                'documents',
            ])
            ->get()
            // Лог без позиции сметы возможен только теоретически (позиция удаляется
            // каскадом вместе с логами), но пустая строка в истории хуже пропуска.
            ->filter(fn (BuhTaskLog $log) => $log->estimateItem && $log->estimateItem->parent_id === null);

        $childDocCounts = $this->childDocumentCounts($client, $logs);
        $currentYear    = CarbonImmutable::now()->year;

        return $logs->map(function (BuhTaskLog $log) use ($childDocCounts, $currentYear) {
            $item = $log->estimateItem;
            $key  = $this->childGroupKey($item->id, $log->year, $log->month, $log->due_date?->toDateString());

            // Документ мог быть приложен не к самой задаче, а к её подпункту —
            // для фильтра «без документов» это всё равно «документ есть».
            $docsCount = $log->documents->count() + ($childDocCounts[$key] ?? 0);

            return [
                'uid'               => 'planned_' . $log->id,
                'type'              => 'planned',
                'id'                => $log->id,
                'name'              => $item->name,
                'branch_label'      => $item->branch_label,
                'periodicity'       => $item->periodicity,
                'reporting_period'  => $this->reportingPeriod($item, $log->year, $log->month, $currentYear),
                'due_date'          => $log->due_date?->toDateString(),
                'completed_at'      => $log->completed_at?->toDateTimeString(),
                'completed_at_sort' => $log->completed_at?->toDateTimeString() ?? '',
                'doer_name'         => $log->employee?->full_name,
                'reviewer_name'     => $log->reviewer?->full_name,
                'reviewed_at'       => $log->reviewed_at?->toDateTimeString(),
                'rework_count'      => (int) $log->rework_count,
                'force_closed'      => (bool) $log->force_closed,
                'requires_document' => (bool) $item->service?->requires_document,
                'documents_count'   => $docsCount,
            ];
        });
    }

    /**
     * Внеплановые задачи. В смете их нет — в списке помечаются отдельно.
     * Подпункты у них — снимок чеклиста внутри самой задачи, отдельных логов
     * (а значит и своих документов) у подпунктов не бывает.
     */
    private function adhocRows(Client $client): Collection
    {
        return BuhAdhocTask::query()
            ->where('client_id', $client->id)
            ->where('status', 'completed')
            ->with(['employee:id,full_name', 'reviewer:id,full_name', 'documents'])
            ->get()
            ->map(fn (BuhAdhocTask $task) => [
                'uid'               => 'adhoc_' . $task->id,
                'type'              => 'adhoc',
                'id'                => $task->id,
                'name'              => $task->name,
                'branch_label'      => null,
                'periodicity'       => null,
                'reporting_period'  => null,
                'due_date'          => $this->adhocDueDate($task),
                'completed_at'      => $task->completed_at?->toDateTimeString(),
                'completed_at_sort' => $task->completed_at?->toDateTimeString() ?? '',
                'doer_name'         => $task->employee?->full_name,
                'reviewer_name'     => $task->reviewer?->full_name,
                'reviewed_at'       => $task->reviewed_at?->toDateTimeString(),
                'rework_count'      => (int) $task->rework_count,
                // Принудительного закрытия у внеплановых нет — флаг только на плановых.
                'force_closed'      => false,
                // Документ у внеплановой всегда опционален (как в задачнике).
                'requires_document' => false,
                'documents_count'   => $task->documents->count(),
            ]);
    }

    /**
     * Сколько документов висит на подпунктах каждой плановой задачи.
     * Ключ группировки — родительская позиция сметы + период (+ дата слота для
     * еженедельных): именно так подпункт сопоставляется со своей задачей в задачнике.
     * Исполнитель в ключ не входит — после переназначения подпункт мог остаться
     * за прежним бухгалтером, но документ всё равно относится к этой задаче.
     *
     * @param  Collection<int, BuhTaskLog>  $rootLogs
     * @return array<string, int>
     */
    private function childDocumentCounts(Client $client, Collection $rootLogs): array
    {
        $rootItemIds = $rootLogs->pluck('estimateItem.id')->unique()->values();
        if ($rootItemIds->isEmpty()) {
            return [];
        }

        $childItems = EstimateItem::query()
            ->whereIn('parent_id', $rootItemIds)
            ->get(['id', 'parent_id']);
        if ($childItems->isEmpty()) {
            return [];
        }

        $parentByChildItem = $childItems->pluck('parent_id', 'id');

        // Статус подпункта не важен: документ приложен — значит он есть.
        $childLogs = BuhTaskLog::query()
            ->where('client_id', $client->id)
            ->whereIn('estimate_item_id', $childItems->pluck('id'))
            ->withCount('documents')
            ->get(['id', 'estimate_item_id', 'year', 'month', 'due_date']);

        $counts = [];
        foreach ($childLogs as $childLog) {
            if ($childLog->documents_count === 0) {
                continue;
            }

            $parentItemId = $parentByChildItem[$childLog->estimate_item_id] ?? null;
            if (!$parentItemId) {
                continue;
            }

            $key = $this->childGroupKey(
                (int) $parentItemId,
                (int) $childLog->year,
                (int) $childLog->month,
                $childLog->due_date?->toDateString(),
            );
            $counts[$key] = ($counts[$key] ?? 0) + $childLog->documents_count;
        }

        return $counts;
    }

    private function childGroupKey(int $parentItemId, int $year, int $month, ?string $dueDate): string
    {
        return $parentItemId . ':' . $year . ':' . $month . ':' . ($dueDate ?? '');
    }

    /** @param  Collection<int, array<string, mixed>>  $rows */
    private function applyDocsFilter(Collection $rows, string $filter): Collection
    {
        return match ($filter) {
            self::DOCS_WITH    => $rows->filter(fn (array $r) => $r['documents_count'] > 0),
            self::DOCS_WITHOUT => $rows->filter(fn (array $r) => $r['documents_count'] === 0),
            default            => $rows,
        };
    }

    /** «за июль», «за 2 квартал», «за 2025 год». У еженедельных периода словами нет. */
    private function reportingPeriod(EstimateItem $item, int $year, int $month, int $currentYear): ?string
    {
        if (!$item->periodicity) {
            return null;
        }

        return Service::reportingPeriodLabel(
            Service::kindForPeriodicity($item->periodicity),
            CarbonImmutable::create($year, $month, 1),
            $currentYear,
        );
    }

    /** Дата срока внеплановой задачи — только если день задан (как в задачнике). */
    private function adhocDueDate(BuhAdhocTask $task): ?string
    {
        if (!$task->due_day) {
            return null;
        }

        $firstDay = CarbonImmutable::create($task->year, $task->month, 1);

        return $firstDay->day(min((int) $task->due_day, $firstDay->daysInMonth))->toDateString();
    }
}
