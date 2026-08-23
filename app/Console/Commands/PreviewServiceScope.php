<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\ClientServiceCatalog;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Что сужение по типу обслуживания отрезает у клиентов.
 *
 * Ничего не меняет: только показывает, какие БП не подтягиваются клиенту из-за
 * его типа обслуживания. До включения сужения это был отчёт «что изменится»,
 * после — способ объяснить, почему в смете нет ожидаемого БП.
 *
 * Ответ берём у ClientServiceCatalog, то есть у того же кода, который собирает
 * смету. Отдельная реализация «для отчёта» рано или поздно разошлась бы с ней.
 */
class PreviewServiceScope extends Command
{
    protected $signature = 'clients:scope-preview
                            {--tenant= : Только один аккаунт (id или slug); по умолчанию все}
                            {--all : Показать и тех клиентов, у кого ничего не отрезано}';

    protected $description = 'Показать, какие БП не подтягиваются клиентам из-за типа обслуживания';

    public function handle(): int
    {
        $tenants = Tenant::real()
            ->when($this->option('tenant'), function ($q) {
                $value = $this->option('tenant');
                $q->where(is_numeric($value) ? 'id' : 'slug', $value);
            })
            ->orderBy('id')
            ->get();

        if ($tenants->isEmpty()) {
            $this->error('Аккаунты не найдены');

            return self::FAILURE;
        }

        foreach ($tenants as $tenant) {
            $this->newLine();
            $this->line("<info>Аккаунт:</info> {$tenant->name}");

            TenantContext::for($tenant, fn () => $this->previewForCurrentTenant());
        }

        return self::SUCCESS;
    }

    private function previewForCurrentTenant(): void
    {
        $catalog = new ClientServiceCatalog();

        $total    = Service::roots()->active()->count();
        $untyped  = Service::roots()->active()->whereNull('service_type')->count();

        $this->line("  Каталог: {$total} активных БП, из них без типа {$untyped}.");

        if ($untyped === $total) {
            $this->warn('  Ни одному БП тип не проставлен — сужение ничего не отрезает.');
        }

        $clients = Client::active()->orderBy('name')->get();

        if ($clients->isEmpty()) {
            $this->line('  Клиентов нет.');

            return;
        }

        $affected = 0;

        foreach ($clients as $client) {
            $drops = $catalog->narrowedAwayFor($client);

            if ($drops->isEmpty() && !$this->option('all')) {
                continue;
            }

            if ($drops->isNotEmpty()) {
                $affected++;
            }

            $scope = $client->servesEverything()
                ? 'полное обслуживание'
                : implode(' + ', $client->serviceTypeLabels());

            $this->newLine();
            $this->line("  <comment>{$client->name}</comment> ({$scope})");

            if ($drops->isEmpty()) {
                $this->line('    ничего не отрезано');

                continue;
            }

            foreach ($drops as $bp) {
                $type = Service::serviceTypeLabel($bp->service_type);
                $this->line("    не подтягивается: {$bp->name} [{$type}]");
            }
        }

        $this->newLine();
        $this->line($affected === 0
            ? '  Итог: ни у одного клиента тип обслуживания ничего не отрезает.'
            : "  Итог: отрезано у {$affected} клиентов из {$clients->count()}.");
    }
}
