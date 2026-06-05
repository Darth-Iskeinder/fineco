<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Service;
use App\Models\TaskReminder;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Воркер: материализует напоминания о сроках выполнения БП на горизонт вперёд.
 *
 * Безопасность («не вхолостую»):
 *  - идемпотентно: updateOrCreate по (employee, client, service, due_date) — без дублей,
 *    статус и время выполнения существующих напоминаний не трогаются;
 *  - только реальные обязательства: БП должен быть включён в смету клиента, у клиента есть
 *    ответственный активный сотрудник, и у БП вычислимое расписание (иначе пропускаем);
 *  - учитывает индивидуальный срок клиента (ClientServiceSchedule) через dueDatesForClient;
 *  - ограниченный горизонт;
 *  - прунинг: будущие НЕвыполненные напоминания в окне, которые больше не подкреплены
 *    активным БП (убрали из сметы / сменили расписание), удаляются. Выполненные — никогда.
 */
class GenerateTaskReminders extends Command
{
    protected $signature = 'tasks:generate
                            {--horizon=45 : На сколько дней вперёд генерировать}
                            {--date= : База расчёта (YYYY-MM-DD), по умолчанию сегодня}';

    protected $description = 'Сгенерировать напоминания о сроках выполнения БП для ответственных сотрудников';

    public function handle(): int
    {
        $today = $this->option('date')
            ? CarbonImmutable::parse($this->option('date'))->startOfDay()
            : CarbonImmutable::now()->startOfDay();
        $horizon = max(1, (int) $this->option('horizon'));
        $to = $today->addDays($horizon);

        $this->info("Генерация напоминаний: {$today->toDateString()} .. {$to->toDateString()} (горизонт {$horizon} дн.)");

        $clients = Client::query()
            ->whereNotNull('responsible_employee_id')
            ->with([
                'responsibleEmployee',
                'serviceSchedules',
                'estimates.rootItems' => fn ($q) => $q->whereNull('parent_id')->whereNotNull('service_id'),
            ])
            ->get();

        // Предзагрузка БП одним запросом (нужны их методы расписания)
        $serviceIds = $clients
            ->flatMap(fn ($c) => $c->estimates->flatMap->rootItems->pluck('service_id'))
            ->filter()->unique()->values();
        $services = $serviceIds->isNotEmpty()
            ? Service::whereIn('id', $serviceIds)->get()->keyBy('id')
            : collect();

        $created = 0;
        $refreshed = 0;
        $pruned = 0;
        $clientsTouched = 0;

        foreach ($clients as $client) {
            $employee = $client->responsibleEmployee;
            if (!$employee || $employee->status !== Employee::STATUS_ACTIVE) {
                continue;
            }

            $estimate = $client->estimates->first();
            if (!$estimate) {
                continue;
            }

            $overrides = $client->serviceSchedules->keyBy('service_id');
            $activeKeys = [];        // service_id|due_date, которые должны существовать
            $clientHadWork = false;

            foreach ($estimate->rootItems as $item) {
                $service = $services->get($item->service_id);
                if (!$service) {
                    continue;
                }

                $override = $overrides->get($item->service_id);
                $dates = $service->dueDatesForClient($override, $today, $to);
                if (empty($dates)) {
                    continue; // нет расписания → не плодим пустые задачи
                }

                foreach ($dates as $date) {
                    $dueStr = $date->toDateString();
                    $activeKeys["{$item->service_id}|{$dueStr}"] = true;

                    $reminder = TaskReminder::updateOrCreate(
                        [
                            'employee_id' => $employee->id,
                            'client_id'   => $client->id,
                            'service_id'  => $item->service_id,
                            'due_date'    => $dueStr,
                        ],
                        [
                            'name'        => $item->name,
                            'periodicity' => $item->periodicity,
                        ],
                    );

                    $reminder->wasRecentlyCreated ? $created++ : $refreshed++;
                    $clientHadWork = true;
                }
            }

            // Прунинг: будущие pending-напоминания в окне, потерявшие активный БП
            $stale = TaskReminder::query()
                ->where('employee_id', $employee->id)
                ->where('client_id', $client->id)
                ->where('status', TaskReminder::STATUS_PENDING)
                ->whereBetween('due_date', [$today->toDateString(), $to->toDateString()])
                ->get()
                ->filter(fn ($r) => !isset($activeKeys["{$r->service_id}|{$r->due_date->toDateString()}"]));

            if ($stale->isNotEmpty()) {
                TaskReminder::whereIn('id', $stale->pluck('id'))->delete();
                $pruned += $stale->count();
            }

            if ($clientHadWork) {
                $clientsTouched++;
            }
        }

        $this->info("Готово. Клиентов: {$clientsTouched} · создано: {$created} · обновлено: {$refreshed} · убрано устаревших: {$pruned}");

        return self::SUCCESS;
    }
}
