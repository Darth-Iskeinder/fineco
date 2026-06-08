<?php

namespace App\Http\Controllers;

use App\Models\BuhAdhocTask;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Service;
use App\Models\TaskReminder;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

class BuhTasksController extends Controller
{
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
                        ->orderBy('sort_order'),
                    ]),
            ])
            ->whereHas('estimates', fn($q) => $q
                ->whereHas('rootItems', fn($q2) => $q2->whereNull('parent_id')))
            ->orderBy('name')
            ->get();

        // Все клиенты сотрудника (для создания внеплановых задач)
        $allClients = $employee->responsibleClients()->orderBy('name')->get(['id', 'name']);

        // Логи плановых задач за этот период
        $logs = BuhTaskLog::where('employee_id', $employee->id)
            ->where('year', $year)
            ->where('month', $month)
            ->get()
            ->keyBy('estimate_item_id');

        // Внеплановые задачи за этот период
        $adhocs = BuhAdhocTask::where('employee_id', $employee->id)
            ->where('year', $year)
            ->where('month', $month)
            ->with('client')
            ->get();

        // Окно выбранного месяца — для расчёта срока из расписания БП
        $monthStart = CarbonImmutable::create($year, $month, 1)->startOfDay();
        $monthEnd   = $monthStart->endOfMonth();

        // Предзагрузка БП по сметным позициям (нужны их методы расписания)
        $serviceIds = $clients
            ->flatMap(fn ($c) => $c->estimates->first()?->rootItems ?? collect())
            ->pluck('service_id')->filter()->unique()->values();
        $services = $serviceIds->isNotEmpty()
            ? Service::whereIn('id', $serviceIds)->get()->keyBy('id')
            : collect();

        // Плановые задачи из сметы
        $tasks = [];
        foreach ($clients as $client) {
            $items = $client->estimates->first()?->rootItems ?? collect();
            $overrides = $client->serviceSchedules->keyBy('service_id');
            foreach ($items as $item) {
                $log = $logs->get($item->id);

                // Срок: считаем из расписания БП (с учётом override клиента) на выбранный месяц;
                // если расписания нет — фолбэк на статичное поле позиции сметы.
                $dueDay = $item->due_day;
                $service = $item->service_id ? $services->get($item->service_id) : null;
                if ($service) {
                    $dates = $service->dueDatesForClient($overrides->get($item->service_id), $monthStart, $monthEnd);
                    if (!empty($dates)) {
                        $dueDay = min(array_map(fn ($d) => (int) $d->day, $dates));
                    }
                }

                $tasks[] = [
                    'uid'             => 'planned_' . $item->id,
                    'type'            => 'planned',
                    'item_id'         => $item->id,
                    'client_id'       => $client->id,
                    'client_name'     => $client->name,
                    'log_id'          => $log?->id,
                    'name'            => $item->name,
                    'cost'            => (float) $item->total,
                    'periodicity'     => $item->periodicity,
                    'due_day'         => $dueDay,
                    'comment'         => $service?->comment,
                    'description'     => $service?->description,
                    'status'          => $log?->status ?? 'pending',
                    'elapsed_seconds' => $this->calcElapsed($log),
                ];
            }
        }

        // Внеплановые задачи
        foreach ($adhocs as $adhoc) {
            $tasks[] = [
                'uid'             => 'adhoc_' . $adhoc->id,
                'type'            => 'adhoc',
                'adhoc_id'        => $adhoc->id,
                'client_id'       => $adhoc->client_id,
                'client_name'     => $adhoc->client->name,
                'name'            => $adhoc->name,
                'cost'            => (float) $adhoc->cost,
                'periodicity'     => null,
                'due_day'         => $adhoc->due_day,
                'comment'         => null,
                'description'     => null,
                'status'          => $adhoc->status,
                'elapsed_seconds' => $this->calcElapsed($adhoc),
            ];
        }

        $services = Service::with('children')->roots()->active()->ordered()->get()
            ->map(fn($s) => [
                'id'          => $s->id,
                'name'        => $s->name,
                'cost'        => (float) $s->cost,
                'periodicity' => $s->periodicity ?? '',
                'children'    => $s->children->map(fn($c) => [
                    'id'          => $c->id,
                    'name'        => $c->name,
                    'cost'        => (float) $c->cost,
                    'periodicity' => $c->periodicity ?? '',
                ])->values()->toArray(),
            ])->values()->toArray();

        // Напоминания о сроках (выход воркера tasks:generate) — активные (невыполненные)
        $reminders = TaskReminder::where('employee_id', $employee->id)
            ->where('status', TaskReminder::STATUS_PENDING)
            ->with('client:id,name')
            ->orderBy('due_date')
            ->get()
            ->map(fn ($r) => [
                'id'          => $r->id,
                'client_id'   => $r->client_id,
                'client_name' => $r->client?->name ?? '—',
                'name'        => $r->name,
                'periodicity' => $r->periodicity,
                'due_date'    => $r->due_date->toDateString(),
            ])->values()->toArray();

        // Проекция расписаний на календарь — вживую из расписаний БП (не ограничена
        // горизонтом воркера), чтобы видеть сроки на месяцы вперёд и ничего не терялось.
        $calFrom = now()->startOfMonth()->subMonths(2);
        $calTo   = now()->startOfMonth()->addMonths(12)->endOfMonth();

        $calServiceIds = $clients
            ->flatMap(fn ($c) => $c->estimates->flatMap->rootItems->pluck('service_id'))
            ->filter()->unique();
        $calServices = $calServiceIds->isNotEmpty()
            ? Service::whereIn('id', $calServiceIds)->get()->keyBy('id')
            : collect();

        $schedule = [];
        foreach ($clients as $client) {
            $overrides = $client->serviceSchedules->keyBy('service_id');
            foreach (($client->estimates->first()?->rootItems ?? collect()) as $item) {
                $svc = $calServices->get($item->service_id);
                if (!$svc) {
                    continue;
                }
                foreach ($svc->dueDatesForClient($overrides->get($item->service_id), $calFrom, $calTo) as $date) {
                    $schedule[] = [
                        'date'        => $date->toDateString(),
                        'name'        => $item->name,
                        'client_id'   => $client->id,
                        'client_name' => $client->name,
                    ];
                }
            }
        }

        return view('buhtasks.index', compact('year', 'month', 'employee', 'tasks', 'allClients', 'services', 'reminders', 'schedule'));
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
    // ДОБАВИТЬ ЗАДАЧУ В СМЕТУ (extra EstimateItem)
    // =============================================

    public function storeExtra(Request $request)
    {
        $request->validate([
            'client_id'  => 'required|exists:clients,id',
            'service_id' => 'nullable|exists:services,id',
            'name'       => 'required_without:service_id|nullable|string|max:255',
            'cost'       => 'nullable|numeric|min:0',
        ]);

        $employee = auth('employee')->user();

        abort_if(
            !$employee->responsibleClients()->where('id', $request->client_id)->exists(),
            403
        );

        $client = Client::find($request->client_id);

        $estimate = Estimate::firstOrCreate(
            ['client_id' => $client->id],
            ['total' => 0]
        );

        if ($request->service_id) {
            $service     = Service::findOrFail($request->service_id);
            $name        = $service->name;
            $cost        = (float) $service->cost;
            $periodicity = $service->periodicity;
            $dueDay      = $service->due_day;
            $serviceId   = $service->id;
        } else {
            $name        = $request->name;
            $cost        = (float) ($request->cost ?? 0);
            $periodicity = null;
            $dueDay      = $request->due_day ? (int) $request->due_day : null;
            $serviceId   = null;
        }

        $sortOrder = ($estimate->items()->max('sort_order') ?? 0) + 1;

        $item = $estimate->items()->create([
            'service_id'  => $serviceId,
            'type'        => 'one_time',
            'name'        => $name,
            'periodicity' => $periodicity,
            'due_day'     => $dueDay,
            'cost'        => $cost,
            'quantity'    => 1,
            'total'       => $cost,
            'sort_order'  => $sortOrder,
        ]);

        $estimate->total = $estimate->items()->whereNull('parent_id')->sum('total');
        $estimate->save();

        return response()->json([
            'success' => true,
            'task' => [
                'uid'               => 'planned_' . $item->id,
                'type'              => 'planned',
                'item_id'           => $item->id,
                'client_id'         => $client->id,
                'client_name'       => $client->name,
                'log_id'            => null,
                'name'              => $item->name,
                'cost'              => (float) $item->total,
                'periodicity'       => $item->periodicity,
                'due_day'           => $item->due_day,
                'status'            => 'pending',
                'elapsed_seconds'   => 0,
                'loading'           => false,
                'client_resumed_at' => null,
            ],
        ]);
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
        ]);

        $employee = auth('employee')->user();

        $log = BuhTaskLog::firstOrCreate([
            'employee_id'      => $employee->id,
            'client_id'        => $request->client_id,
            'estimate_item_id' => $request->estimate_item_id,
            'year'             => $request->year,
            'month'            => $request->month,
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
        } elseif ($log->status === 'paused') {
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

        $now = now();

        if ($log->status === 'running' && $log->resumed_at) {
            $log->paused_seconds += max(0, $now->timestamp - $log->resumed_at->timestamp);
        }

        $log->status       = 'completed';
        $log->completed_at = $now;
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
        $log->save();

        return response()->json(['success' => true, 'log' => $this->formatLog($log)]);
    }

    // =============================================
    // ВНЕПЛАНОВЫЕ ЗАДАЧИ (BuhAdhocTask)
    // =============================================

    public function storeAdhoc(Request $request)
    {
        $request->validate([
            'client_id' => 'required|exists:clients,id',
            'name'      => 'required|string|max:255',
            'cost'      => 'required|numeric|min:0',
            'year'      => 'required|integer',
            'month'     => 'required|integer|min:1|max:12',
            'due_day'   => 'nullable|integer|min:1|max:31',
        ]);

        $employee = auth('employee')->user();

        abort_if(
            !$employee->responsibleClients()->where('id', $request->client_id)->exists(),
            403
        );

        $adhoc = BuhAdhocTask::create([
            'employee_id'    => $employee->id,
            'client_id'      => $request->client_id,
            'name'           => $request->name,
            'cost'           => $request->cost,
            'year'           => $request->year,
            'month'          => $request->month,
            'due_day'        => $request->due_day,
            'status'         => 'pending',
            'paused_seconds' => 0,
        ]);

        return response()->json([
            'success' => true,
            'task' => [
                'uid'             => 'adhoc_' . $adhoc->id,
                'type'            => 'adhoc',
                'adhoc_id'        => $adhoc->id,
                'client_id'       => $adhoc->client_id,
                'client_name'     => Client::find($adhoc->client_id)->name,
                'name'            => $adhoc->name,
                'cost'            => (float) $adhoc->cost,
                'periodicity'     => null,
                'due_day'         => $adhoc->due_day,
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
        } elseif ($task->status === 'paused') {
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

        $task->status       = 'completed';
        $task->completed_at = $now;
        $task->save();

        return response()->json(['success' => true, 'log' => $this->formatAdhoc($task)]);
    }

    public function resetAdhoc(BuhAdhocTask $task)
    {
        $this->authorizeAdhoc($task);

        $task->status         = 'pending';
        $task->started_at     = null;
        $task->resumed_at     = null;
        $task->paused_seconds = 0;
        $task->completed_at   = null;
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
        ];
    }

    private function formatAdhoc(BuhAdhocTask $task): array
    {
        return [
            'id'              => $task->id,
            'status'          => $task->status,
            'elapsed_seconds' => $this->calcElapsed($task),
        ];
    }

    private function calcElapsed($log): int
    {
        if (!$log || !$log->started_at) return 0;

        $now = now()->timestamp;

        return match ($log->status) {
            'running'   => $log->paused_seconds + ($log->resumed_at ? max(0, $now - $log->resumed_at->timestamp) : 0),
            'paused'    => $log->paused_seconds,
            'completed' => $log->paused_seconds,
            default     => 0,
        };
    }
}
