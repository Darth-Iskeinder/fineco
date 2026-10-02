<?php

namespace App\Services\AutoAudit;

/**
 * Результат чтения одного показателя из документа.
 *
 * Исходов несколько, и путать их нельзя. «Не нашли» и «документ не тот» — разные вещи:
 * первое значит, что форма опознана, но нужной строки в ней нет (у клиента просто нет
 * такого счёта); второе — что перед нами вообще другой документ или другой период, и
 * считать по нему нельзя ничего. Смешать их значило бы выдавать вердикт по чужому файлу.
 *
 * Скан стоит отдельно по той же причине. Текста в нём нет, и какая это форма, мы не знаем:
 * документ может быть и тем. Назвать его «не тем» значило бы обвинить бухгалтера зря.
 */
class DocumentValue
{
    private function __construct(
        public readonly ?float $value,
        public readonly string $status,
        public readonly ?string $reason = null,
        public readonly array $trace = [],
        /** Период, за который составлен документ. Читается из него самого, см. DocumentPeriod. */
        public readonly ?DocumentPeriod $period = null,
        /** ИНН организации из шапки документа, если он там есть. По нему ловим чужой документ. */
        public readonly ?string $inn = null,
        /** Раскладка суммы по счетам, когда проверка берёт их несколько: счёт => оборот. */
        public readonly array $parts = [],
    ) {}

    public const FOUND        = 'found';         // показатель прочитан
    public const NOT_FOUND    = 'not_found';     // форма та, показателя в ней нет
    public const WRONG_DOC    = 'wrong_doc';     // не та форма или не тот период
    public const UNREADABLE   = 'unreadable';    // файл не открылся
    public const SCAN         = 'scan';          // текста нет: скан или фото, прочитать нечем
    public const UNCERTAIN    = 'uncertain';     // форма та, а число из неё прочитать не удалось

    public static function found(float $value, array $trace = [], ?DocumentPeriod $period = null, ?string $inn = null): self
    {
        return new self($value, self::FOUND, null, $trace, $period, $inn);
    }

    /**
     * Период здесь бывает известен: форма опознана, заголовок прочитан, нет только строки.
     * Сверке он нужен, чтобы поставить документ в пару, даже когда числа в нём нет.
     */
    public static function notFound(string $reason, array $trace = [], ?DocumentPeriod $period = null): self
    {
        return new self(null, self::NOT_FOUND, $reason, $trace, $period);
    }

    public static function wrongDocument(string $reason, array $trace = []): self
    {
        return new self(null, self::WRONG_DOC, $reason, $trace);
    }

    public static function unreadable(string $reason): self
    {
        return new self(null, self::UNREADABLE, $reason);
    }

    public static function scan(string $reason): self
    {
        return new self(null, self::SCAN, $reason);
    }

    /**
     * Форма опознана, а числа мы не знаем: в ячейке не число, колонку в бланке не нашли.
     *
     * От notFound отличается тем, что там показателя в документе нет вовсе (у клиента просто
     * нет таких операций, и ноль честен), а здесь он есть, но какой именно, непонятно.
     * Подставить ноль тут нельзя: по нему был бы выписан вердикт.
     *
     * Период передаём всегда, когда он известен. Без него строка не встанет в пару, и клиент
     * пропадёт со страницы молча, а нужно ровно обратное: пусть его проверят руками.
     */
    public static function uncertain(string $reason, array $trace = [], ?DocumentPeriod $period = null, ?string $inn = null): self
    {
        return new self(null, self::UNCERTAIN, $reason, $trace, $period, $inn);
    }

    public function isFound(): bool
    {
        return $this->status === self::FOUND;
    }

    /**
     * Оборот по нескольким счетам одной ведомости: числа складываются.
     *
     * Так у фирмы, где единый налог лежит и на 3410, и на 3490 (КЛВ Эксперт). Счёта, которого
     * в ведомости нет, считаем нулём: 1С не печатает счета без оборотов. Если же нет ни
     * одного, исход прежний, «нет показателя», и сверка решит, можно ли верить нулю.
     *
     * Файл у всех счетов один. Не та форма, скан или битый файл одинаковы для каждого
     * счёта, поэтому такой исход отдаём как есть.
     *
     * @param array<string, self> $values счёт => что прочитано по нему
     */
    public static function sum(array $values): self
    {
        foreach ($values as $value) {
            if ($value->period === null) {
                return $value;
            }
        }

        $period    = reset($values)->period;
        $uncertain = array_filter($values, fn (self $value) => $value->status === self::UNCERTAIN);

        if ($uncertain) {
            return self::uncertain(
                implode('. ', array_map(fn (self $value) => $value->reason, $uncertain)),
                [],
                $period,
            );
        }

        $found = array_filter($values, fn (self $value) => $value->isFound());

        if (!$found) {
            return self::notFound('В ведомости нет счетов ' . implode(', ', array_keys($values)), [], $period);
        }

        $parts = array_map(fn (self $value) => $value->isFound() ? (float) $value->value : 0.0, $values);

        return new self(
            array_sum($parts),
            self::FOUND,
            null,
            // След по каждому счёту отдельно: у них одинаковые ключи, слитые затёрли бы друг друга.
            array_map(fn (self $value) => $value->trace, $found),
            $period,
            null,
            $parts,
        );
    }
}
