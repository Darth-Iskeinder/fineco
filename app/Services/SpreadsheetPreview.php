<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\Csv as CsvReader;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;

/**
 * Чтение Excel-таблицы для просмотра в окне.
 *
 * Браузер таблицы рисовать не умеет: .xls/.xlsx он либо скачает, либо покажет
 * мусор. Поэтому файл разбираем на сервере и отдаём фронту голый текст ячеек —
 * дальше он рисует обычную HTML-таблицу. Это ещё и безопаснее клиентских
 * парсеров: чужой файл не попадает в браузер сотрудника, где лежит его сессия.
 *
 * Формулы намеренно НЕ вычисляем: берём значение, которое посчитал сам Excel и
 * сохранил в файле. Движок вычислений — это исполнение выражений из чужого
 * файла, для предпросмотра такая цена не нужна.
 *
 * Просмотр — не выгрузка: показываем начало листа, за остальным человек скачает
 * файл. Ограничения ниже держат и память, и время ответа.
 */
class SpreadsheetPreview
{
    /** Что умеет читать библиотека и что реально приносят клиенты. */
    public const EXTENSIONS = ['xls', 'xlsx', 'xlsm', 'ods', 'csv'];

    /** Дальше парсер съедает слишком много памяти — такой файл проще скачать. */
    private const MAX_BYTES = 15 * 1024 * 1024;

    /**
     * Сколько показываем. В окне поверх карточки — только начало: туда заглядывают
     * «глянуть, что приложили». На отдельной странице человек работает с таблицей
     * (сверяет цифры, ищет по Ctrl+F), поэтому там лимит на порядок выше.
     */
    public const MODAL_ROWS = 300;
    public const MODAL_COLUMNS = 40;
    public const PAGE_ROWS = 2000;
    public const PAGE_COLUMNS = 60;

    private const MAX_SHEETS = 20;

    public function supports(string $name): bool
    {
        return in_array(
            strtolower(pathinfo($name, PATHINFO_EXTENSION)),
            self::EXTENSIONS,
            true,
        );
    }

    /**
     * @return array{sheets: list<array{name: string, rows: list<list<string>>, columns: int, truncated: bool}>, truncated: bool, limits: array{rows: int, columns: int}}
     */
    public function read(
        string $absolutePath,
        string $name,
        int $maxRows = self::MODAL_ROWS,
        int $maxColumns = self::MODAL_COLUMNS,
    ): array {
        if (!$this->supports($name)) {
            throw new RuntimeException('Этот файл не таблица');
        }

        $size = @filesize($absolutePath);
        if ($size === false) {
            throw new RuntimeException('Файл не читается с диска');
        }

        if ($size > self::MAX_BYTES) {
            throw new RuntimeException('Файл слишком большой для просмотра');
        }

        $book = $this->load($absolutePath, $name);

        try {
            $sheets = [];
            $truncated = $book->getSheetCount() > self::MAX_SHEETS;

            foreach ($book->getAllSheets() as $index => $sheet) {
                if ($index >= self::MAX_SHEETS) {
                    break;
                }

                $data = $this->readSheet($sheet, $maxRows, $maxColumns);
                $sheets[] = $data;
                $truncated = $truncated || $data['truncated'];
            }
        } finally {
            // Книга держит в памяти весь файл: у длинных отчётов это десятки
            // мегабайт, которые иначе доживут до конца запроса.
            $book->disconnectWorksheets();
        }

        return [
            'sheets'    => $sheets,
            'truncated' => $truncated,
            'limits'    => ['rows' => $maxRows, 'columns' => $maxColumns],
        ];
    }

    /**
     * Подбираем читателя. Сначала по расширению — имя приходит из нашей БД, это
     * дёшево и точно. Если не вышло, определяем по содержимому: «.xls» из 1С,
     * банк-клиента или почты сплошь и рядом оказывается HTML-таблицей или текстом,
     * и настоящий Xls-ридер на нём падает.
     *
     * Второй заход каждого читателя — без стилей. Стили тянут за собой картинки
     * внутри .xls, а их библиотека разбирает через gd; если расширения нет, лучше
     * показать таблицу с датой в виде числа, чем не показать её вовсе.
     */
    private function load(string $absolutePath, string $name): Spreadsheet
    {
        $failures = [];

        foreach ($this->readerNames($absolutePath, $name) as $readerName) {
            foreach ([false, true] as $readDataOnly) {
                try {
                    $reader = IOFactory::createReader($readerName);
                    $reader->setReadDataOnly($readDataOnly);

                    // CSV приходит в чём попало: Excel отдаёт UTF-8 с BOM, 1С — cp1251,
                    // разделитель то запятая, то точка с запятой. BOM и разделитель
                    // библиотека определит сама, а вот текст без BOM она считает UTF-8 —
                    // поэтому для не-UTF-8 файлов подсказываем кодировку явно.
                    if ($reader instanceof CsvReader) {
                        $reader->setInputEncoding(CsvReader::GUESS_ENCODING);
                        $reader->setFallbackEncoding($this->fallbackEncoding($absolutePath));
                    }

                    return $reader->load($absolutePath);
                } catch (\Throwable $e) {
                    $failures[] = $readerName . ': ' . $e->getMessage();
                }
            }
        }

        throw new RuntimeException('Файл не читается как таблица (' . implode('; ', array_unique($failures)) . ')');
    }

