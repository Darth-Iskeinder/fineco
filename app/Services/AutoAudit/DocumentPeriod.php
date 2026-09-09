<?php

namespace App\Services\AutoAudit;

use Carbon\CarbonImmutable;

/**
 * Период, за который составлен документ.
 *
 * Читаем его из самого документа, а не выводим из задачи. Причина в том, что месяц
 * задачи — это когда работу делали, а не за какой период отчитались: июльский отчёт по
 * единому налогу лежит на августовской задаче, а квартальный — на июльской. Сверять
 * документы между собой можно только по их собственным периодам.
 */
class DocumentPeriod
{
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
    ) {}

    public static function of(int $year, int $month): self
    {
        $from = CarbonImmutable::create($year, $month, 1);

        return new self($from, $from->endOfMonth()->startOfDay());
    }

    public function covers(self $other): bool
    {
        return $this->from->lessThanOrEqualTo($other->from)
            && $this->to->greaterThanOrEqualTo($other->to);
    }

    public function equals(self $other): bool
    {
        return $this->from->isSameDay($other->from) && $this->to->isSameDay($other->to);
    }

    /** Месяцы внутри периода: квартальный отчёт сверяем с тремя месячными ведомостями. */
    public function months(): array
    {
        $months = [];

        for ($m = $this->from->startOfMonth(); $m->lessThanOrEqualTo($this->to); $m = $m->addMonth()) {
            $months[] = [$m->year, $m->month];
        }

        return $months;
    }

    public function label(): string
    {
        return $this->from->format('d.m.Y') . ' – ' . $this->to->format('d.m.Y');
    }
}
