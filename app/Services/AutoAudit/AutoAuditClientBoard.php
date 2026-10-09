<?php

namespace App\Services\AutoAudit;

use App\Models\AutoAuditFinding;
use App\Models\AutoAuditFindingMessage;
use App\Models\AutoAuditResult;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\EstimateItem;
use App\Models\Service;
use App\Models\Tenant;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Вкладка «По клиентам»: что с каждым клиентом за каждый отчётный месяц и за кем ход.
 *
 * Только чтение и только из того, что уже есть: плановые задачи эталонных БП по смете,
 * действующие строки автоаудита и находки с перепиской. Своих таблиц нет.
 *
 * Месяц здесь отчётный, как на «Все сверки»: работу за сентябрь делают в октябре, и задача
 * октября стоит в клетке «сентябрь». Квартальный отчёт по ЕН встаёт в последний месяц
 * квартала (решение 08.10.2026): сверить его можно, только когда есть все три ведомости.
 *
 * Статус клетки один, первый подходящий по списку (monthCell):
 *   - задача просрочена;
 *   - вопрос ждёт бухгалтера: ответа нет или главбух его не принял;
 *   - ответ ждёт решения главбуха;
 *   - ждёт ночной проверки: бухгалтер написал «Исправил», задачу закрыли или файл
 *     заменили после последнего прогона. Раньше «не проверено»: старый скан мог уже
 *     смениться настоящим файлом;
 *   - сверку не удалось провести: скан, «не удалось проверить» или не с чем сверить;
 *   - месяц идёт: задачи открыты, срок не прошёл;
 *   - сверки сошлись;
 *   - сверять нечего: задачи закрыты, но сдана одна сторона (только ОСВ при квартальном
 *     отчёте по ЕН, только форма 161). Никто ничего не нарушил, действия не нужно;
 *   - нет задач автоаудита.
 *
 * Запросов на всю фирму постоянное число, сколько бы ни было клиентов: всё грузится разом
 * и раскладывается в памяти. Тест на это есть (AutoAuditClientsPageTest).
 */
class AutoAuditClientBoard
{
    public const OVERDUE     = 'overdue';
    public const ACCOUNTANT  = 'accountant';
    public const CHIEF       = 'chief';
    public const UNVERIFIED  = 'unverified';
    public const WAITING_RUN = 'waiting_run';
    public const IN_PROGRESS = 'in_progress';
    public const OK          = 'ok';
    public const NOTHING     = 'nothing';
    public const NONE        = 'none';

    /**
     * Статусы клетки в порядке срочности: и выбор статуса, и сортировка строк идут по нему.
     * letter: буква в клетке (решено 07.10.2026 вместо значков).
     */
    public const STATUSES = [
        self::OVERDUE     => ['letter' => '!', 'label' => 'Просрочено',        'hint' => 'Срок задачи прошёл, а она не закрыта'],
        self::ACCOUNTANT  => ['letter' => 'Б', 'label' => 'Ждёт бухгалтера',   'hint' => 'Есть вопрос по сверке, бухгалтер не ответил или ответ не приняли'],
        self::CHIEF       => ['letter' => 'Г', 'label' => 'Ждёт главбуха',     'hint' => 'Бухгалтер ответил, решение за главбухом'],
        self::UNVERIFIED  => ['letter' => '?', 'label' => 'Не проверено',      'hint' => 'Система не смогла сверить: скан, непрочитанное число или нет пары документов'],
        self::WAITING_RUN => ['letter' => '↻', 'label' => 'Ждёт проверки',     'hint' => 'Задачу закрыли или файл заменили после последней проверки. Проверим ночью'],
        self::IN_PROGRESS => ['letter' => '…', 'label' => 'Месяц идёт',        'hint' => 'Задачи ещё открыты, срок не прошёл'],
        self::OK          => ['letter' => '✓', 'label' => 'Сверки сошлись',    'hint' => 'Все сверки месяца сошлись или объяснение принято'],
        self::NOTHING     => ['letter' => '○', 'label' => 'Сверять нечего',    'hint' => 'Задачи закрыты, но сдана одна сторона: например, только ОСВ, а отчёт по ЕН квартальный'],
        self::NONE        => ['letter' => '·', 'label' => 'Нет задач',         'hint' => 'За этот месяц у клиента нет задач, которые проверяет автоаудит'],
    ];

    /** Требуют чьего-то действия: их показывает фильтр по умолчанию. */
    public const ACTION = [self::OVERDUE, self::ACCOUNTANT, self::CHIEF, self::UNVERIFIED];

