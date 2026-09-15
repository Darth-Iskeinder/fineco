<?php

namespace Tests\Unit;

use App\Services\AutoAudit\DocumentValue;
use App\Services\AutoAudit\Form161Reader;
use App\Services\AutoAudit\PdfTextLayer;
use App\Services\AutoAudit\PdfWord;
use PHPUnit\Framework\TestCase;

/**
 * Разбор формы 161.
 *
 * Настоящие формы в репозиторий не кладём: в них доходы живых сотрудников. Текстовый слой
 * собираем руками, повторяя расположение из боевых форм за июль 2026: ИНН и период в шапке
 * по одной цифре в клетке, итоговая строка с числами по центру клеток, на второй странице
 * список сотрудников.
 */
class Form161ReaderTest extends TestCase
{
    private const TOTALS_TOP = -341.0;

    /** Середины колонок итоговой строки в боевых формах. */
    private const CENTER_EMPLOYEES = 65.8;
    private const CENTER_INCOME    = 110.6;

    /** @param array<int, PdfWord[]> $pages */
    private function reader(array $pages): Form161Reader
    {
        $layer = new class($pages) extends PdfTextLayer {
            public function __construct(private array $pages) {}

            public function pages(string $path): array
            {
                return $this->pages;
            }
        };

        return new Form161Reader($layer);
    }

    /** Цифры по одной в своей клетке. */
    private function digits(string $digits, float $top, array $lefts): array
    {
        return array_map(fn ($digit, $left) => new PdfWord($left, $top, $digit), str_split($digits), $lefts);
    }

    /** Первая страница: шапка и итоговая строка. Правее дохода колонки взносов, читалке они не нужны. */
    private function firstPage(string $employees, string $income, string $inn = '02101202510267'): array
    {
        $innLefts    = array_map(fn ($i) => 133.2 + $i * 14.2, range(0, 13));
        $periodLefts = [259.8, 270.0, 292.4, 302.6, 323.3, 337.4, 351.6, 365.8, 433.3, 443.5, 462.7, 472.9, 491.9, 506.1, 520.3, 534.5];

        return array_merge(
            [new PdfWord(517.8, -491.5, 'Общество с ограниченной ответственностью "Нова Трек"')],
            $this->digits($inn, -491.1, $innLefts),
            $this->digits('0107202631072026', -422.0, $periodLefts),
            [
                new PdfWord(self::CENTER_EMPLOYEES - mb_strlen($employees) * 1.5, self::TOTALS_TOP, $employees),
                new PdfWord(self::CENTER_INCOME - mb_strlen($income) * 1.5, self::TOTALS_TOP, $income),
                new PdfWord(172.5, self::TOTALS_TOP, '28829'),
                new PdfWord(321.5, self::TOTALS_TOP, '308518'),
                new PdfWord(390.3, self::TOTALS_TOP, '2644,44'),
                new PdfWord(670.7, self::TOTALS_TOP, '12307,50'),
            ],
        );
    }

    /** Список сотрудников: строка на человека, имя переносится на соседнюю строку без дат. */
    private function employeePage(array $incomes, int $firstIndex = 1): array
    {
        $words = [];
        $top   = -469.0;

        foreach ($incomes as $i => $income) {
            $words[] = new PdfWord(137.7, $top - 5.5, 'Фамилия Имя');
            $words[] = new PdfWord(40.9, $top, (string) ($firstIndex + $i));
            $words[] = new PdfWord(54.0, $top, '22801199200081');
            $words[] = new PdfWord(293.9, $top, '01.07.2026 00:00:00');
            $words[] = new PdfWord(325.4, $top, '31.07.2026 00:00:00');
            $words[] = new PdfWord(360.6, $top, '23');
            $words[] = new PdfWord(383.8, $top, '001');
            $words[] = new PdfWord(404.0, $top, $income);
            $words[] = new PdfWord(443.4, $top, '17629,6');
            $words[] = new PdfWord(558.2, $top, '44074');
            $words[] = new PdfWord(137.7, $top + 6.0, 'Отчество');
            $top += 20.0;
        }

        // Подвал страницы: цифры даты сдачи, к списку не относятся.
        return array_merge($words, $this->digits('04082026', -90.6, [628, 643, 663, 677, 696, 711, 727, 743]));
    }

    public function test_reads_total_income_period_and_inn(): void
    {
        $result = $this->reader([1 => $this->firstPage('1', '16000'), 2 => $this->employeePage(['16000'])])
            ->income('форма.pdf');

        $this->assertTrue($result->isFound(), $result->reason ?? '');
        $this->assertSame(16000.0, $result->value);
        $this->assertSame('01.07.2026 – 31.07.2026', $result->period->label());
        $this->assertSame('02101202510267', $result->inn);
    }

