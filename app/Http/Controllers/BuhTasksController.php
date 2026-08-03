<?php

namespace App\Http\Controllers;

use App\Models\BuhAdhocTask;
use App\Models\BuhTaskDocument;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Service;
use App\Models\TaskReminder;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;

class BuhTasksController extends Controller
{
    /** Глубина истории вкладки «Выполненные» (дней назад) */
    private const COMPLETED_HISTORY_DAYS = 90;

    /**
     * Насколько «далеко» ушла задача — для выбора победителя среди логов одного слота.
     * Дубли остались от переназначения БП: прежний исполнитель закрыл период, новому
     * завёлся свой лог (до того, как отметка стала общей, см. logsForSlots()).
     */
    private const LOG_STATUS_RANK = [
        'completed' => 5,
        'review'    => 4,
        'rework'    => 3,
        'running'   => 2,
        'paused'    => 2,
        'pending'   => 1,
    ];

    /** Максимум документов на одну задачу */
    private const MAX_DOCUMENTS = 10;

    /** Расширения, которые нельзя загружать (исполняемые на сервере/клиенте) */
    private const FORBIDDEN_EXTENSIONS = [
        'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'pht',
        'cgi', 'pl', 'sh', 'bash', 'exe', 'bat', 'cmd', 'com',
        'htaccess', 'hta', 'js', 'mjs', 'html', 'htm', 'shtml', 'xhtml', 'svg',
    ];

    /** Правило валидации загружаемого документа (общее для всех точек загрузки). */
    private function documentFileRules(bool $required = true): array
    {
        return [
            $required ? 'required' : 'nullable',
            'file',
            'max:40960',
            function ($attribute, $value, $fail) {
                $ext = strtolower($value->getClientOriginalExtension());
                if (in_array($ext, self::FORBIDDEN_EXTENSIONS, true)) {
                    $fail('Файлы этого типа загружать нельзя');
                }
            },
        ];
    }

    /**
     * Можно ли менять документы задачи: нельзя на проверке; у корневой задачи /
     * внеплановой — также после закрытия. Подпункт чеклиста (дочерний лог) остаётся
     * редактируемым после отметки галочки, но блокируется вместе с родительской
     * задачей — когда она закрыта или ушла на проверку.
     */
    private function documentsLocked(BuhTaskLog|BuhAdhocTask $task): bool
    {
        if ($task->status === 'review') {
            return true;
        }

        if ($task instanceof BuhAdhocTask) {
            return $task->status === 'completed';
        }

        $parentItemId = $task->estimateItem?->parent_id;
        if (!$parentItemId) {
            return $task->status === 'completed';
        }

        // Подпункт: смотрим состояние родительской задачи (тот же слот, что в complete()).
        // Ищем по слоту, а не по сотруднику: после переназначения родитель мог остаться
        // за прежним исполнителем, и блокировка должна работать по нему же.
        $parentLog = $this->pickLog(
            BuhTaskLog::where('client_id', $task->client_id)
                ->where('year', $task->year)
                ->where('month', $task->month)
                ->where('estimate_item_id', $parentItemId)
                ->when($task->due_date,
                    fn ($q) => $q->whereDate('due_date', $task->due_date),
                    fn ($q) => $q->whereNull('due_date'))
                ->get()
        );

        return $parentLog && in_array($parentLog->status, ['completed', 'review'], true);
    }

    /**
     * Отсечка backlog: задачи со сроком РАНЬШЕ этой даты не показываем вообще
     * (разовая очистка накопившейся просрочки до июля 2026). Единая с воркером
     * напоминаний (GenerateTaskReminders::BACKLOG_CUTOFF).
     */
    private const BACKLOG_CUTOFF = '2026-07-01';

