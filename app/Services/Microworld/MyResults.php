<?php

namespace App\Services\Microworld;

use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\ClientResponsibleChange;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Services\Dashboard\MonthPeople;
use App\Services\Dashboard\PlannedTask;
use App\Services\Dashboard\PlannedTaskWalk;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Результаты сотрудника для «Микромира», только сметные задачи. Два разреза за один проход:
 *
 *  - личный: задачи, которые он делает сам (или уже сдал);
 *  - команда: все задачи клиентов, которых он ведёт как главбух, кто бы их ни делал.
 *    Есть только у того, на ком в окне были клиенты. Группировка по факту, а не по роли,
 *    как на дашборде: руководитель с клиентами тоже получает команду.
 *
 * Кому засчитана задача:
 *  - сданная (на проверке или закрытая) тому, кто её сдал, по журналу;
 *  - остальные тому, кто исполнитель в том месяце (MonthPeople::doer), как на дашборде.
 * Главбух задачи: кто вёл клиента в конце её месяца (MonthPeople::lead).
 */
final class MyResults
{
    /** Раньше этой даты задач в системе нет (BuhTasksController::BACKLOG_CUTOFF). */
    public const CUTOFF = '2026-07-01';

    /** Сколько месяцев в графике. */
    public const HISTORY = 6;

    /** Цвета людей в кольце команды: палитра дашборда, хвост сворачивается в «Другие». */
    public const COLORS = ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300'];
    public const OTHER_COLOR = '#94a3b8';

    /** Поля логов, которые читают обход, OnTimeRule и этот класс. */
    private const LOG_COLUMNS = [
        'id', 'tenant_id', 'client_id', 'estimate_item_id', 'employee_id', 'year', 'month', 'due_date',
        'status', 'review_started_at', 'reviewed_at', 'completed_at', 'updated_at',
    ];

    public function __construct(private readonly CarbonImmutable $today) {}

