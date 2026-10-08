<?php

namespace App\Console\Commands;

use App\Models\AutoAuditWatchClient;
use App\Models\AutoAuditWatchRun;
use App\Support\TenantContext;
use Illuminate\Console\Command;

/**
 * Журнал наблюдения за прогоном автоаудита (AutoAuditWatch): сколько клиентов и сверок
 * нужно было проверять на самом деле и были ли тревоги.
 *
 * Ничего не меняет: только читает журнал и печатает.
 */
class AutoAuditWatchReport extends Command
{
    protected $signature = 'autoaudit:watch
        {--tenant= : Фирма (id)}
        {--runs=14 : Сколько последних прогонов показать}';

    protected $description = 'Показать, кого прогон автоаудита мог бы пропустить и были ли тревоги';

    public function handle(): int
    {
        $tenant = (int) $this->option('tenant');

        if (!$tenant) {
            $this->error('Укажите фирму: --tenant=1');

            return self::FAILURE;
        }

        return TenantContext::for($tenant, function () {
            $runs = AutoAuditWatchRun::latest('id')->limit(max(1, (int) $this->option('runs')))->get();

            if ($runs->isEmpty()) {
                $this->line('Журнал пуст: после выкатки наблюдения прогонов ещё не было');

                return self::SUCCESS;
            }

            $this->table(
                ['Когда', 'Кто', 'Клиентов', 'Нужно', 'Сверок', 'По клиенту', 'По месяцам', 'Тревог', 'По месяцам', 'Сек'],
                $runs->map(fn (AutoAuditWatchRun $r) => [
                    $r->created_at->setTimezone(config('app.timezone'))->format('d.m H:i'),
                    $r->trigger === AutoAuditWatchRun::NIGHT ? 'ночь' : 'команда',
                    $r->clients,
                    $r->clients_needed,
                    $r->checks,
                    $r->checks_by_client,
                    $r->checks_by_month,
                    $r->alarms,
                    $r->alarms_by_month,
                    $r->seconds,
                ])->all(),
            );

            $this->line('Нужно: клиентов, у которых что-то поменялось. Сверок: сколько сделал прогон.');
            $this->line('По клиенту и по месяцам: сколько сверок нужно было бы при экономии.');
            $this->line('Тревога: снимок тот же, а итог сверки поменялся. Пока они есть, экономию не включаем.');

            $this->details($runs->first());

            return self::SUCCESS;
        });
    }

    /** Последний прогон: кого и почему нужно было проверять, и тревоги с ключами строк. */
    private function details(AutoAuditWatchRun $run): void
    {
        $clients = $run->clients()->with('client:id,name')->get();

        $this->newLine();
        $this->line('Последний прогон по причинам:');

        foreach ($clients->groupBy('reason') as $reason => $group) {
            $this->line(sprintf('  %s: %d', AutoAuditWatchClient::REASONS[$reason] ?? $reason, $group->count()));
        }

        $alarms = $clients->filter(fn (AutoAuditWatchClient $c) => $c->alarm || $c->alarm_by_month);

        if ($alarms->isEmpty()) {
            $this->line('Тревог нет');

            return;
        }

        $this->newLine();
        $this->warn('Тревоги:');

        foreach ($alarms as $alarm) {
            $this->line(sprintf(
                '  %s (%s%s): %s',
                $alarm->client?->name ?? "клиент {$alarm->client_id}",
                AutoAuditWatchClient::REASONS[$alarm->reason] ?? $alarm->reason,
                $alarm->months ? ', задеты ' . implode(', ', $alarm->months) : '',
                implode('; ', $alarm->changed),
            ));
        }
    }
}