    public function index(Request $request)
    {
        $employee = auth('employee')->user();

        $year  = (int) $request->get('year',  now()->year);
        $month = (int) $request->get('month', now()->month);
        $year  = max(2020, min(2030, $year));
        $month = max(1, min(12, $month));

        // Клиент «принадлежит» сотруднику, если он исполнитель хотя бы одного БП сметы
        // (estimate_items.assignee_id), либо — при пустом assignee — ответственный клиента.
        $assignedToEmployee = fn ($q) => $q
            ->where('responsible_employee_id', $employee->id)
            ->orWhereHas('estimates.rootItems', fn ($i) => $i
                ->whereNull('parent_id')
                ->where('assignee_id', $employee->id));

        // Мои клиенты (я — ответственный/главбух) и «мои бухгалтеры» — назначены хоть
        // на один БП моих клиентов. Главбух видит ВСЕ текущие задачи своих бухгалтеров:
        // и по чужим клиентам, и внеплановые без клиента (в т.ч. самозаведённые).
        $myClientIds = Client::where('responsible_employee_id', $employee->id)->pluck('id');
        $myAccountantIds = $myClientIds->isNotEmpty()
            ? EstimateItem::whereNull('parent_id')
                ->whereNotNull('assignee_id')
                ->where('assignee_id', '!=', $employee->id)
                ->whereHas('estimate', fn ($q) => $q->whereIn('client_id', $myClientIds))
                ->pluck('assignee_id')->unique()->values()
            : collect();

        // Клиенты с задачами этого сотрудника ИЛИ его бухгалтеров, с непустой сметой (одна на клиента)
        $clients = Client::query()
            ->where(fn ($q) => $q
                ->where($assignedToEmployee)
                ->orWhereHas('estimates.rootItems', fn ($i) => $i
                    ->whereNull('parent_id')
                    ->whereIn('assignee_id', $myAccountantIds)))
            ->with([
                'serviceSchedules',
                'estimates' => fn($q) => $q
                    ->with(['rootItems' => fn($q) => $q
                        ->whereNull('parent_id')
                        ->with('children.service')
                        ->orderBy('sort_order'),
                    ]),
            ])
            ->whereHas('estimates', fn($q) => $q
                ->whereHas('rootItems', fn($q2) => $q2->whereNull('parent_id')))
            ->orderBy('name')
            ->get();

        // Компании для селектора «Добавить задачу»: админ/руководитель — все активные;
        // главбух — свои + клиенты своих бухгалтеров (ставит им задачи и по клиентам,
        // за которых сам не ответственен); остальные — где ответственный или исполнитель БП.
        if ($employee->isAdmin() || $employee->isManager()) {
            $allClients = Client::active()->orderBy('name')->get(['id', 'name']);
        } else {
            $allClients = Client::query()
                ->where(function ($q) use ($assignedToEmployee, $myAccountantIds) {
                    $q->where($assignedToEmployee);
                    if ($myAccountantIds->isNotEmpty()) {
                        $q->orWhere(fn ($qq) => $qq
                            ->whereIn('responsible_employee_id', $myAccountantIds)
                            ->orWhereHas('estimates.rootItems', fn ($i) => $i
                                ->whereNull('parent_id')
                                ->whereIn('assignee_id', $myAccountantIds)));
                    }
                })
                ->orderBy('name')->get(['id', 'name']);
        }

        // Правила активного списка:
        //  - просроченные невыполненные — показываем ВСЕ, пока не закроют;
        //  - предстоящие — на 30 дней вперёд от сегодня;
        //  - выполненные — только в день закрытия (сегодня), дальше уходят во вкладку «Выполненные».
        $today       = CarbonImmutable::now()->startOfDay();
        $todayStr    = $today->toDateString();
        $horizonEnd  = $today->addDays(30)->endOfDay();
        $curStart    = $today->startOfMonth();
        $year        = $curStart->year;
        $month       = $curStart->month;
        $curMonthIdx = $year * 12 + $month; // для отсечения будущих месяцев в списке
        $historyFrom = $today->subDays(self::COMPLETED_HISTORY_DAYS)->startOfDay(); // глубина вкладки «Выполненные»
        $backlogCutoff = CarbonImmutable::parse(self::BACKLOG_CUTOFF)->startOfDay(); // просрочку раньше июля 2026 не показываем

        // Логи плановых задач (нужны статусы за прошлое для просрочки), ключ — «слот»:
        // year-month-item + дата для weekly (due_date), иначе пусто. Так weekly-вхождения
        // в одном месяце различаются, а помесячные логи (due_date=NULL) матчатся помесячно.
        // Ограничиваем годом окна просрочки (6 мес назад) — старые логи всё равно не запрашиваются.
        //
        // Раньше логи брались только свои (where employee_id), и после смены исполнителя БП
        // уже закрытые прошлые периоды всплывали у нового бухгалтера как «просрочено» —
        // отметка о выполнении оставалась на прежнем исполнителе. Теперь берём логи всей
        // зоны видимости и группируем по слоту без employee_id, а кому какой лог показать,
        // решает logForEmployee(). Той же картой пользуется вкладка «Задачи бухгалтеров».
        $logs = BuhTaskLog::where(fn ($q) => $q
                ->whereIn('client_id', $clients->pluck('id'))
                ->orWhere('employee_id', $employee->id)
                ->orWhereIn('employee_id', $myAccountantIds))
            ->where('year', '>=', $today->subMonths(6)->year)
            ->with('documents')
            ->get()
            ->groupBy(fn ($l) => $l->year . '-' . $l->month . '-' . $l->estimate_item_id
                . ($l->due_date ? '-' . $l->due_date->toDateString() : ''));

        // Все внеплановые задачи сотрудника (невыполненные висят, пока не закроют)
        $adhocs = BuhAdhocTask::where('employee_id', $employee->id)
            ->with('client', 'documents')
            ->get();

        // === Задачи бухгалтеров (этап 1): вкладка главбуха ===
        // Общий охват «команды»: задачи по моим клиентам (кто бы ни делал, кроме меня)
        // + любые задачи моих бухгалтеров. Применяется к логам и внеплановым.
        $teamScope = fn ($q) => $q->whereIn('client_id', $myClientIds)
            ->orWhereIn('employee_id', $myAccountantIds);

        $employeeNames = Employee::pluck('full_name', 'id');
        $teamTasks = [];

        // Предзагрузка БП по сметным позициям (нужны их методы расписания)
        $serviceIds = $clients
            ->flatMap(fn ($c) => $c->estimates->first()?->rootItems ?? collect())
            ->pluck('service_id')->filter()->unique()->values();
        $services = $serviceIds->isNotEmpty()
            ? Service::whereIn('id', $serviceIds)->get()->keyBy('id')
            : collect();

        // Плановые задачи из сметы.
        // Берём реальные даты срока от старта обслуживания клиента до +30 дней, затем оставляем:
        //  - просроченные невыполненные (все), предстоящие в пределах 30 дней, выполненные сегодня.
        // Квартальные/годовые всплывают сами за 30 дней до срока. Позиции без расписания
        // (ручные one_time или БП без периодичности) показываем как текущую задачу.
        $tasks = [];
        foreach ($clients as $client) {
            $items = $client->estimates->first()?->rootItems ?? collect();
            $overrides = $client->serviceSchedules->keyBy('service_id');

            // Просрочку показываем только за последние 6 месяцев (единая логика с воркером напоминаний),
            // но не раньше старта обслуживания клиента и не раньше отсечки backlog (июль 2026).
            $lookbackStart = $today->subMonths(6);
            if ($backlogCutoff->gt($lookbackStart)) {
                $lookbackStart = $backlogCutoff;
            }
            if ($client->service_start_date) {
                $serviceStart = CarbonImmutable::parse($client->service_start_date)->startOfDay();
                if ($serviceStart->gt($lookbackStart)) {
                    $lookbackStart = $serviceStart;
                }
            }

            foreach ($items as $item) {
                // Исполнитель позиции: assignee_id, при пустом — ответственный клиента.
                // Свои БП идут в основной список; во вкладку «задачи бухгалтеров» — БП моих
                // клиентов (кто бы ни делал) и БП моих бухгалтеров по любым клиентам.
                $effectiveAssignee = (int) ($item->assignee_id ?? $client->responsible_employee_id);
                $isMine = $effectiveAssignee === $employee->id;
                $isTeam = !$isMine && $effectiveAssignee !== 0
                    && ((int) $client->responsible_employee_id === $employee->id
                        || $myAccountantIds->contains($effectiveAssignee));
                if (!$isMine && !$isTeam) {
                    continue;
                }

                $service  = $item->service_id ? $services->get($item->service_id) : null;
                $override = $service ? $overrides->get($item->service_id) : null;
                $resolved = $service ? $service->resolveForClient($override) : null;
                $hasSchedule = !empty($resolved['periodicity']);
                $kind = $resolved ? Service::kindForPeriodicity($resolved['periodicity']) : null;

                // Экземпляры задачи: [year, month, dueDateString|null, dueDay|null, Carbon|null]
                $occurrences = [];
                if ($hasSchedule) {
                    foreach ($service->dueDatesForClient($override, $lookbackStart, $horizonEnd) as $due) {
                        $occurrences[] = [$due->year, $due->month, $due->toDateString(), (int) $due->day, $due];
                    }
                } else {
                    $dueDay = $item->due_day ? min((int) $item->due_day, $curStart->daysInMonth) : null;
                    $dueObj = $dueDay ? $curStart->day($dueDay) : null;
                    $occurrences[] = [$curStart->year, $curStart->month, $dueObj?->toDateString(), $item->due_day ? (int) $item->due_day : null, $dueObj];
                }

                foreach ($occurrences as [$wy, $wm, $dueDateStr, $dueDay, $dueObj]) {
                    // Слот: для weekly различаем вхождения по дате; для остальных — помесячно (пусто).
                    $slot    = $kind === 'weekly' ? $dueDateStr : null;
                    $slotKey = $slot ? '-' . $slot : '';

                    // Задача бухгалтера: во вкладку главбуха попадает только то, что сейчас
                    // «у бухгалтера» — не начатое, в работе, на паузе или на доработке.
                    // Выполненные — этап 2 (вкладка «Выполненные»), review уже в основном списке.
                    if ($isTeam) {
                        $tLog    = $this->logForEmployee($logs->get($wy . '-' . $wm . '-' . $item->id . $slotKey), $effectiveAssignee);
                        $tStatus = $tLog?->status ?? 'pending';
                        // Кто реально делает: исполнитель лога, если он уже заведён (после
                        // переназначения прошлый период мог остаться за прежним бухгалтером).
                        $tDoer   = (int) ($tLog?->employee_id ?? $effectiveAssignee);
                        if (!in_array($tStatus, ['pending', 'running', 'paused', 'rework'], true)) {
                            continue;
                        }
                        if ($wy * 12 + $wm > $curMonthIdx) {
                            continue; // будущие месяцы скрываем, как и в основном списке
                        }
                        $teamTasks[] = [
                            'uid'              => 'team_' . $item->id . '_' . $wy . '_' . $wm . ($slot ? '_' . $slot : ''),
                            'type'             => 'planned',
                            'client_id'        => $client->id,
                            'client_name'      => $client->name,
                            'year'             => $wy,
                            'month'            => $wm,
                            'name'             => $item->name,
                            'branch_label'     => $item->branch_label,
                            'periodicity'      => $item->periodicity,
                            'reporting_period' => Service::reportingPeriodLabel($kind, $dueObj, $today->year),
                            'due_date'         => $dueDateStr,
                            'status'           => $tStatus,
                            'doer_id'          => $tDoer,
                            'doer_name'        => $employeeNames->get($tDoer),
                            'employee_comment' => $tLog?->employee_comment,
                        ];
                        continue;
                    }

                    $log = $this->logForEmployee($logs->get($wy . '-' . $wm . '-' . $item->id . $slotKey), $employee->id);
                    $status = $log?->status ?? 'pending';

                    // Фильтр видимости в активном списке:
                    if ($status === 'completed') {
                        // Выполненные скрыты из списка (visibleTasks), но остаются в наборе —
                        // чтобы считаться в прогрессе месяца и в «Все компании», если закрыты
                        // в ТЕКУЩЕМ месяце. Закрытые в прошлых месяцах из набора уходят.
                        $completedThisMonth = $log?->completed_at
                            && $log->completed_at->year === $year && $log->completed_at->month === $month;
                        if (!$completedThisMonth) {
                            continue;
                        }
                    } elseif ($wy * 12 + $wm > $curMonthIdx) {
                        // показываем только текущий месяц и все просроченные (прошлые месяцы);
                        // будущие месяцы скрываем, чтобы не засорять список
                        continue;
                    }
                    // позиции без даты (ручные без срока) — показываем как текущую задачу

                    $tasks[] = [
                        'uid'             => 'planned_' . $item->id . '_' . $wy . '_' . $wm . ($slot ? '_' . $slot : ''),
                        'type'            => 'planned',
                        'item_id'         => $item->id,
                        'slot'            => $slot, // weekly → дата вхождения, иначе null (передаётся при создании лога)
                        'client_id'       => $client->id,
                        'client_name'     => $client->name,
                        'year'            => $wy,
                        'month'           => $wm,
                        'log_id'          => $log?->id,
                        'name'            => $item->name,
                        'branch_label'    => $item->branch_label,
                        'service_group'   => $service?->service_group, // для сортировки чеклиста по группе
                        'cost'            => (float) $item->total,
                        'periodicity'     => $item->periodicity,
                        'reporting_period' => Service::reportingPeriodLabel($kind, $dueObj, $today->year),
                        'due_day'         => $dueDay,
                        'due_date'        => $dueDateStr,
                        'comment'         => $service?->comment,
                        'description'     => $service?->description,
                        'status'          => $log?->status ?? 'pending',
                        'elapsed_seconds' => $this->calcElapsed($log),
                        'review_comment'  => $log?->review_comment,
                        'employee_comment' => $log?->employee_comment,
                        'quantity'         => (int) $item->quantity,
                        'allows_quantity'  => (bool) ($service?->allows_quantity),
                        'actual_quantity'  => $log?->actual_quantity,
                        'requires_document' => (bool) ($service?->requires_document),
                        'documents'        => $log ? $this->docs($log) : [],
                        'force_closed'        => (bool) ($log?->force_closed),
                        'force_close_comment' => $log?->force_close_comment,
                        'children'        => $item->children->map(function ($child) use ($logs, $wy, $wm, $slotKey, $slot, $employee) {
                            $childLog = $this->logForEmployee($logs->get($wy . '-' . $wm . '-' . $child->id . $slotKey), $employee->id);

                            return [
                                'id'                 => $child->id,
                                'log_id'             => $childLog?->id,
                                'name'               => $child->name,
                                'status'             => $childLog?->status ?? 'pending',
                                'review_comment'     => $childLog?->review_comment,
                                'quantity'           => (int) $child->quantity,
                                'allows_quantity'    => (bool) ($child->service?->allows_quantity),
                                'actual_quantity'    => $childLog?->actual_quantity,
                                'requires_document'  => (bool) ($child->service?->requires_document),
                                'documents'          => $childLog ? $this->docs($childLog) : [],
                            ];
                        })->values(),
                    ];
                }
            }
        }

        // Внеплановые задачи показываем ВСЕ независимо от месяца (ручные поручения, не в смете):
        // невыполненные висят, пока не закроют; выполненные — только в день закрытия.
        foreach ($adhocs as $adhoc) {
            if ($adhoc->status === 'completed') {
                // как и плановые: остаётся в наборе, только если закрыта в текущем месяце
                $completedThisMonth = $adhoc->completed_at
                    && $adhoc->completed_at->year === $year && $adhoc->completed_at->month === $month;
                if (!$completedThisMonth) {
                    continue;
                }
            }

            // Дата срока внеплановой (если задан день)
            $adhocDate = $adhoc->due_day
                ? CarbonImmutable::create($adhoc->year, $adhoc->month, min((int) $adhoc->due_day, CarbonImmutable::create($adhoc->year, $adhoc->month, 1)->daysInMonth))
                : null;

            $tasks[] = [
                'uid'             => 'adhoc_' . $adhoc->id,
                'type'            => 'adhoc',
                'is_custom'       => true,
                'adhoc_id'        => $adhoc->id,
                'client_id'       => $adhoc->client_id,
                'client_name'     => $adhoc->client?->name,
                'year'            => $adhoc->year,
                'month'           => $adhoc->month,
                'name'            => $adhoc->name,
                'service_group'   => null,
                'cost'            => (float) $adhoc->cost,
                'periodicity'     => null,
                'reporting_period' => null,
                'due_day'         => $adhoc->due_day,
                'due_date'        => $adhocDate?->toDateString(),
                'comment'         => null,
                'description'     => $adhoc->description,
                'requires_review' => $adhoc->requires_review,
                'status'          => $adhoc->status,
                'elapsed_seconds' => $this->calcElapsed($adhoc),
                'review_comment'  => $adhoc->review_comment,
                'employee_comment' => $adhoc->employee_comment,
                'quantity'        => 1,
                'allows_quantity' => false,
                'actual_quantity' => null,
                'requires_document' => false, // документ всегда опционален для внеплановых
                'documents'       => $this->docs($adhoc),
                'children'        => [],
            ];
        }

        // Внеплановые задачи команды — в ту же вкладку главбуха: по моим клиентам
        // (кто бы ни делал) + любые задачи моих бухгалтеров, включая без клиента.
        if ($myClientIds->isNotEmpty()) {
            $teamAdhocs = BuhAdhocTask::where('employee_id', '!=', $employee->id)
                ->where($teamScope)
                ->whereIn('status', ['pending', 'running', 'paused', 'rework'])
                ->with('client:id,name')
                ->get();
            foreach ($teamAdhocs as $a) {
                $adhocDate = $a->due_day
                    ? CarbonImmutable::create($a->year, $a->month, min((int) $a->due_day, CarbonImmutable::create($a->year, $a->month, 1)->daysInMonth))
                    : null;
                $teamTasks[] = [
                    'uid'              => 'team_adhoc_' . $a->id,
                    'type'             => 'adhoc',
                    'is_custom'        => true,
                    'client_id'        => $a->client_id,
                    'client_name'      => $a->client?->name,
                    'year'             => $a->year,
                    'month'            => $a->month,
                    'name'             => $a->name,
                    'branch_label'     => null,
                    'periodicity'      => null,
                    'reporting_period' => null,
                    'due_date'         => $adhocDate?->toDateString(),
                    'status'           => $a->status,
                    'doer_id'          => $a->employee_id,
                    'doer_name'        => $employeeNames->get($a->employee_id),
                    'employee_comment' => $a->employee_comment,
                ];
            }
        }

        // Бухгалтеры для фильтра вкладки (только те, у кого сейчас есть задачи)
        $teamMembers = collect($teamTasks)
            ->map(fn ($t) => ['id' => $t['doer_id'], 'name' => $t['doer_name']])
            ->unique('id')
            ->sortBy('name')
            ->values()
            ->toArray();

        // === Проверка главбуха (шаг 7.1): задачи на проверке от бухгалтеров МОИХ клиентов ===
        // Главбух (клиент, где он responsible_employee_id) видит в своём же списке задачи в статусе
        // review, сделанные НЕ им самим, с пометкой «от бухгалтера». Свои review-задачи уже попали
        // в $tasks выше как обычные строки исполнителя (employee_id == $employee->id), поэтому здесь
        // их исключаем — иначе задвоятся. Действия «принять/вернуть» появятся отдельным шагом.
        if ($myClientIds->isNotEmpty()) {
            $reviewPlanned = BuhTaskLog::where('status', 'review')
                ->whereIn('client_id', $myClientIds)
                ->where('employee_id', '!=', $employee->id)
                ->with(['estimateItem.service', 'client:id,name', 'employee:id,full_name', 'documents'])
                ->get();
            foreach ($reviewPlanned as $log) {
                $item    = $log->estimateItem;
                $service = $item?->service;
                $tasks[] = [
                    'uid'             => 'review_log_' . $log->id,
                    'type'            => 'planned',
                    'item_id'         => $item?->id,
                    'log_id'          => $log->id,
                    'review_for_head' => true,
                    'doer_name'       => $log->employee?->full_name,
                    'client_id'       => $log->client_id,
                    'client_name'     => $log->client?->name,
                    'year'            => $log->year,
                    'month'           => $log->month,
                    'name'            => $item?->name ?? '—',
                    'branch_label'    => $item?->branch_label,
                    'service_group'   => $service?->service_group,
                    'cost'            => (float) ($item?->total ?? 0),
                    'periodicity'     => $item?->periodicity,
                    'reporting_period' => null,
                    'due_day'         => null,
                    'due_date'        => $log->due_date?->toDateString(),
                    'comment'         => $service?->comment,
                    'description'     => $service?->description,
                    'status'          => 'review',
                    'elapsed_seconds' => $this->calcElapsed($log),
                    'review_comment'  => $log->review_comment,
                    'employee_comment' => $log->employee_comment,
                    'quantity'        => (int) ($item?->quantity ?? 0),
                    'allows_quantity' => (bool) ($service?->allows_quantity),
                    'actual_quantity' => $log->actual_quantity,
                    'requires_document' => (bool) ($service?->requires_document),
                    'documents'       => $this->docs($log),
                    'force_closed'        => (bool) $log->force_closed,
                    'force_close_comment' => $log->force_close_comment,
                    'children'        => [],
                ];
            }

            $reviewAdhoc = BuhAdhocTask::where('status', 'review')
                ->whereIn('client_id', $myClientIds)
                ->where('employee_id', '!=', $employee->id)
                ->with(['client:id,name', 'employee:id,full_name', 'documents'])
                ->get();
            foreach ($reviewAdhoc as $a) {
                $tasks[] = [
                    'uid'             => 'review_adhoc_' . $a->id,
                    'type'            => 'adhoc',
                    'is_custom'       => true,
                    'adhoc_id'        => $a->id,
                    'review_for_head' => true,
                    'doer_name'       => $a->employee?->full_name,
                    'client_id'       => $a->client_id,
                    'client_name'     => $a->client?->name,
                    'year'            => $a->year,
                    'month'           => $a->month,
                    'name'            => $a->name,
                    'service_group'   => null,
                    'cost'            => (float) $a->cost,
                    'periodicity'     => null,
                    'reporting_period' => null,
                    'due_day'         => $a->due_day,
                    'due_date'        => null,
                    'comment'         => null,
                    'description'     => $a->description,
                    'requires_review' => $a->requires_review,
                    'status'          => 'review',
                    'elapsed_seconds' => $this->calcElapsed($a),
                    'review_comment'  => $a->review_comment,
                    'employee_comment' => $a->employee_comment,
                    'quantity'        => 1,
                    'allows_quantity' => false,
                    'actual_quantity' => null,
                    'requires_document' => false,
                    'documents'       => $this->docs($a),
                    'children'        => [],
                ];
            }
        }

        // Вкладка «Выполненные» — история за последние 90 дней (read-only): плановые + внеплановые.
        $completedPlanned = BuhTaskLog::where('employee_id', $employee->id)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $historyFrom)
            ->with(['estimateItem.service', 'estimateItem.children.service', 'client:id,name', 'documents'])
            ->get()
            ->map(function ($l) use ($logs) {
                $item    = $l->estimateItem;
                $service = $item?->service;

                return [
                    'id'           => 'log_' . $l->id,
                    'type'         => 'planned',
                    'name'         => $item?->name ?? '—',
                    'branch_label' => $item?->branch_label,
                    'client_name'  => $l->client?->name ?? '—',
                    'completed_at' => $l->completed_at->toIso8601String(),
                    'employee_comment' => $l->employee_comment,
                    'comment_url'      => route('buhtasks.logs.comment', $l->id),
                    'elapsed_seconds'   => $this->calcElapsed($l),
                    'description'       => $service?->description,
                    'comment'          => $service?->comment,
                    'periodicity'      => $item?->periodicity,
                    'allows_quantity'  => (bool) ($service?->allows_quantity),
                    'quantity'         => (int) ($item?->quantity ?? 0),
                    'actual_quantity'  => $l->actual_quantity,
                    'requires_document' => (bool) ($service?->requires_document),
                    'documents'        => $this->docs($l),
                    'force_closed'        => (bool) $l->force_closed,
                    'force_close_comment' => $l->force_close_comment,
                    'children'         => ($item?->children ?? collect())->map(function ($child) use ($logs, $l) {
                        $cSlotKey = $l->due_date ? '-' . $l->due_date->toDateString() : '';
                        $childLog = $this->logForEmployee($logs->get($l->year . '-' . $l->month . '-' . $child->id . $cSlotKey), $l->employee_id);
                        $cs = $child->service;

                        return [
                            'id'                => $child->id,
                            'name'              => $child->name,
                            'status'            => $childLog?->status ?? 'pending',
                            'allows_quantity'   => (bool) ($cs?->allows_quantity),
                            'quantity'          => (int) $child->quantity,
                            'actual_quantity'   => $childLog?->actual_quantity,
                            'requires_document' => (bool) ($cs?->requires_document),
                            'documents'         => $childLog ? $this->docs($childLog) : [],
                        ];
                    })->values()->toArray(),
                ];
            });
        $completedAdhoc = BuhAdhocTask::where('employee_id', $employee->id)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $historyFrom)
            ->with('client:id,name', 'documents')
            ->get()
            ->map(fn ($a) => [
                'id'           => 'adhoc_' . $a->id,
                'type'         => 'adhoc',
                'name'         => $a->name,
                'client_name'  => $a->client?->name ?? '—',
                'completed_at' => $a->completed_at->toIso8601String(),
                'employee_comment' => $a->employee_comment,
                'comment_url'      => route('buhtasks.adhoc.comment', $a->id),
                'elapsed_seconds'   => $this->calcElapsed($a),
                'description'       => $a->description,
                'comment'          => null,
                'periodicity'      => null,
                'allows_quantity'  => false,
                'quantity'         => 1,
                'actual_quantity'  => null,
                'requires_document' => false,
                'documents'        => $this->docs($a),
                'children'         => [],
            ]);
        // Выполненные задачи бухгалтеров по моим клиентам (этап 2): попадают в ту же вкладку
        // «Выполненные» главбуха с пометкой исполнителя (doer_name). Заметка бухгалтера
        // видна, но не редактируется (comment_url = null — чужая заметка).
        $teamCompletedPlanned = collect();
        $teamCompletedAdhoc   = collect();
        if ($myClientIds->isNotEmpty()) {
            $teamCompletedPlanned = BuhTaskLog::where('employee_id', '!=', $employee->id)
                ->where($teamScope)
                ->where('status', 'completed')
                ->whereNotNull('completed_at')
                ->where('completed_at', '>=', $historyFrom)
                ->with(['estimateItem.service', 'estimateItem.children.service', 'client:id,name', 'documents'])
                ->get()
                ->map(function ($l) use ($logs, $employeeNames) {
                    $item    = $l->estimateItem;
                    $service = $item?->service;

                    return [
                        'id'           => 'team_log_' . $l->id,
                        'type'         => 'planned',
                        'doer_name'    => $employeeNames->get($l->employee_id),
                        'name'         => $item?->name ?? '—',
                        'branch_label' => $item?->branch_label,
                        'client_name'  => $l->client?->name ?? '—',
                        'completed_at' => $l->completed_at->toIso8601String(),
                        'employee_comment' => $l->employee_comment,
                        'comment_url'      => null,
                        'elapsed_seconds'   => $this->calcElapsed($l),
                        'description'       => $service?->description,
                        'comment'          => $service?->comment,
                        'periodicity'      => $item?->periodicity,
                        'allows_quantity'  => (bool) ($service?->allows_quantity),
                        'quantity'         => (int) ($item?->quantity ?? 0),
                        'actual_quantity'  => $l->actual_quantity,
                        'requires_document' => (bool) ($service?->requires_document),
                        'documents'        => $this->docs($l),
                        'force_closed'        => (bool) $l->force_closed,
                        'force_close_comment' => $l->force_close_comment,
                        'children'         => ($item?->children ?? collect())->map(function ($child) use ($logs, $l) {
                            $cSlotKey = $l->due_date ? '-' . $l->due_date->toDateString() : '';
                            $childLog = $this->logForEmployee($logs->get($l->year . '-' . $l->month . '-' . $child->id . $cSlotKey), $l->employee_id);
                            $cs = $child->service;

                            return [
                                'id'                => $child->id,
                                'name'              => $child->name,
                                'status'            => $childLog?->status ?? 'pending',
                                'allows_quantity'   => (bool) ($cs?->allows_quantity),
                                'quantity'          => (int) $child->quantity,
                                'actual_quantity'   => $childLog?->actual_quantity,
                                'requires_document' => (bool) ($cs?->requires_document),
                                'documents'         => $childLog ? $this->docs($childLog) : [],
                            ];
                        })->values()->toArray(),
                    ];
                });

            $teamCompletedAdhoc = BuhAdhocTask::where('employee_id', '!=', $employee->id)
                ->where($teamScope)
                ->where('status', 'completed')
                ->whereNotNull('completed_at')
                ->where('completed_at', '>=', $historyFrom)
                ->with('client:id,name', 'documents')
                ->get()
                ->map(fn ($a) => [
                    'id'           => 'team_adhoc_' . $a->id,
                    'type'         => 'adhoc',
                    'doer_name'    => $employeeNames->get($a->employee_id),
                    'name'         => $a->name,
                    'client_name'  => $a->client?->name ?? '—',
                    'completed_at' => $a->completed_at->toIso8601String(),
                    'employee_comment' => $a->employee_comment,
                    'comment_url'      => null,
                    'elapsed_seconds'   => $this->calcElapsed($a),
                    'description'       => $a->description,
                    'comment'          => null,
                    'periodicity'      => null,
                    'allows_quantity'  => false,
                    'quantity'         => 1,
                    'actual_quantity'  => null,
                    'requires_document' => false,
                    'documents'        => $this->docs($a),
                    'children'         => [],
                ]);
        }

        $completed = $completedPlanned->concat($completedAdhoc)
            ->concat($teamCompletedPlanned)->concat($teamCompletedAdhoc)
            ->sortByDesc('completed_at')->values()->toArray();

        // «Сроки по клиентам» — уведомление (только чтение): просроченные и сегодняшние
        // НЕвыполненные задачи из того же списка $tasks, что и таблица. Завершать отсюда нельзя.
        // Это сводка-сигнал: их могут быть сотни, поэтому в разметку отдаём СЧЁТЧИКИ + первые N
        // (самые свежие), а не все строки — иначе блок рисует сотни DOM-узлов на каждый заход.
        $dueItems = collect($tasks)
            ->filter(fn ($t) => !empty($t['due_date'])
                && $t['due_date'] <= $todayStr
                && !in_array($t['status'], ['completed', 'review'], true))
            ->sortByDesc('due_date')->values(); // свежие сверху: сегодня и недавняя просрочка

        $reminderCounts = [
            'overdue' => $dueItems->where('due_date', '<', $todayStr)->count(),
            'today'   => $dueItems->where('due_date', $todayStr)->count(),
        ];
        $reminders = $dueItems->take(50)->map(fn ($t) => [
            'client_name'  => $t['client_name'] ?? '—',
            'name'         => $t['name'],
            'branch_label' => $t['branch_label'] ?? null,
            'due_date'     => $t['due_date'],
        ])->all();

        $completedDays = self::COMPLETED_HISTORY_DAYS;

        // Активные сотрудники — для назначения произвольной задачи (по умолчанию текущий)
        $employees = Employee::where('status', 'active')
            ->orderBy('full_name')
            ->get(['id', 'full_name']);

        // Каталог услуг для создания задачи «из каталога» — берём только id+name (имя переносится в задачу).
        $catalog = Service::roots()->active()->ordered()->get(['id', 'name'])
            ->map(fn ($s) => ['id' => $s->id, 'name' => $s->name])
            ->values()->toArray();

        return view('buhtasks.index', compact('year', 'month', 'employee', 'tasks', 'allClients', 'reminders', 'reminderCounts', 'completed', 'completedDays', 'employees', 'catalog', 'teamTasks', 'teamMembers'));
    }

    // =============================================
    // НАПОМИНАНИЯ О СРОКАХ (TaskReminder)
    // =============================================

    public function completeReminder(TaskReminder $reminder)
    {
        $this->authorizeReminder($reminder);

        $reminder->update([
            'status'       => TaskReminder::STATUS_DONE,
            'completed_at' => now(),
            'completed_by' => auth('employee')->id(),
        ]);

        return response()->json(['success' => true]);
    }

    public function reopenReminder(TaskReminder $reminder)
    {
        $this->authorizeReminder($reminder);

        $reminder->update([
            'status'       => TaskReminder::STATUS_PENDING,
            'completed_at' => null,
            'completed_by' => null,
        ]);

        return response()->json(['success' => true]);
    }

    private function authorizeReminder(TaskReminder $reminder): void
    {
        abort_if($reminder->employee_id !== auth('employee')->id(), 403);
    }

    // =============================================
    // ПЛАНОВЫЕ ЗАДАЧИ (BuhTaskLog)
    // =============================================

    public function getOrCreateLog(Request $request)
    {
        $request->validate([
            'client_id'        => 'required|exists:clients,id',
            'estimate_item_id' => 'required|exists:estimate_items,id',
            'year'             => 'required|integer',
            'month'            => 'required|integer|min:1|max:12',
            'due_date'         => 'nullable|date', // weekly → дата вхождения; иначе null (помесячный слот)
        ]);

        $employee = auth('employee')->user();

        // Ищем лог слота теми же правилами, что и список: если период уже закрыл прежний
        // исполнитель, отдаём его лог, а не заводим второй — иначе закрытое им прошлое
        // снова выглядело бы невыполненным. Свою запись заводим, только если готовой нет.
        $log = $this->logForEmployee(
            BuhTaskLog::where('client_id', $request->client_id)
                ->where('estimate_item_id', $request->estimate_item_id)
                ->where('year', $request->year)
                ->where('month', $request->month)
                ->when($request->due_date,
                    fn ($q) => $q->whereDate('due_date', $request->due_date),
                    fn ($q) => $q->whereNull('due_date'))
                ->get(),
            $employee->id,
        ) ?? BuhTaskLog::create([
            'employee_id'      => $employee->id,
            'client_id'        => $request->client_id,
            'estimate_item_id' => $request->estimate_item_id,
            'year'             => $request->year,
            'month'            => $request->month,
            'due_date'         => $request->due_date,
            'status'           => 'pending',
            'paused_seconds'   => 0,
        ]);

        return response()->json(['success' => true, 'log' => $this->formatLog($log)]);
    }

    public function start(BuhTaskLog $log)
    {
        $this->authorizeLog($log);

        $now = now();

        if ($log->status === 'pending') {
            $log->status     = 'running';
            $log->started_at = $now;
            $log->resumed_at = $now;
        } elseif (in_array($log->status, ['paused', 'rework'], true)) {
            $log->status     = 'running';
            $log->resumed_at = $now;
        }

        $log->save();

        return response()->json(['success' => true, 'log' => $this->formatLog($log)]);
    }

    public function pause(BuhTaskLog $log)
    {
        $this->authorizeLog($log);

        if ($log->status !== 'running') {
            return response()->json(['success' => false, 'message' => 'Задача не запущена'], 422);
        }

        $now = now();
        $workedSinceResume = $log->resumed_at
            ? max(0, $now->timestamp - $log->resumed_at->timestamp)
            : 0;
        $log->paused_seconds += $workedSinceResume;
        $log->status          = 'paused';
        $log->save();

        return response()->json(['success' => true, 'log' => $this->formatLog($log)]);
    }

    public function complete(BuhTaskLog $log)
    {
        $this->authorizeLog($log);

        if ($log->estimateItem?->service?->requires_document && !$log->documents()->exists()) {
            return response()->json([
                'success'            => false,
                'requires_document'  => true,
                'message'            => 'Для завершения задачи нужно прикрепить документ',
            ], 422);
        }

        // Задачу с подпунктами нельзя закрыть, пока все подпункты не выполнены
        $childIds = $log->estimateItem?->children()->pluck('id') ?? collect();
        if ($childIds->isNotEmpty()) {
            // Подпункты считаем по слоту, а не по сотруднику: после переназначения часть
            // галочек могла проставить прежний исполнитель — они остаются в силе.
            $doneChildren = BuhTaskLog::where('client_id', $log->client_id)
                ->where('year', $log->year)
                ->where('month', $log->month)
                ->when($log->due_date,
                    fn ($q) => $q->whereDate('due_date', $log->due_date),
                    fn ($q) => $q->whereNull('due_date'))
                ->whereIn('estimate_item_id', $childIds)
                ->where('status', 'completed')
                ->distinct()
                ->count('estimate_item_id');
            if ($doneChildren < $childIds->count()) {
                return response()->json([
                    'success'            => false,
                    'requires_checklist' => true,
                    'message'            => 'Сначала отметьте все подпункты задачи',
                ], 422);
            }
        }

        $now = now();

        if ($log->status === 'running' && $log->resumed_at) {
            $log->paused_seconds += max(0, $now->timestamp - $log->resumed_at->timestamp);
        }

        // На проверку идёт задача с requires_review, ТОЛЬКО если её выполнил не сам главбух клиента:
        // проверяет главбух (responsible_employee_id), поэтому свою же работу он не проверяет —
        // такая задача закрывается сразу (шаг 7.3).
        $responsibleId = Client::whereKey($log->client_id)->value('responsible_employee_id');
        $needsReview = ($log->estimateItem?->service?->requires_review)
            && (int) $log->employee_id !== (int) $responsibleId;

        if ($needsReview) {
            $log->status            = 'review';
            $log->review_comment    = null;
            $log->review_started_at = $now; // старт/перезапуск 3-дневного срока проверки
        } else {
            $log->status       = 'completed';
            $log->completed_at = $now;
        }

        // Нормальное закрытие снимает след принудительного (актуально после доработки:
        // задача была force-closed, вернулась с проверки и теперь сдана как положено).
        $log->force_closed        = false;
        $log->force_close_comment = null;

        $log->save();

        return response()->json(['success' => true, 'log' => $this->formatLog($log)]);
    }

    /**
     * Принудительное закрытие: в обход требования документа и чеклиста подпунктов,
     * с обязательным комментарием-причиной. Дальше всё как в complete():
     * requires_review → на проверку главбуху, иначе сразу completed.
     */
    public function forceComplete(Request $request, BuhTaskLog $log)
    {
        $this->authorizeLog($log);

        $validated = $request->validate([
            'comment' => 'required|string|max:2000',
        ], [
            'comment.required' => 'Укажите причину принудительного закрытия',
        ]);

        $now = now();

        if ($log->status === 'running' && $log->resumed_at) {
            $log->paused_seconds += max(0, $now->timestamp - $log->resumed_at->timestamp);
        }

        $log->force_closed        = true;
        $log->force_close_comment = $validated['comment'];

        $responsibleId = Client::whereKey($log->client_id)->value('responsible_employee_id');
        $needsReview = ($log->estimateItem?->service?->requires_review)
            && (int) $log->employee_id !== (int) $responsibleId;

        if ($needsReview) {
            $log->status            = 'review';
            $log->review_comment    = null;
            $log->review_started_at = $now;
        } else {
            $log->status       = 'completed';
            $log->completed_at = $now;
        }

        $log->save();

        return response()->json(['success' => true, 'log' => $this->formatLog($log)]);
    }

    public function reset(BuhTaskLog $log)
    {
        $this->authorizeLog($log);

        $log->status         = 'pending';
        $log->started_at     = null;
        $log->resumed_at     = null;
        $log->paused_seconds = 0;
        $log->completed_at   = null;
        $log->review_comment = null;
        $log->reviewed_at    = null;
        $log->reviewed_by    = null;
        $log->force_closed        = false;
        $log->force_close_comment = null;
        $log->save();

        return response()->json(['success' => true, 'log' => $this->formatLog($log)]);
    }

    public function updateQuantity(Request $request, BuhTaskLog $log)
    {
        $this->authorizeLog($log);

        $validated = $request->validate([
            'actual_quantity' => ['nullable', 'integer', 'min:0'],
        ]);

        $log->actual_quantity = $validated['actual_quantity'] ?? null;
        $log->save();

        return response()->json(['success' => true, 'log' => $this->formatLog($log)]);
    }

    public function updateComment(Request $request, BuhTaskLog $log)
    {
        $this->authorizeLog($log);

        $validated = $request->validate([
            'employee_comment' => ['nullable', 'string', 'max:5000'],
        ]);

        $log->employee_comment = $validated['employee_comment'] ?: null;
        $log->save();

        return response()->json(['success' => true, 'log' => $this->formatLog($log)]);
    }

    public function uploadDocument(Request $request, BuhTaskLog $log)
    {
        $this->authorizeLog($log);

        $request->validate([
            'file' => $this->documentFileRules(),
        ], [
            'file.required' => 'Выберите файл',
            'file.file'     => 'Не удалось прочитать файл — возможно, он превышает лимит сервера',
            'file.max'      => 'Файл не должен превышать 40 МБ',
        ]);

        if ($this->documentsLocked($log)) {
            return response()->json([
                'success' => false,
                'message' => 'Нельзя менять документы у закрытой задачи или задачи на проверке',
            ], 422);
        }

        if ($log->documents()->count() >= self::MAX_DOCUMENTS) {
            return response()->json([
                'success' => false,
                'message' => 'Не больше ' . self::MAX_DOCUMENTS . ' документов на задачу',
            ], 422);
        }

        $file         = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $extension    = $file->getClientOriginalExtension();
        $safeName     = pathinfo($originalName, PATHINFO_FILENAME) . '_' . time() . '.' . $extension;
        $path         = $file->storeAs('buh_task_documents/' . $log->id, $safeName, 'local');

        $log->documents()->create(['path' => $path, 'name' => $originalName]);

        return response()->json(['success' => true, 'log' => $this->formatLog($log)]);
    }

    /** Удаление прикреплённого документа — пока задача не закрыта и не на проверке. */
    public function deleteDocument(BuhTaskLog $log, BuhTaskDocument $document)
    {
        $this->authorizeLog($log);
        abort_unless($document->documentable_type === BuhTaskLog::class
            && (int) $document->documentable_id === (int) $log->id, 404);

        if ($this->documentsLocked($log)) {
            return response()->json([
                'success' => false,
                'message' => 'Нельзя удалить документ у закрытой задачи или задачи на проверке',
            ], 422);
        }

        Storage::disk('local')->delete($document->path);
        $document->delete();

        return response()->json(['success' => true, 'log' => $this->formatLog($log)]);
    }

    // =============================================
    // ВНЕПЛАНОВЫЕ ЗАДАЧИ (BuhAdhocTask)
    // =============================================

    public function storeAdhoc(Request $request)
    {
        // Произвольная задача: не в смете, без стоимости, с датой-напоминанием,
        // назначается любому активному сотруднику (по умолчанию — себе). Клиент необязателен.
        $validated = $request->validate([
            'employee_id'     => 'required|exists:employees,id',
            'client_id'       => 'nullable|exists:clients,id',
            'service_id'      => 'nullable|exists:services,id', // выбор из каталога — берём только имя
            'name'            => 'required|string|max:255',
            'description'     => 'nullable|string|max:2000',
            'requires_review' => 'boolean',
            'due_date'        => 'required|date',
            'file'            => $this->documentFileRules(required: false), // необязательный документ
        ], [
            'employee_id.required' => 'Выберите сотрудника',
            'name.required'        => 'Введите название задачи',
            'due_date.required'    => 'Укажите дату',
            'file.max'             => 'Файл не должен превышать 40 МБ',
        ]);

        $author   = auth('employee')->user();
        $assignee = Employee::where('status', 'active')->findOrFail($validated['employee_id']);
        $due      = CarbonImmutable::parse($validated['due_date']);

        // Из каталога переносим ТОЛЬКО название (по договорённости); описание/проверка — ручные.
        $name = $validated['name'];
        if (!empty($validated['service_id'])) {
            $name = Service::find($validated['service_id'])?->name ?? $name;
        }

        $adhoc = BuhAdhocTask::create([
            'employee_id'     => $assignee->id,
            'client_id'       => $validated['client_id'] ?? null,
            'service_id'      => $validated['service_id'] ?? null,
            'name'            => $name,
            'description'     => $validated['description'] ?? null,
            'requires_review' => (bool) ($validated['requires_review'] ?? false),
            'cost'            => 0,
            'year'            => $due->year,
            'month'           => $due->month,
            'due_day'         => $due->day,
            'status'          => 'pending',
            'paused_seconds'  => 0,
        ]);

        // Необязательный документ — прикрепляем сразу при создании (автор может приложить
        // даже когда задача назначена другому сотруднику).
        if ($request->hasFile('file')) {
            $file         = $request->file('file');
            $originalName = $file->getClientOriginalName();
            $safeName     = pathinfo($originalName, PATHINFO_FILENAME) . '_' . time() . '.' . $file->getClientOriginalExtension();
            $path         = $file->storeAs('buh_adhoc_documents/' . $adhoc->id, $safeName, 'local');
            $adhoc->documents()->create(['path' => $path, 'name' => $originalName]);
        }

        return response()->json([
            'success' => true,
            // Добавлять в текущий список — только если назначено себе
            'mine'    => $assignee->id === $author->id,
            'assignee_name' => $assignee->full_name,
            'task' => [
                'uid'             => 'adhoc_' . $adhoc->id,
                'type'            => 'adhoc',
                'is_custom'       => true,
                'adhoc_id'        => $adhoc->id,
                'client_id'       => $adhoc->client_id,
                'client_name'     => $adhoc->client_id ? Client::find($adhoc->client_id)?->name : null,
                'year'            => $adhoc->year,
                'month'           => $adhoc->month,
                'name'            => $adhoc->name,
                'description'     => $adhoc->description,
                'requires_review' => $adhoc->requires_review,
                'requires_document' => false, // документ всегда опционален для внеплановых
                'documents'       => $this->docs($adhoc->load('documents')),
                'review_comment'  => null,
                'employee_comment' => null,
                'cost'            => 0,
                'periodicity'     => null,
                'reporting_period' => null,
                'due_day'         => $adhoc->due_day,
                'due_date'        => $due->toDateString(),
                'status'          => 'pending',
                'elapsed_seconds' => 0,
                'loading'         => false,
                'client_resumed_at' => null,
            ],
        ]);
    }

    public function startAdhoc(BuhAdhocTask $task)
    {
        $this->authorizeAdhoc($task);

        $now = now();

        if ($task->status === 'pending') {
            $task->status     = 'running';
            $task->started_at = $now;
            $task->resumed_at = $now;
        } elseif (in_array($task->status, ['paused', 'rework'], true)) {
            $task->status     = 'running';
            $task->resumed_at = $now;
        }

        $task->save();

        return response()->json(['success' => true, 'log' => $this->formatAdhoc($task)]);
    }

    public function pauseAdhoc(BuhAdhocTask $task)
    {
        $this->authorizeAdhoc($task);

        if ($task->status !== 'running') {
            return response()->json(['success' => false, 'message' => 'Задача не запущена'], 422);
        }

        $now = now();
        $workedSinceResume = $task->resumed_at
            ? max(0, $now->timestamp - $task->resumed_at->timestamp)
            : 0;
        $task->paused_seconds += $workedSinceResume;
        $task->status          = 'paused';
        $task->save();

        return response()->json(['success' => true, 'log' => $this->formatAdhoc($task)]);
    }

    public function completeAdhoc(BuhAdhocTask $task)
    {
        $this->authorizeAdhoc($task);

        $now = now();

        if ($task->status === 'running' && $task->resumed_at) {
            $task->paused_seconds += max(0, $now->timestamp - $task->resumed_at->timestamp);
        }

        // Задача с проверкой уходит на ревью (3-дневный срок), НО если её выполнил сам главбух
        // клиента — проверять некому, закрываем сразу (шаг 7.3).
        $responsibleId = Client::whereKey($task->client_id)->value('responsible_employee_id');
        $needsReview = $task->requires_review
            && (int) $task->employee_id !== (int) $responsibleId;

        if ($needsReview) {
            $task->status            = 'review';
            $task->review_comment    = null;
            $task->review_started_at = $now;
        } else {
            $task->status       = 'completed';
            $task->completed_at = $now;
        }
        $task->save();

        return response()->json(['success' => true, 'log' => $this->formatAdhoc($task)]);
    }

    // =============================================
    // ПРОВЕРКА ГЛАВБУХОМ (шаг 7.2): принять / вернуть задачу бухгалтера
    // =============================================

    /** Действие проверки доступно admin или главбуху этого клиента (responsible_employee_id). */
    private function authorizeReview(int $clientId): void
    {
        $me = auth('employee')->user();
        abort_unless(
            $me->isAdmin() || Client::whereKey($clientId)->where('responsible_employee_id', $me->id)->exists(),
            403,
            'Нет прав на проверку этой задачи'
        );
    }

    public function approveReview(BuhTaskLog $log)
    {
        abort_if($log->status !== 'review', 422, 'Задача не на проверке');
        $this->authorizeReview((int) $log->client_id);

        $log->update([
            'status'       => 'completed',
            'completed_at' => now(),
            'reviewed_at'  => now(),
            'reviewed_by'  => auth('employee')->id(),
        ]);

        return response()->json(['success' => true]);
    }

    public function rejectReview(Request $request, BuhTaskLog $log)
    {
        abort_if($log->status !== 'review', 422, 'Задача не на проверке');
        $this->authorizeReview((int) $log->client_id);

        $validated = $request->validate(
            ['comment' => ['required', 'string', 'max:2000']],
            ['comment.required' => 'Укажите, что нужно исправить']
        );

        $log->update([
            'status'         => 'rework',
            'review_comment' => $validated['comment'],
            'rework_count'   => $log->rework_count + 1,
            'reviewed_at'    => now(),
            'reviewed_by'    => auth('employee')->id(),
        ]);

        return response()->json(['success' => true]);
    }

    public function approveReviewAdhoc(BuhAdhocTask $task)
    {
        abort_if($task->status !== 'review', 422, 'Задача не на проверке');
        $this->authorizeReview((int) $task->client_id);

        $task->update([
            'status'       => 'completed',
            'completed_at' => now(),
            'reviewed_at'  => now(),
            'reviewed_by'  => auth('employee')->id(),
        ]);

        return response()->json(['success' => true]);
    }

    public function rejectReviewAdhoc(Request $request, BuhAdhocTask $task)
    {
        abort_if($task->status !== 'review', 422, 'Задача не на проверке');
        $this->authorizeReview((int) $task->client_id);

        $validated = $request->validate(
            ['comment' => ['required', 'string', 'max:2000']],
            ['comment.required' => 'Укажите, что нужно исправить']
        );

        $task->update([
            'status'         => 'rework',
            'review_comment' => $validated['comment'],
            'rework_count'   => $task->rework_count + 1,
            'reviewed_at'    => now(),
            'reviewed_by'    => auth('employee')->id(),
        ]);

        return response()->json(['success' => true]);
    }

    public function updateCommentAdhoc(Request $request, BuhAdhocTask $task)
    {
        $this->authorizeAdhoc($task);

        $validated = $request->validate([
            'employee_comment' => ['nullable', 'string', 'max:5000'],
        ]);

        $task->employee_comment = $validated['employee_comment'] ?: null;
        $task->save();

        return response()->json(['success' => true, 'log' => $this->formatAdhoc($task)]);
    }

    public function uploadDocumentAdhoc(Request $request, BuhAdhocTask $task)
    {
        $this->authorizeAdhoc($task);

        $request->validate([
            'file' => $this->documentFileRules(),
        ], [
            'file.required' => 'Выберите файл',
            'file.file'     => 'Не удалось прочитать файл — возможно, он превышает лимит сервера',
            'file.max'      => 'Файл не должен превышать 40 МБ',
        ]);

        if ($this->documentsLocked($task)) {
            return response()->json([
                'success' => false,
                'message' => 'Нельзя менять документы у закрытой задачи или задачи на проверке',
            ], 422);
        }

        if ($task->documents()->count() >= self::MAX_DOCUMENTS) {
            return response()->json([
                'success' => false,
                'message' => 'Не больше ' . self::MAX_DOCUMENTS . ' документов на задачу',
            ], 422);
        }

        $file         = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $extension    = $file->getClientOriginalExtension();
        $safeName     = pathinfo($originalName, PATHINFO_FILENAME) . '_' . time() . '.' . $extension;
        $path         = $file->storeAs('buh_adhoc_documents/' . $task->id, $safeName, 'local');

        $task->documents()->create(['path' => $path, 'name' => $originalName]);

        return response()->json(['success' => true, 'log' => $this->formatAdhoc($task)]);
    }

    /** Удаление документа внеплановой задачи — пока не закрыта и не на проверке. */
    public function deleteDocumentAdhoc(BuhAdhocTask $task, BuhTaskDocument $document)
    {
        $this->authorizeAdhoc($task);
        abort_unless($document->documentable_type === BuhAdhocTask::class
            && (int) $document->documentable_id === (int) $task->id, 404);

        if ($this->documentsLocked($task)) {
            return response()->json([
                'success' => false,
                'message' => 'Нельзя удалить документ у закрытой задачи или задачи на проверке',
            ], 422);
        }

        Storage::disk('local')->delete($document->path);
        $document->delete();

        return response()->json(['success' => true, 'log' => $this->formatAdhoc($task)]);
    }

    public function resetAdhoc(BuhAdhocTask $task)
    {
        $this->authorizeAdhoc($task);

        $task->status            = 'pending';
        $task->started_at        = null;
        $task->resumed_at        = null;
        $task->paused_seconds    = 0;
        $task->completed_at      = null;
        $task->review_comment    = null;
        $task->review_started_at = null;
        $task->reviewed_at       = null;
        $task->reviewed_by       = null;
        $task->save();

        return response()->json(['success' => true, 'log' => $this->formatAdhoc($task)]);
    }

    /**
     * Удаление произвольной (внеплановой) задачи. Плановые задачи из сметы удалять
     * нельзя — они генерируются расписанием; удаляем только BuhAdhocTask, созданную
     * вручную. Доступно исполнителю задачи (тому, в чьём списке она висит).
     */
    public function destroyAdhoc(BuhAdhocTask $task)
    {
        $this->authorizeAdhoc($task);

        // Файлы: и новые (documents), и оставшийся от старой схемы одиночный
        foreach ($task->documents as $doc) {
            Storage::disk('local')->delete($doc->path);
        }
        $task->documents()->delete();
        if ($task->document_path) {
            Storage::disk('local')->delete($task->document_path);
        }

        $task->delete();

        return response()->json(['success' => true]);
    }

    // =============================================
    // ПРИВАТНЫЕ МЕТОДЫ
    // =============================================

    private function authorizeLog(BuhTaskLog $log): void
    {
        $employee = auth('employee')->user();
        abort_if($log->employee_id !== $employee->id, 403);
    }

    /**
     * Лог слота глазами конкретного исполнителя. Закрытая задача (и ушедшая на проверку) —
     * ОБЩИЙ факт: её видит любой, кто окажется исполнителем этого БП, поэтому после смены
     * исполнителя прошлые закрытые периоды не всплывают у нового бухгалтера как «просрочено».
     * А незаконченная работа личная: чужой лог «в работе» не подхватываем — иначе сотрудник
     * увидел бы чужой таймер и упёрся в 403 при попытке что-то с ним сделать.
     */
    private function logForEmployee(?Collection $slot, ?int $employeeId): ?BuhTaskLog
    {
        if (!$slot || $slot->isEmpty()) {
            return null;
        }

        $finished = $slot->whereIn('status', ['completed', 'review']);
        if ($finished->isNotEmpty()) {
            return $this->pickLog($finished);
        }

        return $employeeId ? $slot->firstWhere('employee_id', $employeeId) : null;
    }

    /**
     * Победитель среди логов одного слота: после переназначения БП у периода могут
     * оказаться записи разных исполнителей. Берём самый «продвинутый» статус, при
     * равенстве — закрытую раньше (настоящая работа, а не повторное закрытие фантома).
     */
    private function pickLog(Collection $slot): ?BuhTaskLog
    {
        return $slot->sort(function (BuhTaskLog $a, BuhTaskLog $b) {
            $rank = (self::LOG_STATUS_RANK[$b->status] ?? 0) <=> (self::LOG_STATUS_RANK[$a->status] ?? 0);
            if ($rank !== 0) {
                return $rank;
            }

            $done = ($a->completed_at?->timestamp ?? PHP_INT_MAX) <=> ($b->completed_at?->timestamp ?? PHP_INT_MAX);

            return $done !== 0 ? $done : $a->id <=> $b->id;
        })->first();
    }

    private function authorizeAdhoc(BuhAdhocTask $task): void
    {
        $employee = auth('employee')->user();
        abort_if($task->employee_id !== $employee->id, 403);
    }

    /** Документы задачи для фронта: [{id, name, url}], по порядку добавления. */
    private function docs($model): array
    {
        return $model->documents
            ->map(fn ($d) => ['id' => $d->id, 'name' => $d->name, 'url' => $d->url])
            ->values()
            ->all();
    }

    private function formatLog(BuhTaskLog $log): array
    {
        return [
            'id'              => $log->id,
            'status'          => $log->status,
            'elapsed_seconds' => $this->calcElapsed($log),
            'review_comment'  => $log->review_comment,
            'employee_comment' => $log->employee_comment,
            'actual_quantity' => $log->actual_quantity,
            'documents'       => $this->docs($log->load('documents')),
            'force_closed'        => (bool) $log->force_closed,
            'force_close_comment' => $log->force_close_comment,
        ];
    }

    private function formatAdhoc(BuhAdhocTask $task): array
    {
        return [
            'id'              => $task->id,
            'status'          => $task->status,
            'elapsed_seconds' => $this->calcElapsed($task),
            'review_comment'  => $task->review_comment,
            'employee_comment' => $task->employee_comment,
            'documents'       => $this->docs($task->load('documents')),
        ];
    }

    private function calcElapsed($log): int
    {
        if (!$log || !$log->started_at) return 0;

        $now = now()->timestamp;

        return match ($log->status) {
            'running'   => $log->paused_seconds + ($log->resumed_at ? max(0, $now - $log->resumed_at->timestamp) : 0),
            'paused', 'review', 'rework', 'completed' => $log->paused_seconds,
            default     => 0,
        };
    }
}
