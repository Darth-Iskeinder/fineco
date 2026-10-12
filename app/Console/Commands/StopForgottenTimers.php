<?php

namespace App\Console\Commands;

use App\Models\BuhAdhocTask;
use App\Models\BuhTaskLog;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Вечерняя пауза забытых таймеров.
 *
 * Человек нажал «Старт» и ушёл домой: таймер шёл бы всю ночь, и по задаче набегали бы
 * лишние часы. Каждый вечер в 20:00 по Бишкеку все идущие таймеры встают на паузу, а в 03:00
 * то, что запустили после 20:00 (засчитано не больше двух часов и не дальше полуночи).
 * Время засчитывается до этого момента, а не до запуска команды: опоздавший на пару минут
 * крон или пропущенный прогон не прибавят человеку лишнего. Правило в clockDailyStopAfterResume().
 *
 * Без --apply только показывает, что поставит на паузу. Повторный запуск безопасен:
 * уже стоящие на паузе задачи не трогаются.
 */
class StopForgottenTimers extends Command
{
    protected $signature = 'buhtasks:stop-forgotten-timers
                            {--apply : Поставить на паузу; без него только показать}
                            {--tenant= : Только одна фирма (id); по умолчанию все}';

    protected $description = 'Поставить на паузу забытые таймеры (прогоны в 20:00 и 03:00 по Бишкеку)';

    public function handle(): int
    {
        $tenants = Tenant::real()
            ->when($this->option('tenant'), fn ($q) => $q->whereKey((int) $this->option('tenant')))
            ->orderBy('id')
            ->get();

        $total = 0;
        foreach ($tenants as $tenant) {
            $total += TenantContext::for($tenant, fn () => $this->stopInTenant($tenant));
        }

        $this->info(($this->option('apply') ? 'Поставлено на паузу: ' : 'Будет поставлено на паузу: ') . $total);

        return self::SUCCESS;
    }

    private function stopInTenant(Tenant $tenant): int
    {
        $now     = now();
        $stopped = 0;

        foreach ([BuhTaskLog::class, BuhAdhocTask::class] as $model) {
            foreach ($model::where('status', 'running')->pluck('id') as $id) {
                $stopped += (int) DB::transaction(function () use ($model, $id, $now, $tenant) {
                    $task = $model::find($id);
                    if (!$task) {
                        return false;
                    }

                    // Тот же замок, что у кнопки «Старт»: если человек как раз сейчас
                    // переключает задачу, ждём его и смотрим на свежий статус.
                    $model::lockClockOwner($task->employee_id);
                    $task->refresh();

                    $stopAt = $task->clockDailyStopAfterResume() ?? $now;
                    if ($task->status !== 'running' || $stopAt->greaterThan($now)) {
                        return false; // ещё не забыт: например, запущен вечером, а два часа не прошли
                    }

                    $this->line(sprintf('  %s #%d, сотрудник %d, засчитано до %s',
                        $model === BuhTaskLog::class ? 'плановая' : 'внеплановая',
                        $task->id, $task->employee_id,
                        $stopAt->copy()->setTimezone($model::CLOCK_TIMEZONE)->format('d.m.Y H:i'),
                    ) . "  [{$tenant->name}]");

                    if ($this->option('apply')) {
                        $task->pauseClockAt($stopAt);
                    }

                    return true;
                });
            }
        }

        return $stopped;
    }
}
