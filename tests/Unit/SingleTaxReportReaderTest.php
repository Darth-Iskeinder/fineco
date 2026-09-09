<?php

namespace Tests\Unit;

use App\Services\AutoAudit\DocumentValue;
use App\Services\AutoAudit\PdfTextLayer;
use App\Services\AutoAudit\PdfWord;
use App\Services\AutoAudit\SingleTaxReportReader;
use PHPUnit\Framework\TestCase;

/**
 * Разбор отчёта по единому налогу.
 *
 * Настоящие бланки в репозиторий не кладём — там обороты живых клиентов, — да и не нужны:
 * читалка получает текстовый слой снаружи, и его проще собрать руками. Заодно так можно
 * подстроить бланк под каждый случай, чего с готовыми файлами не сделаешь.
 *
 * Числа воспроизведены так, как их отдаёт разборщик: целым куском вместе с пробелами
 * между разрядами, «1 465 310,00». Цифры отчётного периода — наоборот, по одной в своей
 * клетке бланка, шестнадцатью отдельными кусками.
 */
class SingleTaxReportReaderTest extends TestCase
{
    /** Левые края колонок в настоящем бланке — по ним читалка и опознаёт числа. */
    private const X_BASE = 375.0;
    private const X_RATE = 465.0;
    private const X_TAX  = 540.0;

    private function reader(array $words): SingleTaxReportReader
    {
        $layer = new class($words) extends PdfTextLayer {
            public function __construct(private array $words) {}

            public function words(string $path, int $page = 1): array
            {
                return $this->words;
            }
        };

        return new SingleTaxReportReader($layer);
    }

    /**
     * Число целым куском, как его отдаёт разборщик: с пробелами-разрядами внутри.
     *
     * @return PdfWord[]
     */
    private function number(float $left, float $top, string $value): array
    {
        return [new PdfWord($left, $top, $value)];
    }

    /** Строка периода: шестнадцать цифр по одной в своей клетке. */
    private function periodRow(string $from, string $to, float $top = 151.0): array
    {
        $words = [];
        $x     = 187.0;

        foreach (str_split($from . $to) as $i => $digit) {
            // Между датами бланк оставляет разрыв — цифры второй начинаются правее.
            $left    = $x + ($i >= 8 ? 45.0 : 0.0);
            $words[] = new PdfWord($left, $top, $digit);
            $x      += 15.0;
        }

        return $words;
    }

    private function line(float $top, string $base, string $rate, string $tax): array
    {
        return array_merge(
            $this->number(self::X_BASE, $top, $base),
            $this->number(self::X_RATE, $top, $rate),
            $this->number(self::X_TAX, $top, $tax),
        );
    }

    /** Авансовая строка (поля 182 и 184): база и налог без ставки. */
    private function advance(float $top, string $base, string $tax): array
    {
        return $this->totals($top, $base, $tax);
    }

    /** Итоговая строка: база и налог есть, ставки нет — как и у авансовых, но она последняя. */
    private function totals(float $top, string $base, string $tax): array
    {
        return array_merge(
            $this->number(self::X_BASE, $top, $base),
            $this->number(self::X_TAX, $top, $tax),
        );
    }

    /** Бланк целиком: период, две строки по разным ставкам и итог. */
    private function report(): array
    {
        return array_merge(
            $this->periodRow('01072026', '31072026'),
            $this->line(300, '23 000,00', '6,00', '1 380,00'),
            $this->line(340, '1 442 310,00', '4,00', '57 692,40'),
            $this->totals(752, '1 465 310,00', '59 072,40'),
        );
    }

    public function test_reads_total_taxable_base(): void
    {
        $result = $this->reader($this->report())->taxableBase('отчёт.pdf');

        $this->assertTrue($result->isFound(), $result->reason ?? '');
        $this->assertSame(1465310.00, $result->value);
    }

    /** Период читается из самого документа: месяц задачи ему не равен. */
    public function test_reads_period_from_the_document(): void
    {
        $result = $this->reader($this->report())->taxableBase('отчёт.pdf');

        $this->assertSame('01.07.2026 – 31.07.2026', $result->period->label());
    }

    /** Квартальный отчёт — обычное дело: часть клиентов сдаёт раз в три месяца. */
    public function test_reads_quarterly_period(): void
    {
        $words = array_merge(
            $this->periodRow('01042026', '30062026'),
            $this->line(300, '7 080 196,97', '2,00', '141 603,94'),
            $this->totals(752, '7 080 196,97', '141 603,94'),
        );

        $result = $this->reader($words)->taxableBase('отчёт.pdf');

        $this->assertTrue($result->isFound(), $result->reason ?? '');
        $this->assertSame('01.04.2026 – 30.06.2026', $result->period->label());
        $this->assertCount(3, $result->period->months());
    }

    /**
     * Главная защита: бланк обязан сходиться сам с собой.
     *
     * Подписей полей в тексте нет, читалка идёт по расположению — а оно съедет, когда
     * сменится редакция приказа. Тогда числа останутся правдоподобными, и заметить
     * подмену можно только по арифметике.
     */
    public function test_rejects_report_where_total_does_not_match_the_lines(): void
    {
        $words = array_merge(
            $this->periodRow('01072026', '31072026'),
            $this->line(300, '23 000,00', '6,00', '1 380,00'),
            $this->totals(752, '999 999,00', '1 380,00'),
        );

        $result = $this->reader($words)->taxableBase('отчёт.pdf');

        $this->assertSame(DocumentValue::WRONG_DOC, $result->status);
        $this->assertStringContainsString('не сходится', $result->reason);
    }