    /**
     * @return list<string>
     */
    private function readerNames(string $absolutePath, string $name): array
    {
        $names = [$this->readerName($name)];

        try {
            // Список задаём явно: без него identify перебирает вообще всё, что умеет
            // библиотека, включая форматы, которых у нас быть не может.
            $detected = IOFactory::identify($absolutePath, [
                IOFactory::READER_XLSX,
                IOFactory::READER_XLS,
                IOFactory::READER_ODS,
                IOFactory::READER_HTML,
                IOFactory::READER_CSV,
            ]);
        } catch (\Throwable) {
            return $names;
        }

        return in_array($detected, $names, true) ? $names : [...$names, $detected];
    }

    /**
     * Чем читать CSV, если он не UTF-8. Отдельного признака в файле нет, поэтому
     * смотрим на содержимое: у нас и у клиентов «не UTF-8» на практике означает
     * windows-1251 — так пишут 1С и старый Excel.
     */
    private function fallbackEncoding(string $absolutePath): string
    {
        $head = @file_get_contents($absolutePath, false, null, 0, 8192);

        return $head !== false && !mb_check_encoding($head, 'UTF-8') ? 'CP1251' : 'UTF-8';
    }

    /** Читатель по расширению: имя файла приходит из нашей БД, а не от браузера. */
    private function readerName(string $name): string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'xls'          => 'Xls',
            'xlsx', 'xlsm' => 'Xlsx',
            'ods'          => 'Ods',
            'csv'          => 'Csv',
            default        => throw new RuntimeException('Этот файл не таблица'),
        };
    }

    /**
     * @return array{name: string, rows: list<list<string>>, columns: int, truncated: bool}
     */
    private function readSheet(Worksheet $sheet, int $maxRows, int $maxColumns): array
    {
        $lastRow = min($sheet->getHighestDataRow(), $maxRows);
        $lastColumnIndex = min(
            Coordinate::columnIndexFromString($sheet->getHighestDataColumn()),
            $maxColumns,
        );

        $rows = [];

        foreach ($sheet->getRowIterator(1, $lastRow) as $row) {
            $cells = [];
            $cellIterator = $row->getCellIterator('A', Coordinate::stringFromColumnIndex($lastColumnIndex));

            foreach ($cellIterator as $cell) {
                $cells[] = $cell === null ? '' : $this->cellText($cell);
            }

            $rows[] = $cells;
        }

        // Хвост из пустых строк — обычное дело для файла, где что-то удаляли.
        while ($rows !== [] && $this->isEmptyRow(end($rows))) {
            array_pop($rows);
        }

        return [
            'name'      => $sheet->getTitle(),
            'rows'      => array_values($rows),
            'columns'   => $lastColumnIndex,
            'truncated' => $sheet->getHighestDataRow() > $maxRows
                || Coordinate::columnIndexFromString($sheet->getHighestDataColumn()) > $maxColumns,
        ];
    }

    private function cellText(Cell $cell): string
    {
        $value = $cell->getDataType() === DataType::TYPE_FORMULA
            ? $cell->getOldCalculatedValue()   // то, что посчитал Excel при сохранении
            : $cell->getValue();

        if ($value instanceof RichText) {
            $value = $value->getPlainText();
        }

        if ($value === null || $value === '') {
            return '';
        }

        if (is_bool($value)) {
            return $value ? 'ИСТИНА' : 'ЛОЖЬ';
        }

        // Числом считаем только то, что число и по типу ячейки: текстовый «000123»
        // (ИНН, номер счёта) библиотека отдаёт строкой, и форматировать его нельзя —
        // ведущие нули отвалятся.
        if (is_int($value) || is_float($value)) {
            return (string) NumberFormat::toFormattedString(
                $value,
                $cell->getStyle()->getNumberFormat()->getFormatCode() ?: NumberFormat::FORMAT_GENERAL,
            );
        }

        return (string) $value;
    }

    /** @param list<string> $row */
    private function isEmptyRow(array $row): bool
    {
        foreach ($row as $cell) {
            if (trim($cell) !== '') {
                return false;
            }
        }

        return true;
    }
}
