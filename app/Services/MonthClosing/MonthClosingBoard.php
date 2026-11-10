<?php

namespace App\Services\MonthClosing;

use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\ClientResponsibleChange;
use App\Models\ClientServiceSchedule;
use App\Models\Employee;
use App\Models\EstimateItem;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\AutoAudit\AutoAuditClientBoard;
use App\Services\AutoAudit\AutoAuditSources;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * «Закрытие месяца»: закрыт ли месяц каждого клиента. Первый шаг, только чтение.
 *
 * Главное правило (Искендер, 10.11.2026): все задачи сметы, которые выпали на октябрь, это
 * работа за сентябрь. Месяц клиента = его сметные задачи со сроком в следующем месяце.
 * Внеплановые задачи сюда не входят: их объём сметой не запланирован.
 *
 * Месяц закрыт, когда одновременно (договорились 10.11.2026):
 *   - все его сметные задачи приняты (status completed). «На проверке» ещё не закрыта:
 *     главбух с работой пока не согласился;
 *   - у автоаудита нет вопросов (ждёт бухгалтера или руководителя). Только у фирм, где
 *     страница автоаудита открыта руководителю, иначе он не увидит, о чём речь.
 *
 * Чего в условии нет и почему. «Отчёты сданы» и «налоги оплачены» со скрина: какие БП
 * отчёты, не размечено, факт оплаты налога не фиксируем; пока они просто часть сметных
 * задач. Ручной аудит в разработке и к закрытию не подключён, карточка так и пишет.
 *
 * Срок закрытия пока = самый поздний срок задач месяца. Жёсткое 25-е спорит со сметой: у
 * фирм есть БП со сроком 28-30 числа (разведка 10.11.2026). Окончательно решим в процессе,
 * правило живёт в одном месте, deadline().
 *
 * Своих таблиц нет: «закрыт» значит «закрыт на сегодня». Переоткрыли задачу, и месяц
 * снова открыт. Дату закрытия и «вовремя / с опозданием» дадут следующие шаги.
 *
 * Запросов на всю фирму постоянное число, сколько бы ни было клиентов.
 */
class MonthClosingBoard
{
    public const CLOSED  = 'closed';
    public const OVERDUE = 'overdue';
    public const OPEN    = 'open';
    public const NONE    = 'none';

    public const STATUSES = [
        self::CLOSED  => ['letter' => '✓', 'label' => 'Закрыт',              'hint' => 'Все сметные задачи месяца приняты, вопросов автоаудита нет'],
        self::OVERDUE => ['letter' => '×', 'label' => 'Открыт, срок прошёл', 'hint' => 'Срок закрытия прошёл, а месяц не закрыт'],
        self::OPEN    => ['letter' => '·', 'label' => 'Открыт, срок не прошёл', 'hint' => 'Месяц ещё закрывают, срок закрытия впереди'],
        self::NONE    => ['letter' => '',  'label' => 'Нет задач',           'hint' => 'За этот месяц у клиента нет задач по смете'],
    ];

    /** Сколько месяцев в таблице. */
    public const WINDOW = 6;

    /** Статусы автоаудита, которые держат месяц открытым: по ним ждут ответа или решения. */
    public const AUDIT_BLOCKING = [AutoAuditClientBoard::ACCOUNTANT, AutoAuditClientBoard::CHIEF];

    /** Разобранное расписание БП, сроки по отрезку и месяц работы по месяцу задачи: кеш на один расчёт. */
    private array $schedules = [];
    private array $dueCache  = [];
    private array $periods   = [];

    public function __construct(private readonly CarbonImmutable $today) {}

    /**
     * Месяцы таблицы: выбранный и до него, следующий тоже, если работа за него уже идёт.
     * Последний возможный = прошлый месяц: работу за текущий начнут только в следующем.
     *
     * @return CarbonImmutable[] первые числа месяцев, по возрастанию
     */
    public static function window(CarbonImmutable $focus, CarbonImmutable $today): array
    {
        $last = $focus->startOfMonth()->addMonth()->min(self::latest($today));

        return array_map(fn (int $i) => $last->subMonths(self::WINDOW - 1 - $i), range(0, self::WINDOW - 1));
    }

    /** Самый поздний месяц, за который уже идёт работа: прошлый. */
    public static function latest(CarbonImmutable $today): CarbonImmutable
    {
        return $today->startOfMonth()->subMonth();
    }

