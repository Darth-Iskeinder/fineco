<?php

namespace App\Http\Controllers;

use App\Models\BuhAdhocTask;
use App\Models\BuhTaskLog;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Всплывающие уведомления сотруднику: о них он должен узнать, не заходя в задачник.
 *
 * Поводов два: задачу ПОРУЧИЛИ (её завёл кто-то другой) и работу ВЕРНУЛИ на доработку.
 * И то, и другое легко пропустить: поручённая задача просто появляется среди прочих,
 * а возврат виден, только если открыть список.
 *
 * Уведомление висит, пока сотрудник не нажмёт «Понятно» — отметка о просмотре хранится
 * в самой задаче (assign_seen_at / rework_seen_at), а не в браузере: иначе, зайдя с
 * другого компьютера, он получил бы всё заново.
 */
class TaskAlertController extends Controller
{
    /** Сколько уведомлений отдаём за раз: карточка всё равно показывает список коротко. */
    private const MAX_ITEMS = 20;

    public function index()
    {
        $employee = auth('employee')->user();

        // Задачник закрыт модулем: незачем звать туда того, кто всё равно не войдёт.
        if (!$employee->hasAccessToModule('buhtasks')) {
            return response()->json(['items' => []]);
        }

        $items = collect();

        // 1. Задачу поручил кто-то другой, и сотрудник её ещё не видел.
        //    Закрытые пропускаем: сообщать о работе, которая уже сделана, незачем.
        $assigned = BuhAdhocTask::where('employee_id', $employee->id)
            ->whereNull('assign_seen_at')
            ->whereNotNull('created_by')
            ->whereColumn('created_by', '!=', 'employee_id')
            ->where('status', '!=', 'completed')
            ->with(['creator:id,full_name', 'client:id,name'])
            ->latest('id')
            ->limit(self::MAX_ITEMS)
            ->get()
            ->map(fn (BuhAdhocTask $t) => [
                'key'         => 'assigned:adhoc:' . $t->id,
                'kind'        => 'assigned',
                'name'        => $t->name,
                'client_name' => $t->client?->name,
                'due_date'    => $this->dueDate($t->year, $t->month, $t->due_day),
                'from_name'   => $t->creator?->full_name,
                'comment'     => null,
            ]);

        // 2. Работу вернули на доработку — и у внеплановых, и у плановых задач из сметы.
        $reworkAdhoc = BuhAdhocTask::where('employee_id', $employee->id)
            ->where('status', 'rework')
            ->whereNull('rework_seen_at')
            ->with(['reviewer:id,full_name', 'client:id,name'])
            ->latest('reviewed_at')
            ->limit(self::MAX_ITEMS)
            ->get()
            ->map(fn (BuhAdhocTask $t) => [
                'key'         => 'rework:adhoc:' . $t->id,
                'kind'        => 'rework',
                'name'        => $t->name,
                'client_name' => $t->client?->name,
                'due_date'    => $this->dueDate($t->year, $t->month, $t->due_day),
                'from_name'   => $t->reviewer?->full_name,
                'comment'     => $t->review_comment,
            ]);

        $reworkLogs = BuhTaskLog::where('employee_id', $employee->id)
            ->where('status', 'rework')
            ->whereNull('rework_seen_at')
            ->with(['reviewer:id,full_name', 'client:id,name', 'estimateItem:id,name'])
            ->latest('reviewed_at')
            ->limit(self::MAX_ITEMS)
            ->get()
            ->map(fn (BuhTaskLog $l) => [
                'key'         => 'rework:log:' . $l->id,
                'kind'        => 'rework',
                'name'        => $l->estimateItem?->name ?? 'Задача',
                'client_name' => $l->client?->name,
                'due_date'    => $l->due_date?->toDateString(),
                'from_name'   => $l->reviewer?->full_name,
                'comment'     => $l->review_comment,
            ]);

        // Доработка выше новых поручений: это работа, которую уже ждут обратно.
        $items = $reworkAdhoc->concat($reworkLogs)->concat($assigned)
            ->take(self::MAX_ITEMS)
            ->values();

        return response()->json(['items' => $items]);
    }

    /** «Понятно»: гасим уведомления по ключам, но только на своих задачах. */
    public function seen(Request $request)
    {
        $validated = $request->validate([
            'keys'   => ['required', 'array', 'max:' . self::MAX_ITEMS],
            'keys.*' => ['string', 'max:40'],
        ]);

        $employeeId = auth('employee')->id();
        $buckets    = ['assigned:adhoc' => [], 'rework:adhoc' => [], 'rework:log' => []];

        foreach ($validated['keys'] as $key) {
            [$kind, $type, $id] = array_pad(explode(':', $key), 3, null);
            $bucket = $kind . ':' . $type;

            if (isset($buckets[$bucket]) && ctype_digit((string) $id)) {
                $buckets[$bucket][] = (int) $id;
            }
        }

        $now = now();

        if ($buckets['assigned:adhoc']) {
            BuhAdhocTask::whereIn('id', $buckets['assigned:adhoc'])
                ->where('employee_id', $employeeId)
                ->update(['assign_seen_at' => $now]);
        }

        if ($buckets['rework:adhoc']) {
            BuhAdhocTask::whereIn('id', $buckets['rework:adhoc'])
                ->where('employee_id', $employeeId)
                ->update(['rework_seen_at' => $now]);
        }

        if ($buckets['rework:log']) {
            BuhTaskLog::whereIn('id', $buckets['rework:log'])
                ->where('employee_id', $employeeId)
                ->update(['rework_seen_at' => $now]);
        }

        return response()->json(['success' => true]);
    }

    /** Срок внеплановой задачи: день внутри её месяца, с поправкой на короткие месяцы. */
    private function dueDate(int $year, int $month, ?int $day): ?string
    {
        if (!$day) {
            return null;
        }

        $first = Carbon::create($year, $month, 1);

        return Carbon::create($year, $month, min($day, $first->daysInMonth))->toDateString();
    }
}
