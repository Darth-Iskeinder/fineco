<?php

namespace App\Services;

use PhpOffice\PhpSpreadsheet\Cell\Cell;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
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
    public const EXTENSIONS = ['xls', 'xlsx', 'xlsm', 'ods'];

    /** Дальше парсер съедает слишком много памяти — такой файл проще скачать. */
    private const MAX_BYTES = 15 * 1024 * 1024;

    private const MAX_ROWS = 300;
    private const MAX_COLUMNS = 40;
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
    public function read(string $absolutePath, string $name): array
    {
        if (!$this->supports($name)) {
            throw new RuntimeException('Этот файл не таблица');
        }

        $size = @filesize($absolutePath);
        if ($size === false || $size > self::MAX_BYTES) {
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

                $data = $this->readSheet($sheet);
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
            'limits'    => ['rows' => self::MAX_ROWS, 'columns' => self::MAX_COLUMNS],
        ];
    }

    /**
     * Читаем со стилями: без них дата превращается в число вроде 45870, а сумма —
     * в 10.500000000001. Но стили тянут за собой и картинки внутри .xls, а их
     * библиотека разбирает через gd. Если расширения нет — перечитываем голые
     * значения: лучше показать таблицу с числом вместо даты, чем не показать её.
     */
    private function load(string $absolutePath, string $name): Spreadsheet
    {
        $reader = IOFactory::createReader($this->readerName($name));

        try {
            return $reader->load($absolutePath);
        } catch (\Throwable $e) {
            if (function_exists('imagecreatefromstring')) {
                throw $e;
            }

            $reader = IOFactory::createReader($this->readerName($name));
            $reader->setReadDataOnly(true);

            return $reader->load($absolutePath);
        }
    }

    /**
     * Читатель по расширению, а не по содержимому: имя приходит из нашей БД, а
     * автоопределение библиотеки само открывает файл всеми ридерами подряд.
     */
    private function readerName(string $name): string
    {
        return match (strtolower(pathinfo($name, PATHINFO_EXTENSION))) {
            'xls'          => 'Xls',
            'xlsx', 'xlsm' => 'Xlsx',
            'ods'          => 'Ods',
            default        => throw new RuntimeException('Этот файл не таблица'),
        };
    }

    /**
     * @return array{name: string, rows: list<list<string>>, columns: int, truncated: bool}
     */
    private function readSheet(Worksheet $sheet): array
    {
        $lastRow = min($sheet->getHighestDataRow(), self::MAX_ROWS);
        $lastColumnIndex = min(
            Coordinate::columnIndexFromString($sheet->getHighestDataColumn()),
            self::MAX_COLUMNS,
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
            'truncated' => $sheet->getHighestDataRow() > self::MAX_ROWS
                || Coordinate::columnIndexFromString($sheet->getHighestDataColumn()) > self::MAX_COLUMNS,
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
