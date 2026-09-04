<?php

namespace App\Http\Controllers;

use App\Models\BuhAdhocTask;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Service;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Страница «Руководитель»: сводка по задачам всех сотрудников и компаний.
 * Только чтение. Плановые задачи выводятся из смет так же, как в БухЗадачнике
 * (логи создаются лениво, поэтому план месяца нельзя взять из buh_task_logs).
 */
class DashboardController extends Controller
{
    /** Единая отсечка backlog (см. BuhTasksController::BACKLOG_CUTOFF и воркер напоминаний). */
    private const BACKLOG_CUTOFF = '2026-07-01';

    /** Статусы «задача ещё у исполнителя» — только они считаются просрочкой (review — нет). */
    private const OPEN_STATUSES = ['pending', 'running', 'paused', 'rework'];

    private const MONTH_LABELS = [
        1 => 'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь',
        'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь',
    ];

    private const MONTH_SHORT = [
        1 => 'Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн',
        'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек',
    ];

    public function index(Request $request)
    {
        $today = CarbonImmutable::now()->startOfDay();

        $year  = max(2020, min(2030, (int) $request->get('year', $today->year)));
        $month = max(1, min(12, (int) $request->get('month', $today->month)));

        // Будущие месяцы не показываем — плана «вперёд» на дашборде нет
        if ($year * 12 + $month > $today->year * 12 + $today->month) {
            $year  = $today->year;
            $month = $today->month;
        }

        $monthStart = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $monthEnd   = $monthStart->endOfMonth();

        // Окно просрочки — как в задачнике: 6 месяцев назад, но не раньше отсечки backlog
        $cutoff        = CarbonImmutable::parse(self::BACKLOG_CUTOFF)->startOfDay();
        $lookbackStart = $today->subMonths(6)->max($cutoff);

        // Общее окно генерации дат: покрывает и выбранный месяц, и окно просрочки
        $scanFrom = $lookbackStart->min($monthStart);
        $scanTo   = $today->max($monthEnd);

        $clients = Client::query()
            ->with([
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

        // Логи всех сотрудников, ключ — «слот» (year-month-item[-дата для weekly]).
        // После переназначения у слота могут быть логи разных исполнителей — берём последний.
        $logRows = BuhTaskLog::where('year', '>=', min($scanFrom->year, $year))
            ->orderBy('id')
            ->get();

        $logs = $logRows->keyBy(fn ($l) => $l->year . '-' . $l->month . '-' . $l->estimate_item_id
            . ($l->due_date ? '-' . $l->due_date->toDateString() : ''));

        // Второй, свободный индекс: у части помесячных логов срок всё-таки проставлен,
        // и точный ключ по ним не сходится — закрытая задача выглядела незакрытой.
        // Для недельных им пользоваться нельзя: там дата и есть различитель вхождений.
        $logsByMonth = $logRows->keyBy(fn ($l) => $l->year . '-' . $l->month . '-' . $l->estimate_item_id);

        // Логи выбранного месяца, которые уже нашли свой слот. Всё закрытое, что осталось
        // за пределами этого списка, добавляем в разрез отдельно — см. ниже про сирот.
        $matchedLogIds = [];

        // Роль нужна для подписи в строке: разрез делится на главбухов и бухгалтеров
        $employees     = Employee::with('role:id,display_name')->get(['id', 'full_name', 'role_id']);
        $employeeNames = $employees->pluck('full_name', 'id');
        $employeeRoles = $employees->mapWithKeys(fn ($e) => [$e->id => $e->role?->display_name]);

        $stats = [
            'total'     => 0, // задач в выбранном месяце
            'adhoc'     => 0, // из них вне сметы
            'completed' => 0,
            'on_time'   => 0, // выполнены не позже срока
            'review'    => 0, // на проверке
            'in_progress' => 0, // в работе / на паузе / на доработке
            'pending'   => 0, // не начаты
            'elapsed'   => 0, // суммарные секунды по таймерам
            'with_time' => 0, // задач с ненулевым временем (для среднего)
        ];
        $overdue = [];

        // Дисциплина по месяцам (вне фильтра периода): от отсечки backlog, максимум 6 месяцев.
        // В месяц попадают только «рассуженные» задачи: закрытые и просроченные открытые
        $discipline = [];
        $dCursor    = $today->startOfMonth()->subMonths(5)->max($cutoff->startOfMonth());
        while ($dCursor->lte($today)) {
            $discipline[$dCursor->year . '-' . $dCursor->month] = [
                'label'   => self::MONTH_SHORT[$dCursor->month]
                    . ($dCursor->year !== $today->year ? ' ’' . ($dCursor->year % 100) : ''),
                'on_time' => 0,
                'late'    => 0,
                'overdue' => 0,
            ];
            $dCursor = $dCursor->addMonth();
        }

        /**
         * Разрез «по сотрудникам» — две группы, обе только по сметным задачам.
         *
         * `leads` — тот, на ком компания (clients.responsible_employee_id). В его
         * объём входят все задачи его компаний, включая розданные: спрашивают за
         * компанию с него. Внутри — доли исполнителей, из них рисуется кольцо.
         *
         * `people` — объём самого исполнителя, все его компании сразу. Поэтому
         * бухгалтер, работающий на двух главбухов, в своей строке один раз и со
         * всем объёмом, а в кольцах виден у обоих, каждый раз своей частью.
         *
         * Внеплановых тут нет: их объём не запланирован сметой, сравнивать по нему
         * людей нельзя. Из-за этого числа блока меньше, чем в сводке страницы.
         */
        $team = ['leads' => [], 'people' => []];

        // Разрез по компаниям: счётчики месяца + просрочка + смета и сом/час.
        // Показываем всё, что есть: клиент без времени/сметы получает «—» в этих колонках
        $byCompany = [];

        foreach ($clients as $client) {
            $estimate  = $client->estimates->first();
            $items     = $estimate?->rootItems ?? collect();
            $overrides = $client->serviceSchedules->keyBy('service_id');

            // Клиент со сметой виден всегда, даже без задач в выбранном месяце
            $this->ensureCompanyRow($byCompany, (int) $client->id, $client->name, (float) $items->sum('total'));

            // Не генерируем даты раньше старта обслуживания клиента и раньше начала
            // генерации по смете (месяц её создания холостой) — те же границы, что в задачнике.
            $clientFrom = $scanFrom;
            if ($client->service_start_date) {
                $serviceStart = CarbonImmutable::parse($client->service_start_date)->startOfDay();
                if ($serviceStart->gt($clientFrom)) {
                    $clientFrom = $serviceStart;
                }
            }
            if ($estimate && $estimate->tasksStartFrom()->gt($clientFrom)) {
                $clientFrom = $estimate->tasksStartFrom();
            }

            foreach ($items as $item) {
                $assigneeId = (int) ($item->assignee_id ?? $client->responsible_employee_id);

                // Своя граница позиции: БП, добавленный в смету в середине месяца,
                // начинает работать со следующего. У позиций, заведённых до появления
                // границы, и у разовых доп. услуг она пуста — окно остаётся клиентским.
                $itemFrom = $clientFrom;
                if ($itemStart = $item->tasksStartFrom()) {
                    $itemFrom = $itemStart->gt($itemFrom) ? $itemStart : $itemFrom;
                }

                // Верхняя граница позиции: закрытый БП после своей даты задач не даёт.
                $itemTo = $scanTo;
                if ($closedAt = $item->tasksEndAt()) {
                    $itemTo = $closedAt->lt($itemTo) ? $closedAt : $itemTo;
                }

                $service     = $item->service_id ? $services->get($item->service_id) : null;
                $override    = $service ? $overrides->get($item->service_id) : null;
                $resolved    = $service ? $service->resolveForClient($override) : null;
                $hasSchedule = !empty($resolved['periodicity']);
                $kind        = $resolved ? Service::kindForPeriodicity($resolved['periodicity']) : null;

                // Экземпляры задачи: [год, месяц, срок|null]
                $occurrences = [];
                if ($hasSchedule) {
                    foreach ($service->dueDatesForClient($override, $itemFrom, $itemTo) as $due) {
                        $occurrences[] = [$due->year, $due->month, $due];
                    }
                } elseif ($today->startOfMonth()->gte($itemFrom) && $today->startOfMonth()->lte($itemTo)) {
                    // Позиции без расписания — задача текущего месяца (как в задачнике),
                    // но не в холостой месяц только что заведённой сметы
                    $dueDay = $item->due_day ? min((int) $item->due_day, $today->daysInMonth) : null;
                    $occurrences[] = [$today->year, $today->month, $dueDay ? $today->startOfMonth()->addDays($dueDay - 1) : null];
                }

                foreach ($occurrences as [$wy, $wm, $dueObj]) {
                    $slotKey = ($kind === 'weekly' && $dueObj) ? '-' . $dueObj->toDateString() : '';
                    $log     = $logs->get($wy . '-' . $wm . '-' . $item->id . $slotKey);

                    if (!$log && $kind !== 'weekly') {
                        $log = $logsByMonth->get($wy . '-' . $wm . '-' . $item->id);
                    }

                    $status = $log?->status ?? 'pending';

                    if ($log && $wy === $year && $wm === $month) {
                        $matchedLogIds[$log->id] = true;
                    }

                    $this->classifyDiscipline($discipline, $wy, $wm, $dueObj, $status, $log?->completed_at, $today);

                    if ($wy === $year && $wm === $month) {
                        $elapsed = $this->calcElapsed($log);
                        $this->accumulate($stats, $status, $dueObj, $log?->completed_at, $elapsed);

                        // Закрытую задачу засчитываем тому, кто её закрыл, а не тому, за кем
                        // она числится сейчас: позиции переезжают при смене ответственного, и
                        // прошлый месяц иначе переписывается задним числом.
                        $this->accumulateTeam(
                            $team,
                            (int) $client->id,
                            $client->name,
                            (int) ($client->responsible_employee_id ?? 0),
                            $this->doerOf($status, $log, $assigneeId),
                            $status,
                            $dueObj,
                            $today,
                        );

                        $this->accumulate($byCompany[$client->id], $status, $dueObj, $log?->completed_at, $elapsed);
                    }

                    if ($dueObj && $dueObj->lt($today) && $dueObj->gte($cutoff)
                        && in_array($status, self::OPEN_STATUSES, true)) {
                        $byCompany[$client->id]['overdue']++;
                        $overdue[] = [
                            'name'        => $item->name . ($item->branch_label ? ' — ' . $item->branch_label : ''),
                            'client_name' => $client->name,
                            'assignee'    => $employeeNames->get($assigneeId) ?? 'Не назначено',
                            'due_date'    => $dueObj,
                            'days'        => (int) $dueObj->diffInDays($today),
                            'status'      => $status,
                            'is_adhoc'    => false,
                        ];
                    }
                }
            }
        }

        // Внеплановые задачи (вне сметы) — наравне с плановыми
        foreach (BuhAdhocTask::with('client:id,name')->get() as $a) {
            $dueObj = $a->due_day
                ? CarbonImmutable::create($a->year, $a->month, 1)
                    ->addDays(min((int) $a->due_day, CarbonImmutable::create($a->year, $a->month, 1)->daysInMonth) - 1)
                : null;

            $companyId = (int) ($a->client_id ?? 0);

            $this->classifyDiscipline($discipline, (int) $a->year, (int) $a->month, $dueObj, $a->status, $a->completed_at, $today);

            if ((int) $a->year === $year && (int) $a->month === $month) {
                $elapsed = $this->calcElapsed($a);
                $stats['adhoc']++;
                $this->accumulate($stats, $a->status, $dueObj, $a->completed_at, $elapsed);

                // Внеплановая может прийти от клиента без сметы или вовсе без клиента
                $this->ensureCompanyRow($byCompany, $companyId, $a->client?->name ?? 'Без компании', null);
                $byCompany[$companyId]['adhoc']++;
                $this->accumulate($byCompany[$companyId], $a->status, $dueObj, $a->completed_at, $elapsed);
            }

            if ($dueObj && $dueObj->lt($today) && $dueObj->gte($cutoff)
                && in_array($a->status, self::OPEN_STATUSES, true)) {
                $this->ensureCompanyRow($byCompany, $companyId, $a->client?->name ?? 'Без компании', null);
                $byCompany[$companyId]['overdue']++;
                $overdue[] = [
                    'name'        => $a->name,
                    'client_name' => $a->client?->name ?? '—',
                    'assignee'    => $employeeNames->get($a->employee_id) ?? 'Не назначено',
                    'due_date'    => $dueObj,
                    'days'        => (int) $dueObj->diffInDays($today),
                    'status'      => $a->status,
                    'is_adhoc'    => true,
                ];
            }
        }

        /**
         * Закрытые задачи, которым слота в этом месяце нет.
         *
         * Так выходит у позиций без периодичности (для них слот создаётся только на
         * текущий месяц, и в прошлом их работа исчезала) и у тех, чьё расписание после
         * закрытия поменяли. Работа сделана, и в разрезе по сотрудникам она должна быть,
         * иначе прошлый месяц занижен. В сводку и в разрез по компаниям такие задачи не
         * идут: там счёт по плану месяца, и трогать его отдельным решением.
         */
        $clientsById = $clients->keyBy('id');

        foreach ($logRows as $log) {
            if ((int) $log->year !== $year || (int) $log->month !== $month) {
                continue;
            }

            if ($log->status !== 'completed' || !$log->estimate_item_id || isset($matchedLogIds[$log->id])) {
                continue;
            }

            $client = $clientsById->get($log->client_id);
            if (!$client) {
                continue;
            }

            $this->accumulateTeam(
                $team,
                (int) $client->id,
                $client->name,
                (int) ($client->responsible_employee_id ?? 0),
                (int) $log->employee_id,
                'completed',
                null,
                $today,
            );
        }

        usort($overdue, fn ($x, $y) => [$y['days'], $x['client_name']] <=> [$x['days'], $y['client_name']]);

        $leads       = $this->buildLeads($team, $employeeNames, $employeeRoles);
        $accountants = $this->buildAccountants($team, $employeeNames, $employeeRoles);

        // сом/час = смета месяца ÷ часы по таймерам; без времени или без сметы — null («—»).
        // Колонки «Смета, сом» и «сом/час» пока скрыты в таблице (решение 15.07.2026), расчёт оставлен
        foreach ($byCompany as &$row) {
            $row['rate'] = ($row['estimate'] !== null && $row['elapsed'] > 0)
                ? (int) round($row['estimate'] / ($row['elapsed'] / 3600))
                : null;
            $row['time'] = $row['elapsed'] > 0 ? $this->formatDuration($row['elapsed']) : null;
        }
        unset($row);
        // Проблемные сверху: просрочка, затем объём задач месяца, затем имя
        uasort($byCompany, fn ($x, $y) =>
            [$y['overdue'], $y['total'], $x['name']] <=> [$x['overdue'], $x['total'], $y['name']]);

        // Компании по затраченному времени месяца — для горизонтальных баров:
        // топ-7 отдельными строками, остальные одной строкой «Остальные»
        $timeAll = array_values(array_filter($byCompany, fn ($r) => $r['elapsed'] > 0));
        usort($timeAll, fn ($x, $y) => $y['elapsed'] <=> $x['elapsed']);
        $timeTop  = array_slice($timeAll, 0, 7);
        $timeRest = null;
        if (count($timeAll) > 7) {
            $restElapsed = array_sum(array_column(array_slice($timeAll, 7), 'elapsed'));
            $timeRest = [
                'count'   => count($timeAll) - 7,
                'elapsed' => $restElapsed,
                'time'    => $this->formatDuration($restElapsed),
            ];
        }

        $prev = $monthStart->subMonth();
        $next = $monthStart->addMonth();

        return view('dashboard.index', [
            'year'         => $year,
            'month'        => $month,
            'monthLabel'   => self::MONTH_LABELS[$month] . ' ' . $year,
            'prev'         => ['year' => $prev->year, 'month' => $prev->month],
            'next'         => ['year' => $next->year, 'month' => $next->month],
            'isCurrent'    => $year === $today->year && $month === $today->month,
            'stats'        => $stats,
            'onTimePct'    => $stats['completed'] > 0 ? (int) round($stats['on_time'] / $stats['completed'] * 100) : null,
            'totalTime'    => $this->formatDuration($stats['elapsed']),
            'avgTime'      => $stats['with_time'] > 0 ? $this->formatDuration(intdiv($stats['elapsed'], $stats['with_time'])) : null,
            'overdue'      => $overdue,
            'overdueEmployees' => count(array_unique(array_column($overdue, 'assignee'))),
            'leads'        => $leads,
            'accountants'  => $accountants,
            'byCompany'    => $byCompany,
            'timeTop'      => $timeTop,
            'timeRest'     => $timeRest,
            'timeMax'      => $timeTop[0]['elapsed'] ?? 0,
            'discipline'   => array_values($discipline),
            'disciplineMax' => max(1, ...array_map(
                fn ($m) => $m['on_time'] + $m['late'] + $m['overdue'],
                array_values($discipline)
            )),
        ]);
    }

    /**
     * Кому засчитать задачу: закрытую — тому, кто её закрыл, остальные — тому, за кем
     * она числится. Логи при переносе работы не двигаются, а позиции сметы двигаются,
     * поэтому по назначению закрытый месяц меняется каждый раз после смены ответственного.
     */
    private function doerOf(string $status, ?BuhTaskLog $log, int $assigneeId): int
    {
        return $status === 'completed' && $log?->employee_id
            ? (int) $log->employee_id
            : $assigneeId;
    }

    /**
     * Копит одну сметную задачу в оба разреза «по сотрудникам».
     *
     * Задача идёт и главбуху компании (он отвечает за всё, что по ней делается),
     * и тому, кто её делает. Просрочка тут месячная: задача выбранного месяца,
     * срок прошёл, а она открыта. Просрочка «вообще» живёт в своём блоке выше.
     */
    private function accumulateTeam(
        array &$team,
        int $clientId,
        string $clientName,
        int $leadId,
        int $assigneeId,
        string $status,
        ?CarbonImmutable $due,
        CarbonImmutable $today,
    ): void {
        $done = $status === 'completed';
        $late = !$done && $due && $due->lt($today) && in_array($status, self::OPEN_STATUSES, true);

        $lead = &$team['leads'][$leadId];
        $lead ??= ['total' => 0, 'completed' => 0, 'overdue' => 0, 'companies' => [], 'members' => []];
        $lead['companies'][$clientId] ??= ['name' => $clientName, 'total' => 0, 'completed' => 0];
        $lead['members'][$assigneeId] ??= ['total' => 0, 'completed' => 0, 'overdue' => 0];

        $lead['total']++;
        $lead['companies'][$clientId]['total']++;
        $lead['members'][$assigneeId]['total']++;

        if ($done) {
            $lead['completed']++;
            $lead['companies'][$clientId]['completed']++;
            $lead['members'][$assigneeId]['completed']++;
        }

        if ($late) {
            $lead['overdue']++;
            $lead['members'][$assigneeId]['overdue']++;
        }

        unset($lead);

        $person = &$team['people'][$assigneeId];
        $person ??= ['total' => 0, 'completed' => 0, 'overdue' => 0, 'companies' => [], 'leads' => []];
        $person['companies'][$clientId] ??= ['name' => $clientName, 'total' => 0, 'completed' => 0];
        $person['leads'][$leadId] = true;

        $person['total']++;
        $person['companies'][$clientId]['total']++;

        if ($done) {
            $person['completed']++;
            $person['companies'][$clientId]['completed']++;
        }

        if ($late) {
            $person['overdue']++;
        }

        unset($person);
    }

    /**
     * Строки главбухов: доли исполнителей для кольца плюс список компаний.
     *
     * Долей в кольце не больше шести: дальше цвета перестают различаться, поэтому
     * хвост сворачивается в «Другие». Сам главбух в списке всегда первый.
     */
    private function buildLeads(array $team, $names, $roles): array
    {
        $rows = [];

        foreach ($team['leads'] as $id => $row) {
            $members = [];

            foreach ($row['members'] as $memberId => $member) {
                $members[] = [
                    'name'      => $names->get($memberId) ?? 'Не назначено',
                    'self'      => $memberId === $id,
                    'total'     => $member['total'],
                    'completed' => $member['completed'],
                    'overdue'   => $member['overdue'],
                    'pct'       => $this->pct($member['completed'], $member['total']),
                ];
            }

            // Сам первым, дальше по объёму: кольцо читается от большей доли
            usort($members, fn ($x, $y) => [!$x['self'], $y['total'], $x['name']]
                <=> [!$y['self'], $x['total'], $y['name']]);

            $rows[] = [
                'id'        => $id,
                'name'      => $names->get($id) ?? 'Не назначено',
                'role'      => $roles->get($id) ?? 'Главбух',
                'total'     => $row['total'],
                'completed' => $row['completed'],
                'overdue'   => $row['overdue'],
                'pct'       => $this->pct($row['completed'], $row['total']),
                'members'   => $this->foldMembers($members),
                'companies' => $this->sortCompanies($row['companies']),
            ];
        }

        return $this->sortByPct($rows);
    }

    /** Строки бухгалтеров: только свои задачи, зато по всем компаниям сразу. */
    private function buildAccountants(array $team, $names, $roles): array
    {
        $rows = [];

        foreach ($team['people'] as $id => $row) {
            // Тот, на ком есть компании, уже показан выше — со всем объёмом команды
            if (isset($team['leads'][$id])) {
                continue;
            }

            $leadNames = array_map(
                fn ($leadId) => $names->get($leadId) ?? 'Не назначено',
                array_keys($row['leads']),
            );
            sort($leadNames);

            $rows[] = [
                'id'        => $id,
                'name'      => $names->get($id) ?? 'Не назначено',
                'role'      => $roles->get($id) ?? 'Бухгалтер',
                'total'     => $row['total'],
                'completed' => $row['completed'],
                'overdue'   => $row['overdue'],
                'pct'       => $this->pct($row['completed'], $row['total']),
                'leads'     => $leadNames,
                'companies' => $this->sortCompanies($row['companies']),
            ];
        }

        return $this->sortByPct($rows);
    }

    /** Больше шести долей кольцо не различает: хвост уходит в «Другие». */
    private function foldMembers(array $members): array
    {
        if (count($members) <= 6) {
            return $members;
        }

        $head = array_slice($members, 0, 5);
        $tail = array_slice($members, 5);

        $head[] = [
            'name'      => 'Другие · ' . count($tail),
            'self'      => false,
            'other'     => true,
            'total'     => array_sum(array_column($tail, 'total')),
            'completed' => array_sum(array_column($tail, 'completed')),
            'overdue'   => array_sum(array_column($tail, 'overdue')),
            'pct'       => $this->pct(
                array_sum(array_column($tail, 'completed')),
                array_sum(array_column($tail, 'total')),
            ),
        ];

        return $head;
    }

    /** Компании внутри строки: где просело сильнее, то и сверху. */
    private function sortCompanies(array $companies): array
    {
        $rows = [];

        foreach ($companies as $id => $company) {
            $rows[] = $company + [
                'id'  => $id,
                'pct' => $this->pct($company['completed'], $company['total']),
            ];
        }

        usort($rows, fn ($x, $y) => [$x['pct'], $x['name']] <=> [$y['pct'], $y['name']]);

        return $rows;
    }

    /** Слабые сверху: разговор руководителя начинается с них. */
    private function sortByPct(array $rows): array
    {
        usort($rows, fn ($x, $y) => [$x['pct'], $x['name']] <=> [$y['pct'], $y['name']]);

        return $rows;
    }

    private function pct(int $completed, int $total): ?int
    {
        return $total > 0 ? (int) round($completed / $total * 100) : null;
    }

    /** Заводит строку компании (счётчики совместимы с accumulate); смета null = у клиента её нет. */
    private function ensureCompanyRow(array &$byCompany, int $id, string $name, ?float $estimate): void
    {
        $byCompany[$id] ??= [
            'name'      => $name,
            'total'     => 0,
            'adhoc'     => 0,
            'completed' => 0,
            'on_time'   => 0,
            'review'    => 0,
            'in_progress' => 0,
            'pending'   => 0,
            'elapsed'   => 0,
            'with_time' => 0,
            'overdue'   => 0, // просрочено сейчас — вне фильтра периода
            'estimate'  => null,
        ];

        if ($estimate !== null) {
            $byCompany[$id]['estimate'] = $estimate;
        }
    }

    /**
     * Относит задачу к месяцу графика дисциплины. Учитываются только «рассуженные»:
     * закрытые (вовремя/с опозданием) и открытые с прошедшим сроком (просрочено);
     * задачи в работе до срока месяц ещё не портят.
     */
    private function classifyDiscipline(array &$discipline, int $y, int $m, ?CarbonImmutable $dueObj, string $status, $completedAt, CarbonImmutable $today): void
    {
        $key = $y . '-' . $m;
        if (!isset($discipline[$key])) {
            return;
        }

        if ($status === 'completed') {
            if (!$dueObj || ($completedAt && $completedAt->lte($dueObj->endOfDay()))) {
                $discipline[$key]['on_time']++;
            } else {
                $discipline[$key]['late']++;
            }
        } elseif ($dueObj && $dueObj->lt($today) && in_array($status, self::OPEN_STATUSES, true)) {
            $discipline[$key]['overdue']++;
        }
    }

    /** Копит счётчики выбранного месяца по одной задаче. */
    private function accumulate(array &$stats, string $status, ?CarbonImmutable $dueObj, $completedAt, int $elapsed): void
    {
        $stats['total']++;

        if ($status === 'completed') {
            $stats['completed']++;
            if (!$dueObj || ($completedAt && $completedAt->lte($dueObj->endOfDay()))) {
                $stats['on_time']++;
            }
        } elseif ($status === 'review') {
            $stats['review']++;
        } elseif (in_array($status, ['running', 'paused', 'rework'], true)) {
            $stats['in_progress']++;
        } else {
            $stats['pending']++;
        }

        $stats['elapsed'] += $elapsed;
        if ($elapsed > 0) {
            $stats['with_time']++;
        }
    }

    /** Чистое время по таймеру (та же логика, что BuhTasksController::calcElapsed). */
    private function calcElapsed($log): int
    {
        if (!$log || !$log->started_at) {
            return 0;
        }

        $now = now()->timestamp;

        return match ($log->status) {
            'running' => $log->paused_seconds + ($log->resumed_at ? max(0, $now - $log->resumed_at->timestamp) : 0),
            'paused', 'review', 'rework', 'completed' => $log->paused_seconds,
            default => 0,
        };
    }

    private function formatDuration(int $seconds): string
    {
        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);

        return $h > 0 ? $h . 'ч ' . $m . 'м' : $m . 'м';
    }
}
