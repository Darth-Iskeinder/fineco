<?php

namespace App\Services\Dashboard;

use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\EstimateItem;
use Carbon\CarbonImmutable;

/**
 * Одна сметная задача из PlannedTaskWalk: позиция сметы в конкретном месяце.
 * Лога может не быть: задачу ещё никто не открывал, она «не начата».
 */
final class PlannedTask
{
    public readonly string $status;

    public function __construct(
        public readonly Client $client,
        public readonly EstimateItem $item,
        public readonly int $assigneeId,
        public readonly int $year,
        public readonly int $month,
        public readonly ?CarbonImmutable $due,
        public readonly ?BuhTaskLog $log,
    ) {
        $this->status = $log?->status ?? 'pending';
    }

    public function isIn(int $year, int $month): bool
    {
        return $this->year === $year && $this->month === $month;
    }
}
