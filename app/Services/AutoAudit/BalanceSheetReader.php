<?php

namespace App\Services\AutoAudit;

use Carbon\CarbonImmutable;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Чтение оборотно-сальдовой ведомости (ОСВ) из выгрузки 1С.
 *
 * Читаем сами, без ИИ: файл не покидает сервер, токены не тратятся, а два прогона по
 * одному файлу всегда дают одно и то же число. Отдавать таблицу модели незачем — она
 * уже разложена по строкам и колонкам.
 *
 * Форматов два. Половина бухгалтеров прикладывает выгрузку в Excel, половина — печать
 * в PDF, и запрещать второе значило бы менять людям привычки ради удобства проверки.
 * Разбор при этом общий: PDF сводится к той же таблице, потому что 1С печатает шапку
 * текстом, а положение слов «Дебет» и «Кредит» в ней и задаёт границы колонок. Дальше
 * работает одна и та же логика, и ловушки ниже одинаково важны для обоих форматов.
 *
 * Три вещи, на которых легко ошибиться, и как они здесь решены:
 *
 * 1. Под каждым счётом идут подстроки по валютам (RUB, USD, сом), а под ними — строка
 *    «Вал.» с суммой в самой валюте, а не в сомах. Схватить её вместо строки счёта
 *    значит получить бессмысленное число без всякой ошибки. Отличаем по двум признакам
 *    сразу: в первой колонке стоит код счёта, во второй — «БУ».
 *
 * 2. «Кредит» в шапке встречается трижды: под сальдо на начало, под оборотами за период
 *    и под сальдо на конец. Ищем не слово, а пару «верхний заголовок + нижний», иначе
 *    промах гарантирован.
 *
 * 3. Шапка двухуровневая и в объединённых ячейках: «Обороты за период» стоит в первой
 *    ячейке диапазона, остальные пустые. Поэтому верхний заголовок «растягиваем»
 *    вправо до следующего непустого.
 *
 * Прежде чем что-то считать, убеждаемся, что это вообще ОСВ, и вытаскиваем из заголовка
 * период. Период именно читаем, а не сверяем с месяцем задачи: месяц задачи — это когда
 * работу делали, а не за какой период отчитались. Сверять периоды двух документов между
 * собой будет уже сама проверка.
 */
class BalanceSheetReader
{
    public function __construct(private readonly PdfTextLayer $pdf) {}

    /** Сколько первых строк просматриваем в поисках заголовка и шапки. */
    private const HEADER_SCAN_ROWS = 12;

    /** Разброс высоты, внутри которого слова PDF считаются одной строкой. */
    private const LINE_TOLERANCE = 3.0;

    /** На сколько строк ниже верхнего уровня шапки может стоять нижний. */
    private const HEADER_LEVEL_GAP = 4;

    /**
     * Корни названий месяцев для поиска в заголовке ведомости.
     *
     * Корни, а не полные слова, потому что 1С пишет то «за Июль 2026 г.», то «за Июля».
     * Май задан как «ма[йя]»: голое «ма» поймало бы и март.
     */
    private const MONTHS = [
        1 => 'январ',  2 => 'феврал', 3  => 'март',    4  => 'апрел',
        5 => 'ма[йя]', 6 => 'июн',    7  => 'июл',     8  => 'август',
        9 => 'сентябр', 10 => 'октябр', 11 => 'ноябр', 12 => 'декабр',
    ];

    /** Разобранные ведомости: путь к файлу => строки листа или отказ. */
    private array $tables = [];

    /**
     * Оборот по счёту за период ведомости.
     *
     * @param string $path    путь к файлу на диске
     * @param string $account номер счёта, например «3210»
     * @param string $side    'credit' или 'debit'
     */
    public function turnover(string $path, string $account, string $side = 'credit'): DocumentValue
    {
        $rows = $this->table($path);

        if ($rows instanceof DocumentValue) {
            return $rows;
        }

        $head = $this->header($rows);

        if (!str_contains($head, 'оборотно-сальдовая ведомость')) {
            return DocumentValue::wrongDocument('Это не оборотно-сальдовая ведомость');
        }

        $period = $this->period($head);

        if (!$period) {
            return DocumentValue::wrongDocument('В заголовке ведомости не разобрали период');
        }

        $column = $this->findTurnoverColumn($rows, $side);

        if ($column === null) {
            return DocumentValue::wrongDocument(
                'В шапке не нашли колонку «Обороты за период / ' . $this->sideLabel($side) . '»'
            );
        }

        $row = $this->findAccountRow($rows, $account);

        if ($row === null) {
            return DocumentValue::notFound("В ведомости нет счёта {$account}", [], $period);
        }

        $raw = $rows[$row][$column] ?? null;

        return DocumentValue::found($this->toNumber($raw), [
            'период'  => $period->label(),
            'счёт'    => $account,
            'колонка' => 'Обороты за период / ' . $this->sideLabel($side),
            'строка'  => $row + 1,
            'ячейка'  => $this->columnLetter($column) . ($row + 1),
            'сырое'   => (string) $raw,
        ], $period);
    }