    /**
     * @return array{
     *   month: array, history: list<array>, overdue: list<array>,
     *   team: ?array{month: array, history: list<array>, members: list<array>, ring: list<array>},
     * }
     */
    public function build(Employee $employee, CarbonImmutable $focus): array
    {
        $cutoff = CarbonImmutable::parse(self::CUTOFF)->startOfMonth();
        $focus  = $focus->startOfMonth();

        // Месяцы графика: шесть по выбранный включительно, но не раньше начала задач
        $months = [];
        for ($i = self::HISTORY - 1; $i >= 0; $i--) {
            $m = $focus->subMonths($i);
            if ($m->gte($cutoff)) {
                $months[$m->format('Y-n')] = ['year' => $m->year, 'month' => $m->month] + self::blank();
            }
        }
        $focusKey = $focus->format('Y-n');

        // Окно обхода: график плюс полгода назад от сегодня под списки просрочек
        $from = $focus->subMonths(self::HISTORY - 1)->min($this->today->startOfMonth()->subMonths(6))->max($cutoff);
        $to   = $this->today->endOfMonth()->max($focus->endOfMonth());

        // Обход только по клиентам сотрудника и только с теми полями логов, что нужны здесь
        $walk = PlannedTaskWalk::load($from, $to, $from, $this->today, self::touchedClients($employee, $from->year), self::LOG_COLUMNS);

        $employees = Employee::withTrashed()->with('role:id,display_name')
            ->get(['id', 'full_name', 'role_id', 'employment_status', 'fired_at', 'deleted_at', 'created_at', 'hired_at']);
        $clientIds = $walk->clients->pluck('id');
        $people    = [];
        $peopleOf  = function (int $year, int $month) use (&$people, $clientIds, $employees): MonthPeople {
            return $people[$year . '-' . $month] ??= MonthPeople::load(
                CarbonImmutable::create($year, $month, 1)->endOfMonth(),
                $clientIds,
                $employees,
            );
        };

        $me      = (int) $employee->id;
        $mine    = $months;
        $team    = $months;
        $members = []; // исполнитель => счётчики выбранного месяца на моих клиентах
        $overdue = ['mine' => [], 'team' => []]; // просрочено сейчас, команда по исполнителям
        $matched = [];

        // Одна задача в оба разреза. $due = null у сданных задач без слота в плане.
        $count = function (int $doer, int $lead, string $key, string $kind, ?array $overdueRow)
            use (&$mine, &$team, &$members, &$overdue, $me, $focusKey): void {
            if ($doer === $me) {
                if (isset($mine[$key])) {
                    $mine[$key]['total']++;
                    $mine[$key][$kind]++;
                }
                if ($overdueRow) {
                    $overdue['mine'][] = $overdueRow;
                }
            }

            if ($lead === $me) {
                if (isset($team[$key])) {
                    $team[$key]['total']++;
                    $team[$key][$kind]++;
                }
                if ($key === $focusKey) {
                    $members[$doer] ??= self::blank();
                    $members[$doer]['total']++;
                    $members[$doer][$kind]++;
                }
                if ($overdueRow) {
                    $overdue['team'][$doer][] = $overdueRow;
                }
            }
        };

        $walk->each(function (PlannedTask $task) use (&$matched, $count, $peopleOf, $cutoff) {
            if ($task->log) {
                $matched[$task->log->id] = true;
            }

            $people = $peopleOf($task->year, $task->month);
            $doer   = $this->doer($task, $people);
            $lead   = $people->lead($task->client);
            $kind   = OnTimeRule::classify($task->status, $task->due, $task->log, $this->today);

            $row = null;
            if ($kind === OnTimeRule::OVERDUE && $task->due->gte($cutoff)) {
                $item = $task->item;
                $row  = [
                    'name'   => $item->name . ($item->branch_label ? ' — ' . $item->branch_label : ''),
                    'client' => $task->client->name,
                    'due'    => $task->due,
                    // То же, что (int) diffInDays, но без Carbon: часы UTC, перевода времени нет
                    'days'   => intdiv($this->today->getTimestamp() - $task->due->getTimestamp(), 86400),
                    'status' => $task->status,
                ];
            }

            $count($doer, $lead, $task->year . '-' . $task->month, $kind, $row);
        });

        // Сданные задачи, которым слота в плане нет: позиции без периодичности в прошлых
        // месяцах, БП с поменявшимся расписанием. Работа сделана, в процент она идёт, как
        // и в разрезе по сотрудникам на дашборде. Срока у них нет, значит в срок.
        $clients = $walk->clients->keyBy('id');
        foreach ($walk->logRows as $log) {
            if (isset($matched[$log->id]) || !$this->isOrphan($log, $clients) || !isset($months[$log->year . '-' . $log->month])) {
                continue;
            }

            $lead = $peopleOf((int) $log->year, (int) $log->month)->lead($clients->get($log->client_id));
            $count((int) $log->employee_id, $lead, $log->year . '-' . $log->month, OnTimeRule::ON_TIME, null);
        }

        $byDays = fn ($a, $b) => [$b['days'], $a['client'], $a['name']] <=> [$a['days'], $b['client'], $b['name']];
        usort($overdue['mine'], $byDays);
        foreach ($overdue['team'] as &$list) {
            usort($list, $byDays);
        }
        unset($list);

        $result = [
            'month'   => self::withPct($mine[$focusKey] ?? self::blank()),
            'history' => array_map(fn ($m) => self::withPct($m), array_values($mine)),
            'overdue' => $overdue['mine'],
            'team'    => null,
        ];

        $isLead = array_sum(array_column($team, 'total')) > 0 || $overdue['team'] !== [];
        if ($isLead) {
            $result['team'] = $this->team($team, $focusKey, $members, $overdue['team'], $employees, $me);
        }

        return $result;
    }

