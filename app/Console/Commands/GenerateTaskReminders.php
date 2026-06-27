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
 *  - ограниченное окно: назад на --lookback (бэкфилл просрочки) и вперёд на --horizon;
 *  - прунинг: НЕвыполненные напоминания в окне, которые больше не подкреплены
 *    активным БП (убрали из сметы / сменили расписание), удаляются. Выполненные — никогда.
 */
class GenerateTaskReminders extends Command
{
    protected $signature = 'tasks:generate
                            {--horizon=45 : На сколько дней вперёд генерировать}
                            {--lookback=190 : На сколько дней назад бэкфиллить просрочку (с запасом покрывает 6 месяцев; показ ограничен 6 мес на странице)}
                            {--date= : База расчёта (YYYY-MM-DD), по умолчанию сегодня}';

    protected $description = 'Сгенерировать напоминания о сроках выполнения БП для ответственных сотрудников';

    public function handle(): int
    {
        $today = $this->option('date')
            ? CarbonImmutable::parse($this->option('date'))->startOfDay()
            : CarbonImmutable::now()->startOfDay();
        $horizon  = max(1, (int) $this->option('horizon'));
        $lookback = max(0, (int) $this->option('lookback'));
        // Окно материализации: назад на $lookback (бэкфилл просрочки) и вперёд на $horizon.
        $from = $today->subDays($lookback);
        $to   = $today->addDays($horizon);

        $this->info("Генерация напоминаний: {$from->toDateString()} .. {$to->toDateString()} (назад {$lookback} дн., вперёд {$horizon} дн.)");

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
                $dates = $service->dueDatesForClient($override, $from, $to);
                if (empty($dates)) {
                    continue; // нет расписания → не плодим пустые задачи
                }

                foreach ($dates as $date) {
                    $dueStr = $date->toDateString();
                    // Филиальные копии БП имеют один service_id, но разные НО — ключуем с учётом НО.
                    $office = $item->tax_office_code;
                    $activeKeys["{$item->service_id}|{$office}|{$dueStr}"] = true;

                    $reminder = TaskReminder::updateOrCreate(
                        [
                            'employee_id'     => $employee->id,
                            'client_id'       => $client->id,
                            'service_id'      => $item->service_id,
                            'tax_office_code' => $office,
                            'due_date'        => $dueStr,
                        ],
                        [
                            'branch_label' => $item->branch_label,
                            'name'         => $item->name,
                            'periodicity'  => $item->periodicity,
                        ],
                    );

                    $reminder->wasRecentlyCreated ? $created++ : $refreshed++;
                    $clientHadWork = true;
                }
            }

            // Прунинг: pending-напоминания в окне [$from, $to], потерявшие активный БП
            // (убрали из сметы / сменили расписание). Выполненные не трогаем.
            $stale = TaskReminder::query()
                ->where('employee_id', $employee->id)
                ->where('client_id', $client->id)
                ->where('status', TaskReminder::STATUS_PENDING)
                ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
                ->get()
                ->filter(fn ($r) => !isset($activeKeys["{$r->service_id}|{$r->tax_office_code}|{$r->due_date->toDateString()}"]));

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
