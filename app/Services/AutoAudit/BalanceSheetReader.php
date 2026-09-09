<?php

namespace App\Services\AutoAudit;

use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

/**
 * Чтение оборотно-сальдовой ведомости (ОСВ) из выгрузки 1С.
 *
 * Читаем сами, без ИИ: файл не покидает сервер, токены не тратятся, а два прогона по
 * одному файлу всегда дают одно и то же число. Отдавать таблицу модели незачем — она
 * уже разложена по строкам и колонкам.
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
 * Прежде чем что-то считать, убеждаемся, что это вообще ОСВ и за нужный месяц: заголовок
 * файла несёт и то, и другое. Не сошлось — отвечаем «документ не тот», а не числом.
 */
class BalanceSheetReader
{
    /** Сколько первых строк просматриваем в поисках заголовка и шапки. */
    private const HEADER_SCAN_ROWS = 12;

    /**
     * Месяцы: корень для поиска в заголовке и полное имя для сообщения человеку.
     *
     * Ищем по корню, потому что 1С пишет то «за Июль 2026 г.», то «Июля». Май — «ма»,
     * общий кусок «Май» и «Мая», а больше в списке ни одного месяца на «ма» нет.
     */
    private const MONTHS = [
        1  => ['январ',   'январь'],
        2  => ['феврал',  'февраль'],
        3  => ['март',    'март'],
        4  => ['апрел',   'апрель'],
        5  => ['ма',      'май'],
        6  => ['июн',     'июнь'],
        7  => ['июл',     'июль'],
        8  => ['август',  'август'],
        9  => ['сентябр', 'сентябрь'],
        10 => ['октябр',  'октябрь'],
        11 => ['ноябр',   'ноябрь'],
        12 => ['декабр',  'декабрь'],
    ];

    /**
     * Оборот по счёту за период.
     *
     * @param string $path    путь к файлу на диске
     * @param string $account номер счёта, например «3210»
     * @param string $side    'credit' или 'debit'
     * @param int    $year    год периода задачи
     * @param int    $month   месяц периода задачи
     */
    public function turnover(string $path, string $account, string $side, int $year, int $month): DocumentValue
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(false);
            $rows = $reader->load($path)->getActiveSheet()->toArray(null, true, false, false);
        } catch (Throwable $e) {
            return DocumentValue::unreadable('Файл не открылся как таблица: ' . $e->getMessage());
        }

        if ($wrong = $this->rejectIfNotBalanceSheet($rows, $year, $month)) {
            return $wrong;
        }

        $column = $this->findTurnoverColumn($rows, $side);

        if ($column === null) {
            return DocumentValue::wrongDocument(
                'В шапке не нашли колонку «Обороты за период / ' . $this->sideLabel($side) . '»'
            );
        }

        $row = $this->findAccountRow($rows, $account);

        if ($row === null) {
            return DocumentValue::notFound("В ведомости нет счёта {$account}");
        }

        $raw = $rows[$row][$column] ?? null;

        return DocumentValue::found($this->toNumber($raw), [
            'счёт'    => $account,
            'колонка' => 'Обороты за период / ' . $this->sideLabel($side),
            'строка'  => $row + 1,
            'ячейка'  => $this->columnLetter($column) . ($row + 1),
            'сырое'   => (string) $raw,
        ]);
    }

    /**
     * Это точно ОСВ и точно за нужный месяц?
     *
     * Главная защита от подмены. Прикрепили не тот файл или файл за соседний месяц —
     * узнаём здесь, а не выдаём уверенное число по чужому документу.
     */
    private function rejectIfNotBalanceSheet(array $rows, int $year, int $month): ?DocumentValue
    {
        $head = '';
        foreach (array_slice($rows, 0, self::HEADER_SCAN_ROWS) as $row) {
            $head .= ' ' . implode(' ', array_map(fn ($c) => (string) $c, $row));
        }
        $head = mb_strtolower($head);

        if (!str_contains($head, 'оборотно-сальдовая ведомость')) {
            return DocumentValue::wrongDocument('Это не оборотно-сальдовая ведомость');
        }

        // Год ищем как отдельное число: «2026» внутри «12026» — не год.
        if (!preg_match('/(?<!\d)' . $year . '(?!\d)/u', $head)) {
            return DocumentValue::wrongDocument("В заголовке ведомости нет {$year} года");
        }

        [$stem, $name] = self::MONTHS[$month];

        if (!str_contains($head, $stem)) {
            return DocumentValue::wrongDocument("Ведомость не за {$name} {$year}");
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

                // Нижний уровень шапки — следующая строка под тем же столбцом.
                $below = mb_strtolower(trim((string) ($rows[$r + 1][$c] ?? '')));

                if ($below === $needle) {
                    return $c;
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
