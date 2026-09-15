<?php

namespace App\Services\AutoAudit;

use Carbon\CarbonImmutable;

/**
 * Общее у бланков налоговой в PDF: форма нарисована картинкой, а текстом лежат только
 * заполненные клетки. Так устроены отчёт по единому налогу и форма 161.
 *
 * Здесь то, что у таких бланков совпадает: раскладка слов по строкам, ИНН и отчётный
 * период в шапке. Цифры ИНН и периода напечатаны по одной в своей клетке.
 */
final class PdfBlank
{
    /** Разброс высоты, внутри которого слова считаются одной строкой. */
    private const ROW_TOLERANCE = 3.0;

    /**
     * Слова, разложенные по строкам сверху вниз. Ключ: высота строки, значение: ячейки
     * слева направо.
     *
     * @param PdfWord[] $words
     * @return array<string, array<int, array{left: float, text: string}>>
     */
    public static function rows(array $words): array
    {
        $rows = [];

        foreach ($words as $word) {
            $key = null;

            foreach (array_keys($rows) as $existing) {
                if (abs((float) $existing - $word->top) <= self::ROW_TOLERANCE) {
                    $key = $existing;

                    break;
                }
            }

            // Ключ строкой: дробный ключ массива PHP обрезает до целого.
            $rows[(string) ($key ?? $word->top)][] = ['left' => $word->left, 'text' => $word->text];
        }

        uksort($rows, fn ($a, $b) => (float) $a <=> (float) $b);

        foreach ($rows as &$cells) {
            usort($cells, fn ($a, $b) => $a['left'] <=> $b['left']);
        }

        return $rows;
    }

    /**
     * ИНН организации из шапки: первая сверху строка ровно с четырнадцатью цифрами, каждая
     * в своей клетке. Название рядом с ними не мешает, его клетки не одиночные цифры.
     */
    public static function inn(array $rows): ?string
    {
        foreach ($rows as $cells) {
            $digits = array_filter($cells, fn ($cell) => preg_match('/^\d$/', trim($cell['text'])) === 1);

            if (count($digits) === 14) {
                return implode('', array_map(fn ($cell) => trim($cell['text']), $digits));
            }
        }

        return null;
    }

    /**
     * Отчётный период: строка ровно из шестнадцати одиночных цифр, ДДММГГГГ и ДДММГГГГ.
     *
     * Проверяем, что каждая клетка одна цифра: иначе на роль периода претендует строка с
     * числами, в которых вместе тоже набирается шестнадцать цифр.
     *
     * @return array{0: ?DocumentPeriod, 1: ?string} период и высота его строки
     */
    public static function period(array $rows): array
    {
        foreach ($rows as $y => $cells) {
            if (count($cells) !== 16 || array_filter($cells, fn ($cell) => mb_strlen(trim($cell['text'])) !== 1)) {
                continue;
            }

            $digits = preg_replace('/\D+/', '', implode('', array_column($cells, 'text')));

            if (strlen($digits) !== 16) {
                continue;
            }

            $from = self::date(substr($digits, 0, 8));
            $to   = self::date(substr($digits, 8, 8));

            if ($from && $to && $from->lessThanOrEqualTo($to)) {
                return [new DocumentPeriod($from, $to), (string) $y];
            }
        }

        return [null, null];
    }

    /** Число из клетки: «1 465 310,00», «4407,4», «-97 475,00». Не число: null. */
    public static function number(string $text): ?float
    {
        $clean = str_replace(["\u{00A0}", ' '], '', trim($text));

        return preg_match('/^-?\d+([,.]\d+)?$/', $clean) ? (float) str_replace(',', '.', $clean) : null;
    }

    private static function date(string $ddmmyyyy): ?CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!dmY', $ddmmyyyy);

        // createFromFormat молча «исправляет» 32-е число на 1-е следующего месяца:
        // сверяем обратно, чтобы мусор не превратился в правдоподобную дату.
        return $date && $date->format('dmY') === $ddmmyyyy ? $date : null;
    }
}
