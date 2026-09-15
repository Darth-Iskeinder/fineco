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
 * список сотрудников с числами, прижатыми влево.
 */
class Form161ReaderTest extends TestCase
{
    private const TOTALS_TOP = -341.0;

    /** Середины колонок итоговой строки в боевых формах. */
    private const CENTER = [
        'employees'     => 65.8,
        'income'        => 110.6,
        'income_tax'    => 612.3,
        'contributions' => 683.0,
        'pension'       => 761.3,
    ];

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

    /**
     * Первая страница: шапка и итоговая строка. Между нужными колонками стоят чужие числа
     * из боевой формы Темирбаевой: читалка не должна их брать.
     */
    private function firstPage(
        string $employees,
        string $income,
        string $incomeTax = '0',
        string $contributions = '0',
        string $pension = '0',
        string $inn = '02101202510267',
    ): array {
        $innLefts    = array_map(fn ($i) => 133.2 + $i * 14.2, range(0, 13));
        $periodLefts = [259.8, 270.0, 292.4, 302.6, 323.3, 337.4, 351.6, 365.8, 433.3, 443.5, 462.7, 472.9, 491.9, 506.1, 520.3, 534.5];

        $totals = [];

        foreach (compact('employees', 'income', 'incomeTax', 'contributions', 'pension') as $name => $text) {
            $column   = ['incomeTax' => 'income_tax'][$name] ?? $name;
            $totals[] = new PdfWord(self::CENTER[$column] - mb_strlen($text) * 1.5, self::TOTALS_TOP, $text);
        }

        return array_merge(
            [new PdfWord(517.8, -491.5, 'Общество с ограниченной ответственностью "Нова Трек"')],
            $this->digits($inn, -491.1, $innLefts),
            $this->digits('0107202631072026', -422.0, $periodLefts),
            $totals,
            [
                new PdfWord(172.5, self::TOTALS_TOP, '28829'),
                new PdfWord(321.5, self::TOTALS_TOP, '308518'),
                new PdfWord(390.3, self::TOTALS_TOP, '2644,44'),
                new PdfWord(498.6, self::TOTALS_TOP, '19815,8'),
                new PdfWord(544.8, self::TOTALS_TOP, '390779'),
            ],
        );
    }

    /** Сотрудник в списке: доход, взносы, НПФ. */
    private function person(string $income, string $contributions = '0', string $pension = '0'): array
    {
        return compact('income', 'contributions', 'pension');
    }

    /** Список сотрудников: строка на человека, имя переносится на соседние строки без дат. */
    private function employeePage(array $people, int $firstIndex = 1): array
    {
        $words = [];
        $top   = -469.0;

        foreach ($people as $i => $person) {
            $words[] = new PdfWord(137.7, $top - 5.5, 'Фамилия Имя');
            $words[] = new PdfWord(40.9, $top, (string) ($firstIndex + $i));
            $words[] = new PdfWord(54.0, $top, '22801199200081');
            $words[] = new PdfWord(293.9, $top, '01.07.2026 00:00:00');
            $words[] = new PdfWord(325.4, $top, '31.07.2026 00:00:00');
            $words[] = new PdfWord(360.6, $top, '23');
            $words[] = new PdfWord(383.8, $top, '001');
            $words[] = new PdfWord(404.0, $top, $person['income']);
            $words[] = new PdfWord(443.4, $top, '17629,6');
            $words[] = new PdfWord(558.2, $top, '44074');
            $words[] = new PdfWord(597.9, $top, '2250');
            $words[] = new PdfWord(684.0, $top, '2250');
            $words[] = new PdfWord(722.6, $top, $person['contributions']);
            $words[] = new PdfWord(762.3, $top, $person['pension']);
            $words[] = new PdfWord(137.7, $top + 6.0, 'Отчество');
            $top += 20.0;
        }

        // Подвал страницы: цифры даты сдачи, к списку не относятся.
        return array_merge($words, $this->digits('04082026', -90.6, [628, 643, 663, 677, 696, 711, 727, 743]));
    }

    /** Как у «Мета ком»: шесть сотрудников. */
    private function metaCom(): array
    {
        return [
            1 => $this->firstPage('6', '214400', '19907,2', '21976,00', '4288'),
            2 => $this->employeePage([
                $this->person('25000', '2562,5', '500'),
                $this->person('45000', '4612,5', '900'),
                $this->person('50000', '5125', '1000'),
                $this->person('45000', '4612,5', '900'),
                $this->person('24700', '2531,75', '494'),
                $this->person('24700', '2531,75', '494'),
            ]),
        ];
    }

    public function test_reads_total_income_period_and_inn(): void
    {
        $result = $this->reader([1 => $this->firstPage('1', '16000'), 2 => $this->employeePage([$this->person('16000')])])
            ->income('форма.pdf');

        $this->assertTrue($result->isFound(), $result->reason ?? '');
        $this->assertSame(16000.0, $result->value);
        $this->assertSame('01.07.2026 – 31.07.2026', $result->period->label());
        $this->assertSame('02101202510267', $result->inn);
    }

    public function test_reads_all_four_fields(): void
    {
        $reader = $this->reader($this->metaCom());

        $this->assertSame(214400.0, $reader->read('форма.pdf', 'income')->value);
        $this->assertSame(19907.2, $reader->read('форма.pdf', 'income_tax')->value);
        $this->assertSame(21976.0, $reader->read('форма.pdf', 'contributions')->value);
        $this->assertSame(4288.0, $reader->read('форма.pdf', 'pension')->value);
    }