    /**
     * Месяц по умолчанию: позапрошлый. Его задачи были в прошлом месяце, и итог уже виден.
     * Прошлый ещё закрывают, на нём почти всё открыто.
     */
    public static function defaultFocus(CarbonImmutable $today): CarbonImmutable
    {
        return $today->startOfMonth()->subMonths(2);
    }

    /** Включается ли автоаудит в условие закрытия. */
    public static function withAutoAudit(): bool
    {
        return (bool) Tenant::find(TenantContext::id())?->autoAuditEnabled();
    }

    /**
     * Строки таблицы.
     *
     * @param CarbonImmutable[] $months   см. window()
     * @param int|null          $clientId только один клиент: для карточки
     * @return array{rows: array<int, array>, employees: Collection, autoAudit: bool}
     */
    public function build(array $months, ?int $clientId = null): array
    {
        $first = $months[0];
        $last  = end($months);

        // Задачи за месяц окна выпадают на следующий.
        $taskFrom = $first->addMonth();
        $taskTo   = $last->addMonth()->endOfMonth();

        $clients = Client::query()
            ->when($clientId !== null, fn (Builder $q) => $q->whereKey($clientId))
            ->with([
                'clientStatus',
                'serviceSchedules',
                // Только нужные колонки: у Fineco 2000 позиций, лишнее заметно на скорости.
                'estimates.rootItems' => fn ($q) => $q->whereNull('parent_id')->orderBy('sort_order')
                    ->select(['id', 'estimate_id', 'parent_id', 'service_id', 'name', 'branch_label', 'assignee_id', 'tasks_start_from', 'tasks_end_at', 'sort_order']),
            ])
            ->whereHas('estimates.rootItems', fn ($q) => $q->whereNull('parent_id'))
            ->orderBy('name')
            ->get();

        $items    = $clients->flatMap(fn (Client $c) => $c->estimates->first()?->rootItems ?? collect());
        $services = Service::whereIn('id', $items->pluck('service_id')->filter()->unique()->values())->get()->keyBy('id');
        $logs     = $this->logs($items->pluck('id')->all(), $taskFrom, $taskTo);

        // Автоаудит: карточке нужна клетка целиком (статус и пояснение), таблице хватает
        // ответа «есть ли вопрос». Полный расчёт вкладки на всю фирму стоил 2 с (10.11.2026).
        $autoAudit = self::withAutoAudit();
        $audit     = match (true) {
            !$autoAudit        => [],
            $clientId !== null => $this->autoAuditCells($months, $clientId),
            default            => $this->autoAuditQuestions($months, $clients, $services),
        };

        $changes   = ClientResponsibleChange::whereIn('client_id', $clients->pluck('id'))
            ->orderBy('changed_at')->orderBy('id')
            ->get(['client_id', 'previous_employee_id', 'employee_id', 'changed_at'])
            ->groupBy('client_id');
        $employees = Employee::withTrashed()->get(['id', 'full_name', 'created_at', 'hired_at']);
        // С какого дня человек мог вести клиентов: принят или заведён в системе, что раньше.
        $since     = $employees->mapWithKeys(fn (Employee $e) => [$e->id => collect([$e->created_at, $e->hired_at])
            ->filter()->map(fn ($d) => CarbonImmutable::parse($d)->startOfDay())->min()]);

        $rows = [];

        foreach ($clients as $client) {
            $tasks = $this->tasks($client, $services, $logs, $taskFrom, $taskTo);

            if (!$tasks) {
                continue;
            }

            $cells = [];

            foreach ($months as $month) {
                $key = $month->format('Y-m');

                $cells[$key] = $this->cell(
                    $month,
                    $tasks[$key] ?? [],
                    $autoAudit ? ($audit[$client->id][$key] ?? null) : null,
                );
                $cells[$key] += $this->monthChief($client, $cells[$key]['deadline'], $changes->get($client->id, collect()), $since);
            }

            $rows[] = [
                'client' => $client,
                'chief'  => $client->responsible_employee_id ? (int) $client->responsible_employee_id : null,
                'cells'  => $cells,
            ];
        }

        return [
            'rows'      => $rows,
            'employees' => $employees->pluck('full_name', 'id'),
            'autoAudit' => $autoAudit,
        ];
    }

