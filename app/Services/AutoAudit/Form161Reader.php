<?php

namespace App\Services\AutoAudit;

use RuntimeException;
use Throwable;

/**
 * Чтение формы 161: отчёта по подоходному налогу и страховым взносам.
 *
 * Из итоговой строки первой страницы берём четыре числа:
 *
 *   - income:        общая сумма начисленного дохода, сверяется с оборотом Кт 3520;
 *   - income_tax:    подоходный налог к уплате, с оборотом Кт 3420;
 *   - contributions: начисленные страховые взносы, с оборотом Кт 3531;
 *   - pension:       начисленные взносы в НПФ, с оборотом Кт 3534.
 *
 * Бланк устроен как отчёт по единому налогу: форма нарисована картинкой, текстом лежат
 * только заполненные клетки. Числа ловим по расположению, а верим им, только если итог
 * сходится с расшифровкой: на второй и следующих страницах список сотрудников, и
 *
 *   - число строк в списке равно числу сотрудников в итоге;
 *   - сумма их доходов равна доходу в итоге. Так же для взносов и НПФ.
 *
 * Не сошлось, значит разбор взял не те клетки, и мы отказываемся отвечать, а не выдаём
 * правдоподобное чужое число.
 *
 * Подоходного налога к уплате в списке сотрудников нет: там только начисленный налог, и у
 * «Мета ком» их сумма 19 815,80 при 19 907,20 к уплате в итоге. Поэтому налог к уплате
 * берём по месту колонки. Итоговую строку при этом всё равно проверяем по доходу и числу
 * сотрудников: съедет бланк, сломается и эта проверка.
 *
 * Формулой налога бланк не проверяем, хотя сначала пробовали. На боевых формах за июль 2026
 * она ломалась: у резидентов ПВТ ставка 5%, а не 10%, а у части работодателей облагаемая
 * база больше дохода (взносы от установленной базы). Суммы по сотрудникам от этого не зависят.
 *
 * Числа в клетках итоговой строки стоят по центру: у «1600» и «0» в одной колонке левый
 * край разный, а середина одна. Поэтому колонку итога определяем по середине числа, и
 * длинная сумма не уезжает в соседнюю колонку. В списке сотрудников числа прижаты влево.
 *
 * Форму разбираем один раз на файл: четыре проверки просят у одной формы четыре числа, и
 * разбор на каждое вместе с разбором ведомостей упёрся на бою в ограничение времени запроса.
 */
class Form161Reader
{
    /** Колонки итоговой строки по середине числа, в точках PDF. Замеры по шести боевым формам. */
    private const TOTAL_COLUMNS = [
        'employees'     => [40.0, 88.0],
        'income'        => [88.0, 146.0],
        'income_tax'    => [580.0, 645.0],
        'contributions' => [645.0, 720.0],
        'pension'       => [720.0, 800.0],
    ];

    /** Те же суммы в списке сотрудников, по левому краю числа. Налога к уплате в списке нет. */
    private const LIST_COLUMNS = [
        'income'        => [392.0, 432.0],
        'contributions' => [705.0, 745.0],
        'pension'       => [745.0, 790.0],
    ];

    /** Как назвать поле в причине отказа. */
    private const LABELS = [
        'income'        => 'доход',
        'income_tax'    => 'подоходный налог к уплате',
        'contributions' => 'страховые взносы',
        'pension'       => 'взносы в НПФ',
    ];

    /** Строка сотрудника в списке узнаётся по датам начала и конца периода: «01.07.2026 00:00:00». */
    private const EMPLOYEE_DATE = '/^\d{2}\.\d{2}\.\d{4}/';

    /** Половина ширины цифры: середина числа = левый край + длина × столько. */
    private const HALF_CHAR = 1.5;

    /** Копейки складываются из разбора текста, float может дать хвост. */
    private const TOLERANCE = 0.05;

    /** Разобранные формы: путь к файлу => разбор или отказ. */
    private array $forms = [];

    public function __construct(private readonly PdfTextLayer $pdf) {}

    /** Общая сумма начисленного дохода из итоговой строки. */
    public function income(string $path): DocumentValue
    {
        return $this->read($path, 'income');
    }

