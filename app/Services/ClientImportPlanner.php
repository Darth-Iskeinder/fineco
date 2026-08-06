<?php

namespace App\Services;

use App\Models\ActivityType;
use App\Models\Client;
use App\Models\Employee;
use App\Models\OrganizationForm;
use App\Models\Tariff;
use App\Models\TaxSystem;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

/**
 * Решает, что случится с каждой строкой файла, ничего при этом не записывая.
 *
 * Разделение намеренное: человек сначала видит последствия и только потом
 * подтверждает. Тот же план потом исполняет запись — значит показанное на
 * экране и записанное в базу не разойдутся.
 *
 * Вердикты:
 *   create    — нового клиента ещё нет;
 *   update    — в строке указан id существующего клиента;
 *   duplicate — id нет, но ИНН уже занят. Что с ним делать, решает галочка
 *               «обновлять существующих»: без неё это ошибка, с ней — update.
 *               Обе судьбы считаем сразу, чтобы галочка на экране пересчитывала
 *               счётчики мгновенно, без похода на сервер;
 *   error     — строку записать нельзя.
 */
final class ClientImportPlanner
{
    /** @var array<string, array<string, int>> справочник => [название в нижнем регистре => id] */
    private array $books = [];

    /** @var array<string, int> ИНН => id клиента, уже существующего в базе */
    private array $innTaken = [];

    /**
     * @param  array<int, array{line: int, values: array<string, string>}>  $rows
     * @return array<int, array<string, mixed>>
     */
    public function plan(array $rows): array
    {
        $this->loadBooks();
        $this->loadExistingInns();

        $plan = [];
        $seenInn = [];

        foreach ($rows as $row) {
            $result = $this->planRow($row['values'], $seenInn);
            $result['line'] = $row['line'];

            if (!empty($result['inn'])) {
                $seenInn[$result['inn']] = $row['line'];
            }

            $plan[] = $result;
        }

        return $plan;
    }

    /** Итоги для экрана: сколько строк в каждом состоянии. */
    public static function summary(array $plan, bool $updateExisting): array
    {
        $counts = ['create' => 0, 'update' => 0, 'error' => 0];

        foreach ($plan as $row) {
            $counts[self::verdict($row, $updateExisting)]++;
        }

        return $counts;
    }

    /** Судьба строки при выбранном режиме: «duplicate» решается галочкой. */
    public static function verdict(array $row, bool $updateExisting): string
    {
        if ($row['verdict'] !== 'duplicate') {
            return $row['verdict'];
        }

        return $updateExisting ? 'update' : 'error';
    }

    /** Причина, которую видит человек: у дубля она зависит от режима. */
    public static function reason(array $row, bool $updateExisting): ?string
    {
        if ($row['verdict'] === 'duplicate' && !$updateExisting) {
            return $row['duplicate_reason'];
        }

        return $row['reason'];
    }

