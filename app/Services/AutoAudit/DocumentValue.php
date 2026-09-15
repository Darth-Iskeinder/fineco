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
    ) {}

    public const FOUND        = 'found';         // показатель прочитан
    public const NOT_FOUND    = 'not_found';     // форма та, показателя в ней нет
    public const WRONG_DOC    = 'wrong_doc';     // не та форма или не тот период
    public const UNREADABLE   = 'unreadable';    // файл не открылся
    public const SCAN         = 'scan';          // текста нет: скан или фото, прочитать нечем

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

    public function isFound(): bool
    {
        return $this->status === self::FOUND;
    }
}
