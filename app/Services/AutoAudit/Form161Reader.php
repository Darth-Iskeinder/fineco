<?php

namespace App\Services\AutoAudit;

use RuntimeException;
use Throwable;

/**
 * Чтение формы 161: отчёта по подоходному налогу и страховым взносам.
 *
 * Нужное число: общая сумма начисленного дохода из итоговой строки первой страницы. С ним
 * сверяется оборот по кредиту счёта 3520 «Начисленная заработная плата».
 *
 * Бланк устроен как отчёт по единому налогу: форма нарисована картинкой, текстом лежат
 * только заполненные клетки. Числа ловим по расположению, а верим им, только если итог
 * сходится с расшифровкой: на второй и следующих страницах список сотрудников, и
 *
 *   - число строк в списке равно числу сотрудников в итоге;
 *   - сумма их доходов равна доходу в итоге.
 *
 * Не сошлось, значит разбор взял не те клетки, и мы отказываемся отвечать, а не выдаём
 * правдоподобное чужое число.
 *
 * Формулой налога бланк не проверяем, хотя сначала пробовали. На боевых формах за июль 2026
 * она ломалась: у резидентов ПВТ ставка 5%, а не 10%, а у части работодателей облагаемая
 * база больше дохода (взносы от установленной базы). Сумма по сотрудникам от этого не зависит.
 *
 * Числа в клетках итоговой строки стоят по центру: у «1600» и «0» в одной колонке левый
 * край разный, а середина одна. Поэтому колонку итога определяем по середине числа, и
 * длинная сумма не уезжает в соседнюю колонку.
 */
class Form161Reader
{
    /** Границы колонок итоговой строки по середине числа, в точках PDF. Замеры по шести формам. */
    private const COLUMN_EMPLOYEES = [40.0, 88.0];    // число сотрудников
    private const COLUMN_INCOME    = [88.0, 146.0];   // общая сумма начисленного дохода

    /** Доход сотрудника в списке: левый край числа. Слева код «001», справа следующая колонка. */
    private const EMPLOYEE_INCOME_LEFT = [392.0, 432.0];

    /** Строка сотрудника в списке узнаётся по датам начала и конца периода: «01.07.2026 00:00:00». */
    private const EMPLOYEE_DATE = '/^\d{2}\.\d{2}\.\d{4}/';

    /** Половина ширины цифры: середина числа = левый край + длина × столько. */
    private const HALF_CHAR = 1.5;

    /** Копейки складываются из разбора текста, float может дать хвост. */
    private const TOLERANCE = 0.05;

    public function __construct(private readonly PdfTextLayer $pdf) {}

    /** Общая сумма начисленного дохода из итоговой строки. */
    public function income(string $path): DocumentValue
    {
        try {
            $pages = $this->pdf->pages($path);
        } catch (RuntimeException $e) {
            return DocumentValue::unreadable($e->getMessage());
        } catch (Throwable $e) {
            return DocumentValue::unreadable('Не удалось прочитать PDF: ' . $e->getMessage());
        }

        if (empty($pages[1])) {
            // Документ может быть и тем, просто прочитать его нечем: это не «не та форма».
            return DocumentValue::scan('В PDF нет текста, это скан или фото');
        }

        $rows = PdfBlank::rows($pages[1]);

        [$period, $periodRow] = PdfBlank::period($rows);

        if (!$period) {
            return DocumentValue::wrongDocument('Это не форма 161: не нашли строку отчётного периода');
        }

        $totals = $this->totals($rows, $periodRow);

        if (!$totals) {
            return DocumentValue::wrongDocument('Это не форма 161: не нашли итоговую строку');
        }

        [$employees, $income] = $totals;
        [$listed, $listedIncome] = $this->employees(array_slice($pages, 1));

        if ($listed !== $employees || abs($listedIncome - $income) > self::TOLERANCE) {
            return DocumentValue::wrongDocument(sprintf(
                'Форма 161 не сходится: в итоге %d сотрудников и доход %s, а в списке %d сотрудников на %s',
                $employees,
                $this->money($income),
                $listed,
                $this->money($listedIncome),
            ));
        }

        return DocumentValue::found($income, [
            'период'      => $period->label(),
            'сотрудников' => $employees,
            'доход'       => $this->money($income),
            'сходимость'  => 'доход и число сотрудников в итоге = сумма по списку сотрудников',
        ], $period, PdfBlank::inn($rows));
    }

    /**
     * Итоговая строка: первая ниже строки периода, где есть и число сотрудников, и доход.
     *
     * @return array{0: int, 1: float}|null
     */
    private function totals(array $rows, string $periodRow): ?array
    {
        foreach ($rows as $y => $cells) {
            if ((float) $y <= (float) $periodRow) {
                continue;
            }

            $employees = $this->column($cells, self::COLUMN_EMPLOYEES);
            $income    = $this->column($cells, self::COLUMN_INCOME);

            if ($employees !== null && $income !== null) {
                return [(int) $employees, $income];
            }
        }

        return null;
    }

    /**
     * Список сотрудников со второй страницы и дальше: сколько строк и сумма их доходов.
     *
     * Строку сотрудника узнаём по датам периода в ней. Имя и отчество переносятся на
     * соседние строки, но дат там нет, и в счёт они не идут.
     *
     * @param array<int, PdfWord[]> $pages
     * @return array{0: int, 1: float}
     */
    private function employees(array $pages): array
    {
        $count = 0;
        $sum   = 0.0;

        foreach ($pages as $words) {
            foreach (PdfBlank::rows($words) as $cells) {
                if (!array_filter($cells, fn ($cell) => preg_match(self::EMPLOYEE_DATE, trim($cell['text'])) === 1)) {
                    continue;
                }

                $count++;

                foreach ($cells as $cell) {
                    [$from, $to] = self::EMPLOYEE_INCOME_LEFT;

                    if ($cell['left'] >= $from && $cell['left'] < $to && ($number = PdfBlank::number($cell['text'])) !== null) {
                        $sum += $number;

                        break;
                    }
                }
            }
        }

        return [$count, $sum];
    }

    /** Число в колонке итога, которую определяем по середине числа. */
    private function column(array $cells, array $bounds): ?float
    {
        [$from, $to] = $bounds;

        foreach ($cells as $cell) {
            $text   = trim($cell['text']);
            $center = $cell['left'] + mb_strlen($text) * self::HALF_CHAR;

            if ($center < $from || $center >= $to) {
                continue;
            }

            $number = PdfBlank::number($text);

            if ($number !== null) {
                return $number;
            }
        }

        return null;
    }

    private function money(float $value): string
    {
        return number_format($value, 2, ',', ' ');
    }
}
