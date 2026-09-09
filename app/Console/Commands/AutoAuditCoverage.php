<?php

namespace App\Console\Commands;

use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Service;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Сколько клиентов вообще попадёт под первую проверку авто-аудита — разведка перед работой.
 *
 * Правило применимо, когда у клиента кассовый метод учёта и полное обслуживание (все три
 * отметки сразу), а к задачам приложены оба документа за один период. Если под это
 * подходит два клиента из сорока, знать об этом надо до того, как проверка написана,
 * а не после.
 *
 * Ничего не меняет: только считает и печатает.
 */
class AutoAuditCoverage extends Command
{
    protected $signature = 'autoaudit:coverage {--tenant= : Фирма (id)}';

    protected $description = 'Показать, скольких клиентов охватит первая проверка';

    public function handle(): int
    {
        $tenant = (int) $this->option('tenant');

        if (!$tenant) {
            $this->error('Укажите фирму: --tenant=1');

            return self::FAILURE;
        }

        return TenantContext::for($tenant, function () {
            $full = fn ($q) => $q->where('serves_accounting', true)
                ->where('serves_tax', true)
                ->where('serves_payroll', true);

            $total   = Client::count();
            $cash    = Client::where('accounting_method', Client::ACCOUNTING_CASH)->count();
            $served  = Client::where($full)->count();
            $matched = Client::where('accounting_method', Client::ACCOUNTING_CASH)->where($full)->get();

            foreach ([
                'Клиентов всего'                   => $total,
                '  с кассовым методом'             => $cash,
                '  с полным обслуживанием'         => $served,
                '  и то, и другое — правило про них' => $matched->count(),
            ] as $label => $count) {
                $this->line($this->pad($label . ':', 36) . $count);
            }

            // Метод учёта у части клиентов может быть просто не заполнен — это не «метод
            // начисления», а пробел в карточке, и лечится он заполнением, а не кодом.
            $unknown = Client::whereNull('accounting_method')->count();

            if ($unknown) {
                $this->newLine();
                $this->warn("Метод учёта не указан у {$unknown} клиентов — правило их не увидит.");
            }

            $this->documents($matched);

            return self::SUCCESS;
        });
    }

    /** У скольких подходящих клиентов реально есть оба документа за один период. */
    private function documents($clients): void
    {
        $tax = Service::where('reference_id', 3)->first();
        $osv = Service::where('reference_id', 11)->first();

        if (!$tax || !$osv) {
            $this->newLine();
            $this->warn('В фирме размечены не оба эталонных БП — пары не собрать.');

            return;
        }

        $this->newLine();
        $this->line('Документы у подходящих клиентов:');

        $withBoth = 0;

        foreach ($clients as $client) {
            $counts = [];

            foreach ([3 => $tax, 11 => $osv] as $ref => $service) {
                $counts[$ref] = BuhTaskLog::where('client_id', $client->id)
                    ->whereHas('estimateItem', fn ($q) => $q->where('service_id', $service->id))
                    ->whereHas('documents')
                    ->count();
            }

            if ($counts[3] && $counts[11]) {
                $withBoth++;
            }

            $this->line(sprintf(
                '  %s %s  отчётов по налогу: %-3d ведомостей: %d',
                $counts[3] && $counts[11] ? '<fg=green>+</>' : '<fg=yellow>-</>',
                $this->pad($client->name, 32),
                $counts[3],
                $counts[11],
            ));
        }

        $this->newLine();
        $this->line("С обоими документами: {$withBoth} из " . $clients->count());
    }

    /** str_pad считает байты, а не буквы: с кириллицей столбцы разъезжаются. */
    private function pad(string $text, int $width): string
    {
        $text = mb_substr($text, 0, $width - 1);

        return $text . str_repeat(' ', max(1, $width - mb_strlen($text)));
    }
}
