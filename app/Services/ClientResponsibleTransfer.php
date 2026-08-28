<?php

namespace App\Services;

use App\Models\BuhAdhocTask;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\TaskReminder;
use Illuminate\Database\Eloquent\Builder;

/**
 * Смена ответственного у клиента переносит на нового всю незакрытую работу,
 * которая стояла лично на прежнем.
 *
 * Зачем: ответственный (`clients.responsible_employee_id`) и исполнитель позиции
 * сметы (`estimate_items.assignee_id`) — разные поля, и раньше первое меняли, а
 * второе оставалось. Клиент числился за новым сотрудником, а задачи по нему
 * продолжали генерироваться на прежнего (генератор идёт по `assignee_id`), и в
 * смете стоял тоже прежний. У уволенного сотрудника такая работа зависала совсем:
 * прунинг напоминаний неактивных сотрудников не трогает.
 *
 * Что переносим:
 *   - позиции сметы, где исполнителем стоял прежний ответственный (и позиции без
 *     исполнителя: они и так работали по ответственному, просто проставляем явно);
 *   - незакрытые напоминания (`task_reminders`) этого клиента;
 *   - незакрытые внеплановые задачи (`buh_adhoc_tasks`) этого клиента.
 *
 * Чего НЕ переносим:
 *   - позиции, отданные другим бухгалтерам: сменился главбух, а не они;
 *   - логи плановых задач (`buh_task_logs`): кто что делал — история, её не переписываем.
 *     Задачник и так рассчитан на переназначение (см. `BuhTasksController::logForEmployee`):
 *     закрытые периоды видит любой исполнитель БП, а чужая незаконченная работа личная;
 *   - выполненные напоминания и задачи в статусе проверки: они уже сданы, а проверяющий
 *     и без переноса вычисляется по текущему ответственному.
 *
 * `preview()` считает то же самое, но ничего не меняет: окно подтверждения показывает
 * человеку ровно те цифры, которые получатся после сохранения.
 */
class ClientResponsibleTransfer
{
    /** Внеплановая задача, которую ещё делать. Статусы те же, что в списке задачника. */
    private const ADHOC_OPEN = ['pending', 'running', 'paused', 'rework'];

    /**
     * Перенести работу после того, как у клиента уже сохранён новый ответственный.
     *
     * @param  int|null  $previousId  кто был ответственным до сохранения
     * @return array{items:int, reminders:int, adhoc:int} сколько строк перешло
     */
    public function apply(Client $client, ?int $previousId): array
    {
        $result = ['items' => 0, 'reminders' => 0, 'adhoc' => 0];

        $newId = $this->id($client->responsible_employee_id);
        $oldId = $this->id($previousId);

        // Ответственного сняли или он не менялся — переносить некому и незачем.
        if ($newId === null || $newId === $oldId) {
            return $result;
        }

        $result['items']     = $this->itemsQuery($client, $oldId)->update(['assignee_id' => $newId]);
        $result['reminders'] = $this->moveReminders($client, $oldId, $newId);
        $result['adhoc']     = $oldId === null ? 0 : $this->adhocQuery($client, $oldId)
            ->update(['employee_id' => $newId, 'assign_seen_at' => null]);

        return $result;
    }

    /**
     * Что произойдёт, если у этого клиента сменить ответственного на $newId. Только чтение:
     * зовётся из окна подтверждения до сохранения.
     *
     * @return array<string, mixed>
     */
    public function preview(Client $client, ?int $newId): array
    {
        $oldId = $this->id($client->responsible_employee_id);
        $newId = $this->id($newId);

        $names = Employee::whereIn('id', array_filter([$oldId, $newId]))->pluck('full_name', 'id');
        $new   = $newId ? Employee::find($newId) : null;

        $preview = [
            'changed'       => $newId !== $oldId,
            'from'          => $oldId ? ['id' => $oldId, 'name' => $names->get($oldId)] : null,
            'to'            => $newId ? ['id' => $newId, 'name' => $names->get($newId)] : null,
            'items'         => 0,
            'reminders'     => 0,
            'adhoc'         => 0,
            'review'        => 0,
            'stays'         => [],
            // Исполнителей в смете назначает только главбух клиента или админ
            // (EstimateController::canAssign). Если новый ответственный не главбух,
            // он не сможет раздавать БП дальше — про это честнее предупредить сразу.
            'to_can_assign' => $new ? ($new->isHeadAccountant() || $new->isAdmin()) : false,
        ];

        if (!$preview['changed'] || $newId === null) {
            return $preview;
        }

        $preview['items']     = $this->itemsQuery($client, $oldId)->count();
        $preview['reminders'] = $oldId === null ? 0 : $this->remindersQuery($client, $oldId)->count();
        $preview['adhoc']     = $oldId === null ? 0 : $this->adhocQuery($client, $oldId)->count();
        $preview['review']    = $this->reviewCount($client);
        $preview['stays']     = $this->staysWithOthers($client, $oldId, $newId);

        return $preview;
    }