    /** Сколько месяцев в таблице. Дальше назад листают стрелкой. */
    public const WINDOW = 7;

    /** За кем ход по проблемной строке. */
    public const TURN_ACCOUNTANT = 'accountant';
    public const TURN_CHIEF      = 'chief';
    public const TURN_RUN        = 'run';        // бухгалтер написал «Исправил», ждём прогона
    public const TURN_ACCEPTED   = 'accepted';
    public const TURN_MATCHED    = 'matched';
    public const TURN_NOBODY     = 'nobody';     // скан или «не удалось проверить»: вопроса нет

    /** Последний прогон по фирме: строки, тронутые позже, ещё не проверены. */
    private ?CarbonImmutable $checkedAt = null;

    public function __construct(private readonly CarbonImmutable $today) {}

    /**
     * Месяцы таблицы: выбранный, пять до него и один после, но не дальше текущего.
     *
     * @return CarbonImmutable[] первые числа отчётных месяцев, по возрастанию
     */
    public static function window(CarbonImmutable $focus, CarbonImmutable $today): array
    {
        $last = $focus->startOfMonth()->addMonth()->min($today->startOfMonth());

        return array_map(fn (int $i) => $last->subMonths(self::WINDOW - 1 - $i), range(0, self::WINDOW - 1));
    }

    /** Месяц по умолчанию: прошлый. Текущий только начался, работа за него в следующем. */
    public static function defaultFocus(CarbonImmutable $today): CarbonImmutable
    {
        return $today->startOfMonth()->subMonth();
    }

    public function checkedAt(): ?CarbonImmutable
    {
        return $this->checkedAt;
    }

    /**
     * Строки таблицы.
     *
     * @param CarbonImmutable[] $months   отчётные месяцы, см. window()
     * @param int|null          $clientId только один клиент: для карточки, с файлами и перепиской
     * @return array{rows: array<int, array>, notConnected: int, employees: Collection}
     */
    public function build(array $months, ?int $clientId = null): array
    {
        $details  = $clientId !== null;
        $first    = $months[0];
        $last     = end($months);
        $tenant   = Tenant::find(TenantContext::id());

        $sides = [];   // id БП => сторона (osv, tax, f161)

        foreach (Service::whereIn('reference_id', array_column(AutoAuditSources::SIDES, 'ref'))->get(['id', 'reference_id']) as $service) {
            foreach (AutoAuditSources::SIDES as $side => $definition) {
                if ($definition['ref'] === (int) $service->reference_id) {
                    $sides[$service->id] = $side;
                }
            }
        }

        $services = $sides ? Service::whereIn('id', array_keys($sides))->get()->keyBy('id') : collect();

        $clients = Client::query()
            ->when($details, fn (Builder $q) => $q->whereKey($clientId))
            ->with([
                'clientStatus',
                'serviceSchedules',
                'estimates.rootItems' => fn ($q) => $q->whereIn('service_id', array_keys($sides) ?: [0]),
            ])
            ->orderBy('name')
            ->get();

        // Задачи за отчётные месяцы окна делают в следующем месяце.
        $taskFrom = $first->addMonth();
        $taskTo   = $last->addMonth()->endOfMonth();

        $items = $clients->flatMap(fn (Client $c) => $c->estimates->first()?->rootItems ?? collect());
        $logs  = $this->logs($items->pluck('id')->all(), $taskFrom, $taskTo, $details);
        $byItem = $logs->groupBy('estimate_item_id');

        $results = AutoAuditResult::current()
            ->when($details, fn (Builder $q) => $q->where('client_id', $clientId))
            ->whereNotNull('period_to')
            ->whereBetween('period_to', [$first->toDateString(), $last->endOfMonth()->toDateString()])
            ->get()
            ->groupBy('client_id');

        $findings = $this->findings($results->flatten(), $details);

        $checkedAt       = AutoAuditResult::current()->max('updated_at');
        $this->checkedAt = $checkedAt ? CarbonImmutable::parse($checkedAt) : null;

        // Раньше старта автоаудита в фирме клетки пустые: там «не проверено» было бы враньём.
        $start = $tenant?->autoAuditFrom() ?? AutoAuditResult::current()->min('period_from');
        $start = $start ? CarbonImmutable::parse(substr((string) $start, 0, 7) . '-01') : null;

        $rows         = [];
        $notConnected = 0;

        foreach ($clients as $client) {
            $estimate = $client->estimates->first();
            $refItems = $estimate?->rootItems ?? collect();

            if (!$client->servesEverything() || $refItems->isEmpty()) {
                if (!$client->serviceIsStopped()) {
                    $notConnected++;
                }

                continue;
            }

            $tasks = $this->tasks($client, $refItems, $services, $sides, $logs, $byItem, $taskFrom, $taskTo);
            $own   = $results->get($client->id, collect());

            if (!$tasks && $own->isEmpty()) {
                continue;
            }

            $cells = [];

            foreach ($months as $month) {
                $key = $month->format('Y-m');

                $cells[$key] = $start && $month->lt($start)
                    ? $this->cell(self::NONE, [], [])
                    : $this->monthCell(
                        $month,
                        $tasks[$key] ?? [],
                        $own->filter(fn (AutoAuditResult $r) => $r->period_to->format('Y-m') === $key)->values()->all(),
                        $findings,
                    );
            }

            $osvItem = $refItems->first(fn (EstimateItem $i) => ($sides[$i->service_id] ?? null) === 'osv') ?? $refItems->first();

            $rows[] = [
                'client'     => $client,
                'accountant' => (int) ($osvItem->assignee_id ?? $client->responsible_employee_id) ?: null,
                'chief'      => $client->responsible_employee_id ? (int) $client->responsible_employee_id : null,
                'hasTax'     => $refItems->contains(fn (EstimateItem $i) => ($sides[$i->service_id] ?? null) === 'tax'),
                'cells'      => $cells,
            ];
        }

        return [
            'rows'         => $rows,
            'notConnected' => $notConnected,
            'employees'    => Employee::withTrashed()->pluck('full_name', 'id'),
        ];
    }