    /**
     * Одно из чисел итоговой строки.
     *
     * @param string $field 'income', 'income_tax', 'contributions' или 'pension'
     */
    public function read(string $path, string $field): DocumentValue
    {
        $form = $this->forms[$path] ??= $this->parse($path);

        if ($form instanceof DocumentValue) {
            return $form;
        }

        ['period' => $period, 'inn' => $inn, 'totals' => $totals, 'sums' => $sums] = $form;

        // Взносы и НПФ есть и в списке: сверяем и их. Налога к уплате в списке нет.
        if ($field !== 'income' && isset($sums[$field]) && abs($sums[$field] - $totals[$field]) > self::TOLERANCE) {
            return DocumentValue::wrongDocument(sprintf(
                'Форма 161 не сходится: %s в итоге %s, а сумма по сотрудникам %s',
                self::LABELS[$field],
                $this->money($totals[$field]),
                $this->money($sums[$field]),
            ));
        }

        return DocumentValue::found($totals[$field], [
            'период'      => $period->label(),
            'сотрудников' => $totals['employees'],
            'поле'        => self::LABELS[$field],
            'значение'    => $this->money($totals[$field]),
            'сходимость'  => isset($sums[$field])
                ? self::LABELS[$field] . ' и число сотрудников в итоге = сумма по списку сотрудников'
                : 'итоговая строка сверена по доходу и числу сотрудников, налог к уплате по месту колонки',
        ], $period, $inn);
    }

    /**
     * Разбор формы целиком: период, ИНН, итоговая строка и суммы по списку сотрудников.
     *
     * Здесь же проверка, общая для всех полей: итоговая строка та, только если доход и число
     * сотрудников сходятся со списком.
     *
     * @return array{period: DocumentPeriod, inn: ?string, totals: array, sums: array}|DocumentValue
     */
    private function parse(string $path): array|DocumentValue
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

        [$listed, $sums] = $this->employees(array_slice($pages, 1));

        if ($listed !== $totals['employees'] || abs($sums['income'] - $totals['income']) > self::TOLERANCE) {
            return DocumentValue::wrongDocument(sprintf(
                'Форма 161 не сходится: в итоге %d сотрудников и доход %s, а в списке %d сотрудников на %s',
                $totals['employees'],
                $this->money($totals['income']),
                $listed,
                $this->money($sums['income']),
            ));
        }

        return ['period' => $period, 'inn' => PdfBlank::inn($rows), 'totals' => $totals, 'sums' => $sums];
    }

    /**
     * Итоговая строка: первая ниже строки периода, где есть и число сотрудников, и доход.
     * Пустая клетка налога или взносов означает ноль.
     *
     * @return array{employees: int, income: float, income_tax: float, contributions: float, pension: float}|null
     */
    private function totals(array $rows, string $periodRow): ?array
    {
        foreach ($rows as $y => $cells) {
            if ((float) $y <= (float) $periodRow) {
                continue;
            }

            $employees = $this->centered($cells, self::TOTAL_COLUMNS['employees']);
            $income    = $this->centered($cells, self::TOTAL_COLUMNS['income']);

            if ($employees === null || $income === null) {
                continue;
            }

            return [
                'employees'     => (int) $employees,
                'income'        => $income,
                'income_tax'    => $this->centered($cells, self::TOTAL_COLUMNS['income_tax']) ?? 0.0,
                'contributions' => $this->centered($cells, self::TOTAL_COLUMNS['contributions']) ?? 0.0,
                'pension'       => $this->centered($cells, self::TOTAL_COLUMNS['pension']) ?? 0.0,
            ];
        }

        return null;
    }

    /**
     * Список сотрудников со второй страницы и дальше: сколько строк и суммы по колонкам.
     *
     * Строку сотрудника узнаём по датам периода в ней. Имя и отчество переносятся на
     * соседние строки, но дат там нет, и в счёт они не идут.
     *
     * @param array<int, PdfWord[]> $pages
     * @return array{0: int, 1: array<string, float>}
     */
    private function employees(array $pages): array
    {
        $count = 0;
        $sums  = array_fill_keys(array_keys(self::LIST_COLUMNS), 0.0);

        foreach ($pages as $words) {
            foreach (PdfBlank::rows($words) as $cells) {
                if (!array_filter($cells, fn ($cell) => preg_match(self::EMPLOYEE_DATE, trim($cell['text'])) === 1)) {
                    continue;
                }

                $count++;

                foreach (self::LIST_COLUMNS as $column => [$from, $to]) {
                    foreach ($cells as $cell) {
                        if ($cell['left'] >= $from && $cell['left'] < $to && ($number = PdfBlank::number($cell['text'])) !== null) {
                            $sums[$column] += $number;

                            break;
                        }
                    }
                }
            }
        }

        return [$count, $sums];
    }

    /** Число в колонке итога, которую определяем по середине числа. */
    private function centered(array $cells, array $bounds): ?float
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
