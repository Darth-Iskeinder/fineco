<?php

namespace App\Services\Dashboard;

use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Обход сметных задач фирмы за окно дат.
 *
 * Логи задач создаются лениво, поэтому план месяца нельзя взять из buh_task_logs:
 * его выводят из смет, как это делает БухЗадачник. Здесь этот вывод живёт в одном
 * месте, чтобы страница руководителя и «Микромир» считали один и тот же набор
 * задач. Сам обход ничего не считает, он только отдаёт каждую задачу с её логом.
 */
final class PlannedTaskWalk
{
    /**
     * @param Collection<int, Client>     $clients  клиенты со сметой, по имени
     * @param Collection<int, BuhTaskLog> $logRows  логи без «Передана», по id
     */
    private function __construct(
        public readonly Collection $clients,
        public readonly Collection $logRows,
        private readonly Collection $services,
        private readonly Collection $logs,
        private readonly Collection $logsByMonth,
        private readonly CarbonImmutable $from,
        private readonly CarbonImmutable $to,
        private readonly CarbonImmutable $today,
    ) {}

    /**
     * @param CarbonImmutable $from          начало окна генерации сроков
     * @param CarbonImmutable $to            конец окна
     * @param int             $logsFromYear  с какого года читать логи
     */
    public static function load(CarbonImmutable $from, CarbonImmutable $to, int $logsFromYear, CarbonImmutable $today): self
    {
        $clients = Client::query()
            ->with([
                'clientStatus',
                'serviceSchedules',
                'estimates' => fn ($q) => $q->with(['rootItems' => fn ($q2) => $q2
                    ->whereNull('parent_id')
                    ->orderBy('sort_order'),
                ]),
            ])
            ->whereHas('estimates.rootItems', fn ($q) => $q->whereNull('parent_id'))
            ->orderBy('name')
            ->get();

        $serviceIds = $clients
            ->flatMap(fn ($c) => $c->estimates->first()?->rootItems ?? collect())
            ->pluck('service_id')->filter()->unique()->values();
        $services = $serviceIds->isNotEmpty()
            ? Service::whereIn('id', $serviceIds)->get()->keyBy('id')
            : collect();

        // Ключ лога это «слот» (year-month-item[-дата для weekly]). После переназначения
        // у слота могут быть логи разных исполнителей, берём последний. «Передана» не
        // участвует: задача засчитывается тому, кто делает её сейчас. Иначе запись
        // прежнего исполнителя, как самая свежая, выдавала бы задачу за «в работе».
        $logRows = BuhTaskLog::where('year', '>=', $logsFromYear)
            ->where('status', '<>', BuhTaskLog::STATUS_HANDED)
            ->orderBy('id')
            ->get();

        $logs = $logRows->keyBy(fn ($l) => $l->year . '-' . $l->month . '-' . $l->estimate_item_id
            . ($l->due_date ? '-' . $l->due_date->toDateString() : ''));

        // Второй, свободный индекс: у части помесячных логов срок всё-таки проставлен,
        // и точный ключ по ним не сходится, закрытая задача выглядела незакрытой.
        // Для недельных им пользоваться нельзя: там дата и есть различитель вхождений.
        $logsByMonth = $logRows->keyBy(fn ($l) => $l->year . '-' . $l->month . '-' . $l->estimate_item_id);

        return new self($clients, $logRows, $services, $logs, $logsByMonth, $from, $to, $today);
    }

    /**
     * Отдаёт каждую сметную задачу окна в $fn(PlannedTask). Клиенты идут по имени,
     * позиции в порядке сметы, вхождения по сроку.
     */
    public function each(callable $fn): void
    {
        foreach ($this->clients as $client) {
            $this->walkClient($client, $fn);
        }
    }

