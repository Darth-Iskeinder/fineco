<?php

namespace App\Http\Controllers;

use App\Models\BuhAdhocTask;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Service;
use App\Models\TaskReminder;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BuhTasksController extends Controller
{
    /** Глубина истории вкладки «Выполненные» (дней назад) */
    private const COMPLETED_HISTORY_DAYS = 90;

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

        // Клиенты, за которых сотрудник ответственный, с непустой сметой (одна на клиента)
        $clients = $employee->responsibleClients()
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

        // Все клиенты сотрудника (для создания внеплановых задач)
        $allClients = $employee->responsibleClients()->orderBy('name')->get(['id', 'name']);

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

        // Логи плановых задач сотрудника (нужны статусы за прошлое для просрочки), ключ year-month-item.
        // Ограничиваем годом окна просрочки (6 мес назад) — старые логи всё равно не запрашиваются,
        // а индекс (employee_id, year, month) поднимает в память кратно меньше строк.
        // Ключ лога — «слот»: year-month-item + дата для weekly (due_date), иначе пусто.
        // Так weekly-вхождения в одном месяце различаются, а помесячные логи (due_date=NULL)
        // продолжают матчиться как раньше — без бэкфилла.
        $logs = BuhTaskLog::where('employee_id', $employee->id)
            ->where('year', '>=', $today->subMonths(6)->year)
            ->get()
            ->keyBy(fn ($l) => $l->year . '-' . $l->month . '-' . $l->estimate_item_id
                . ($l->due_date ? '-' . $l->due_date->toDateString() : ''));

        // Все внеплановые задачи сотрудника (невыполненные висят, пока не закроют)
        $adhocs = BuhAdhocTask::where('employee_id', $employee->id)
            ->with('client')
            ->get();

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
                    $log = $logs->get($wy . '-' . $wm . '-' . $item->id . $slotKey);
                    $status = $log?->status ?? 'pending';

                    // Фильтр видимости в активном списке:
                    if ($status === 'completed') {
                        // выполненные — только в день закрытия (дальше уходят во вкладку «Выполненные»)
                        $completedToday = $log?->completed_at && $log->completed_at->toDateString() === $todayStr;
                        if (!$completedToday) {
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
                        'document_name'    => $log?->document_name,
                        'document_path'    => $log?->document_path,
                        'children'        => $item->children->map(function ($child) use ($logs, $wy, $wm, $slotKey, $slot) {
                            $childLog = $logs->get($wy . '-' . $wm . '-' . $child->id . $slotKey);

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
                                'document_name'      => $childLog?->document_name,
                                'document_path'      => $childLog?->document_path,
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
                $completedToday = $adhoc->completed_at && $adhoc->completed_at->toDateString() === $todayStr;
                if (!$completedToday) {
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
                'document_name'   => $adhoc->document_name,
                'document_path'  => $adhoc->document_path,
                'children'        => [],
            ];
        }

        // Вкладка «Выполненные» — история за последние 90 дней (read-only): плановые + внеплановые.
        $completedPlanned = BuhTaskLog::where('employee_id', $employee->id)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $historyFrom)
            ->with(['estimateItem.service', 'estimateItem.children.service', 'client:id,name'])
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
                    'document_name'    => $l->document_name,
                    'document_path'    => $l->document_path,
                    'children'         => ($item?->children ?? collect())->map(function ($child) use ($logs, $l) {
                        $cSlotKey = $l->due_date ? '-' . $l->due_date->toDateString() : '';
                        $childLog = $logs->get($l->year . '-' . $l->month . '-' . $child->id . $cSlotKey);
                        $cs = $child->service;

                        return [
                            'id'                => $child->id,
                            'name'              => $child->name,
                            'status'            => $childLog?->status ?? 'pending',
                            'allows_quantity'   => (bool) ($cs?->allows_quantity),
                            'quantity'          => (int) $child->quantity,
                            'actual_quantity'   => $childLog?->actual_quantity,
                            'requires_document' => (bool) ($cs?->requires_document),
                            'document_name'     => $childLog?->document_name,
                            'document_path'     => $childLog?->document_path,
                        ];
                    })->values()->toArray(),
                ];
            });
        $completedAdhoc = BuhAdhocTask::where('employee_id', $employee->id)
            ->where('status', 'completed')
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $historyFrom)
            ->with('client:id,name')
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
                'document_name'    => $a->document_name,
                'document_path'    => $a->document_path,
                'children'         => [],
            ]);
        $completed = $completedPlanned->concat($completedAdhoc)
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

        return view('buhtasks.index', compact('year', 'month', 'employee', 'tasks', 'allClients', 'reminders', 'reminderCounts', 'completed', 'completedDays', 'employees', 'catalog'));
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

        $log = BuhTaskLog::firstOrCreate([
            'employee_id'      => $employee->id,
            'client_id'        => $request->client_id,
            'estimate_item_id' => $request->estimate_item_id,
            'year'             => $request->year,
            'month'            => $request->month,
            'due_date'         => $request->due_date,
        ], [
            'status'         => 'pending',
            'paused_seconds' => 0,
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

        if ($log->estimateItem?->service?->requires_document && !$log->document_path) {
            return response()->json([
                'success'            => false,
                'requires_document'  => true,
                'message'            => 'Для завершения задачи нужно прикрепить документ',
            ], 422);
        }

        // Задачу с подпунктами нельзя закрыть, пока все подпункты не выполнены
        $childIds = $log->estimateItem?->children()->pluck('id') ?? collect();
        if ($childIds->isNotEmpty()) {
            $doneChildren = BuhTaskLog::where('employee_id', $log->employee_id)
                ->where('year', $log->year)
                ->where('month', $log->month)
                ->when($log->due_date,
                    fn ($q) => $q->whereDate('due_date', $log->due_date),
                    fn ($q) => $q->whereNull('due_date'))
                ->whereIn('estimate_item_id', $childIds)
                ->where('status', 'completed')
                ->count();
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

        if ($log->estimateItem?->service?->requires_review) {
            $log->status            = 'review';
            $log->review_comment    = null;
            $log->review_started_at = $now; // старт/перезапуск 3-дневного срока проверки
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
            'file' => ['required', 'file', 'max:40960'],
        ], [
            'file.required' => 'Выберите файл',
            'file.file'     => 'Не удалось прочитать файл — возможно, он превышает лимит сервера',
            'file.max'      => 'Файл не должен превышать 40 МБ',
        ]);

        if ($log->document_path) {
            Storage::disk('public')->delete($log->document_path);
        }

        $file         = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $extension    = $file->getClientOriginalExtension();
        $safeName     = pathinfo($originalName, PATHINFO_FILENAME) . '_' . time() . '.' . $extension;
        $path         = $file->storeAs('buh_task_documents/' . $log->id, $safeName, 'public');

        $log->document_path = $path;
        $log->document_name = $originalName;
        $log->save();

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
            'file'            => 'nullable|file|max:40960', // необязательный документ
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
            $path         = $file->storeAs('buh_adhoc_documents/' . $adhoc->id, $safeName, 'public');
            $adhoc->update(['document_path' => $path, 'document_name' => $originalName]);
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
                'document_name'   => $adhoc->document_name,
                'document_path'   => $adhoc->document_path,
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

        // Задача с проверкой уходит на ревью (3-дневный срок), иначе сразу завершена.
        if ($task->requires_review) {
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
            'file' => ['required', 'file', 'max:40960'],
        ], [
            'file.required' => 'Выберите файл',
            'file.file'     => 'Не удалось прочитать файл — возможно, он превышает лимит сервера',
            'file.max'      => 'Файл не должен превышать 40 МБ',
        ]);

        if ($task->document_path) {
            Storage::disk('public')->delete($task->document_path);
        }

        $file         = $request->file('file');
        $originalName = $file->getClientOriginalName();
        $extension    = $file->getClientOriginalExtension();
        $safeName     = pathinfo($originalName, PATHINFO_FILENAME) . '_' . time() . '.' . $extension;
        $path         = $file->storeAs('buh_adhoc_documents/' . $task->id, $safeName, 'public');

        $task->document_path = $path;
        $task->document_name = $originalName;
        $task->save();

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

    // =============================================
    // ПРИВАТНЫЕ МЕТОДЫ
    // =============================================

    private function authorizeLog(BuhTaskLog $log): void
    {
        $employee = auth('employee')->user();
        abort_if($log->employee_id !== $employee->id, 403);
    }

    private function authorizeAdhoc(BuhAdhocTask $task): void
    {
        $employee = auth('employee')->user();
        abort_if($task->employee_id !== $employee->id, 403);
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
            'document_name'   => $log->document_name,
            'document_path'   => $log->document_path,
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
            'document_name'   => $task->document_name,
            'document_path'   => $task->document_path,
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
