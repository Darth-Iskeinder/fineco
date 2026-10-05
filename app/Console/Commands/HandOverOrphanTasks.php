<?php

namespace App\Console\Commands;

use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Tenant;
use App\Services\TaskHandover;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Разовая уборка: записи, повисшие при смене исполнителя до появления статуса «Передана».
 *
 * Дальше передача идёт сама при сохранении сметы и смене ответственного (TaskHandover),
 * а старые записи так и остались «на паузе» у прежних исполнителей, невидимые никому.
 * Команда находит их теми же правилами и переводит в «Передана». Время не трогает.
 *
 * Без --apply только показывает. Повторный запуск безопасен.
 */
class HandOverOrphanTasks extends Command
{
    protected $signature = 'buhtasks:hand-over-orphans
                            {--apply : Перевести в «Передана»; без него только показать}
                            {--tenant= : Только одна фирма (id); по умолчанию все}';

    protected $description = 'Перевести в «Передана» незакрытые задачи, у которых сменился исполнитель';

    public function handle(): int
    {
        $tenants = Tenant::real()
            ->when($this->option('tenant'), fn ($q) => $q->whereKey((int) $this->option('tenant')))
            ->orderBy('id')
            ->get();

        $total = 0;
        foreach ($tenants as $tenant) {
            $total += TenantContext::for($tenant, fn () => $this->inTenant($tenant));
        }

        $this->info(($this->option('apply') ? 'Изменено записей: ' : 'Будет изменено записей: ') . $total);

        return self::SUCCESS;
    }

    private function inTenant(Tenant $tenant): int
    {
        $names = Employee::withTrashed()->pluck('full_name', 'id');
        $count = 0;

        $clientIds = BuhTaskLog::whereIn('status', ['pending', 'running', 'paused', 'rework', BuhTaskLog::STATUS_HANDED])
            ->distinct()
            ->pluck('client_id');

        foreach (Client::whereIn('id', $clientIds)->orderBy('id')->get() as $client) {
            foreach ((new TaskHandover())->forClient($client, (bool) $this->option('apply')) as $change) {
                $log = $change['log'];
                $this->line(sprintf('  #%d | %s | %s | %d-%02d | %s | %s | %s',
                    $log->id, $client->name, $log->estimateItem?->name, $log->year, $log->month,
                    sprintf('%d ч %02d мин', intdiv($log->paused_seconds, 3600), intdiv($log->paused_seconds % 3600, 60)),
                    $names[$log->employee_id] ?? '?',
                    $change['action'] === 'handed'
                        ? 'передать → ' . ($names[$change['to']] ?? '?')
                        : 'вернуть прежнему исполнителю',
                ) . "  [{$tenant->name}]");
                $count++;
            }
        }

        return $count;
    }
}
