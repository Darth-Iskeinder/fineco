<?php

namespace Tests\Unit;

use App\Services\AutoAudit\BalanceSheetReader;
use App\Services\AutoAudit\DocumentValue;
use App\Services\AutoAudit\PdfTextLayer;
use App\Services\AutoAudit\PdfWord;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PHPUnit\Framework\TestCase;

/**
 * Разбор оборотно-сальдовой ведомости.
 *
 * Ведомость собираем синтетическую, а не кладём боевую в репозиторий: в настоящей
 * лежат обороты живого клиента. Зато повторяем в ней ровно те особенности выгрузки 1С,
 * на которых разбор и спотыкается:
 *
 *   - двухуровневая шапка, где «Кредит» встречается трижды (сальдо на начало,
 *     обороты, сальдо на конец);
 *   - верхний уровень шапки в объединённых ячейках, то есть пустой во всех, кроме первой;
 *   - под счётом идут валютные подстроки, а под ними строка «Вал.» с суммой в валюте.
 *
 * Числа взяты такими, чтобы промах было видно: у строки счёта, у подстроки RUB и у
 * строки «Вал.» они разные.
 */
class BalanceSheetReaderTest extends TestCase
{
    private string $file;

    protected function setUp(): void
    {
        parent::setUp();
        $this->file = $this->writeBalanceSheet();
    }

    protected function tearDown(): void
    {
        @unlink($this->file);
        parent::tearDown();
    }

    private function reader(array $pdfWords = []): BalanceSheetReader
    {
        $layer = new class($pdfWords) extends PdfTextLayer {
            public function __construct(private array $words) {}

            public function words(string $path, int $page = 1): array
            {
                return $this->words;
            }
        };

        return new BalanceSheetReader($layer);
    }

    /**
     * Ведомость в том же виде, в каком её отдаёт 1С.
     *
     * @param string $title заголовок во второй строке — им подменяем форму и период
     */
    private function writeBalanceSheet(string $title = 'Оборотно-сальдовая ведомость за Июль 2026 г.'): string
    {
        $book  = new Spreadsheet();
        $sheet = $book->getActiveSheet();

        $sheet->setCellValue('A1', 'Общество с ограниченной ответственностью "Тест"');
        $sheet->setCellValue('A2', $title);

        // Шапка: верхний уровень объединён, нижний — по одной ячейке.
        $sheet->setCellValue('A4', 'Счет, Наименование счета');
        $sheet->setCellValue('B4', 'Показатели');
        $sheet->setCellValue('C4', 'Сальдо на начало периода');
        $sheet->setCellValue('E4', 'Обороты за период');
        $sheet->setCellValue('H4', 'Сальдо на конец периода');
        $sheet->mergeCells('C4:D4');
        $sheet->mergeCells('E4:G4');
        $sheet->mergeCells('H4:I4');

        $sheet->setCellValue('B5', 'БУ');
        $sheet->setCellValue('C5', 'Дебет');
        $sheet->setCellValue('D5', 'Кредит');
        $sheet->setCellValue('E5', 'Дебет');
        $sheet->setCellValue('F5', 'Кредит');
        $sheet->setCellValue('H5', 'Дебет');
        $sheet->setCellValue('I5', 'Кредит');

        // Соседний счёт — чтобы поиск не хватал первую попавшуюся строку.
        $sheet->setCellValue('A6', '3110, Счета к оплате за товары и услуги');
        $sheet->setCellValue('B6', 'БУ');
        $sheet->setCellValue('F6', 111.11);

        // Искомый счёт: его собственная строка.
        $sheet->setCellValue('A7', '3210, Авансы покупателей и заказчиков');
        $sheet->setCellValue('B7', 'БУ');
        $sheet->setCellValue('D7', 1676987.22);   // сальдо на начало по кредиту — не оно
        $sheet->setCellValue('E7', 419652.47);    // оборот по дебету
        $sheet->setCellValue('F7', 87513.60);     // оборот по кредиту — вот он
        $sheet->setCellValue('I7', 1344848.35);   // сальдо на конец по кредиту — не оно

        // Валютная подстрока и «Вал.» под ней: обе не должны попасться.
        $sheet->setCellValue('A8', 'RUB');
        $sheet->setCellValue('B8', 'БУ');
        $sheet->setCellValue('F8', 55555.55);
        $sheet->setCellValue('B9', 'Вал.');
        $sheet->setCellValue('F9', 76800);

        // Счёт с нулевым оборотом: 1С печатает пустую ячейку, а не ноль.
        $sheet->setCellValue('A10', '3420, Подоходный налог на доходы сотрудников');
        $sheet->setCellValue('B10', 'БУ');

        $path = tempnam(sys_get_temp_dir(), 'osv') . '.xlsx';
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();

        return $path;
    }

