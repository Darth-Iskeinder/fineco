<?php

namespace App\Services;

use App\Models\BuhAdhocTask;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\EstimateItem;
use Illuminate\Support\Collection;

/**
 * Передача всей работы сотрудника одному человеку. Зовётся при увольнении и кнопкой
 * «Передать работу» у уже уволенного, на котором что-то осталось.
 *
 * Зачем: увольнение меняло только отметку, а работа оставалась на человеке. Смена
 * ответственного у клиента её переносила, но только у клиентов, где он ответственный.
 * Позиции, которые он вёл у чужих клиентов, оставались на нём: задачи по ним никто не
 * видел, пока они не становились просрочкой (так было с Мунжуровой у БИТУАН).
 *
 * Что куда:
 *   - клиенты, где он ответственный и обслуживание идёт, переходят к получателю целиком,
 *     через ClientResponsibleTransfer (позиции, напоминания, внеплановые);
 *   - у остановленных клиентов (пауза, «Завершен») ответственный снимается, его позиции
 *     там остаются без исполнителя. Кого ставить, решат при возврате клиента в работу:
 *     без ответственного вернуть его нельзя (ClientController, секция «Статус»);
 *   - остановленный клиент, где у него есть начатая задача, считается рабочим: хвост
 *     ещё доделывают, и он должен на ком-то быть;
 *   - его позиции у чужих клиентов переходят к получателю (у остановленных снимаются);
 *   - его незакрытые внеплановые задачи переходят к получателю;
 *   - начатые задачи получают статус «Передана» (TaskHandover), время остаётся за ним.
 *
 * Историю не трогаем: закрытое им, его время и задачи на проверке остаются как есть.
 */
class EmployeeWorkTransfer
{
    /** Начатая и не сданная плановая задача. Те же статусы, что в TaskHandover. */
    private const LOG_OPEN = ['pending', 'running', 'paused', 'rework'];

    /** Внеплановая, которую ещё делать. Те же статусы, что в ClientResponsibleTransfer. */
    private const ADHOC_OPEN = ['pending', 'running', 'paused', 'rework'];

    /**
     * Что на сотруднике сейчас. Только чтение: окно показывает это до сохранения.
     *
     * @return array{
     *     clients: array<int, array{id:int, name:string}>,
     *     stopped: array<int, array{id:int, name:string}>,
     *     items: int, item_clients: array<int, string>,
     *     adhoc: int, logs: int, needs_recipient: bool
     * }
     */
    public function preview(Employee $from): array
    {
        [$working, $stopped] = $this->responsibleClients($from);
        $items = $this->foreignItems($from, $working->pluck('id'));
        $liveItems = $items->filter(fn ($item) => !$this->isQuiet($item->estimate->client, $from));

        $preview = [
            'clients'      => $this->names($working),
            'stopped'      => $this->names($stopped),
            'items'        => $liveItems->count(),
            'item_clients' => $liveItems->map(fn ($item) => $item->estimate->client->name)->unique()->sort()->values()->all(),
            'adhoc'        => $this->adhocQuery($from)->count(),
            'logs'         => $this->logsQuery($from)->count(),
        ];

        $preview['needs_recipient'] = $working->isNotEmpty() || $preview['items'] > 0
            || $preview['adhoc'] > 0 || $preview['logs'] > 0;

        return $preview;
    }

    /** На сотруднике есть хоть что-то, что надо передать или снять. */
    public function hasWork(Employee $from): bool
    {
        $preview = $this->preview($from);

        return $preview['needs_recipient'] || $preview['stopped'] !== [];
    }