    /**
     * Налога к уплате в списке нет, и сумма начисленного налога по сотрудникам (19 815,80)
     * с ним не совпадает. Это не повод отказывать: налог берём по месту колонки.
     */
    public function test_income_tax_is_taken_without_the_list(): void
    {
        $result = $this->reader($this->metaCom())->read('форма.pdf', 'income_tax');

        $this->assertTrue($result->isFound(), $result->reason ?? '');
        $this->assertStringContainsString('по месту колонки', $result->trace['сходимость']);
    }

    /**
     * Как у Темирбаевой: облагаемая база в итоге больше дохода, у одного сотрудника нет НПФ.
     * Доход, взносы и НПФ сходятся со списком, и форму надо прочитать.
     */
    public function test_reads_form_where_tax_columns_do_not_follow_income(): void
    {
        $people = array_merge(
            array_map(fn ($income) => $this->person($income, '1762,96', '352,59'), ['35000', '35000', '40000', '30000', '30000', '40000']),
            [$this->person('28829', '1729,74', '0')],
        );

        $reader = $this->reader([1 => $this->firstPage('7', '238829', '2644,44', '12307,50', '2115,54'), 2 => $this->employeePage($people)]);

        $this->assertSame(238829.0, $reader->income('форма.pdf')->value);
        $this->assertSame(12307.5, $reader->read('форма.pdf', 'contributions')->value);
        $this->assertSame(2115.54, $reader->read('форма.pdf', 'pension')->value);
    }

    /** Большой штат не влезает на одну страницу: список продолжается на следующей. */
    public function test_employees_on_several_pages_are_summed(): void
    {
        $result = $this->reader([
            1 => $this->firstPage('3', '75000', '6750', '7687,5', '1500'),
            2 => $this->employeePage([$this->person('25000', '2562,5', '500'), $this->person('25000', '2562,5', '500')]),
            3 => $this->employeePage([$this->person('25000', '2562,5', '500')], firstIndex: 3),
        ]);

        $this->assertSame(75000.0, $result->income('форма.pdf')->value);
        $this->assertSame(7687.5, $result->read('форма.pdf', 'contributions')->value);
    }

    /** Длинная сумма растёт в обе стороны от середины клетки и в соседнюю колонку не уезжает. */
    public function test_long_numbers_stay_in_their_columns(): void
    {
        $reader = $this->reader([
            1 => $this->firstPage('2', '12500000', '1125000', '1281250,00', '250000'),
            2 => $this->employeePage([$this->person('6250000', '640625', '125000'), $this->person('6250000', '640625', '125000')]),
        ]);

        $this->assertSame(12500000.0, $reader->income('форма.pdf')->value);
        $this->assertSame(1125000.0, $reader->read('форма.pdf', 'income_tax')->value);
        $this->assertSame(1281250.0, $reader->read('форма.pdf', 'contributions')->value);
        $this->assertSame(250000.0, $reader->read('форма.pdf', 'pension')->value);
    }

    /** Зарплаты за месяц не было: в итоге нули, список пуст. */
    public function test_reads_zero_form(): void
    {
        $reader = $this->reader([1 => $this->firstPage('0', '0'), 2 => []]);

        $this->assertSame(0.0, $reader->income('форма.pdf')->value);
        $this->assertSame(0.0, $reader->read('форма.pdf', 'pension')->value);
    }

    /** Главная защита: итог обязан сходиться со списком, иначе разбор взял не те клетки. */
    public function test_rejects_form_where_income_does_not_match_the_list(): void
    {
        $result = $this->reader([1 => $this->firstPage('2', '50000'), 2 => $this->employeePage([$this->person('25000'), $this->person('20000')])])
            ->income('форма.pdf');

        $this->assertSame(DocumentValue::WRONG_DOC, $result->status);
        $this->assertStringContainsString('не сходится', $result->reason);
    }

    public function test_rejects_form_where_employee_count_does_not_match_the_list(): void
    {
        $result = $this->reader([1 => $this->firstPage('3', '50000'), 2 => $this->employeePage([$this->person('25000'), $this->person('25000')])])
            ->income('форма.pdf');

        $this->assertSame(DocumentValue::WRONG_DOC, $result->status);
        $this->assertStringContainsString('3 сотрудников', $result->reason);
    }

    /** Взносы не сходятся со списком: отказываем только в взносах, доход по-прежнему читается. */
    public function test_contributions_mismatch_does_not_break_income(): void
    {
        $reader = $this->reader([
            1 => $this->firstPage('1', '25000', '2500', '5000', '500'),
            2 => $this->employeePage([$this->person('25000', '4000', '500')]),
        ]);

        $contributions = $reader->read('форма.pdf', 'contributions');

        $this->assertSame(DocumentValue::WRONG_DOC, $contributions->status);
        $this->assertStringContainsString('страховые взносы', $contributions->reason);
        $this->assertSame(25000.0, $reader->income('форма.pdf')->value);
        $this->assertSame(500.0, $reader->read('форма.pdf', 'pension')->value);
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

    /** Четыре проверки просят у одной формы четыре числа: файл разбираем один раз. */
    public function test_form_is_parsed_once_for_all_fields(): void
    {
        $layer = new class($this->metaCom()) extends PdfTextLayer {
            public int $calls = 0;

            public function __construct(private array $pages) {}

            public function pages(string $path): array
            {
                $this->calls++;

                return $this->pages;
            }
        };

        $reader = new Form161Reader($layer);

        foreach (['income', 'income_tax', 'contributions', 'pension'] as $field) {
            $this->assertTrue($reader->read('форма.pdf', $field)->isFound());
        }

        $this->assertSame(1, $layer->calls);
    }
}
