<?php

namespace App\Services\AutoAudit;

use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * Чтение отчёта по единому налогу (бланк ГНС, приложение 3 к приказу от 24.02.2022 № 73).
 *
 * Нужное число — поле 186, «итого налогооблагаемая база». Рядом поле 187, общая сумма
 * единого налога.
 *
 * Ловить его приходится по расположению: бланк целиком нарисован картинкой, в текстовом
 * слое лежат только заполненные числа, а подписей полей и их номеров нет вовсе. Само по
 * себе это ненадёжно — сменится редакция приказа, всё съедет, и мы будем брать соседнюю
 * клетку молча.
 *
 * Поэтому координатам не верим, а проверяем арифметикой. Бланк сходится сам с собой
 * дважды, и обе проверки обязаны пройти:
 *
 *   - итог по каждой колонке равен сумме строк выше;
 *   - в каждой строке база, умноженная на ставку, даёт сумму налога.
 *
 * Если разбор съехал, арифметика развалится, и мы ответим «документ не тот», а не
 * правдоподобным, но чужим числом. Проверено на четырёх боевых отчётах разных клиентов,
 * включая квартальный и заполненный по двум ставкам сразу.
 */
class SingleTaxReportReader
{
    /**
     * Границы числовых колонок по левому краю (в точках PDF).
     *
     * Числа в бланке выровнены по правому краю, поэтому левый край гуляет вместе с длиной
     * числа. Замеры по четырём боевым отчётам: база 358-380, ставка 463-470, налог 530-547.
     * Между колонками остаётся шестьдесят с лишним точек, так что даже с большим запасом
     * границы не пересекаются.
     *
     * Ошибиться колонкой всё же можно — на бланке невиданной длины числа. Тогда развалится
     * арифметика, и мы откажемся отвечать; см. checkArithmetic().
     */
    private const COLUMN_BASE = [330.0, 420.0];   // налогооблагаемая база
    private const COLUMN_RATE = [450.0, 500.0];   // ставка налога, %
    private const COLUMN_TAX  = [505.0, 600.0];   // сумма налога

    /** Разброс высоты, внутри которого слова считаются одной строкой. */
    private const ROW_TOLERANCE = 3.0;

    /** Допуск при сверке арифметики: ставка даёт третий знак, бланк печатает два. */
    private const TOLERANCE = 0.05;

    public function __construct(private readonly PdfTextLayer $pdf) {}

    /** Итоговая налогооблагаемая база из отчёта. */
    public function taxableBase(string $path): DocumentValue
    {
        try {
            $words = $this->pdf->words($path);
        } catch (RuntimeException $e) {
            return DocumentValue::unreadable($e->getMessage());
        } catch (Throwable $e) {
            return DocumentValue::unreadable('Не удалось прочитать PDF: ' . $e->getMessage());
        }

        if (!$words) {
            return DocumentValue::wrongDocument('В PDF нет текста — похоже, это скан');
        }

        $rows = $this->rows($words);

        [$period, $periodRow] = $this->period($rows);

        if (!$period) {
            return DocumentValue::wrongDocument('Это не отчёт по единому налогу: не нашли строку отчётного периода');
        }

        // Всё, что ниже строки периода, — тело бланка. Отсчитываем от неё, а не от
        // фиксированной высоты: так разбор переживёт сдвиг шапки.
        $body = array_filter($rows, fn ($y) => $y > $periodRow, ARRAY_FILTER_USE_KEY);

        $lines  = $this->dataLines($body);
        $totals = $this->totalsLine($body);

        if (!$totals) {
            return DocumentValue::wrongDocument('Не нашли итоговую строку (поля 186 и 187)');
        }

        if (!$lines) {
            return DocumentValue::wrongDocument('Не нашли ни одной строки со ставкой налога');
        }

        if ($mismatch = $this->checkArithmetic($lines, $totals)) {
            return $mismatch;
        }

        return DocumentValue::found($totals['base'], [
            'период'      => $period->label(),
            'база'        => number_format($totals['base'], 2, ',', ' '),
            'налог'       => number_format($totals['tax'], 2, ',', ' '),
            'строк'       => count($lines),
            'сходимость'  => 'итог = сумма строк, база × ставка = налог',
        ], $period);
    }

    /**
     * Обе проверки бланка.
     *
     * Разошлось — значит разбор взял не те клетки. Числа при этом выглядят настоящими,
     * поэтому единственный честный ответ — отказ, а не «скорее всего вот столько».
     */
    private function checkArithmetic(array $lines, array $totals): ?DocumentValue
    {
        $sumBase = array_sum(array_column($lines, 'base'));
        $sumTax  = array_sum(array_column($lines, 'tax'));

        if (abs($sumBase - $totals['base']) > self::TOLERANCE) {
            return DocumentValue::wrongDocument(sprintf(
                'Бланк не сходится: итоговая база %s, а сумма строк %s',
                number_format($totals['base'], 2, ',', ' '),
                number_format($sumBase, 2, ',', ' '),
            ));
        }

        if (abs($sumTax - $totals['tax']) > self::TOLERANCE) {
            return DocumentValue::wrongDocument(sprintf(
                'Бланк не сходится: итоговый налог %s, а сумма строк %s',
                number_format($totals['tax'], 2, ',', ' '),
                number_format($sumTax, 2, ',', ' '),
            ));
        }

        foreach ($lines as $line) {
            $expected = $line['base'] * $line['rate'] / 100;

            if (abs($expected - $line['tax']) > self::TOLERANCE) {
                return DocumentValue::wrongDocument(sprintf(
                    'Строка не сходится: %s по ставке %s%% даёт %s, а в бланке %s',
                    number_format($line['base'], 2, ',', ' '),
                    rtrim(rtrim(number_format($line['rate'], 2, ',', ' '), '0'), ','),
                    number_format($expected, 2, ',', ' '),
                    number_format($line['tax'], 2, ',', ' '),
                ));
            }
        }

        return null;
    }