    /**
     * Строки ведомости, разобранные один раз на файл, или отказ.
     *
     * Проверки берут из одной ведомости обороты по нескольким счетам. Без запоминания файл
     * разбирался заново на каждый счёт, и на боевой фирме прогон упёрся в ограничение
     * времени запроса.
     */
    private function table(string $path): array|DocumentValue
    {
        return $this->tables[$path] ??= $this->loadTable($path);
    }

    private function loadTable(string $path): array|DocumentValue
    {
        try {
            if (strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'pdf') {
                $words = $this->pdf->words($path);

                // Текста нет вовсе: скан или фото, сохранённое в PDF. Документ может быть
                // и тем, просто прочитать его нечем, поэтому это не «не та форма».
                if (!$words) {
                    return DocumentValue::scan('В PDF нет текста, это скан или фото');
                }

                return $this->rowsFromPdf($words);
            }

            return $this->rowsFromSpreadsheet($path);
        } catch (Throwable $e) {
            return DocumentValue::unreadable('Файл не открылся как ведомость: ' . $e->getMessage());
        }
    }

    /** Лист Excel как есть: строки и колонки уже разложены за нас. */
    private function rowsFromSpreadsheet(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(false);

        return $reader->load($path)->getActiveSheet()->toArray(null, true, false, false);
    }

    /**
     * Печать ведомости в PDF, сведённая к такой же таблице, как из Excel.
     *
     * Колонки не выдумываем: их границы задаёт нижний уровень шапки. Строка с «БУ»,
     * «Дебет» и «Кредит» стоит ровно над своими колонками, и её слова дают начало каждой.
     * Всё, что левее «БУ», — это первая колонка с номером счёта.
     *
     * Числа в ведомости прижаты вправо, поэтому начало числа всегда правее начала своей
     * колонки, и попадание однозначно. Дальше таблица уходит в общую логику, и разбираться,
     * из какого формата она пришла, никому не нужно.
     */
    private function rowsFromPdf(array $words): array
    {
        $lines = $this->groupByLine($words);
        $starts = $this->columnStarts($lines);

        if (!$starts) {
            // Пустой список — дальше проверка формы скажет «это не ведомость», и это честно:
            // без шапки мы не знаем, где чьи колонки, а гадать тут нельзя.
            return [];
        }

        $table = [];

        foreach ($lines as $cells) {
            $row = array_fill(0, count($starts) + 1, null);

            foreach ($cells as [$left, $text]) {
                $row[$this->columnAt($left, $starts)] = $text;
            }

            $table[] = $row;
        }

        return $table;
    }

    /**
     * Начала колонок 1..N по нижнему уровню шапки.
     *
     * Опознаём её по «Дебету» и «Кредиту»: в ведомости они встречаются трижды, парами под
     * сальдо на начало, оборотами и сальдо на конец. Меньше четырёх — значит перед нами
     * не шапка ведомости.
     *
     * @return float[] пусто, если шапку не нашли
     */
    private function columnStarts(array $lines): array
    {
        foreach ($lines as $cells) {
            $sides = array_filter(
                $cells,
                fn ($c) => in_array(mb_strtolower(trim($c[1])), ['дебет', 'кредит'], true),
            );

            if (count($sides) >= 4) {
                return array_column($cells, 0);
            }
        }

        return [];
    }

    /** В какую колонку попадает слово: 0 — всё, что левее первой; дальше по её началу. */
    private function columnAt(float $left, array $starts): int
    {
        $index = 0;

        foreach ($starts as $i => $start) {
            // Полторы точки допуска: у «БУ» подстроки строка сдвинута на волосок.
            if ($left >= $start - 1.5) {
                $index = $i + 1;
            }
        }

        return $index;
    }

    /**
     * Слова, собранные в строки по высоте.
     *
     * @param PdfWord[] $words
     * @return array<int, array<int, array{0: float, 1: string}>>
     */
    private function groupByLine(array $words): array
    {
        $lines = [];

        foreach ($words as $word) {
            $key = null;

            foreach (array_keys($lines) as $existing) {
                if (abs($existing - $word->top) <= self::LINE_TOLERANCE) {
                    $key = $existing;

                    break;
                }
            }

            $lines[$key ?? (string) $word->top][] = [$word->left, $word->text];
        }

        uksort($lines, fn ($a, $b) => (float) $a <=> (float) $b);

        foreach ($lines as &$cells) {
            usort($cells, fn ($a, $b) => $a[0] <=> $b[0]);
        }

        return array_values($lines);
    }

