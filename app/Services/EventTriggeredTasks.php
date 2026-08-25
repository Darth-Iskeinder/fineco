<?php

namespace App\Services;

use App\Models\BuhAdhocTask;
use App\Models\BuhTaskLog;
use App\Models\Service;
use App\Support\ErrorReporter;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * «По событию»: выполнили задачу по БП-родителю, значит родилась задача по
 * дочернему БП. Тому же клиенту и тому же сотруднику, который выполнил.
 *
 * Родителем бывает и плановая задача (строка сметы, повторяется каждый период),
 * и разовая из каталога. Работает одинаково: каждый закрытый период даёт свою
 * дочернюю задачу.
 *
 * ГЛАВНОЕ ПРАВИЛО: закрытие задачи не может упасть из-за этого класса. Всё, что
 * тут происходит, происходит уже ПОСЛЕ сохранения статуса и целиком обёрнуто в
 * try/catch — см. afterCompleted(). Сломанная настройка, удалённый дочерний БП,
 * ошибка базы: сотрудник в любом случае увидит «задача закрыта».
 *
 * Ограничения, заложенные намеренно:
 *  - одна ступень. Задача, рождённая триггером, сама триггер не запускает,
 *    поэтому кольцо из двух БП даёт максимум одну лишнюю задачу;
 *  - один дочерний БП у родителя;
 *  - дочерняя задача разовая (buh_adhoc_tasks), цена 0, в смету не пишется.
 */
class EventTriggeredTasks
{
    /**
     * Точка входа: вызывается сразу после того, как задача стала completed.
     *
     * Задача с проверкой уходит в review, а не в completed, поэтому у неё
     * триггер срабатывает не на «выполнено», а на приёмке главбухом.
     *
     * @return BuhAdhocTask|null созданная дочерняя задача, если она создалась
     */
    public static function afterCompleted(BuhTaskLog|BuhAdhocTask $task): ?BuhAdhocTask
    {
        try {
            return self::spawn($task);
        } catch (Throwable $e) {
            // Журнал сбоев и файловый лог — но наружу молчим: закрытие уже состоялось.
            Log::warning('Не удалось создать задачу по событию', [
                'source' => $task::class . '#' . $task->id,
                'error'  => $e->getMessage(),
            ]);
            ErrorReporter::server($e);

            return null;
        }
    }

    /** Разбор настройки и создание. Любой отказ — тихий null, это нормальный ход. */
    private static function spawn(BuhTaskLog|BuhAdhocTask $task): ?BuhAdhocTask
    {
        // Одна ступень: рождённая триггером задача сама ничего не рождает.
        if ($task instanceof BuhAdhocTask && $task->isTriggered()) {
            return null;
        }

        $parent = self::serviceOf($task);
        if (!$parent || !$parent->firesOnEvent()) {
            return null;
        }

        $child = self::resolveChild($parent);
        if (!$child) {
            return null;
        }

        // Дубль: по одной задаче-родителю дочерняя создаётся ровно один раз.
        // Сброс и повторное закрытие второй копии не дадут.
        if (self::alreadySpawned($task)) {
            return null;
        }

        // Срок как у задач «по запросу»: день выполнения плюс календарные дни из
        // карточки дочернего БП. Дней нет (настройку успели испортить) — ставим
        // сегодняшний день, но задачу всё равно создаём: потерять её хуже.
        $due = CarbonImmutable::now()->addDays(max(0, (int) $child->deadline_days));

        return BuhAdhocTask::create([
            'employee_id'     => $task->employee_id,
            // Автор — сам исполнитель: задача не поручение, во вкладку «Я поручил»
            // не попадает и принимается по общим правилам своей задачи из каталога.
            'created_by'      => $task->employee_id,
            'assign_seen_at'  => now(),
            'rework_seen_at'  => now(),
            'client_id'       => $task->client_id,
            'service_id'      => $child->id,
            // Название, описание и подпункты — снимком, как в задаче из каталога:
            // правка БП задним числом не должна менять уже созданные задачи.
            'name'            => $child->name,
            'description'     => $child->description,
            'checklist'       => self::checklistOf($child),
            'requires_review' => (bool) $child->requires_review,
            'cost'            => 0,
            'year'            => $due->year,
            'month'           => $due->month,
            'due_day'         => $due->day,
            'status'          => 'pending',
            'paused_seconds'  => 0,
            'trigger_source_type' => $task::class,
            'trigger_source_id'   => $task->id,
            // Название родителя снимком: бейдж «по событию» показывает, откуда
            // задача взялась, и переживёт удаление самого родителя.
            'trigger_source_name' => self::nameOf($task),
        ]);
    }

    /**
     * Название задачи-родителя — ровно то, что сотрудник видел у себя в списке.
     * У плановой оно за строкой сметы и может отличаться от названия БП.
     */
    private static function nameOf(BuhTaskLog|BuhAdhocTask $task): ?string
    {
        return $task instanceof BuhTaskLog
            ? ($task->estimateItem?->name ?? self::serviceOf($task)?->name)
            : $task->name;
    }

    /** БП выполненной задачи: у плановой он за строкой сметы, у разовой лежит прямо на ней. */
    private static function serviceOf(BuhTaskLog|BuhAdhocTask $task): ?Service
    {
        return $task instanceof BuhTaskLog
            ? $task->estimateItem?->service
            : $task->service;
    }

    /**
     * Дочерний БП, если он всё ещё годится: существует, действует, не в архиве
     * и остался основным. Настройку могли испортить после того, как её задали,
     * поэтому проверяем каждый раз заново.
     */
    private static function resolveChild(Service $parent): ?Service
    {
        $child = Service::find($parent->event_child_service_id);

        if (!$child || $child->parent_id || !$child->is_active || $child->isArchived()) {
            Log::info('Задача по событию не создана: дочерний БП недоступен', [
                'parent_service_id' => $parent->id,
                'child_service_id'  => $parent->event_child_service_id,
            ]);

            return null;
        }

        return $child;
    }

    /** По этой задаче-родителю дочерняя уже рождалась. */
    private static function alreadySpawned(BuhTaskLog|BuhAdhocTask $task): bool
    {
        return BuhAdhocTask::where('trigger_source_type', $task::class)
            ->where('trigger_source_id', $task->id)
            ->exists();
    }

    /** Подпункты дочернего БП становятся чеклистом задачи — как при добавлении из каталога. */
    private static function checklistOf(Service $child): ?array
    {
        $items = $child->children()
            ->get(['id', 'parent_id', 'name', 'sort_order'])
            ->map(fn ($c) => ['name' => $c->name, 'done' => false])
            ->values();

        return $items->isNotEmpty() ? $items->all() : null;
    }
}