    /**
     * Отчётный период из шапки бланка: две даты подряд, каждая восемью цифрами.
     *
     * Цифры напечатаны по одной в своей клетке, поэтому склеиваем всю строку и разбираем
     * как ДДММГГГГ + ДДММГГГГ. Эта же строка служит границей: тело таблицы начинается
     * сразу под ней.
     *
     * @return array{0: ?DocumentPeriod, 1: ?float}
     */
    private function period(array $rows): array
    {
        foreach ($rows as $y => $cells) {
            // Каждая цифра — в своей клетке бланка, поэтому и кусков ровно шестнадцать.
            // Без этой проверки на роль периода претендует строка данных: «87 513,60»,
            // «4,00» и «3 500,54» вместе тоже дают шестнадцать цифр.
            if (count($cells) !== 16 || array_filter($cells, fn ($c) => mb_strlen(trim($c['text'])) !== 1)) {
                continue;
            }

            $digits = preg_replace('/\D+/', '', implode('', array_column($cells, 'text')));

            if (strlen($digits) !== 16) {
                continue;
            }

            $from = $this->date(substr($digits, 0, 8));
            $to   = $this->date(substr($digits, 8, 8));

            if ($from && $to && $from->lessThanOrEqualTo($to)) {
                return [new DocumentPeriod($from, $to), $y];
            }
        }

        return [null, null];
    }

    private function date(string $ddmmyyyy): ?CarbonImmutable
    {
        $date = CarbonImmutable::createFromFormat('!dmY', $ddmmyyyy);

        // createFromFormat молча «исправляет» 32-е число на 1-е следующего месяца:
        // сверяем обратно, чтобы мусор не превратился в правдоподобную дату.
        return $date && $date->format('dmY') === $ddmmyyyy ? $date : null;
    }

    /**
     * Строки с данными: те, где есть и база, и ставка.
     *
     * Ставка — надёжный признак строки бланка. У итоговой её нет, у подытогов нет базы,
     * а в шапке нет ни того, ни другого.
     */
    private function dataLines(array $body): array
    {
        $lines = [];

        foreach ($body as $cells) {
            $base = $this->column($cells, self::COLUMN_BASE);
            $rate = $this->column($cells, self::COLUMN_RATE);

            if ($base === null || $rate === null) {
                continue;
            }

            $lines[] = [
                'base' => $base,
                'rate' => $rate,
                'tax'  => $this->column($cells, self::COLUMN_TAX) ?? 0.0,
            ];
        }

        return $lines;
    }

    /**
     * Итоговая строка (поля 186 и 187): есть база и налог, но нет ставки.
     *
     * Берём последнюю такую: выше по бланку встречаются подытоги по разделам, но у них
     * заполнена только колонка налога.
     */
    private function totalsLine(array $body): ?array
    {
        $found = null;

        foreach ($body as $cells) {
            $base = $this->column($cells, self::COLUMN_BASE);
            $tax  = $this->column($cells, self::COLUMN_TAX);
            $rate = $this->column($cells, self::COLUMN_RATE);

            if ($base !== null && $tax !== null && $rate === null) {
                $found = ['base' => $base, 'tax' => $tax];
            }
        }

        return $found;
    }

    /**
     * Число в колонке.
     *
     * Разборщик отдаёт число целиком, вместе с пробелами-разделителями разрядов:
     * «1 465 310,00» приезжает одним куском. Их и убираем перед разбором.
     */
    private function column(array $cells, array $bounds): ?float
    {
        [$from, $to] = $bounds;

        foreach ($cells as $cell) {
            if ($cell['left'] < $from || $cell['left'] > $to) {
                continue;
            }

            $text = str_replace(["\u{00A0}", ' '], '', trim($cell['text']));

            if (preg_match('/^\d+(,\d+)?$/', $text)) {
                return (float) str_replace(',', '.', $text);
            }
        }

        return null;
    }

    /**
     * Слова, разложенные по строкам: ключ — высота, значение — ячейки слева направо.
     *
     * @param PdfWord[] $words
     */
    private function rows(array $words): array
    {
        $rows = [];

        foreach ($words as $word) {
            $key = null;

            foreach (array_keys($rows) as $existing) {
                if (abs($existing - $word->top) <= self::ROW_TOLERANCE) {
                    $key = $existing;

                    break;
                }
            }

            $rows[$key ?? $word->top][] = [
                'left' => $word->left,
                'text' => $word->text,
            ];
        }

        ksort($rows);

        foreach ($rows as &$cells) {
            usort($cells, fn ($a, $b) => $a['left'] <=> $b['left']);
        }

        return $rows;
    }
}
