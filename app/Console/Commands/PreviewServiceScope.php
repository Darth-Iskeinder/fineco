<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\ClientServiceCatalog;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Что изменится, когда сужение по типу обслуживания включат.
 *
 * Ничего не меняет: только показывает, какие БП перестанут подтягиваться каждому
 * клиенту. Смысл в том, чтобы увидеть последствия до выкатки, а не разбираться
 * потом в живых сметах.
 *
 * Ответ берём у ClientServiceCatalog, то есть у того же кода, который собирает
 * смету. Отдельная реализация «для отчёта» рано или поздно разошлась бы с ней.
 */
class PreviewServiceScope extends Command
{
    protected $signature = 'clients:scope-preview
                            {--tenant= : Только один аккаунт (id или slug); по умолчанию все}
                            {--all : Показать и тех клиентов, у кого ничего не изменится}';

    protected $description = 'Показать, какие БП перестанут подтягиваться клиентам после включения сужения по типу обслуживания';

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
            $this->warn('  Ни одному БП тип не проставлен — включать сужение пока нечего.');
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
                $this->line('    ничего не изменится');

                continue;
            }

            foreach ($drops as $bp) {
                $type = Service::serviceTypeLabel($bp->service_type);
                $this->line("    убрать: {$bp->name} [{$type}]");
            }
        }

        $this->newLine();
        $this->line($affected === 0
            ? '  Итог: ни у одного клиента состав сметы не изменится.'
            : "  Итог: изменится у {$affected} клиентов из {$clients->count()}.");
    }
}