    /**
     * Задачи эталонных БП за окно: даты по смете, как в БухЗадачнике и на дашборде, и
     * журнал задачи, если его уже завели. Журнал без даты в расписании (расписание
     * поменяли после закрытия) тоже идёт: работа по нему была.
     *
     * @return array<string, array<int, array>> отчётный месяц 'Y-m' => задачи
     */
    private function tasks(
        Client $client,
        Collection $items,
        Collection $services,
        array $sides,
        Collection $logs,
        Collection $byItem,
        CarbonImmutable $taskFrom,
        CarbonImmutable $taskTo,
    ): array {
        [$from, $to] = $this->clientWindow($client, $taskFrom, $taskTo);
        $overrides   = $client->serviceSchedules->keyBy('service_id');
        $tasks       = [];
        $seen        = [];

        foreach ($items as $item) {
            $service = $services->get($item->service_id);

            if (!$service) {
                continue;
            }

            $itemFrom = $item->tasksStartFrom()?->max($from) ?? $from;
            $itemTo   = $item->tasksEndAt()?->min($to) ?? $to;
            $dues     = $itemFrom->lte($itemTo)
                ? $service->dueDatesForClient($overrides->get($item->service_id), $itemFrom, $itemTo)
                : [];

            foreach ($dues as $due) {
                $due = CarbonImmutable::parse($due);
                $key = "{$item->id}:{$due->year}:{$due->month}";

                $seen[$key] = true;
                $this->addTask($tasks, $item, $sides, $due->year, $due->month, $due, $logs->get($key));
            }
        }

        foreach ($items as $item) {
            foreach ($byItem->get($item->id, []) as $log) {
                if (!isset($seen["{$item->id}:{$log->year}:{$log->month}"])) {
                    $this->addTask($tasks, $item, $sides, (int) $log->year, (int) $log->month, $log->due_date?->toImmutable(), $log);
                }
            }
        }

        return $tasks;
    }

    private function addTask(array &$tasks, EstimateItem $item, array $sides, int $year, int $month, ?CarbonImmutable $due, ?BuhTaskLog $log): void
    {
        $period = CarbonImmutable::create($year, $month, 1)->subMonth()->format('Y-m');
        $done   = $log && in_array($log->status, AutoAuditSources::DONE_STATUSES, true);

        // Таблице время последнего файла приходит готовым (withMax), карточке со списком файлов.
        // Лезть в documents без загрузки нельзя: это запрос на каждую задачу.
        $lastFile = $log?->relationLoaded('documents') ? $log->documents->max('created_at') : $log?->documents_max_created_at;
        $touched  = collect([$log?->completed_at, $lastFile])
            ->filter()
            ->map(fn ($at) => CarbonImmutable::parse($at))
            ->max();

        $tasks[$period][] = [
            'item'      => $item,
            'side'      => $sides[$item->service_id] ?? null,
            'name'      => $item->name . ($item->branch_label ? ', ' . $item->branch_label : ''),
            'assignee'  => (int) ($log?->employee_id ?: ($item->assignee_id ?? 0)) ?: null,
            'due'       => $due,
            'log'       => $log,
            'done'      => $done,
            'forced'    => (bool) $log?->force_closed,
            'touchedAt' => $done ? $touched : null,
            'hasFile'   => $lastFile !== null,
        ];
    }