    /**
     * Чей это месяц: кто вёл клиента в день срока закрытия (договорились 10.11.2026).
     *
     * Кто вёл, берём из истории смен ответственного. Она пишется с 10.11.2026, прежние
     * передачи неизвестны: до первой записи считаем, что вёл нынешний. Поэтому ещё одна
     * страховка: месяц не засчитывается человеку, который появился в системе позже срока.
     * Без неё новая главбух получала июль, закрытый до неё (Сыдыкова, Fineco, 10.11.2026).
     *
     * Позже, когда будем помнить дату закрытия: закрытый до срока месяц засчитывается тому,
     * кто вёл в день закрытия, и передача за 10 дней до срока выводит месяц из процента.
     *
     * @return array{chief: ?int, chiefUnknown: bool}
     */
    private function monthChief(Client $client, ?CarbonImmutable $deadline, Collection $changes, Collection $since): array
    {
        $current = $client->responsible_employee_id ? (int) $client->responsible_employee_id : null;

        if ($deadline === null) {
            return ['chief' => $current, 'chiefUnknown' => false];
        }

        [$chief] = ClientResponsibleChange::responsibleAt($changes, $current, $deadline->endOfDay());

        if ($chief !== null && ($since[$chief] ?? null)?->gt($deadline)) {
            return ['chief' => null, 'chiefUnknown' => true];
        }

        return ['chief' => $chief, 'chiefUnknown' => false];
    }

    /**
     * Срок закрытия месяца: самый поздний срок его задач. Задача без срока (журнал есть, а
     * в расписании даты нет) срока не двигает; если таких все, срок = конец месяца задач.
     */
    public static function deadline(CarbonImmutable $month, array $tasks): CarbonImmutable
    {
        $dues = array_filter(array_column($tasks, 'due'));

        return $dues ? max($dues) : $month->addMonth()->endOfMonth()->startOfDay();
    }

    /**
     * Сметные задачи клиента за окно, разложенные по месяцам работы: даты по смете, как в
     * БухЗадачнике и на дашборде, и журнал задачи, если его завели. Журнал без даты в
     * расписании (расписание поменяли после закрытия) тоже идёт: работа по нему была.
     *
     * @return array<string, array<int, array>> месяц работы 'Y-m' => задачи
     */
    private function tasks(Client $client, Collection $services, array $logs, CarbonImmutable $taskFrom, CarbonImmutable $taskTo): array
    {
        $items = $client->estimates->first()?->rootItems ?? collect();

        if ($items->isEmpty()) {
            return [];
        }

        [$from, $to] = $this->clientWindow($client, $taskFrom, $taskTo);
        $overrides   = $client->serviceSchedules->keyBy('service_id');
        $tasks       = [];
        $used        = [];

        foreach ($items as $item) {
            $service = $services->get($item->service_id);

            if (!$service) {
                continue;
            }

            $itemFrom = $item->tasksStartFrom()?->max($from) ?? $from;
            $itemTo   = $item->tasksEndAt()?->min($to) ?? $to;

            if ($itemFrom->gt($itemTo)) {
                continue;
            }

            [$weekly, $dues] = $this->dues($service, $overrides->get($item->service_id), $itemFrom, $itemTo);
            $name     = $this->taskName($item);
            $assignee = (int) ($item->assignee_id ?? 0) ?: null;

            foreach ($dues as $date => $due) {
                // Недельных в месяце несколько, их различает дата. У помесячных дата в журнале
                // бывает, а бывает и нет: тогда ищем по месяцу (как дашборд).
                $log = $logs['byDate']["{$item->id}:{$date}"]
                    ?? ($weekly ? null : ($logs['byMonth']["{$item->id}:{$due->year}:{$due->month}"] ?? null));

                if ($log && isset($used[$log->id])) {
                    $log = null;
                }

                if ($log) {
                    $used[$log->id] = true;
                }

                $this->addTask($tasks, $name, $assignee, $due->year, $due->month, $due, $log);
            }
        }

        // Журнал за месяц после окончания обслуживания в закрытие не идёт: сметные задачи по
        // этой дате уже обрезаны, а поздняя запись (счёт ушедшему клиенту, ШД Айсу 02.10.2026)
        // создавала бы месяц, которого у клиента не было.
        $end = $client->serviceEndsAt();

        foreach ($items as $item) {
            foreach ($logs['byItem'][$item->id] ?? [] as $log) {
                if ($end && CarbonImmutable::create((int) $log->year, (int) $log->month, 1)->gt($end)) {
                    continue;
                }

                if (!isset($used[$log->id])) {
                    $used[$log->id] = true;
                    $due = $log->due_date ? CarbonImmutable::parse(substr((string) $log->due_date, 0, 10)) : null;
                    $this->addTask($tasks, $this->taskName($item), (int) ($item->assignee_id ?? 0) ?: null, (int) $log->year, (int) $log->month, $due, $log);
                }
            }
        }

        return $tasks;
    }