    public function test_reads_credit_turnover_of_the_account(): void
    {
        $result = $this->reader()->turnover($this->file, '3210', 'credit');

        $this->assertTrue($result->isFound(), $result->reason ?? '');
        $this->assertSame(87513.60, $result->value);
    }

    /** Дебет и кредит стоят рядом: перепутать их — получить правдоподобное, но чужое число. */
    public function test_reads_debit_turnover_of_the_account(): void
    {
        $result = $this->reader()->turnover($this->file, '3210', 'debit');

        $this->assertSame(419652.47, $result->value);
    }

    /**
     * Ключевая ловушка: «Кредит» в шапке трижды.
     *
     * Если зацепиться за слово, а не за пару «Обороты за период + Кредит», ответом
     * станет сальдо — число того же порядка, и подмену никто не заметит.
     */
    public function test_does_not_take_opening_or_closing_balance(): void
    {
        $value = $this->reader()->turnover($this->file, '3210', 'credit')->value;

        $this->assertNotSame(1676987.22, $value, 'взяли сальдо на начало');
        $this->assertNotSame(1344848.35, $value, 'взяли сальдо на конец');
    }

    /** Вторая ловушка: под счётом идут его валютные подстроки, суммы там другие. */
    public function test_does_not_take_currency_sub_rows(): void
    {
        $value = $this->reader()->turnover($this->file, '3210', 'credit')->value;

        $this->assertNotSame(55555.55, $value, 'взяли подстроку RUB');
        $this->assertNotSame(76800.0, $value, 'взяли строку «Вал.» — это сумма в валюте, а не в сомах');
    }

    /** Пустая ячейка в ОСВ означает ноль: нулевые обороты 1С не печатает. */
    public function test_empty_cell_is_zero(): void
    {
        $result = $this->reader()->turnover($this->file, '3420', 'credit');

        $this->assertTrue($result->isFound());
        $this->assertSame(0.0, $result->value);
    }

    /** Счёта нет — это не ошибка сверки: у клиента может просто не быть таких операций. */
    public function test_missing_account_is_not_found(): void
    {
        $result = $this->reader()->turnover($this->file, '9999', 'credit');

        $this->assertSame(DocumentValue::NOT_FOUND, $result->status);
        // Форма опознана, значит период известен: сверке он нужен, чтобы поставить ведомость в пару.
        $this->assertSame('01.07.2026 – 31.07.2026', $result->period?->label());
    }

    /**
     * Период читаем из самой ведомости, а не сверяем с месяцем задачи.
     *
     * Месяц задачи — это когда работу делали, а не за какой период отчитались: на боевом
     * сервере июльская ведомость висит на августовской задаче. Сверять периоды двух
     * документов между собой будет сама проверка.
     */
    public function test_reads_period_from_the_header(): void
    {
        $result = $this->reader()->turnover($this->file, '3210', 'credit');

        $this->assertSame('01.07.2026 – 31.07.2026', $result->period->label());
    }

    /** 1С пишет месяц то в именительном, то в родительном падеже. */
    public function test_reads_period_written_in_genitive(): void
    {
        $other = $this->writeBalanceSheet('Оборотно-сальдовая ведомость за Июля 2026 г.');

        $result = $this->reader()->turnover($other, '3210', 'credit');

        @unlink($other);
        $this->assertSame('01.07.2026 – 31.07.2026', $result->period->label());
    }

    /** Второй вид заголовка: период не словами, а датами. */
    public function test_reads_period_written_as_dates(): void
    {
        $other = $this->writeBalanceSheet('Оборотно-сальдовая ведомость за 01.04.2026 - 30.06.2026');

        $result = $this->reader()->turnover($other, '3210', 'credit');

        @unlink($other);
        $this->assertSame('01.04.2026 – 30.06.2026', $result->period->label());
        $this->assertCount(3, $result->period->months());
    }

    /** Май не должен путаться с мартом: у них общее начало. */
    public function test_may_is_not_confused_with_march(): void
    {
        $other = $this->writeBalanceSheet('Оборотно-сальдовая ведомость за Май 2026 г.');

        $result = $this->reader()->turnover($other, '3210', 'credit');

        @unlink($other);
        $this->assertSame('01.05.2026 – 31.05.2026', $result->period->label());
    }