    /**
     * Передать. Вызывать внутри транзакции вместе со сменой статуса сотрудника:
     * либо уволен и всё передано, либо ничего.
     *
     * @return array{clients:int, stopped:int, items:int, adhoc:int}
     */
    public function apply(Employee $from, ?Employee $to): array
    {
        $result = ['clients' => 0, 'stopped' => 0, 'items' => 0, 'adhoc' => 0];

        [$working, $stopped] = $this->responsibleClients($from);
        $touched = collect();

        if ($to === null && $this->preview($from)['needs_recipient']) {
            throw new \LogicException('Работу сотрудника некому передать: получатель не выбран.');
        }

        foreach ($working as $client) {
            $client->update(['responsible_employee_id' => $to->id]);
            (new ClientResponsibleTransfer())->apply($client, $from->id);
            $result['clients']++;
        }

        foreach ($stopped as $client) {
            $client->update(['responsible_employee_id' => null]);
            $this->clientItems($client)->where('assignee_id', $from->id)->update(['assignee_id' => null]);
            $touched->put($client->id, $client);
            $result['stopped']++;
        }

        foreach ($this->foreignItems($from, $working->pluck('id')) as $item) {
            $client = $item->estimate->client;
            $quiet  = $this->isQuiet($client, $from);

            $item->update(['assignee_id' => $quiet ? null : $to->id]);
            if (!$quiet) {
                (new ClientResponsibleTransfer())->moveReminders($client, $from->id, $to->id);
                $result['items']++;
            }
            $touched->put($client->id, $client);
        }

        if ($to) {
            $result['adhoc'] = $this->adhocQuery($from)->update(['employee_id' => $to->id, 'assign_seen_at' => null]);
        }

        // Начатые задачи: у кого бы они ни были, исполнитель позиции теперь другой.
        // Клиенты, где он только начинал чужую задачу, тоже сюда: её вернут исполнителю.
        $logClients = Client::whereIn('id', $this->logsQuery($from)->select('client_id'))->get();
        foreach ($touched->values()->concat($logClients)->unique('id') as $client) {
            (new TaskHandover())->forClient($client);
        }

        return $result;
    }

    /**
     * Клиенты, где он ответственный: [идёт обслуживание или есть его начатая задача, остальные].
     *
     * @return array{0: Collection<int, Client>, 1: Collection<int, Client>}
     */
    private function responsibleClients(Employee $from): array
    {
        $clients = Client::with('clientStatus')
            ->where('responsible_employee_id', $from->id)
            ->orderBy('name')
            ->get();

        return $clients->partition(fn (Client $client) => !$this->isQuiet($client, $from))->all();
    }

    /**
     * Его позиции у клиентов, где ответственный не он (или где он ответственный, но
     * клиента уже перевели: тогда позиции переехали вместе с ним и сюда не попадут).
     *
     * @return Collection<int, EstimateItem>
     */
    private function foreignItems(Employee $from, Collection $workingClientIds): Collection
    {
        return EstimateItem::query()
            ->whereNull('parent_id')
            ->where('assignee_id', $from->id)
            ->whereHas('estimate.client', fn ($q) => $q->whereNotIn('clients.id', $workingClientIds))
            ->with('estimate.client.clientStatus')
            ->get()
            ->filter(fn (EstimateItem $item) => $item->estimate?->client !== null)
            ->values();
    }

    /**
     * Клиент, по которому работы нет: обслуживание остановлено, и начатых задач этого
     * сотрудника у клиента не осталось.
     */
    private function isQuiet(Client $client, Employee $from): bool
    {
        return $client->serviceIsStopped()
            && !$this->logsQuery($from)->where('client_id', $client->id)->exists();
    }

    private function clientItems(Client $client)
    {
        return EstimateItem::query()
            ->whereIn('estimate_id', Estimate::where('client_id', $client->id)->select('id'))
            ->whereNull('parent_id');
    }

    private function adhocQuery(Employee $from)
    {
        return BuhAdhocTask::query()
            ->where('employee_id', $from->id)
            ->whereIn('status', self::ADHOC_OPEN);
    }

    private function logsQuery(Employee $from)
    {
        return BuhTaskLog::query()
            ->where('employee_id', $from->id)
            ->whereIn('status', self::LOG_OPEN);
    }

    /** @return array<int, array{id:int, name:string}> */
    private function names(Collection $clients): array
    {
        return $clients->map(fn (Client $c) => ['id' => $c->id, 'name' => $c->name])->values()->all();
    }
}