    /** Границы задач клиента: те же, что в BuhTasksController и DashboardController. */
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
     * Журналы задач окна, ключ «позиция:год:месяц». «Передана» не в счёт: задачу делает
     * другой. Если журналов на слот несколько, берём последний, как дашборд.
     */
    private function logs(array $itemIds, CarbonImmutable $from, CarbonImmutable $to, bool $details): Collection
    {
        if (!$itemIds) {
            return collect();
        }

        $query = BuhTaskLog::whereIn('estimate_item_id', $itemIds)
            ->where('status', '<>', BuhTaskLog::STATUS_HANDED)
            ->where(fn (Builder $q) => $q->where('year', '>', $from->year)
                ->orWhere(fn (Builder $q) => $q->where('year', $from->year)->where('month', '>=', $from->month)))
            ->where(fn (Builder $q) => $q->where('year', '<', $to->year)
                ->orWhere(fn (Builder $q) => $q->where('year', $to->year)->where('month', '<=', $to->month)))
            ->orderBy('id');

        // Таблице хватает времени последнего файла, карточке нужны сами файлы.
        $details
            ? $query->with('documents')
            : $query->withMax('documents', 'created_at');

        return $query->get()->keyBy(fn (BuhTaskLog $l) => "{$l->estimate_item_id}:{$l->year}:{$l->month}");
    }

    /**
     * Открытые находки по ключу строки. Если с одним ключом их вдруг две, берём старшую:
     * у неё переписка (как на «Все сверки»).
     *
     * @return array<string, AutoAuditFinding>
     */
    private function findings(Collection $results, bool $details): array
    {
        $keys = $results->map(fn (AutoAuditResult $r) => $r->key())->unique()->values()->all();

        if (!$keys) {
            return [];
        }

        return AutoAuditFinding::open()
            ->whereIn('key', $keys)
            ->with($details ? 'messages.employee:id,full_name' : 'messages')
            ->orderBy('id')
            ->get()
            ->reverse()
            ->keyBy('key')
            ->all();
    }

    /**
     * Клетка одного месяца.
     *
     * @param array<int, array>           $tasks
     * @param array<int, AutoAuditResult> $results
     */
    private function monthCell(CarbonImmutable $month, array $tasks, array $results, array $findings): array
    {
        if (!$tasks && !$results) {
            return $this->cell(self::NONE, [], []);
        }

        $issues = array_map(fn (AutoAuditResult $r) => $this->issue($r, $findings[$r->key()] ?? null), $results);

        $turns   = array_count_values(array_column($issues, 'turn'));
        $open    = array_filter($tasks, fn (array $t) => !$t['done']);
        $overdue = array_filter($open, fn (array $t) => $t['due'] && $t['due']->lt($this->today));
        $touched = array_filter($tasks, fn (array $t) => $t['touchedAt'] && (!$this->checkedAt || $t['touchedAt']->gt($this->checkedAt)));
        $matched = array_filter($issues, fn (array $i) => in_array($i['turn'], [self::TURN_MATCHED, self::TURN_ACCEPTED], true));

        $days = fn (string $turn) => max([0, ...array_column(array_filter($issues, fn (array $i) => $i['turn'] === $turn), 'days')]);

        [$status, $note, $since] = match (true) {
            (bool) $overdue => [
                self::OVERDUE,
                reset($overdue)['name'] . ': срок был ' . reset($overdue)['due']->format('d.m'),
                (int) reset($overdue)['due']->diffInDays($this->today),
            ],
            isset($turns[self::TURN_ACCOUNTANT]) => [
                self::ACCOUNTANT,
                self::count($turns[self::TURN_ACCOUNTANT], ['вопрос', 'вопроса', 'вопросов']),
                $days(self::TURN_ACCOUNTANT),
            ],
            isset($turns[self::TURN_CHIEF]) => [
                self::CHIEF,
                self::count($turns[self::TURN_CHIEF], ['ответ ждёт', 'ответа ждут', 'ответов ждут']) . ' решения',
                $days(self::TURN_CHIEF),
            ],
            isset($turns[self::TURN_RUN]) || (bool) $touched => [
                self::WAITING_RUN,
                isset($turns[self::TURN_RUN]) ? 'бухгалтер исправил, проверим ночью' : 'задачи закрыты после проверки, проверим ночью',
                null,
            ],
            isset($turns[self::TURN_NOBODY]) => [
                self::UNVERIFIED,
                self::count($turns[self::TURN_NOBODY], ['сверку', 'сверки', 'сверок']) . ' не удалось провести',
                null,
            ],
            (bool) $open => [
                self::IN_PROGRESS,
                reset($open)['name'] . (reset($open)['due'] ? ': срок ' . reset($open)['due']->format('d.m') : ''),
                null,
            ],
            !$matched && !$this->bothSides($tasks) => [self::NOTHING, $this->onlySide($tasks), null],
            !$matched => [self::UNVERIFIED, 'документы есть, а сверка не сложилась', null],
            default => [self::OK, self::count(count($matched), ['сверка сошлась', 'сверки сошлись', 'сверок сошлись']), null],
        };

        return $this->cell($status, $tasks, $issues, $note, $since);
    }