    /** Блок команды: итог месяца, история, строки людей и доли кольца. */
    private function team(array $team, string $focusKey, array $members, array $overdue, Collection $employees, int $me): array
    {
        $month = self::withPct($team[$focusKey] ?? self::blank());
        $names = $employees->keyBy('id');

        // В таблице и те, у кого в этом месяце задач нет, но висит старая просрочка
        foreach (array_keys($overdue) as $id) {
            $members[$id] ??= self::blank();
        }

        $rows = [];
        foreach ($members as $id => $m) {
            $e      = $names->get($id);
            $rows[] = self::withPct($m) + [
                'id'      => (int) $id,
                'name'    => $id === 0 ? 'Не назначено' : ($e?->full_name ?? 'Сотрудник удалён'),
                'role'    => $e?->role?->display_name,
                'note'    => $e ? ($e->deleted_at ? 'удалён' : ($e->isFired() ? 'уволен' : null)) : null,
                'is_me'   => (int) $id === $me,
                // Сколько процентных пунктов команды человек забрал своими просрочками
                'loss'    => $month['total'] > 0 ? round($m[OnTimeRule::OVERDUE] / $month['total'] * 100, 1) : 0.0,
                'overdue_now' => $overdue[$id] ?? [],
            ];
        }

        // Кольцо: объём месяца по людям, крупные доли первыми, хвост в «Другие»
        $byVolume = array_values(array_filter($rows, fn ($r) => $r['total'] > 0));
        usort($byVolume, fn ($a, $b) => [$b['total'], $a['name']] <=> [$a['total'], $b['name']]);

        $colors = [];
        $ring   = [];
        $other  = 0;
        foreach ($byVolume as $i => $r) {
            if ($i < count(self::COLORS) - 1 || count($byVolume) === count(self::COLORS)) {
                $colors[$r['id']] = self::COLORS[$i];
                $ring[]           = ['name' => $r['name'], 'total' => $r['total'], 'color' => self::COLORS[$i]];
            } else {
                $colors[$r['id']] = self::OTHER_COLOR;
                $other           += $r['total'];
            }
        }
        if ($other > 0) {
            $ring[] = ['name' => 'Другие', 'total' => $other, 'color' => self::OTHER_COLOR];
        }

        foreach ($rows as &$r) {
            $r['color'] = $colors[$r['id']] ?? self::OTHER_COLOR;
        }
        unset($r);

        // Кто больше всех тянет вниз, тот сверху: с него и начинают разговор
        usort($rows, fn ($a, $b) => [$b['loss'], count($b['overdue_now']), $a['name']] <=> [$a['loss'], count($a['overdue_now']), $b['name']]);

        return [
            'month'   => $month,
            'history' => array_map(fn ($m) => self::withPct($m), array_values($team)),
            'members' => $rows,
            'ring'    => $ring,
        ];
    }

    /**
     * Клиенты, задачи которых могут попасть сотруднику в любой из разрезов. Остальных не
     * обходим: на слабом процессоре боя обход всей фирмы шёл 5 с (21.11.2026).
     *
     * Задача засчитана ему, только если он её сдал (лог), он исполнитель позиции, он
     * ответственный за клиента (позиции без исполнителя, главбух месяца) или вёл клиента
     * раньше (история смен). Здесь все четыре пути, с запасом: лишний клиент не меняет
     * чисел, пропущенный менял бы.
     */
    private static function touchedClients(Employee $employee, int $logsFromYear): Collection
    {
        $id = $employee->id;

        return Client::where('responsible_employee_id', $id)->pluck('id')
            ->merge(ClientResponsibleChange::where(fn ($q) => $q->where('employee_id', $id)->orWhere('previous_employee_id', $id))
                ->pluck('client_id'))
            ->merge(Estimate::whereIn('id', EstimateItem::where('assignee_id', $id)->select('estimate_id'))->pluck('client_id'))
            ->merge(BuhTaskLog::where('employee_id', $id)->where('year', '>=', $logsFromYear)->distinct()->pluck('client_id'))
            ->filter()->unique()->values();
    }

    private function doer(PlannedTask $task, MonthPeople $people): int
    {
        if (in_array($task->status, OnTimeRule::SUBMITTED, true) && $task->log?->employee_id) {
            return (int) $task->log->employee_id;
        }

        return $people->doer($task->client, $task->assigneeId);
    }

    /** Сданная задача без слота в плане, у клиента со сметой. */
    private function isOrphan(BuhTaskLog $log, Collection $clients): bool
    {
        return $log->estimate_item_id
            && in_array($log->status, OnTimeRule::SUBMITTED, true)
            && $clients->get($log->client_id) instanceof Client;
    }

    /** @return array{total:int, on_time:int, late:int, overdue:int, open:int} */
    private static function blank(): array
    {
        return ['total' => 0, OnTimeRule::ON_TIME => 0, OnTimeRule::LATE => 0, OnTimeRule::OVERDUE => 0, OnTimeRule::OPEN => 0];
    }

    /**
     * Процент = сданные задачи (вовремя и с опозданием) ÷ все задачи месяца. Решено 17.11.2026:
     * сделал, значит засчитано; опоздания видны отдельным числом, но процент не режут.
     */
    private static function withPct(array $m): array
    {
        $done = $m[OnTimeRule::ON_TIME] + $m[OnTimeRule::LATE];

        return $m + ['done' => $done, 'pct' => $m['total'] > 0 ? (int) floor($done / $m['total'] * 100) : null];
    }
}
