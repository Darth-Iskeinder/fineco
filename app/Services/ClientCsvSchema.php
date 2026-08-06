<?php

namespace App\Services;

use App\Models\Client;

/**
 * Колонки файла клиентов — одно описание на выгрузку и на загрузку.
 *
 * Держим их здесь, а не по месту использования, чтобы экспорт и импорт не
 * разъехались: файл, выгруженный из системы, обязан ею же и читаться. На этом
 * стоит весь сценарий «выгрузил, поправил в Excel, залил обратно».
 *
 * Состав намеренно короткий — то же, что спрашивает форма «Добавить клиента».
 * Характеристики бизнеса (ККМ, импорт, филиалы и прочие полсотни флагов) сюда
 * не входят: они управляют подтягиванием бизнес-процессов в смету, и
 * проставлять их вслепую из чужой таблицы — значит молча получить неверные
 * сметы. Их заполняют осознанно в карточке клиента.
 */
final class ClientCsvSchema
{
    /** Ключ колонки => заголовок в файле. Порядок — порядок колонок. */
    public const COLUMNS = [
        'id'                 => 'id',
        'name'               => 'Название',
        'inn'                => 'ИНН',
        'organization_form'  => 'Форма организации',
        'activity_type'      => 'Вид деятельности',
        'tax_system'         => 'Режим налогообложения',
        'tariff'             => 'Тариф',
        'responsible'        => 'Ответственный',
        'tax_office_code'    => 'Код НО',
        'service_start_date' => 'Дата начала обслуживания',
        'is_active'          => 'Активен',
        'phone'              => 'Телефон',
        'contact_person'     => 'Контактное лицо',
        'notes'              => 'Заметка',
    ];

    /** Разделитель: Excel в русской локали открывает такой файл двойным щелчком. */
    public const DELIMITER = ';';

    public static function headers(): array
    {
        return array_values(self::COLUMNS);
    }

    /** Строка файла для клиента — значения в порядке колонок. */
    public static function row(Client $client): array
    {
        return [
            $client->id,
            $client->name,
            $client->inn,
            $client->organizationForm?->name,
            $client->activityType?->name,
            $client->taxSystem?->name,
            $client->tariff?->name,
            $client->responsibleEmployee?->full_name,
            $client->tax_office_code,
            $client->service_start_date?->format('Y-m-d'),
            $client->is_active ? 'да' : 'нет',
            self::firstPhone($client),
            self::firstRelatedPerson($client),
            $client->notes,
        ];
    }

    /** Первый телефон из контактов: в файле колонка плоская, в базе — список. */
    private static function firstPhone(Client $client): ?string
    {
        foreach ($client->contacts ?? [] as $contact) {
            if (($contact['type'] ?? null) === 'phone' && !empty($contact['value'])) {
                return $contact['value'];
            }
        }

        return null;
    }

    private static function firstRelatedPerson(Client $client): ?string
    {
        foreach ($client->related_persons ?? [] as $person) {
            if (!empty($person['name'])) {
                return $person['name'];
            }
        }

        return null;
    }
}