    /**
     * Сданы ли обе стороны сверки: ОСВ и отчёт по ЕН или форма 161, закрытые с файлом.
     *
     * Задача, закрытая без файла («Раз в квартал», «Другое»), стороной не считается: сверять
     * с ней нечего. «Нулевой» и «Освобождён» сюда не доходят, по ним прогон сверяет с нулём.
     * Обе стороны есть, а строк сверки нет, значит пара не сложилась: это «Не проверено».
     */
    private function bothSides(array $tasks): bool
    {
        $sides = array_unique(array_column(array_filter($tasks, fn (array $t) => $t['hasFile']), 'side'));

        return in_array('osv', $sides, true) && count($sides) > 1;
    }

    /** «есть только ОСВ», «есть только форма 161», «файлов нет». */
    private function onlySide(array $tasks): string
    {
        $names  = ['osv' => 'ОСВ', 'tax' => 'отчёт по ЕН', 'f161' => 'форма 161'];
        $labels = array_map(
            fn (string $side) => $names[$side] ?? $side,
            array_unique(array_filter(array_column(array_filter($tasks, fn (array $t) => $t['hasFile']), 'side'))),
        );

        return $labels ? 'есть только ' . implode(', ', $labels) : 'задачи закрыты без файлов';
    }

    private function cell(string $status, array $tasks, array $issues, string $note = '', ?int $days = null): array
    {
        return ['status' => $status, 'note' => $note, 'days' => $days, 'tasks' => $tasks, 'issues' => $issues];
    }

    /** Строка результата, за кем по ней ход и сколько дней он там. */
    private function issue(AutoAuditResult $result, ?AutoAuditFinding $finding): array
    {
        $since = null;

        if ($result->outcome === AutoAuditResult::MATCHED) {
            $turn = self::TURN_MATCHED;
        } elseif (!in_array($result->outcome, AutoAuditResult::FINDING_OUTCOMES, true)) {
            $turn = self::TURN_NOBODY;
        } elseif (!$finding) {
            // Находку открывает прогон вместе со строкой, так что без неё строка бывает
            // только в промежутке. Вопрос всё равно к бухгалтеру.
            $turn  = self::TURN_ACCOUNTANT;
            $since = $result->created_at;
        } else {
            $last  = $finding->messages->last();
            $since = $last?->created_at ?? $finding->opened_at;
            $turn  = match ($finding->state($result)) {
                AutoAuditFinding::ACCEPTED => self::TURN_ACCEPTED,
                AutoAuditFinding::ANSWERED => $last?->kind === AutoAuditFindingMessage::FIXED ? self::TURN_RUN : self::TURN_CHIEF,
                default => self::TURN_ACCOUNTANT,
            };
        }

        return [
            'result'  => $result,
            'finding' => $finding,
            'turn'    => $turn,
            'days'    => $since ? (int) CarbonImmutable::parse($since)->startOfDay()->diffInDays($this->today->startOfDay()) : 0,
        ];
    }

    /** «3 вопроса»: число и слово в нужной форме. */
    public static function count(int $n, array $forms): string
    {
        $form = ($n % 10 === 1 && $n % 100 !== 11) ? 0 : (($n % 10 >= 2 && $n % 10 <= 4 && ($n % 100 < 10 || $n % 100 >= 20)) ? 1 : 2);

        return $n . ' ' . $forms[$form];
    }
}
