<?php

namespace App\Services;

use App\Models\ActivityType;
use App\Models\Client;
use App\Models\Employee;
use App\Models\OrganizationForm;
use App\Models\Tariff;
use App\Models\TaxSystem;

/**
 * Строки-примеры для скачиваемого шаблона.
 *
 * Собираются из справочников самого аккаунта, а не из красивых выдуманных
 * названий: шаблон обязан проходить ту же проверку, что и любой другой файл.
 * Придуманный «Общий налоговый режим» в чужом справочнике не найдётся, и
 * человек получит ошибку на файле, который ему только что выдала система.
 *
 * Примеров три — они показывают, что обязательны только название и ИНН,
 * а остальное заполняется по мере надобности.
 */
final class ClientCsvTemplate
{
    public static function rows(): array
    {
        $form     = OrganizationForm::query()->orderBy('id')->value('name');
        $activity = ActivityType::query()->active()->ordered()->value('name');
        $tax      = TaxSystem::query()->active()->ordered()->value('name');
        $tariff   = Tariff::query()->active()->ordered()->value('name');
        $employee = Employee::query()->active()->orderBy('full_name')->value('full_name');

        $inns = self::freeInns(3);

        return [
            // Минимум: только то, без чего клиента не создать.
            self::row(['name' => 'ОсОО «Ромашка»', 'inn' => $inns[0]]),

            // Обычный случай: кто ведёт и по какому тарифу.
            self::row([
                'name'        => 'ОсОО «Василёк»',
                'inn'         => $inns[1],
                'tariff'      => $tariff,
                'responsible' => $employee,
                'phone'       => '+996700123456',
            ]),

            // Все колонки разом — видно формат даты и «да/нет».
            self::row([
                'name'               => 'ИП Иванов И.И.',
                'inn'                => $inns[2],
                'organization_form'  => $form,
                'activity_type'      => $activity,
                'tax_system'         => $tax,
                'tariff'             => $tariff,
                'responsible'        => $employee,
                'tax_office_code'    => '001',
                'service_start_date' => now()->format('Y-m-d'),
                'is_active'          => 'да',
                'phone'              => '+996555987654',
                'contact_person'     => 'Иванова Мария',
                'notes'              => 'Строки-примеры удалите перед загрузкой',
            ]),
        ];
    }

    /** Значения по ключам колонок — в порядке колонок файла, пустые ячейки допустимы. */
    private static function row(array $values): array
    {
        return array_map(
            fn (string $key) => $values[$key] ?? '',
            array_keys(ClientCsvSchema::COLUMNS),
        );
    }

    /**
     * ИНН, которых в базе ещё нет.
     *
     * Иначе шаблон, скачанный дважды, во второй раз упрётся в собственных же
     * клиентов, созданных из него в первый.
     */
    private static function freeInns(int $count): array
    {
        $taken = Client::query()->pluck('inn')->flip();
        $free  = [];

        for ($candidate = 10000000000001; count($free) < $count; $candidate++) {
            if (!$taken->has((string) $candidate)) {
                $free[] = (string) $candidate;
            }
        }

        return $free;
    }
}