    /** Вторая проверка бланка: база, умноженная на ставку, обязана дать сумму налога. */
    public function test_rejects_line_where_rate_does_not_match_the_tax(): void
    {
        $words = array_merge(
            $this->periodRow('01072026', '31072026'),
            $this->line(300, '100 000,00', '4,00', '9 999,00'),
            $this->totals(752, '100 000,00', '9 999,00'),
        );

        $result = $this->reader($words)->taxableBase('отчёт.pdf');

        $this->assertSame(DocumentValue::WRONG_DOC, $result->status);
        $this->assertStringContainsString('ставке', $result->reason);
    }

    /** Копейки от округления ставки не считаем расхождением. */
    public function test_tolerates_rounding_of_a_kopek(): void
    {
        $words = array_merge(
            $this->periodRow('01072026', '31072026'),
            // 87 513,60 × 4% = 3 500,544, в бланке напечатано 3 500,54
            $this->line(300, '87 513,60', '4,00', '3 500,54'),
            $this->totals(752, '87 513,60', '3 500,54'),
        );

        $this->assertTrue($this->reader($words)->taxableBase('отчёт.pdf')->isFound());
    }

    /** Приложили другой документ: без строки периода это не наш бланк. */
    public function test_rejects_document_without_period_row(): void
    {
        $words = array_merge(
            $this->line(300, '23 000,00', '6,00', '1 380,00'),
            $this->totals(752, '23 000,00', '1 380,00'),
        );

        $result = $this->reader($words)->taxableBase('счёт.pdf');

        $this->assertSame(DocumentValue::WRONG_DOC, $result->status);
        $this->assertStringContainsString('отчётного периода', $result->reason);
    }

    /** Скан вместо выгрузки: текстового слоя нет, читать нечего. */
    public function test_rejects_pdf_without_text(): void
    {
        $result = $this->reader([])->taxableBase('скан.pdf');

        $this->assertSame(DocumentValue::WRONG_DOC, $result->status);
        $this->assertStringContainsString('скан', $result->reason);
    }

    /** Бланк со строкой периода, но без итога — разбор съехал, отвечать числом нельзя. */
    public function test_rejects_report_without_totals_line(): void
    {
        $words = array_merge(
            $this->periodRow('01072026', '31072026'),
            $this->line(300, '23 000,00', '6,00', '1 380,00'),
        );

        $result = $this->reader($words)->taxableBase('отчёт.pdf');

        $this->assertSame(DocumentValue::WRONG_DOC, $result->status);
        $this->assertStringContainsString('итоговую строку', $result->reason);
    }

    /**
     * Мусор из шестнадцати цифр не должен притвориться периодом.
     *
     * createFromFormat молча превращает 32-е число в 1-е следующего месяца, поэтому
     * дату сверяем обратно.
     */
    public function test_does_not_take_garbage_digits_for_a_period(): void
    {
        $words = array_merge(
            $this->periodRow('99999999', '99999999'),
            $this->line(300, '23 000,00', '6,00', '1 380,00'),
            $this->totals(752, '23 000,00', '1 380,00'),
        );

        $result = $this->reader($words)->taxableBase('отчёт.pdf');

        $this->assertSame(DocumentValue::WRONG_DOC, $result->status);
    }

    /**
     * Авансовые платежи входят в итог.
     *
     * Поле 182 — авансы текущего периода, поле 184 — те, что уже вошли в базу прошлых
     * периодов. Второе бланк печатает отрицательным, поэтому складываем всё как есть.
     * Числа взяты из боевого отчёта ФинЭко за июнь: без этих двух строк итог не сходился
     * на 8 125 сомов, и разбор отказывался отвечать.
     */
    public function test_advance_payment_rows_are_part_of_the_total(): void
    {
        $words = array_merge(
            $this->periodRow('01062026', '30062026'),
            $this->line(300, '1 494 185,00', '4,00', '59 767,40'),
            $this->advance(700, '89 350,00', '3 574,00'),
            $this->advance(720, '-97 475,00', '-3 899,00'),
            $this->totals(752, '1 486 060,00', '59 442,40'),
        );

        $result = $this->reader($words)->taxableBase('отчёт.pdf');

        $this->assertTrue($result->isFound(), $result->reason ?? '');
        $this->assertSame(1486060.00, $result->value);
    }

    /**
     * Бланк без единой строки деятельности — только авансы.
     *
     * Так выглядит боевой отчёт Сан Планет за июнь: ставки нет нигде, а в итоге 450 000.
     * Требование «хотя бы одна строка со ставкой» отвергало такой отчёт напрасно.
     */
    public function test_reads_report_filled_with_advances_only(): void
    {
        $words = array_merge(
            $this->periodRow('01062026', '30062026'),
            $this->advance(700, '450 000,00', '9 000,00'),
            $this->advance(720, '0,00', '0,00'),
            $this->totals(752, '450 000,00', '9 000,00'),
        );

        $result = $this->reader($words)->taxableBase('отчёт.pdf');

        $this->assertTrue($result->isFound(), $result->reason ?? '');
        $this->assertSame(450000.00, $result->value);
    }

    /** Отрицательное число не должно потеряться при разборе. */
    public function test_negative_numbers_are_read(): void
    {
        $words = array_merge(
            $this->periodRow('01062026', '30062026'),
            $this->advance(700, '-1 000,00', '-40,00'),
            $this->totals(752, '-1 000,00', '-40,00'),
        );

        $this->assertSame(-1000.00, $this->reader($words)->taxableBase('отчёт.pdf')->value);
    }
}
