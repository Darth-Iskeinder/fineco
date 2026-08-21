<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\Tenant;
use App\Models\Employee;
use App\Models\Service;
use App\Models\TaskReminder;
use App\Support\TenantContext;
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
                            {--date= : База расчёта (YYYY-MM-DD), по умолчанию сегодня}
                            {--tenant= : Только один аккаунт (id или slug); по умолчанию все}';

    protected $description = 'Сгенерировать напоминания о сроках выполнения БП для ответственных сотрудников';

    /**
     * Отсечка backlog: не генерируем/не бэкфиллим напоминания со сроком РАНЬШЕ этой
     * даты (разовая очистка накопившейся просрочки до июля 2026). Единая с живым
     * списком (BuhTasksController::BACKLOG_CUTOFF).
     */
    private const BACKLOG_CUTOFF = '2026-07-01';

    /**
     * Проход по аккаунтам: каждый обрабатывается в своём контексте.
     *
     * Без этого воркер шёл бы по всей базе разом, а созданные напоминания
     * получали бы фирму по умолчанию — то есть напоминания второй фирмы
     * достались бы первой, а вторая осталась бы без задач и пропустила сроки.
     * Ошибки на экране при этом не было бы никакой.
     */
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

            $status = TenantContext::for($tenant, fn () => $this->generateForCurrentTenant());

            if ($status !== self::SUCCESS) {
                return $status;
            }
        }

        return self::SUCCESS;
    }

    /** Генерация в пределах текущей фирмы: её клиенты, её БП, её сотрудники. */
    private function generateForCurrentTenant(): int
    {
        $today = $this->option('date')
            ? CarbonImmutable::parse($this->option('date'))->startOfDay()
            : CarbonImmutable::now()->startOfDay();
        $horizon  = max(1, (int) $this->option('horizon'));
        $lookback = max(0, (int) $this->option('lookback'));
        // Окно материализации: назад на $lookback (бэкфилл просрочки) и вперёд на $horizon.
        $from = $today->subDays($lookback);
        $to   = $today->addDays($horizon);
        // Отсечка backlog: не уходим раньше июля 2026 (разовая очистка накопившейся просрочки).
        $cutoff = CarbonImmutable::parse(self::BACKLOG_CUTOFF)->startOfDay();
        if ($cutoff->gt($from)) {
            $from = $cutoff;
        }

        $this->info("Генерация напоминаний: {$from->toDateString()} .. {$to->toDateString()} (назад {$lookback} дн., вперёд {$horizon} дн.)");

        $clients = Client::query()
            ->whereNotNull('responsible_employee_id')
            ->with([
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

        // Сотрудники: для резолва исполнителя позиции (assignee) и проверки активности при прунинге.
        $employees = Employee::all()->keyBy('id');

        $created = 0;
        $refreshed = 0;
        $pruned = 0;
        $clientsTouched = 0;

        foreach ($clients as $client) {
            $estimate = $client->estimates->first();
            if (!$estimate) {
                continue;
            }

            $overrides = $client->serviceSchedules->keyBy('service_id');
            $activeKeys = [];        // "employee|service|office|due", которые должны существовать
            $clientHadWork = false;

            // За конец обслуживания задачи не заводим. Окно прунинга при этом остаётся
            // общим: напоминания после этой даты не попадут в $activeKeys и уйдут как
            // устаревшие — а всё, что внутри периода, продолжает жить по прежним правилам.
            $clientTo = $client->serviceEndsAt();
            $clientTo = $clientTo && $clientTo->lt($to) ? $clientTo : $to;

            // Обслуживание закончилось раньше окна — заводить нечего. Из цикла при этом
            // не выходим: клиенту нужен прунинг, иначе напоминания, созданные до
            // завершения, остались бы висеть навсегда.
            $serviceOver = $clientTo->lt($from);

            foreach ($serviceOver ? [] : $estimate->rootItems as $item) {
                // Исполнитель позиции: assignee_id, при пустом — ответственный клиента.
                $assigneeId = $item->assignee_id ?? $client->responsible_employee_id;
                $employee = $employees->get($assigneeId);
                if (!$employee || $employee->status !== Employee::STATUS_ACTIVE) {
                    continue; // нет активного исполнителя — пропускаем позицию
                }

                $service = $services->get($item->service_id);
                if (!$service) {
                    continue;
                }

                $override = $overrides->get($item->service_id);

                // Пересечение трёх окон: прогона, обслуживания клиента и рабочего
                // периода БП. У действующих БП период не ограничен и ничего не режет.
                [$bpFrom, $bpTo] = $service->workPeriod();
                $itemFrom = $bpFrom && $bpFrom->gt($from) ? $bpFrom : $from;
                $itemTo   = $bpTo && $bpTo->lt($clientTo) ? $bpTo : $clientTo;

                if ($itemTo->lt($itemFrom)) {
                    continue; // БП вне работы в этом окне — заводить нечего
                }

                $dates = $service->dueDatesForClient($override, $itemFrom, $itemTo);
                if (empty($dates)) {
                    continue; // нет расписания → не плодим пустые задачи
                }

                foreach ($dates as $date) {
                    $dueStr = $date->toDateString();
                    // Филиальные копии БП имеют один service_id, но разные НО — ключуем с учётом НО.
                    $office = $item->tax_office_code;
                    $activeKeys["{$employee->id}|{$item->service_id}|{$office}|{$dueStr}"] = true;

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

            // Прунинг: pending-напоминания клиента в окне [$from, $to], потерявшие активный БП
            // (убрали из сметы / сменили расписание / переназначили на другого исполнителя).
            // Напоминания неактивных сотрудников не трогаем (как раньше). Выполненные — никогда.
            $stale = TaskReminder::query()
                ->where('client_id', $client->id)
                ->where('status', TaskReminder::STATUS_PENDING)
                ->whereBetween('due_date', [$from->toDateString(), $to->toDateString()])
                ->get()
                ->filter(function ($r) use ($activeKeys, $employees) {
                    $emp = $employees->get($r->employee_id);
                    if (!$emp || $emp->status !== Employee::STATUS_ACTIVE) {
                        return false; // сотрудник неактивен — сохраняем напоминание
                    }
                    $key = "{$r->employee_id}|{$r->service_id}|{$r->tax_office_code}|{$r->due_date->toDateString()}";
                    return !isset($activeKeys[$key]);
                });

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