    /** Как у «Мета ком»: шесть сотрудников, итог равен сумме по списку. */
    public function test_reads_form_with_several_employees(): void
    {
        $incomes = ['25000', '45000', '50000', '45000', '24700', '24700'];

        $result = $this->reader([1 => $this->firstPage('6', '214400'), 2 => $this->employeePage($incomes)])
            ->income('форма.pdf');

        $this->assertTrue($result->isFound(), $result->reason ?? '');
        $this->assertSame(214400.0, $result->value);
    }

    /**
     * Как у Темирбаевой: облагаемая база в итоге больше дохода, а у резидентов ПВТ налог 5%.
     * На доход это не влияет: он сходится со списком, и форму надо прочитать.
     */
    public function test_reads_form_where_tax_columns_do_not_follow_income(): void
    {
        $incomes = ['35000', '35000', '40000', '30000', '30000', '40000', '28829'];

        $result = $this->reader([1 => $this->firstPage('7', '238829'), 2 => $this->employeePage($incomes)])
            ->income('форма.pdf');

        $this->assertTrue($result->isFound(), $result->reason ?? '');
        $this->assertSame(238829.0, $result->value);
    }

    /** Большой штат не влезает на одну страницу: список продолжается на следующей. */
    public function test_employees_on_several_pages_are_summed(): void
    {
        $result = $this->reader([
            1 => $this->firstPage('3', '75000'),
            2 => $this->employeePage(['25000', '25000']),
            3 => $this->employeePage(['25000'], firstIndex: 3),
        ])->income('форма.pdf');

        $this->assertTrue($result->isFound(), $result->reason ?? '');
        $this->assertSame(75000.0, $result->value);
    }

    /** Длинная сумма растёт в обе стороны от середины клетки и в соседнюю колонку не уезжает. */
    public function test_long_income_stays_in_its_column(): void
    {
        $result = $this->reader([1 => $this->firstPage('2', '12500000'), 2 => $this->employeePage(['6250000', '6250000'])])
            ->income('форма.pdf');

        $this->assertTrue($result->isFound(), $result->reason ?? '');
        $this->assertSame(12500000.0, $result->value);
    }

    public function test_reads_decimal_comma(): void
    {
        $result = $this->reader([1 => $this->firstPage('1', '25004,5'), 2 => $this->employeePage(['25004,5'])])
            ->income('форма.pdf');

        $this->assertSame(25004.5, $result->value);
    }

    /** Зарплаты за месяц не было: в итоге нули, список пуст. */
    public function test_reads_zero_form(): void
    {
        $result = $this->reader([1 => $this->firstPage('0', '0'), 2 => []])->income('форма.pdf');

        $this->assertTrue($result->isFound(), $result->reason ?? '');
        $this->assertSame(0.0, $result->value);
    }

    /** Главная защита: итог обязан сходиться со списком, иначе разбор взял не те клетки. */
    public function test_rejects_form_where_income_does_not_match_the_list(): void
    {
        $result = $this->reader([1 => $this->firstPage('2', '50000'), 2 => $this->employeePage(['25000', '20000'])])
            ->income('форма.pdf');

        $this->assertSame(DocumentValue::WRONG_DOC, $result->status);
        $this->assertStringContainsString('не сходится', $result->reason);
    }

    public function test_rejects_form_where_employee_count_does_not_match_the_list(): void
    {
        $result = $this->reader([1 => $this->firstPage('3', '50000'), 2 => $this->employeePage(['25000', '25000'])])
            ->income('форма.pdf');

        $this->assertSame(DocumentValue::WRONG_DOC, $result->status);
        $this->assertStringContainsString('3 сотрудников', $result->reason);
    }

    public function test_pdf_without_text_is_a_scan(): void
    {
        $this->assertSame(DocumentValue::SCAN, $this->reader([1 => []])->income('скан.pdf')->status);
        $this->assertSame(DocumentValue::SCAN, $this->reader([])->income('скан.pdf')->status);
    }

    /** Отчёт по единому налогу похож шапкой, но итоговой строки формы 161 в нём нет. */
    public function test_tax_report_is_not_a_form_161(): void
    {
        $words = array_merge(array_slice($this->firstPage('1', '1'), 0, 31), [
            new PdfWord(375.0, -300.0, '23 000,00'),
            new PdfWord(465.0, -300.0, '6,00'),
            new PdfWord(540.0, -300.0, '1 380,00'),
        ]);

        $result = $this->reader([1 => $words])->income('отчёт.pdf');

        $this->assertSame(DocumentValue::WRONG_DOC, $result->status);
        $this->assertStringContainsString('не нашли итоговую строку', $result->reason);
    }

    public function test_form_without_period_row_is_rejected(): void
    {
        $words = [new PdfWord(517.8, -491.5, 'Счёт на оплату № 12'), new PdfWord(103.1, self::TOTALS_TOP, '16000')];

        $this->assertSame(DocumentValue::WRONG_DOC, $this->reader([1 => $words])->income('счёт.pdf')->status);
    }
}