    private function walkClient(Client $client, callable $fn): void
    {
        $items = $client->estimates->first()?->rootItems ?? collect();
        if ($items->isEmpty()) {
            return;
        }

        [$clientFrom, $clientTo] = $this->clientWindow($client);
        $overrides = $client->serviceSchedules->keyBy('service_id');

        foreach ($items as $item) {
            $assigneeId = (int) ($item->assignee_id ?? $client->responsible_employee_id);

            // Своя граница позиции: БП, добавленный в смету в середине месяца,
            // начинает работать со следующего. У позиций, заведённых до появления
            // границы, и у разовых доп. услуг она пуста, окно остаётся клиентским.
            $itemFrom = $clientFrom;
            if ($itemStart = $item->tasksStartFrom()) {
                $itemFrom = $itemStart->gt($itemFrom) ? $itemStart : $itemFrom;
            }

            // Верхняя граница позиции: закрытый БП после своей даты задач не даёт.
            // Дальше конца обслуживания клиента она в любом случае не уходит.
            $itemTo = $clientTo;
            if ($closedAt = $item->tasksEndAt()) {
                $itemTo = $closedAt->lt($itemTo) ? $closedAt : $itemTo;
            }

            $service  = $item->service_id ? $this->services->get($item->service_id) : null;
            $override = $service ? $overrides->get($item->service_id) : null;
            $resolved = $service ? $service->resolveForClient($override) : null;
            $kind     = $resolved ? Service::kindForPeriodicity($resolved['periodicity']) : null;

            // Экземпляры задачи: [год, месяц, срок|null]
            $occurrences = [];
            if (!empty($resolved['periodicity'])) {
                foreach ($service->dueDatesForClient($override, $itemFrom, $itemTo) as $due) {
                    $occurrences[] = [$due->year, $due->month, $due];
                }
            } elseif ($this->today->startOfMonth()->gte($itemFrom) && $this->today->startOfMonth()->lte($itemTo)) {
                // Позиции без расписания дают задачу текущего месяца (как в задачнике),
                // но не в холостой месяц только что заведённой сметы
                $dueDay = $item->due_day ? min((int) $item->due_day, $this->today->daysInMonth) : null;
                $occurrences[] = [
                    $this->today->year,
                    $this->today->month,
                    $dueDay ? $this->today->startOfMonth()->addDays($dueDay - 1) : null,
                ];
            }

            foreach ($occurrences as [$year, $month, $due]) {
                $slotKey = ($kind === 'weekly' && $due) ? '-' . $due->toDateString() : '';
                $log     = $this->logs->get($year . '-' . $month . '-' . $item->id . $slotKey);

                if (!$log && $kind !== 'weekly') {
                    $log = $this->logsByMonth->get($year . '-' . $month . '-' . $item->id);
                }

                $fn(new PlannedTask($client, $item, $assigneeId, $year, $month, $due, $log));
            }
        }
    }

    /**
     * Окно клиента: те же границы, что в BuhTasksController. Экраны обязаны
     * показывать один и тот же набор задач.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    private function clientWindow(Client $client): array
    {
        $estimate = $client->estimates->first();

        // Не генерируем даты раньше старта обслуживания клиента и раньше начала
        // генерации по смете (месяц её создания холостой).
        $from = $this->from;
        if ($client->service_start_date) {
            $serviceStart = CarbonImmutable::parse($client->service_start_date)->startOfDay();
            if ($serviceStart->gt($from)) {
                $from = $serviceStart;
            }
        }
        if ($estimate && $estimate->tasksStartFrom()->gt($from)) {
            $from = $estimate->tasksStartFrom();
        }

        // Возврат после перерыва: за месяцы простоя задач нет. Без этой границы
        // приостановленный клиент продолжал давать план, и он оседал на том, за
        // кем клиент числится, в том числе на уволенном.
        if ($resumedFrom = $client->tasksStartFrom()) {
            if ($resumedFrom->gt($from)) {
                $from = $resumedFrom;
            }
        }

        // Верхняя граница это конец обслуживания, если он назначен.
        $to = $this->to;
        if ($serviceEnd = $client->serviceEndsAt()) {
            if ($serviceEnd->lt($to)) {
                $to = $serviceEnd;
            }
        }

        return [$from, $to];
    }
}
