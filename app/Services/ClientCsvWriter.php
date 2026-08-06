<?php

namespace App\Services;

/**
 * Пишет CSV, который откроется в Excel и никого не подставит.
 *
 * Две вещи, из-за которых это отдельный класс, а не пара строк в контроллере:
 *
 *   - BOM в начале. Без него Excel читает файл в системной кодировке и вместо
 *     русских названий показывает кракозябры.
 *   - Экранирование формул. Значение, начинающееся с «=», «+», «-» или «@»,
 *     Excel у получателя выполнит как формулу — то есть содержимое нашей базы
 *     может стать исполняемым кодом на чужом компьютере. Гасим апострофом.
 */
final class ClientCsvWriter
{
    private const BOM = "\xEF\xBB\xBF";

    /** Символы, с которых Excel начинает считать значение формулой. */
    private const FORMULA_STARTERS = ['=', '+', '-', '@', "\t", "\r"];

    /** @param iterable<array> $rows */
    public static function stream($handle, array $headers, iterable $rows): void
    {
        fwrite($handle, self::BOM);

        self::putRow($handle, $headers);

        foreach ($rows as $row) {
            self::putRow($handle, $row);
        }
    }

    private static function putRow($handle, array $row): void
    {
        fputcsv(
            $handle,
            array_map(self::defuse(...), $row),
            ClientCsvSchema::DELIMITER,
            '"',
            '',
        );
    }

    /** Обезвредить значение, которое Excel принял бы за формулу. */
    private static function defuse(mixed $value): string
    {
        $value = (string) ($value ?? '');

        if ($value !== '' && in_array($value[0], self::FORMULA_STARTERS, true)) {
            return "'" . $value;
        }

        return $value;
    }
}
