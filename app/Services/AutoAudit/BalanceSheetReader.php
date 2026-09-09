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
    /** Сколько первых строк просматриваем в поисках заголовка и шапки. */
    private const HEADER_SCAN_ROWS = 12;

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

    /**
     * Оборот по счёту за период ведомости.
     *
     * @param string $path    путь к файлу на диске
     * @param string $account номер счёта, например «3210»
     * @param string $side    'credit' или 'debit'
     */
    public function turnover(string $path, string $account, string $side = 'credit'): DocumentValue
    {
        try {
            $reader = IOFactory::createReaderForFile($path);
            $reader->setReadDataOnly(false);
            $rows = $reader->load($path)->getActiveSheet()->toArray(null, true, false, false);
        } catch (Throwable $e) {
            return DocumentValue::unreadable('Файл не открылся как таблица: ' . $e->getMessage());
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
            return DocumentValue::notFound("В ведомости нет счёта {$account}");
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