    /**
     * Позиции сметы, которые переедут. Только корневые: у подпунктов исполнителя нет,
     * задачи идут от родителя.
     */
    private function itemsQuery(Client $client, ?int $oldId): Builder
    {
        return EstimateItem::query()
            ->whereIn('estimate_id', Estimate::where('client_id', $client->id)->select('id'))
            ->whereNull('parent_id')
            ->where(function ($q) use ($oldId) {
                $q->whereNull('assignee_id');
                if ($oldId !== null) {
                    $q->orWhere('assignee_id', $oldId);
                }
            });
    }

    /** Незакрытые напоминания прежнего ответственного. Выполненные — история, их не трогаем. */
    private function remindersQuery(Client $client, int $oldId): Builder
    {
        return TaskReminder::query()
            ->where('client_id', $client->id)
            ->where('employee_id', $oldId)
            ->where('status', TaskReminder::STATUS_PENDING);
    }

    /** Внеплановые задачи прежнего ответственного, которые ещё делать. */
    private function adhocQuery(Client $client, int $oldId): Builder
    {
        return BuhAdhocTask::query()
            ->where('client_id', $client->id)
            ->where('employee_id', $oldId)
            ->whereIn('status', self::ADHOC_OPEN);
    }

    /**
     * Перенос напоминаний.
     *
     * Ключ таблицы (employee, client, service, due_date) уникален, поэтому напоминание,
     * которое у нового исполнителя уже есть, не переносим, а убираем: иначе перенос
     * упал бы на дубликате. Такое бывает, когда новый ответственный уже вёл часть БП
     * этого клиента.
     */
    private function moveReminders(Client $client, ?int $oldId, int $newId): int
    {
        if ($oldId === null) {
            return 0;
        }

        $stale = $this->remindersQuery($client, $oldId)->get();

        if ($stale->isEmpty()) {
            return 0;
        }

        $taken = TaskReminder::query()
            ->where('client_id', $client->id)
            ->where('employee_id', $newId)
            ->get()
            ->map(fn ($r) => $this->reminderKey($r))
            ->flip();

        $moved = 0;

        foreach ($stale as $reminder) {
            $key = $this->reminderKey($reminder);

            if ($taken->has($key)) {
                $reminder->delete();
                continue;
            }

            $reminder->update(['employee_id' => $newId]);
            $taken[$key] = true;
            $moved++;
        }

        return $moved;
    }

    private function reminderKey(TaskReminder $reminder): string
    {
        return $reminder->service_id . '|' . $reminder->due_date?->toDateString();
    }

    /**
     * Сколько задач по клиенту ждут проверки. Не переносим их и никак не трогаем, но
     * проверять теперь будет новый ответственный: проверяющий нигде не хранится, он
     * вычисляется по `responsible_employee_id`. Для человека это сюрприз, поэтому цифра
     * в окне есть.
     */
    private function reviewCount(Client $client): int
    {
        return BuhTaskLog::where('client_id', $client->id)->where('status', 'review')->count()
            + BuhAdhocTask::where('client_id', $client->id)->where('status', 'review')->count();
    }

    /**
     * Позиции сметы, которые остаются у других бухгалтеров: сменился главбух, а не они.
     *
     * @return array<int, array{name:string, count:int}>
     */
    private function staysWithOthers(Client $client, ?int $oldId, int $newId): array
    {
        $counts = EstimateItem::query()
            ->whereIn('estimate_id', Estimate::where('client_id', $client->id)->select('id'))
            ->whereNull('parent_id')
            ->whereNotNull('assignee_id')
            ->whereNotIn('assignee_id', array_filter([$oldId, $newId]))
            ->selectRaw('assignee_id, count(*) as total')
            ->groupBy('assignee_id')
            ->pluck('total', 'assignee_id');

        if ($counts->isEmpty()) {
            return [];
        }

        $names = Employee::whereIn('id', $counts->keys())->pluck('full_name', 'id');

        return $counts
            ->map(fn ($total, $id) => [
                'name'  => $names->get($id) ?? 'Сотрудник #' . $id,
                'count' => (int) $total,
            ])
            ->sortBy('name')
            ->values()
            ->all();
    }

    private function id($value): ?int
    {
        return $value ? (int) $value : null;
    }
}