    /**
     * Сроки БП за отрезок, ключ 'Y-m-d'. То же, что Service::dueDatesForClient, но расписание
     * БП разбирается один раз, а сроки без особого расписания клиента считаются один раз на
     * отрезок: у большинства клиентов отрезок одинаковый, и это главная экономия.
     *
     * @return array{0: bool, 1: array<string, CarbonImmutable>} [недельный ли, сроки]
     */
    private function dues(Service $service, ?ClientServiceSchedule $override, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $schedule = $override
            ? $service->resolveForClient($override)
            : ($this->schedules[$service->id] ??= $service->resolveForClient(null));
        $kind = Service::kindForPeriodicity($schedule['periodicity']);
        $key  = $override ? null : "{$service->id}:{$from->timestamp}:{$to->timestamp}";

        if ($key !== null && isset($this->dueCache[$key])) {
            return $this->dueCache[$key];
        }

        $dues = [];
        foreach (Service::computeDueDates($kind, $schedule['months'], $schedule['days'], $from, $to) as $due) {
            $dues[$due->toDateString()] = $due;
        }

        $result = [$kind === 'weekly', $dues];

        if ($key !== null) {
            $this->dueCache[$key] = $result;
        }

        return $result;
    }

    private function taskName(EstimateItem $item): string
    {
        return $item->name . ($item->branch_label ? ', ' . $item->branch_label : '');
    }

    /** @param object|null $log строка журнала задачи (без модели, см. logs()) */
    private function addTask(array &$tasks, string $name, ?int $itemAssignee, int $year, int $month, ?CarbonImmutable $due, ?object $log): void
    {
        $period = $this->periods[$year * 100 + $month] ??= CarbonImmutable::create($year, $month, 1)->subMonth()->format('Y-m');

        $tasks[$period][] = [
            'name'     => $name,
            'assignee' => (int) ($log?->employee_id ?: $itemAssignee) ?: null,
            'due'      => $due,
            'log'      => $log,
            'done'     => $log?->status === 'completed',
        ];
    }

    /** Границы задач клиента: те же, что в БухЗадачнике, на дашборде и в автоаудите. */
    private function clientWindow(Client $client, CarbonImmutable $from, CarbonImmutable $to): array
    {
        if ($client->service_start_date) {
            $from = $from->max(CarbonImmutable::parse($client->service_start_date)->startOfDay());
        }

        if ($estimate = $client->estimates->first()) {
            $from = $from->max($estimate->tasksStartFrom());
        }

        if ($resumed = $client->tasksStartFrom()) {
            $from = $from->max($resumed);
        }

        if ($end = $client->serviceEndsAt()) {
            $to = $to->min($end);
        }

        return [$from, $to];
    }