    private function planRow(array $values, array $seenInn): array
    {
        $base = [
            'name'             => $values['name'] ?? '',
            'inn'              => $values['inn'] ?? '',
            'verdict'          => 'error',
            'reason'           => null,
            'duplicate_reason' => null,
            'client_id'        => null,
            'attributes'       => [],
        ];

        $validator = Validator::make($values, [
            'name' => ['required', 'string', 'max:255'],
            'inn'  => ['required', 'string', 'max:14'],
        ], [
            'name.required' => 'не заполнено название',
            'name.max'      => 'слишком длинное название',
            'inn.required'  => 'не заполнен ИНН',
            'inn.max'       => 'ИНН длиннее 14 знаков',
        ]);

        if ($validator->fails()) {
            return ['reason' => implode('; ', $validator->errors()->all())] + $base;
        }

        // Один и тот же ИНН дважды в одном файле: какая из строк «правильная» —
        // знает только человек, поэтому вторую не берём.
        if (isset($seenInn[$base['inn']])) {
            return ['reason' => 'этот ИНН уже встречался в строке ' . $seenInn[$base['inn']]] + $base;
        }

        $attributes = [];

        foreach ($this->references() as $key => [$book, $field, $title]) {
            $raw = trim($values[$key] ?? '');

            if ($raw === '') {
                continue;
            }

            $id = $this->books[$book][mb_strtolower($raw)] ?? null;

            if ($id === null) {
                // Создавать справочник на лету нельзя: от одной опечатки
                // в настройках заводится мусорный тариф, и это никто не заметит.
                return ['reason' => $title . ' «' . $raw . '» не найден. Допустимые: ' . $this->allowed($book)] + $base;
            }

            $attributes[$field] = $id;
        }

        if (($date = trim($values['service_start_date'] ?? '')) !== '') {
            $parsed = $this->parseDate($date);

            if (!$parsed) {
                return ['reason' => 'дата начала обслуживания «' . $date . '» непонятна, нужен вид 2026-08-31'] + $base;
            }

            $attributes['service_start_date'] = $parsed;
        }

        $attributes['name'] = $base['name'];
        $attributes['inn']  = $base['inn'];

        foreach (['tax_office_code' => 'tax_office_code', 'notes' => 'notes'] as $key => $field) {
            if (($value = trim($values[$key] ?? '')) !== '') {
                $attributes[$field] = $value;
            }
        }

        if (($active = trim($values['is_active'] ?? '')) !== '') {
            $attributes['is_active'] = $this->isYes($active);
        }

        if (($phone = trim($values['phone'] ?? '')) !== '') {
            $attributes['contacts'] = [['type' => 'phone', 'value' => $phone, 'note' => null]];
        }

        if (($person = trim($values['contact_person'] ?? '')) !== '') {
            $attributes['related_persons'] = [['name' => $person, 'role' => null, 'inn' => null, 'note' => null]];
        }

        $base['attributes'] = $attributes;

        // Явно указанный id — обновление конкретного клиента.
        if (($id = trim($values['id'] ?? '')) !== '') {
            $client = Client::find($id);

            if (!$client) {
                return ['reason' => 'клиента с id ' . $id . ' в базе нет'] + $base;
            }

            $takenBy = $this->innTaken[$base['inn']] ?? null;

            if ($takenBy !== null && $takenBy !== $client->id) {
                return ['reason' => 'ИНН занят другим клиентом (id ' . $takenBy . ')'] + $base;
            }

            return ['verdict' => 'update', 'client_id' => $client->id] + $base;
        }

        if (isset($this->innTaken[$base['inn']])) {
            $existing = $this->innTaken[$base['inn']];

            return [
                'verdict'          => 'duplicate',
                'client_id'        => $existing,
                'duplicate_reason' => 'клиент с таким ИНН уже есть (id ' . $existing . ')',
            ] + $base;
        }

        return ['verdict' => 'create'] + $base;
    }

    /** колонка => [справочник, поле клиента, как назвать в сообщении об ошибке] */
    private function references(): array
    {
        return [
            'organization_form' => ['organization_forms', 'organization_form_id', 'Форма организации'],
            'activity_type'     => ['activity_types', 'activity_type_id', 'Вид деятельности'],
            'tax_system'        => ['tax_systems', 'tax_system_id', 'Режим налогообложения'],
            'tariff'            => ['tariffs', 'tariff_id', 'Тариф'],
            'responsible'       => ['employees', 'responsible_employee_id', 'Ответственный'],
        ];
    }

    private function loadBooks(): void
    {
        $this->books = [
            'organization_forms' => $this->book(OrganizationForm::query()->pluck('id', 'name')),
            'activity_types'     => $this->book(ActivityType::query()->pluck('id', 'name')),
            'tax_systems'        => $this->book(TaxSystem::query()->pluck('id', 'name')),
            'tariffs'            => $this->book(Tariff::query()->pluck('id', 'name')),
            // Ответственного ищем и по имени, и по почте: в чужих таблицах
            // встречается и то, и другое.
            'employees'          => $this->book(Employee::query()->pluck('id', 'full_name'))
                                    + $this->book(Employee::query()->pluck('id', 'email')),
        ];
    }

    private function book(\Illuminate\Support\Collection $pairs): array
    {
        return $pairs->mapWithKeys(fn ($id, $name) => [mb_strtolower(trim((string) $name)) => $id])->all();
    }

    private function loadExistingInns(): void
    {
        $this->innTaken = Client::query()->pluck('id', 'inn')->all();
    }

    private function allowed(string $book): string
    {
        $names = array_slice(array_keys($this->books[$book]), 0, 5);

        return $names ? implode(', ', $names) : 'справочник пуст';
    }

    private function parseDate(string $value): ?string
    {
        foreach (['Y-m-d', 'd.m.Y', 'd/m/Y', 'Y/m/d'] as $format) {
            try {
                return Carbon::createFromFormat($format, $value)->toDateString();
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function isYes(string $value): bool
    {
        return in_array(mb_strtolower($value), ['да', 'yes', 'y', '1', 'true', 'активен'], true);
    }
}