    /**
     * Период не разобрали — отказываемся.
     *
     * Догадка тут хуже отказа: по периоду дальше подбирают вторую половину пары, и
     * ошибка увела бы сверку на документы за другой месяц.
     */
    public function test_rejects_balance_sheet_without_a_period(): void
    {
        $other = $this->writeBalanceSheet('Оборотно-сальдовая ведомость');

        $result = $this->reader()->turnover($other, '3210', 'credit');

        @unlink($other);
        $this->assertSame(DocumentValue::WRONG_DOC, $result->status);
        $this->assertStringContainsString('период', $result->reason);
    }

    /**
     * Таблица читается, но это не ведомость.
     *
     * Главная защита от подмены: без неё разбор пошёл бы искать счёт в чужом документе
     * и с какой-то вероятностью что-нибудь да нашёл.
     */
    public function test_other_spreadsheet_is_rejected(): void
    {
        $other = $this->writeBalanceSheet('Реестр электронных счетов-фактур за Июль 2026 г.');

        $result = $this->reader()->turnover($other, '3210', 'credit');

        @unlink($other);
        $this->assertSame(DocumentValue::WRONG_DOC, $result->status);
        $this->assertStringContainsString('не оборотно-сальдовая', $result->reason);
    }

    /**
     * Текстовый файл PhpSpreadsheet открывает как csv, и он проходит дальше.
     *
     * Отбивает его уже проверка формы — и это ровно то, что нужно: важно не то, на каком
     * шаге мы отказались, а то, что числа из чужого файла наружу не ушли.
     */
    public function test_text_file_is_rejected_as_not_a_balance_sheet(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'notxls');
        file_put_contents($path, "просто текст\nвторая строка");

        $result = $this->reader()->turnover($path, '3210', 'credit');

