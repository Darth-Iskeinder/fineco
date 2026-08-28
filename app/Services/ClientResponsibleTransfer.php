<?php

namespace App\Services;

use App\Models\BuhAdhocTask;
use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\TaskReminder;

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

        $newId = $client->responsible_employee_id ? (int) $client->responsible_employee_id : null;
        $oldId = $previousId ? (int) $previousId : null;

        // Ответственного сняли или он не менялся — переносить некому и незачем.
        if ($newId === null || $newId === $oldId) {
            return $result;
        }

        $result['items']     = $this->moveEstimateItems($client, $oldId, $newId);
        $result['reminders'] = $this->moveReminders($client, $oldId, $newId);
        $result['adhoc']     = $this->moveAdhocTasks($client, $oldId, $newId);

        return $result;
    }

    /**
     * Позиции сметы. Только корневые: у подпунктов исполнителя нет, задачи идут
     * от родителя.
     */
    private function moveEstimateItems(Client $client, ?int $oldId, int $newId): int
    {
        return EstimateItem::query()
            ->whereIn('estimate_id', Estimate::where('client_id', $client->id)->select('id'))
            ->whereNull('parent_id')
            ->where(function ($q) use ($oldId) {
                $q->whereNull('assignee_id');
                if ($oldId !== null) {
                    $q->orWhere('assignee_id', $oldId);
                }
            })
            ->update(['assignee_id' => $newId]);
    }

    /**
     * Напоминания о сроках. Выполненные не трогаем — это история прежнего сотрудника.
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

        $stale = TaskReminder::query()
            ->where('client_id', $client->id)
            ->where('employee_id', $oldId)
            ->where('status', TaskReminder::STATUS_PENDING)
            ->get();

        if ($stale->isEmpty()) {
            return 0;
        }

        $taken = TaskReminder::query()
            ->where('client_id', $client->id)
            ->where('employee_id', $newId)
            ->get()
            ->map(fn ($r) => $r->service_id . '|' . $r->due_date?->toDateString())
            ->flip();

        $moved = 0;

        foreach ($stale as $reminder) {
            $key = $reminder->service_id . '|' . $reminder->due_date?->toDateString();

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

    /**
     * Внеплановые задачи. `assign_seen_at` сбрасываем: для нового исполнителя задача
     * новая, и в списке она должна подсветиться как непросмотренная.
     */
    private function moveAdhocTasks(Client $client, ?int $oldId, int $newId): int
    {
        if ($oldId === null) {
            return 0;
        }

        return BuhAdhocTask::query()
            ->where('client_id', $client->id)
            ->where('employee_id', $oldId)
            ->whereIn('status', self::ADHOC_OPEN)
            ->update(['employee_id' => $newId, 'assign_seen_at' => null]);
    }
}
