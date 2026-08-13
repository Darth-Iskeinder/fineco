<?php

namespace App\Services;

use App\Models\ActivityType;
use App\Models\Client;
use App\Models\OrganizationForm;
use App\Models\Tariff;
use App\Models\TaxSystem;

/**
 * Скачиваемый шаблон для загрузки клиентов: набор колонок и строки-примеры.
 *
 * Колонок здесь МЕНЬШЕ, чем в выгрузке. Выгрузка обязана отдавать всё, что есть
 * в карточке, иначе сценарий «выгрузил, поправил в Excel, залил обратно» терял бы
 * данные. А шаблон заполняет человек руками при первом переносе базы, и лишние
 * колонки в нём только мешают: ответственного назначают позже (сотрудников на
 * момент переноса может ещё не быть), код НО известен не всегда. Импорт от этого
 * не страдает — он читает колонки по заголовкам, недостающие просто пропускает.
 *
 * Значения-примеры собираются из справочников самого аккаунта, а не из красивых
 * выдуманных названий: шаблон обязан проходить ту же проверку, что и любой другой
 * файл. Придуманный «Общий налоговый режим» в чужом справочнике не найдётся, и
 * человек получит ошибку на файле, который ему только что выдала система.
 *
 * Примеров три — они показывают, что обязательны только название и ИНН,
 * а остальное заполняется по мере надобности.
 */
final class ClientCsvTemplate
{
    /**
     * Колонки шаблона — подмножество `ClientCsvSchema::COLUMNS` в том же порядке.
     * Чего здесь нет по сравнению с выгрузкой: `id`, `responsible`, `tax_office_code`.
     *
     * `id` убран не для красоты. Заполненный id означает «обновить этого клиента»:
     * человек, переносящий базу из своей таблицы со своей нумерацией 1, 2, 3,
     * переписал бы название и ИНН у трёх существующих клиентов вместо создания
     * новых. В выгрузке колонка остаётся — там она заполнена настоящими id, и
     * сценарий «выгрузил, поправил, залил обратно» работает как прежде.
     */
    public const COLUMNS = [
        'name',
        'inn',
        'organization_form',
        'activity_type',
        'tax_system',
        'tariff',
        'service_start_date',
        'is_active',
        'phone',
        'contact_person',
        'notes',
    ];

    /** Заголовки шаблона — те же подписи, что и в выгрузке, иначе импорт их не узнает. */
    public static function headers(): array
    {
        return array_map(
            fn (string $key) => ClientCsvSchema::COLUMNS[$key],
            self::COLUMNS,
        );
    }

    public static function rows(): array
    {
        $form     = OrganizationForm::query()->orderBy('id')->value('name');
        $activity = ActivityType::query()->active()->ordered()->value('name');
        $tax      = TaxSystem::query()->active()->ordered()->value('name');
        $tariff   = Tariff::query()->active()->ordered()->value('name');

        $inns = self::freeInns(3);

        return [
            // Минимум: только то, без чего клиента не создать.
            self::row(['name' => 'ОсОО «Ромашка»', 'inn' => $inns[0]]),

            // Обычный случай: по какому тарифу ведём и как связаться.
            self::row([
                'name'   => 'ОсОО «Василёк»',
                'inn'    => $inns[1],
                'tariff' => $tariff,
                'phone'  => '+996700123456',
            ]),

            // Все колонки разом — видно формат даты и «да/нет».
            self::row([
                'name'               => 'ИП Иванов И.И.',
                'inn'                => $inns[2],
                'organization_form'  => $form,
                'activity_type'      => $activity,
                'tax_system'         => $tax,
                'tariff'             => $tariff,
                'service_start_date' => now()->format('Y-m-d'),
                'is_active'          => 'да',
                'phone'              => '+996555987654',
                'contact_person'     => 'Иванова Мария',
                'notes'              => 'Строки-примеры удалите перед загрузкой',
            ]),
        ];
    }

    /** Значения по ключам колонок — в порядке колонок шаблона, пустые ячейки допустимы. */
    private static function row(array $values): array
    {
        return array_map(
            fn (string $key) => $values[$key] ?? '',
            self::COLUMNS,
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