        @unlink($path);
        $this->assertSame(DocumentValue::WRONG_DOC, $result->status);
    }

    /**
     * Битый PDF: разборщик до текста не доберётся.
     *
     * Здесь берём настоящий разборщик, а не подставной: проверяем именно то, что файл
     * не открылся, а этого с подставным не увидеть.
     */
    public function test_unreadable_file(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'broken') . '.pdf';
        file_put_contents($path, "%PDF-1.7\nни оглавления, ни объектов");

        $result = (new BalanceSheetReader(new PdfTextLayer()))->turnover($path, '3210', 'credit');

        @unlink($path);
        $this->assertSame(DocumentValue::UNREADABLE, $result->status);
    }

    /** След нужен, чтобы человек открыл файл и увидел ту же ячейку. */
    public function test_trace_points_at_the_cell(): void
    {
        $result = $this->reader()->turnover($this->file, '3210', 'credit');

        $this->assertSame('F7', $result->trace['ячейка']);
        $this->assertSame('Обороты за период / Кредит', $result->trace['колонка']);
    }

    // ---------------------------------------------------------------------
    // Печать ведомости в PDF: тот же разбор, другой способ получить таблицу
    // ---------------------------------------------------------------------

    /** Координаты взяты с боевых ведомостей: у всех трёх, что смотрели, шапка совпала. */
    private const PDF_COLUMNS = [
        'счёт'         => 59.0,
        'показатели'   => 100.0,
        'сальдо_нач_д' => 136.0,
        'сальдо_нач_к' => 208.0,
        'оборот_д'     => 281.0,
        'оборот_к'     => 353.0,
        'сальдо_кон_д' => 425.0,
        'сальдо_кон_к' => 497.0,
    ];

    /**
     * Ведомость, напечатанная в PDF.
     *
     * Воспроизведены две особенности живых файлов: слово «Показатели» переносится и
     * оставляет между уровнями шапки строки-обрывки, а числа прижаты вправо, поэтому
     * начинаются заметно правее начала своей колонки.
     *
     * @return PdfWord[]
     */
    private function pdfBalanceSheet(string $title = 'Оборотно-сальдовая ведомость за Июль 2026 г.'): array
    {
        $c = self::PDF_COLUMNS;
        $w = [];
        $at = function (float $column, float $top, string $text) use (&$w) {
            // Число прижато вправо: сдвигаем начало внутрь колонки, как в настоящем файле.
            $w[] = new PdfWord($column + (is_numeric(str_replace([' ', ','], ['', '.'], $text)) ? 25.0 : 0.0), $top, $text);
        };

        $at($c['счёт'], -804, 'ип "Тестовый"');
        $at($c['счёт'], -791, $title);

        $at($c['счёт'], -777, 'Счет');
        $at($c['показатели'], -777, 'Показа');
        $at($c['сальдо_нач_д'], -777, 'Сальдо на начало периода');
        $at($c['оборот_д'], -777, 'Обороты за период');
        $at($c['сальдо_кон_д'], -777, 'Сальдо на конец периода');

        // Обрывки переносимого «Показателя» — из-за них нижний уровень шапки не соседняя строка.
        $at($c['показатели'], -767, '-');
        $at($c['показатели'], -757, 'тели');

        $at($c['показатели'], -745, 'БУ');
        $at($c['сальдо_нач_д'], -745, 'Дебет');
        $at($c['сальдо_нач_к'], -745, 'Кредит');
        $at($c['оборот_д'], -745, 'Дебет');
        $at($c['оборот_к'], -745, 'Кредит');
        $at($c['сальдо_кон_д'], -745, 'Дебет');
        $at($c['сальдо_кон_к'], -745, 'Кредит');

        // Соседний счёт — чтобы поиск не хватал первую попавшуюся строку.
        $at($c['счёт'], -600, '3110');
        $at($c['показатели'], -600, 'БУ');
        $at($c['оборот_к'], -600, '111,11');

        // Искомый счёт: заполнены все четыре колонки, числа разные.
        $at($c['счёт'], -586, '3210');
        $at($c['показатели'], -586, 'БУ');
        $at($c['сальдо_нач_к'], -586, '1 676 987,22');
        $at($c['оборот_д'], -586, '419 652,47');
        $at($c['оборот_к'], -586, '87 513,60');
        $at($c['сальдо_кон_к'], -586, '1 344 848,35');

        // Валютная подстрока и «Вал.» под ней: обе не должны попасться.
        $at($c['счёт'], -575, 'KGS');
        $at($c['показатели'], -575, 'БУ');
        $at($c['оборот_к'], -575, '55 555,55');
        $at($c['показатели'], -565, 'Вал.');
        $at($c['оборот_к'], -565, '76 800,00');

        $at($c['счёт'], -463, 'Итого');
        $at($c['показатели'], -463, 'БУ');
        $at($c['оборот_к'], -463, '999 999,99');

        return $w;
    }

    public function test_reads_credit_turnover_from_pdf(): void
    {
        $result = $this->reader($this->pdfBalanceSheet())->turnover('осв.pdf', '3210', 'credit');

        $this->assertTrue($result->isFound(), $result->reason ?? '');
        $this->assertSame(87513.60, $result->value);
        $this->assertSame('01.07.2026 – 31.07.2026', $result->period->label());
    }

    public function test_reads_debit_turnover_from_pdf(): void
    {
        $result = $this->reader($this->pdfBalanceSheet())->turnover('осв.pdf', '3210', 'debit');

        $this->assertSame(419652.47, $result->value);
    }

    /** Та же ловушка, что и в Excel: «Кредит» в шапке трижды. */
    public function test_pdf_does_not_take_balance_columns(): void
    {
        $value = $this->reader($this->pdfBalanceSheet())->turnover('осв.pdf', '3210', 'credit')->value;

        $this->assertNotSame(1676987.22, $value, 'взяли сальдо на начало');
        $this->assertNotSame(1344848.35, $value, 'взяли сальдо на конец');
    }

    /** И вторая: под счётом идут валютные подстроки. */
    public function test_pdf_does_not_take_currency_sub_rows(): void
    {
        $value = $this->reader($this->pdfBalanceSheet())->turnover('осв.pdf', '3210', 'credit')->value;

        $this->assertNotSame(55555.55, $value, 'взяли подстроку KGS');
        $this->assertNotSame(76800.0, $value, 'взяли строку «Вал.»');
    }

    /** Чужой PDF: без шапки ведомости колонок не найти, и гадать нельзя. */
    public function test_pdf_without_balance_sheet_header_is_rejected(): void
    {
        $words = [new PdfWord(59.0, -100.0, 'Счет на оплату № 12'), new PdfWord(59.0, -120.0, '1 000,00')];

        $result = $this->reader($words)->turnover('счёт.pdf', '3210', 'credit');

        $this->assertSame(DocumentValue::WRONG_DOC, $result->status);
    }
}
