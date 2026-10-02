<?php

namespace App\Console\Commands;

use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\ClientStatus;
use App\Models\TaskReminder;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Починка расхождения «статус говорит стоп, а клиент живой».
 *
 * На бою у «Завершен» не было флагов (сидер там не запускали), и смена статуса
 * в карточке возвращала клиента в работу: `is_active` включался, дата остановки
 * стиралась, задачи по смете шли дальше. Код теперь подстрахован, а данные надо
 * привести в порядок один раз:
 *  - флаги системных статусов в справочнике (он общий на все фирмы);
 *  - клиентам фирмы с останавливающим статусом: `is_active = false` и дата
 *    остановки. Где даты нет, её задаёт человек через --stopped-at: истории смены
 *    статуса в базе нет, угадывать нельзя.
 *
 * Невыполненные напоминания после даты остановки уберёт следующий tasks:generate.
 * Задачи бухгалтеров команда не трогает, только показывает.
 *
 * По умолчанию ничего не меняет, только печатает план. Меняет с --apply.
 * Повторный запуск безопасен: починенное уже не попадает в выборку.
 */
class SyncClientServiceStatus extends Command
{
    protected $signature = 'clients:sync-service-status
                            {--tenant= : Фирма (id)}
                            {--stopped-at= : Дата остановки (YYYY-MM-DD) для клиентов, у которых её нет}
                            {--apply : Применить изменения; без флага только показать}';

    protected $description = 'Привести статус клиента, флаг активности и дату остановки к одному';

    public function handle(): int
    {
        $tenant = (int) $this->option('tenant');

        if (!$tenant) {
            $this->error('Укажите фирму: --tenant=1');

            return self::FAILURE;
        }

        $stoppedAt = $this->option('stopped-at');
        if ($stoppedAt && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $stoppedAt)) {
            $this->error('Дата остановки в формате YYYY-MM-DD');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $this->line($apply ? '<comment>Режим: применяю изменения</comment>' : '<info>Режим: только показываю (--apply применит)</info>');

        $this->syncStatusFlags($apply);

        return TenantContext::for($tenant, fn () => $this->syncClients($stoppedAt, $apply));
    }

    /** Справочник статусов: колонки догоняют смысл названия. */
    private function syncStatusFlags(bool $apply): void
    {
        $this->newLine();
        $this->line('<info>Справочник статусов</info>');

        $broken = ClientStatus::orderBy('id')->get()->filter(fn (ClientStatus $s) => $s->hasLostFlags());

        if ($broken->isEmpty()) {
            $this->line('Флаги на месте, править нечего.');

            return;
        }

        $this->table(
            ['id', 'Статус', 'closes_service: было → станет', 'stops_tasks: было → станет'],
            $broken->map(fn (ClientStatus $s) => [
                $s->id,
                $s->name,
                $this->flag($s->getRawOriginal('closes_service')) . ' → ' . $this->flag($s->closes_service),
                $this->flag($s->getRawOriginal('stops_tasks')) . ' → ' . $this->flag($s->stops_tasks),
            ])->all(),
        );

        if (!$apply) {
            return;
        }

        foreach ($broken as $status) {
            DB::table('client_statuses')->where('id', $status->id)->update([
                'closes_service' => $status->closes_service,
                'stops_tasks'    => $status->stops_tasks,
                'updated_at'     => now(),
            ]);
        }

        $this->info('Флаги исправлены: ' . $broken->count());
    }

    private function syncClients(?string $stoppedAt, bool $apply): int
    {
        $clients = Client::with('clientStatus')->orderBy('name')->get();

        $stopped = $clients->filter(fn (Client $c) => $c->clientStatus?->stops_tasks
            && ($c->is_active || $c->service_end_date === null));

        // Обратное расхождение: статус рабочий, а клиент выключен. Сами не чиним:
        // непонятно, что верно, статус или флаг. Показываем человеку.
        $suspicious = $clients->filter(fn (Client $c) => $c->clientStatus && !$c->clientStatus->stops_tasks
            && (!$c->is_active || $c->service_end_date !== null));

        $this->newLine();
        $this->line('<info>Клиенты с останавливающим статусом, но живые</info>');

        if ($stopped->isEmpty()) {
            $this->line('Таких нет.');
        } else {
            $this->table(
                ['id', 'Клиент', 'Статус', 'Активен', 'Дата остановки: было → станет', 'Напоминаний уйдёт', 'Задач после даты'],
                $stopped->map(fn (Client $c) => $this->clientRow($c, $stoppedAt))->all(),
            );
        }

        if ($suspicious->isNotEmpty()) {
            $this->newLine();
            $this->line('<comment>Статус рабочий, а клиент выключен или с датой остановки. Не трогаю, решите сами:</comment>');
            $this->table(
                ['id', 'Клиент', 'Статус', 'Активен', 'Дата остановки'],
                $suspicious->map(fn (Client $c) => [
                    $c->id, $c->name, $c->clientStatus->name, $this->flag($c->is_active),
                    $c->service_end_date?->toDateString() ?? 'нет',
                ])->all(),
            );
        }

        $withoutDate = $stopped->filter(fn (Client $c) => $c->service_end_date === null);
        if ($withoutDate->isNotEmpty() && !$stoppedAt) {
            $this->newLine();
            $this->error('У ' . $withoutDate->count() . ' клиентов нет даты остановки. Задайте её: --stopped-at=YYYY-MM-DD');

            return $apply ? self::FAILURE : self::SUCCESS;
        }

        if (!$apply || $stopped->isEmpty()) {
            return self::SUCCESS;
        }

        DB::transaction(function () use ($stopped, $stoppedAt) {
            foreach ($stopped as $client) {
                $client->forceFill([
                    'is_active'        => false,
                    'service_end_date' => $client->service_end_date?->toDateString() ?? $stoppedAt,
                ])->save();
            }
        });

        $this->newLine();
        $this->info('Клиенты исправлены: ' . $stopped->count());
        $this->line('Лишние напоминания уберёт следующий tasks:generate. Сразу: php artisan tasks:generate --tenant=' . TenantContext::id());

        return self::SUCCESS;
    }

    /** @return array<int, mixed> */
    private function clientRow(Client $client, ?string $stoppedAt): array
    {
        $end = $client->service_end_date?->toDateString() ?? $stoppedAt;

        return [
            $client->id,
            $client->name,
            $client->clientStatus->name,
            $this->flag($client->is_active) . ' → нет',
            ($client->service_end_date?->toDateString() ?? 'нет') . ' → ' . ($end ?? '?'),
            $end ? $this->countAfter(TaskReminder::query()->where('status', TaskReminder::STATUS_PENDING), $client, $end) : '?',
            $end ? $this->countAfter(BuhTaskLog::query(), $client, $end) : '?',
        ];
    }

    private function countAfter($query, Client $client, string $end): int
    {
        return $query->where('client_id', $client->id)
            ->where('due_date', '>', CarbonImmutable::parse($end)->toDateString())
            ->count();
    }

    private function flag(mixed $value): string
    {
        return $value ? 'да' : 'нет';
    }
}
