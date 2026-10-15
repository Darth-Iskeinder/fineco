<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Employee;
use App\Models\EstimateItem;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Кому и по каким компаниям можно поручить внеплановую задачу.
 *
 * Форма «Добавить задачу» создаёт пачку: несколько сотрудников, у каждого свои компании.
 * Каждая пара «сотрудник + компания» становится отдельной задачей, будто её завели руками.
 *
 * Компанию можно выбрать, только если она закреплена за исполнителем и её видит автор:
 *   - за исполнителем: он ответственный, он в команде клиента или исполнитель БП в смете
 *     (тот же перечень, что в профиле сотрудника, EmployeeController::clientsOfEmployee);
 *   - автору: иначе поставивший задачу потом её не увидит (см. authorClients).
 * По чужой компании поручить нельзя: сначала сотрудника прикрепляют в карточке клиента.
 */
class AdhocAssignments
{
    /** Больше за раз обычно бывает по ошибке, а удалять потом пришлось бы по одной. */
    public const MAX_TASKS = 100;

    /**
     * Компании, по которым автор вообще может ставить задачи: админ и руководитель любые
     * действующие, остальные (включая главбуха) свои, где ответственный или исполнитель БП.
     * Ответственный нужен режиму «По компаниям»: ему задача уходит по умолчанию.
     */
    public static function authorClients(Employee $author): Collection
    {
        $query = Client::query()->orderBy('name');

        if ($author->isAdmin() || $author->isManager()) {
            return $query->active()->get(['id', 'name', 'responsible_employee_id']);
        }

        return $query
            ->where(fn ($q) => $q
                ->where('responsible_employee_id', $author->id)
                ->orWhereHas('estimates.rootItems', fn ($i) => $i
                    ->whereNull('parent_id')
                    ->where('assignee_id', $author->id)))
            ->get(['id', 'name', 'responsible_employee_id']);
    }

    /**
     * Компании каждого сотрудника среди данных: [employee_id => [client_id, ...]].
     *
     * Три запроса на всю фирму, а не по три на сотрудника: форма получает карту сразу
     * для всех. Клиенты взяты через модель с фильтром фирмы, позиции сметы только по ним,
     * поэтому чужая фирма сюда не попадает.
     */
    public static function linkedClientIds(Collection $clientIds): array
    {
        $ids   = $clientIds->values()->all();
        $pairs = [];

        foreach (Client::whereIn('id', $ids)->whereNotNull('responsible_employee_id')->get(['id', 'responsible_employee_id']) as $client) {
            $pairs[$client->responsible_employee_id][$client->id] = true;
        }

        foreach (Client::whereIn('id', $ids)->whereHas('employees')->with('employees:employees.id')->get(['id']) as $client) {
            foreach ($client->employees as $member) {
                $pairs[$member->id][$client->id] = true;
            }
        }

        $assignees = EstimateItem::query()
            ->join('estimates', 'estimates.id', '=', 'estimate_items.estimate_id')
            ->whereIn('estimates.client_id', $ids)
            ->whereNotNull('estimate_items.assignee_id')
            ->distinct()
            ->get(['estimate_items.assignee_id', 'estimates.client_id']);

        foreach ($assignees as $row) {
            $pairs[$row->assignee_id][$row->client_id] = true;
        }

        return array_map('array_keys', $pairs);
    }

    /**
     * Проверить пачку и вернуть её разобранной: [[Employee, [Client|null, ...]], ...].
     * Сотрудник без отмеченных компаний получает одну задачу без компании.
     *
     * @param array<int, array{employee_id: int, client_ids?: array<int>}> $requested
     * @throws ValidationException компания не закреплена за сотрудником или задач слишком много
     */
    public static function resolve(Employee $author, array $requested): array
    {
        $total = array_sum(array_map(fn (array $a) => max(1, count($a['client_ids'] ?? [])), $requested));

        if ($total > self::MAX_TASKS) {
            throw ValidationException::withMessages([
                'assignments' => 'Не больше ' . self::MAX_TASKS . " задач за раз, сейчас выбрано {$total}",
            ]);
        }

        $allowed = self::authorClients($author)->keyBy('id');
        $linked  = self::linkedClientIds($allowed->keys());
        $result  = [];

        foreach ($requested as $assignment) {
            // Тот же отбор, что и в списке: форму можно отправить и в обход него.
            $assignee  = Employee::assignable()->findOrFail($assignment['employee_id']);
            $own       = array_flip($linked[$assignee->id] ?? []);
            $clientIds = array_values(array_unique($assignment['client_ids'] ?? []));

            foreach ($clientIds as $clientId) {
                if (!isset($own[$clientId])) {
                    throw ValidationException::withMessages([
                        'assignments' => 'Компания ' . ($allowed->has($clientId) ? '«' . $allowed[$clientId]->name . '»' : "#{$clientId}")
                            . " не закреплена за сотрудником {$assignee->full_name}",
                    ]);
                }
            }

            $result[] = [$assignee, $clientIds ? array_map(fn ($id) => $allowed[$id], $clientIds) : [null]];
        }

        return $result;
    }
}
