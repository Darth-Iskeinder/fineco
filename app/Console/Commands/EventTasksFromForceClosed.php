<?php

namespace App\Console\Commands;

use App\Models\BuhAdhocTask;
use App\Models\BuhTaskLog;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Разведка по старым данным: задачи «по событию», родившиеся от принудительно
 * закрытых родителей.
 *
 * До сентября 2026 триггер срабатывал на любом закрытии, включая принудительное.
 * Теперь так не бывает, но задачи, рождённые по старому правилу, остались лежать
 * у людей в задачнике. Команда показывает их списком, чтобы решение принимал
 * человек: часть из них наверняка уже выполнена и трогать её не надо.
 *
 * Ничего не меняет и ничего не удаляет: только читает и печатает.
 */
class EventTasksFromForceClosed extends Command
{
    protected $signature = 'buhtasks:event-from-force-closed {--tenant= : Фирма (id)}';

    protected $description = 'Показать задачи «по событию», рождённые принудительно закрытой задачей';

    public function handle(): int
    {
        $tenant = (int) $this->option('tenant');

        // Фирму спрашиваем явно: задачи живут внутри фирмы, и без контекста
        // запрос к базе просто отобьётся (см. BelongsToTenant).
        if (!$tenant) {
            $this->error('Укажите фирму: --tenant=1');

            return self::FAILURE;
        }

        return TenantContext::for($tenant, fn () => $this->report());
    }

    private function report(): int
    {
        // Принудительно закрытые родители. Берём только плановые: у внеплановых
        // задач принудительного закрытия нет вовсе, значит и разбирать нечего.
        $forced = BuhTaskLog::where('force_closed', true)
            ->get(['id', 'year', 'month', 'force_close_comment'])
            ->keyBy('id');

        if ($forced->isEmpty()) {
            $this->info('Принудительно закрытых задач нет, разбирать нечего.');

            return self::SUCCESS;
        }

        $tasks = BuhAdhocTask::where('trigger_source_type', BuhTaskLog::class)
            ->whereIn('trigger_source_id', $forced->keys())
            ->with(['client:id,name', 'employee:id,full_name'])
            ->orderBy('id')
            ->get();

        $this->line('Принудительно закрытых задач: ' . $forced->count());
        $this->line('Из них родили задачу по событию: ' . $tasks->count());

        if ($tasks->isEmpty()) {
            $this->newLine();
            $this->info('Чистить нечего.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->table(
            ['Задача', 'Название', 'Клиент', 'Исполнитель', 'Статус', 'Родитель', 'Период', 'Причина закрытия'],
            $this->rows($tasks, $forced)->all(),
        );

        $this->newLine();
        $this->line('Выполненные из этого списка лучше оставить как есть: работа уже сделана.');
        $this->line('Команда ничего не изменила — что делать с остальными, решать человеку.');

        return self::SUCCESS;
    }

    private function rows(Collection $tasks, Collection $forced): Collection
    {
        return $tasks->map(function (BuhAdhocTask $task) use ($forced) {
            $parent = $forced->get($task->trigger_source_id);

            return [
                $task->id,
                $task->name,
                $task->client?->name ?? '—',
                $task->employee?->full_name ?? '—',
                $task->status,
                $task->trigger_source_id,
                $parent ? sprintf('%02d.%04d', $parent->month, $parent->year) : '',
                $this->short($parent?->force_close_comment),
            ];
        });
    }

    /** Причина бывает длинной, в таблице нужна только её суть. */
    private function short(?string $comment): string
    {
        $comment = trim((string) $comment);

        return mb_strlen($comment) > 40 ? mb_substr($comment, 0, 39) . '…' : $comment;
    }
}