    /**
     * Журналы задач окна тремя указателями. «Передана» не в счёт: задачу делает другой.
     * Если на один слот журналов несколько, берём последний, как дашборд.
     *
     * @return array{byDate: array, byMonth: array, byItem: array}
     */
    private function logs(array $itemIds, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $empty = ['byDate' => [], 'byMonth' => [], 'byItem' => []];

        if (!$itemIds) {
            return $empty;
        }

        // Без моделей и только нужные колонки: на Fineco 4000 журналов, модели стоили секунду.
        $rows = BuhTaskLog::whereIn('estimate_item_id', $itemIds)
            ->where('status', '<>', BuhTaskLog::STATUS_HANDED)
            ->where(fn (Builder $q) => $q->where('year', '>', $from->year)
                ->orWhere(fn (Builder $q) => $q->where('year', $from->year)->where('month', '>=', $from->month)))
            ->where(fn (Builder $q) => $q->where('year', '<', $to->year)
                ->orWhere(fn (Builder $q) => $q->where('year', $to->year)->where('month', '<=', $to->month)))
            ->orderBy('id')
            ->toBase()
            ->get(['id', 'estimate_item_id', 'employee_id', 'year', 'month', 'due_date', 'status']);

        $logs = $empty;

        foreach ($rows as $log) {
            if ($log->due_date) {
                $logs['byDate'][$log->estimate_item_id . ':' . substr((string) $log->due_date, 0, 10)] = $log;
            }

            $logs['byMonth']["{$log->estimate_item_id}:{$log->year}:{$log->month}"] = $log;
        }

        // Для «сирот» берём только последний журнал слота, иначе старый журнал того же
        // месяца встал бы отдельной задачей.
        foreach ([...array_values($logs['byMonth']), ...array_values($logs['byDate'])] as $log) {
            $logs['byItem'][$log->estimate_item_id][$log->id] = $log;
        }

        return $logs;
    }

    /**
     * Клетки вкладки автоаудита «По клиентам» за те же месяцы. Месяц там тот же: работа за
     * сентябрь в задачах октября, поэтому клетки берём как есть.
     *
     * @return array<int, array<string, array>> клиент => месяц => клетка
     */
    private function autoAuditCells(array $months, ?int $clientId): array
    {
        $cells = [];

        foreach ((new AutoAuditClientBoard($this->today))->build($months, $clientId)['rows'] as $row) {
            $cells[$row['client']->id] = $row['cells'];
        }

        return $cells;
    }

    /**
     * Вопросы автоаудита для таблицы: только «ждёт бухгалтера» и «ждёт руководителя» по
     * клиенту и месяцу. Клиенты те же, что видит вкладка «По клиентам»: полное обслуживание
     * и размеченные БП автоаудита в смете. Остальных вкладка не проверяет, их строки не в счёт.
     *
     * @return array<int, array<string, array{status: string}>>
     */
    private function autoAuditQuestions(array $months, Collection $clients, Collection $services): array
    {
        $refs = array_column(AutoAuditSources::SIDES, 'ref');

        $connected = $clients
            ->filter(fn (Client $c) => $c->servesEverything()
                && ($c->estimates->first()?->rootItems ?? collect())->contains(fn (EstimateItem $i) => in_array((int) $services->get($i->service_id)?->reference_id, $refs, true)))
            ->pluck('id')
            ->flip();

        $questions = [];

        foreach ((new AutoAuditClientBoard($this->today))->questions($months) as $clientId => $byMonth) {
            if (isset($connected[$clientId])) {
                $questions[$clientId] = array_map(fn (string $status) => ['status' => $status], $byMonth);
            }
        }

        return $questions;
    }

    /**
     * Клетка одного месяца.
     *
     * @param array<int, array> $tasks
     * @param array|null        $audit клетка автоаудита, null если автоаудит не в условии
     */
    private function cell(CarbonImmutable $month, array $tasks, ?array $audit): array
    {
        if (!$tasks) {
            return [
                'status' => self::NONE, 'tasks' => [], 'open' => 0, 'late' => 0, 'deadline' => null,
                'audit' => $audit, 'auditBlocks' => false, 'reasons' => [],
            ];
        }

        $deadline = self::deadline($month, $tasks);
        $open     = array_filter($tasks, fn (array $t) => !$t['done']);
        $late     = array_filter($open, fn (array $t) => $t['due'] && $t['due']->lt($this->today));

        $auditBlocks = $audit !== null && in_array($audit['status'], self::AUDIT_BLOCKING, true);

        $reasons = array_values(array_filter([
            $open ? AutoAuditClientBoard::count(count($open), ['задача не закрыта', 'задачи не закрыты', 'задач не закрыто']) : null,
            $auditBlocks ? 'вопросы автоаудита' : null,
        ]));

        $status = match (true) {
            !$reasons                     => self::CLOSED,
            $this->today->gt($deadline)   => self::OVERDUE,
            default                       => self::OPEN,
        };

        return [
            'status'      => $status,
            'tasks'       => $tasks,
            'open'        => count($open),
            'late'        => count($late),
            'deadline'    => $deadline,
            'audit'       => $audit,
            'auditBlocks' => $auditBlocks,
            'reasons'     => $reasons,
        ];
    }
}
