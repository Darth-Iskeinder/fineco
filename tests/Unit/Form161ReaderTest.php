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
 * по одной цифре в клетке, итоговая строка с числами по центру клеток.
 */
class Form161ReaderTest extends TestCase
{
    private const TOTALS_TOP = -341.0;

    /** Середины колонок итоговой строки в боевых формах. */
    private const CENTER = [
        'employees'  => 65.8,
        'income'     => 110.6,
        'exempt'     => 181.5,
        'deductions' => 256.4,
        'taxable'    => 330.8,
        'tax'        => 400.9,
    ];

    private function reader(array $words): Form161Reader
    {
        $layer = new class($words) extends PdfTextLayer {
            public function __construct(private array $words) {}

            public function words(string $path, int $page = 1): array
            {
                return $this->words;
            }
        };

        return new Form161Reader($layer);
    }

    /** Цифры по одной в своей клетке. */
    private function digits(string $digits, float $top, array $lefts): array
    {
        return array_map(fn ($digit, $left) => new PdfWord($left, $top, $digit), str_split($digits), $lefts);
    }

    private function header(string $inn = '02101202510267', string $from = '01072026', string $to = '31072026'): array
    {
        $innLefts    = array_map(fn ($i) => 133.2 + $i * 14.2, range(0, 13));
        $periodLefts = [259.8, 270.0, 292.4, 302.6, 323.3, 337.4, 351.6, 365.8, 433.3, 443.5, 462.7, 472.9, 491.9, 506.1, 520.3, 534.5];

        return array_merge(
            [new PdfWord(517.8, -491.5, 'Общество с ограниченной ответственностью "Нова Трек"')],
            $this->digits($inn, -491.1, $innLefts),
            $this->digits($from . $to, -422.0, $periodLefts),
        );
    }

    /** Итоговая строка: каждое число ставим так, чтобы его середина пришлась на свою колонку. */
    private function totals(string $employees, string $income, string $exempt, string $deductions, string $taxable, string $tax): array
    {
        $words = [];

        foreach (compact('employees', 'income', 'exempt', 'deductions', 'taxable', 'tax') as $column => $text) {
            $words[] = new PdfWord(self::CENTER[$column] - mb_strlen($text) * 1.5, self::TOTALS_TOP, $text);
        }

        // Правее идут колонки взносов: читалке они не нужны, но и мешать не должны.
        $words[] = new PdfWord(452.7, self::TOTALS_TOP, '0');
        $words[] = new PdfWord(677.3, self::TOTALS_TOP, '1640');
        $words[] = new PdfWord(757.1, self::TOTALS_TOP, '320');

        return $words;
    }

    public function test_reads_total_income_period_and_inn(): void
    {
        $result = $this->reader(array_merge($this->header(), $this->totals('1', '16000', '0', '1600', '14400', '1440')))
            ->income('форма.pdf');

        $this->assertTrue($result->isFound(), $result->reason ?? '');
        $this->assertSame(16000.0, $result->value);
        $this->assertSame('01.07.2026 – 31.07.2026', $result->period->label());
        $this->assertSame('02101202510267', $result->inn);
    }

    public function test_reads_form_with_several_employees(): void
    {
        $result = $this->reader(array_merge($this->header(), $this->totals('2', '50000', '0', '6300', '43700', '4370')))
            ->income('форма.pdf');

        $this->assertSame(50000.0, $result->value);
    }

    /** Длинная сумма растёт в обе стороны от середины клетки и в соседнюю колонку не уезжает. */
    public function test_long_income_stays_in_its_column(): void
    {
        $result = $this->reader(array_merge($this->header(), $this->totals('120', '12500000', '0', '0', '12500000', '1250000')))
            ->income('форма.pdf');

        $this->assertTrue($result->isFound(), $result->reason ?? '');
        $this->assertSame(12500000.0, $result->value);
    }

    public function test_reads_decimal_comma(): void
    {
        $result = $this->reader(array_merge($this->header(), $this->totals('1', '25004,5', '0', '0', '25004,5', '2500,45')))
            ->income('форма.pdf');

        $this->assertSame(25004.5, $result->value);
    }

    /** Главная защита: строка обязана сходиться сама с собой, иначе разбор взял не те клетки. */
    public function test_rejects_form_where_taxable_income_does_not_add_up(): void
    {
        $result = $this->reader(array_merge($this->header(), $this->totals('1', '16000', '0', '1600', '15000', '1500')))
            ->income('форма.pdf');

        $this->assertSame(DocumentValue::WRONG_DOC, $result->status);
        $this->assertStringContainsString('не сходится', $result->reason);
    }

    public function test_rejects_form_where_tax_is_not_ten_percent(): void
    {
        $result = $this->reader(array_merge($this->header(), $this->totals('1', '16000', '0', '1600', '14400', '2000')))
            ->income('форма.pdf');

        $this->assertSame(DocumentValue::WRONG_DOC, $result->status);
        $this->assertStringContainsString('10%', $result->reason);
    }

    public function test_pdf_without_text_is_a_scan(): void
    {
        $this->assertSame(DocumentValue::SCAN, $this->reader([])->income('скан.pdf')->status);
    }

    /** Отчёт по единому налогу похож шапкой, но итоговой строки формы 161 в нём нет. */
    public function test_tax_report_is_not_a_form_161(): void
    {
        $words = array_merge($this->header(), [
            new PdfWord(375.0, -300.0, '23 000,00'),
            new PdfWord(465.0, -300.0, '6,00'),
            new PdfWord(540.0, -300.0, '1 380,00'),
        ]);

        $result = $this->reader($words)->income('отчёт.pdf');

        $this->assertSame(DocumentValue::WRONG_DOC, $result->status);
        $this->assertStringContainsString('не нашли итоговую строку', $result->reason);
    }

    public function test_form_without_period_row_is_rejected(): void
    {
        $words = array_merge(
            [new PdfWord(517.8, -491.5, 'Счёт на оплату № 12')],
            $this->totals('1', '16000', '0', '1600', '14400', '1440'),
        );

        $result = $this->reader($words)->income('счёт.pdf');

        $this->assertSame(DocumentValue::WRONG_DOC, $result->status);
    }
}