    /** Верхние строки листа одной строкой: там лежат название фирмы, форма и период. */
    private function header(array $rows): string
    {
        $head = '';

        foreach (array_slice($rows, 0, self::HEADER_SCAN_ROWS) as $row) {
            $head .= ' ' . implode(' ', array_map(fn ($c) => (string) $c, $row));
        }

        return mb_strtolower($head);
    }

    /**
     * Период ведомости из её заголовка.
     *
     * 1С пишет его двумя способами: словами («за Июль 2026 г.») и датами
     * («за 01.07.2026 - 31.07.2026»). Понимаем оба, и не понимаем — так и говорим:
     * догадка о периоде хуже отказа, потому что дальше по нему подбирают пару.
     */
    private function period(string $head): ?DocumentPeriod
    {
        foreach (self::MONTHS as $number => $stem) {
            // «за Июль 2026», «за Июля 2026 г.» — год обязателен, иначе это не период.
            if (preg_match('/за\s+' . $stem . '[а-яё]*\s+(\d{4})/u', $head, $m)) {
                return DocumentPeriod::of((int) $m[1], $number);
            }
        }

        if (preg_match('/(\d{2})\.(\d{2})\.(\d{4})\s*[-–—]\s*(\d{2})\.(\d{2})\.(\d{4})/u', $head, $m)) {
            $from = CarbonImmutable::createFromFormat('!d.m.Y', "{$m[1]}.{$m[2]}.{$m[3]}");
            $to   = CarbonImmutable::createFromFormat('!d.m.Y', "{$m[4]}.{$m[5]}.{$m[6]}");

            if ($from && $to && $from->lessThanOrEqualTo($to)) {
                return new DocumentPeriod($from, $to);
            }
        }

        return null;
    }

    /**
     * Колонка «Обороты за период» → «Дебет»/«Кредит».
     *
     * Верхний заголовок тянем вправо: он стоит в объединённых ячейках, и во всех, кроме
     * первой, пусто. Пара «верх + низ» — единственный надёжный признак, потому что
     * «Кредит» сам по себе есть ещё у сальдо на начало и на конец.
     */
    private function findTurnoverColumn(array $rows, string $side): ?int
    {
        $needle = $side === 'debit' ? 'дебет' : 'кредит';

        foreach (array_slice($rows, 0, self::HEADER_SCAN_ROWS, true) as $r => $top) {
            $carried = '';
            foreach ($top as $c => $cell) {
                $text = mb_strtolower(trim((string) $cell));

                if ($text !== '') {
                    $carried = $text;
                }

                if (!str_contains($carried, 'обороты за период')) {
                    continue;
                }

                // Нижний уровень шапки ищем на нескольких строках ниже, а не строго на
                // следующей: при печати в PDF слово «Показатели» переносится, и между
                // уровнями шапки оказываются строки-обрывки вроде «-» и «тели».
                for ($below = $r + 1; $below <= $r + self::HEADER_LEVEL_GAP; $below++) {
                    if (mb_strtolower(trim((string) ($rows[$below][$c] ?? ''))) === $needle) {
                        return $c;
                    }
                }
            }
        }

        return null;
    }

    /**
     * Строка самого счёта, а не его валютной подстроки.
     *
     * В первой колонке у счёта стоит «3210, Авансы покупателей...», у подстроки — «RUB»
     * или «сом», а у строки «Вал.» первая колонка вообще пуста. Плюс требуем «БУ» во
     * второй колонке: строка «Вал.» несёт сумму в валюте, и брать её нельзя.
     */
    private function findAccountRow(array $rows, string $account): ?int
    {
        foreach ($rows as $r => $row) {
            $first = trim((string) ($row[0] ?? ''));

            if ($first === '' || !preg_match('/^' . preg_quote($account, '/') . '\b/u', $first)) {
                continue;
            }

            if (mb_strtolower(trim((string) ($row[1] ?? ''))) !== 'бу') {
                continue;
            }

            return $r;
        }

        return null;
    }

    /** Пустая ячейка — это ноль: в ОСВ нулевые обороты просто не печатают. */
    private function toNumber(mixed $raw): float
    {
        if ($raw === null || trim((string) $raw) === '') {
            return 0.0;
        }

        // Пробелы-разделители разрядов и запятая как десятичный знак.
        $clean = str_replace([' ', "\u{00A0}", ','], ['', '', '.'], (string) $raw);

        return (float) $clean;
    }

    private function sideLabel(string $side): string
    {
        return $side === 'debit' ? 'Дебет' : 'Кредит';
    }

    /** Номер столбца → буква, чтобы человек мог открыть файл и увидеть ту же ячейку. */
    private function columnLetter(int $index): string
    {
        $letter = '';

        for ($i = $index; $i >= 0; $i = intdiv($i, 26) - 1) {
            $letter = chr(65 + $i % 26) . $letter;
        }

        return $letter;
    }
}
