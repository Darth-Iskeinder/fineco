<?php

namespace App\Services\Dashboard;

use App\Models\Client;
use App\Models\ClientResponsibleChange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Кто вёл клиента и кто делал задачу в выбранном месяце Дашборда.
 *
 * Правило (Искендер, 12.11.2026): месяц показываем людьми того месяца. Сотрудник, который
 * появился в системе после конца месяца, в нём не виден. Иначе новая главбух, которой в
 * ноябре передали клиента, всплывала в сентябре (Абдыкадырова, Fineco, 12.11.2026).
 *
 *  - Главбух месяца: кто вёл клиента в конце месяца, по истории смен ответственного (она
 *    пишется с 02.11.2026). До первой записанной смены считаем, что вёл нынешний.
 *  - Исполнитель незакрытой задачи: нынешний исполнитель БП, если он уже был в том месяце.
 *    Если появился позже, задача идёт главбуху месяца: при передаче клиента БП прежнего
 *    главбуха переезжают на нового, так что до передачи их держал он. Истории назначений
 *    БП нет, это лучшее, что можно сказать.
 *  - Никого из того месяца нет (клиента завели задним числом сразу на нового человека):
 *    0, на Дашборде это строка «Не назначено».
 *
 * Закрытые задачи этот класс не касается: они идут тому, кто закрыл, по журналу.
 */
class MonthPeople
{
    /** @var array<int, int> клиент => главбух месяца, кеш на один расчёт */
    private array $leads = [];

    /**
     * @param Collection<int, Collection<int, ClientResponsibleChange>> $changes клиент => смены по возрастанию
     * @param Collection<int, ?CarbonImmutable>                         $since   сотрудник => с какого дня есть в системе
     */
    public function __construct(
        private readonly CarbonImmutable $monthEnd,
        private readonly Collection $changes,
        private readonly Collection $since,
    ) {}

    /** Одним проходом по базе: история смен клиентов и даты появления сотрудников. */
    public static function load(CarbonImmutable $monthEnd, Collection $clientIds, Collection $employees): self
    {
        $changes = ClientResponsibleChange::whereIn('client_id', $clientIds)
            ->orderBy('changed_at')->orderBy('id')
            ->get(['client_id', 'previous_employee_id', 'employee_id', 'changed_at'])
            ->groupBy('client_id');

        // Появился = заведён в системе или принят на работу, что раньше.
        $since = $employees->mapWithKeys(fn ($e) => [$e->id => collect([$e->created_at, $e->hired_at])
            ->filter()->map(fn ($d) => CarbonImmutable::parse($d)->startOfDay())->min()]);

        return new self($monthEnd, $changes, $since);
    }

    /** Главбух клиента в этом месяце; 0 = никого из того месяца. */
    public function lead(Client $client): int
    {
        return $this->leads[$client->id] ??= $this->resolveLead($client);
    }

    /** Кому засчитать незакрытую задачу: нынешнему исполнителю, если он был в том месяце. */
    public function doer(Client $client, int $assigneeId): int
    {
        return $this->existed($assigneeId) ? $assigneeId : $this->lead($client);
    }

    private function resolveLead(Client $client): int
    {
        $current = $client->responsible_employee_id ? (int) $client->responsible_employee_id : null;

        [$lead] = ClientResponsibleChange::responsibleAt($this->changes->get($client->id, []), $current, $this->monthEnd);

        return $lead !== null && $this->existed($lead) ? $lead : 0;
    }

    private function existed(int $employeeId): bool
    {
        $since = $this->since[$employeeId] ?? null;

        return $employeeId === 0 || $since === null || $since->lte($this->monthEnd);
    }
}
