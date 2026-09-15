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
 * только заполненные клетки. Числа ловим по расположению, а верим им, только если строка
 * сходится сама с собой:
 *
 *   - доход минус необлагаемый доход и вычеты даёт облагаемый доход;
 *   - облагаемый доход × 10% даёт подоходный налог.
 *
 * Разошлось, значит разбор взял не те клетки, и мы отвечаем «документ не тот», а не
 * правдоподобным чужим числом. Проверено на четырёх боевых формах за июль 2026.
 *
 * Числа в клетках итоговой строки стоят по центру: у «1600» и «0» в одной колонке левый
 * край разный, а середина одна. Поэтому колонку определяем по середине числа, и длинная
 * сумма не уезжает в соседнюю колонку.
 */
class Form161Reader
{
    /** Границы колонок итоговой строки по середине числа, в точках PDF. Замеры по четырём формам. */
    private const COLUMN_EMPLOYEES  = [40.0, 88.0];    // число сотрудников
    private const COLUMN_INCOME     = [88.0, 146.0];   // общая сумма начисленного дохода
    private const COLUMN_EXEMPT     = [146.0, 219.0];  // необлагаемый доход
    private const COLUMN_DEDUCTIONS = [219.0, 293.0];  // вычеты
    private const COLUMN_TAXABLE    = [293.0, 365.0];  // облагаемый доход
    private const COLUMN_TAX        = [365.0, 427.0];  // подоходный налог

    /** Половина ширины цифры: середина числа = левый край + длина × столько. */
    private const HALF_CHAR = 1.5;

    private const TAX_RATE = 0.10;

    /** Налог округляют до сома, поэтому сверяем с запасом в сом. */
    private const TOLERANCE = 1.0;

    public function __construct(private readonly PdfTextLayer $pdf) {}

    /** Общая сумма начисленного дохода из итоговой строки. */
    public function income(string $path): DocumentValue
    {
        try {
            $words = $this->pdf->words($path);
        } catch (RuntimeException $e) {
            return DocumentValue::unreadable($e->getMessage());
        } catch (Throwable $e) {
            return DocumentValue::unreadable('Не удалось прочитать PDF: ' . $e->getMessage());
        }

        if (!$words) {
            // Документ может быть и тем, просто прочитать его нечем: это не «не та форма».
            return DocumentValue::scan('В PDF нет текста, это скан или фото');
        }

        $rows = PdfBlank::rows($words);

        [$period, $periodRow] = PdfBlank::period($rows);

        if (!$period) {
            return DocumentValue::wrongDocument('Это не форма 161: не нашли строку отчётного периода');
        }

        foreach ($rows as $y => $cells) {
            // Итоговая строка ниже строки периода: первая, где есть и сотрудники, и доход, и облагаемый доход.
            if ((float) $y <= (float) $periodRow) {
                continue;
            }

            $employees = $this->column($cells, self::COLUMN_EMPLOYEES);
            $income    = $this->column($cells, self::COLUMN_INCOME);
            $taxable   = $this->column($cells, self::COLUMN_TAXABLE);

            if ($employees === null || $income === null || $taxable === null) {
                continue;
            }

            $exempt     = $this->column($cells, self::COLUMN_EXEMPT) ?? 0.0;
            $deductions = $this->column($cells, self::COLUMN_DEDUCTIONS) ?? 0.0;
            $tax        = $this->column($cells, self::COLUMN_TAX);

            if ($tax === null) {
                return DocumentValue::wrongDocument('В итоговой строке формы 161 нет подоходного налога');
            }

            if ($mismatch = $this->checkArithmetic($income, $exempt, $deductions, $taxable, $tax)) {
                return $mismatch;
            }

            return DocumentValue::found($income, [
                'период'      => $period->label(),
                'сотрудников' => (int) $employees,
                'доход'       => $this->money($income),
                'вычеты'      => $this->money($exempt + $deductions),
                'облагаемый'  => $this->money($taxable),
                'налог'       => $this->money($tax),
                'сходимость'  => 'доход минус вычеты = облагаемый, облагаемый × 10% = налог',
            ], $period, PdfBlank::inn($rows));
        }

        return DocumentValue::wrongDocument('Это не форма 161: не нашли итоговую строку');
    }

    private function checkArithmetic(float $income, float $exempt, float $deductions, float $taxable, float $tax): ?DocumentValue
    {
        if (abs($income - $exempt - $deductions - $taxable) > self::TOLERANCE) {
            return DocumentValue::wrongDocument(sprintf(
                'Форма 161 не сходится: доход %s минус необлагаемый %s и вычеты %s не даёт облагаемый доход %s',
                $this->money($income),
                $this->money($exempt),
                $this->money($deductions),
                $this->money($taxable),
            ));
        }

        if (abs($taxable * self::TAX_RATE - $tax) > self::TOLERANCE) {
            return DocumentValue::wrongDocument(sprintf(
                'Форма 161 не сходится: 10%% от облагаемого дохода %s не равно налогу %s',
                $this->money($taxable),
                $this->money($tax),
            ));
        }

        return null;
    }

    /** Число в колонке, которую определяем по середине числа. */
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
