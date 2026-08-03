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
        $logs = BuhTaskLog::where('year', '>=', min($scanFrom->year, $year))
            ->orderBy('id')
            ->get()
            ->keyBy(fn ($l) => $l->year . '-' . $l->month . '-' . $l->estimate_item_id
                . ($l->due_date ? '-' . $l->due_date->toDateString() : ''));

        $employeeNames = Employee::pluck('full_name', 'id');

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

        // Разрез по сотрудникам: те же счётчики + просрочка (вне фильтра периода),
        // возвраты с проверки и список задач месяца для раскрытия строки
        $byEmployee = [];

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

                $service     = $item->service_id ? $services->get($item->service_id) : null;
                $override    = $service ? $overrides->get($item->service_id) : null;
                $resolved    = $service ? $service->resolveForClient($override) : null;
                $hasSchedule = !empty($resolved['periodicity']);
                $kind        = $resolved ? Service::kindForPeriodicity($resolved['periodicity']) : null;

                // Экземпляры задачи: [год, месяц, срок|null]
                $occurrences = [];
                if ($hasSchedule) {
                    foreach ($service->dueDatesForClient($override, $clientFrom, $scanTo) as $due) {
                        $occurrences[] = [$due->year, $due->month, $due];
                    }
                } elseif ($today->startOfMonth()->gte($clientFrom)) {
                    // Позиции без расписания — задача текущего месяца (как в задачнике),
                    // но не в холостой месяц только что заведённой сметы
                    $dueDay = $item->due_day ? min((int) $item->due_day, $today->daysInMonth) : null;
                    $occurrences[] = [$today->year, $today->month, $dueDay ? $today->startOfMonth()->addDays($dueDay - 1) : null];
                }

                foreach ($occurrences as [$wy, $wm, $dueObj]) {
                    $slotKey = ($kind === 'weekly' && $dueObj) ? '-' . $dueObj->toDateString() : '';
                    $log     = $logs->get($wy . '-' . $wm . '-' . $item->id . $slotKey);
                    $status  = $log?->status ?? 'pending';

                    $this->classifyDiscipline($discipline, $wy, $wm, $dueObj, $status, $log?->completed_at, $today);

                    if ($wy === $year && $wm === $month) {
                        $elapsed = $this->calcElapsed($log);
                        $this->accumulate($stats, $status, $dueObj, $log?->completed_at, $elapsed);

                        $this->ensureEmployeeRow($byEmployee, $assigneeId, $employeeNames);
                        $this->accumulate($byEmployee[$assigneeId], $status, $dueObj, $log?->completed_at, $elapsed);
                        $byEmployee[$assigneeId]['rework'] += (int) ($log?->rework_count ?? 0);
                        $byEmployee[$assigneeId]['tasks'][] = $this->drillTask(
                            $item->name . ($item->branch_label ? ' — ' . $item->branch_label : ''),
                            $client->name, $dueObj, $status, $log?->completed_at, $elapsed,
                            (int) ($log?->rework_count ?? 0), false, $today
                        );

                        $this->accumulate($byCompany[$client->id], $status, $dueObj, $log?->completed_at, $elapsed);
                    }

                    if ($dueObj && $dueObj->lt($today) && $dueObj->gte($cutoff)
                        && in_array($status, self::OPEN_STATUSES, true)) {
                        $this->ensureEmployeeRow($byEmployee, $assigneeId, $employeeNames);
                        $byEmployee[$assigneeId]['overdue']++;
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

            $assigneeId = (int) $a->employee_id;
            $companyId  = (int) ($a->client_id ?? 0);

            $this->classifyDiscipline($discipline, (int) $a->year, (int) $a->month, $dueObj, $a->status, $a->completed_at, $today);

            if ((int) $a->year === $year && (int) $a->month === $month) {
                $elapsed = $this->calcElapsed($a);
                $stats['adhoc']++;
                $this->accumulate($stats, $a->status, $dueObj, $a->completed_at, $elapsed);

                $this->ensureEmployeeRow($byEmployee, $assigneeId, $employeeNames);
                $byEmployee[$assigneeId]['adhoc']++;
                $this->accumulate($byEmployee[$assigneeId], $a->status, $dueObj, $a->completed_at, $elapsed);
                $byEmployee[$assigneeId]['rework'] += (int) $a->rework_count;
                $byEmployee[$assigneeId]['tasks'][] = $this->drillTask(
                    $a->name, $a->client?->name ?? '—', $dueObj, $a->status,
                    $a->completed_at, $elapsed, (int) $a->rework_count, true, $today
                );

                // Внеплановая может прийти от клиента без сметы или вовсе без клиента
                $this->ensureCompanyRow($byCompany, $companyId, $a->client?->name ?? 'Без компании', null);
                $byCompany[$companyId]['adhoc']++;
                $this->accumulate($byCompany[$companyId], $a->status, $dueObj, $a->completed_at, $elapsed);
            }

            if ($dueObj && $dueObj->lt($today) && $dueObj->gte($cutoff)
                && in_array($a->status, self::OPEN_STATUSES, true)) {
                $this->ensureEmployeeRow($byEmployee, $assigneeId, $employeeNames);
                $byEmployee[$assigneeId]['overdue']++;
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

        usort($overdue, fn ($x, $y) => [$y['days'], $x['client_name']] <=> [$x['days'], $y['client_name']]);

        // Проблемные сотрудники сверху: по просрочке, затем по объёму задач
        foreach ($byEmployee as &$row) {
            usort($row['tasks'], fn ($x, $y) => [$x['due_sort'], $x['client_name'], $x['name']] <=> [$y['due_sort'], $y['client_name'], $y['name']]);
            $row['time'] = $row['elapsed'] > 0 ? $this->formatDuration($row['elapsed']) : null;
        }
        unset($row);
        uasort($byEmployee, fn ($x, $y) => [$y['overdue'], $y['total'], $x['name']] <=> [$x['overdue'], $x['total'], $y['name']]);

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
            'byEmployee'   => $byEmployee,
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

    /** Заводит строку сотрудника в разрезе «по сотрудникам» (счётчики совместимы с accumulate). */
    private function ensureEmployeeRow(array &$byEmployee, int $id, $employeeNames): void
    {
        $byEmployee[$id] ??= [
            'name'      => $employeeNames->get($id) ?? 'Не назначено',
            'total'     => 0,
            'adhoc'     => 0,
            'completed' => 0,
            'on_time'   => 0,
            'review'    => 0,
            'in_progress' => 0,
            'pending'   => 0,
            'elapsed'   => 0,
            'with_time' => 0,
            'overdue'   => 0, // просрочено сейчас — вне фильтра периода, как и одноимённый блок
            'rework'    => 0, // возвраты с проверки по задачам выбранного месяца
            'tasks'     => [],
        ];
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

    /** Строка задачи для раскрытия сотрудника. */
    private function drillTask(
        string $name, string $clientName, ?CarbonImmutable $dueObj, string $status,
        $completedAt, int $elapsed, int $reworkCount, bool $isAdhoc, CarbonImmutable $today
    ): array {
        // «Поздно»: открытая задача с прошедшим сроком или выполненная после срока
        $late = $dueObj && (
            ($status === 'completed' && $completedAt && $completedAt->gt($dueObj->endOfDay()))
            || (in_array($status, self::OPEN_STATUSES, true) && $dueObj->lt($today))
        );

        return [
            'name'        => $name,
            'client_name' => $clientName,
            'due_date'    => $dueObj?->format('d.m'),
            'due_sort'    => $dueObj?->toDateString() ?? '9999-99-99',
            'status'      => $status,
            'late'        => $late,
            'time'        => $elapsed > 0 ? $this->formatDuration($elapsed) : null,
            'rework'      => $reworkCount,
            'is_adhoc'    => $isAdhoc,
        ];
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
