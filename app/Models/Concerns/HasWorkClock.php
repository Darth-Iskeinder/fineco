<?php

namespace App\Models\Concerns;

use App\Models\Employee;
use Illuminate\Support\Carbon;

/**
 * Таймер работы над задачей: общий для плановых (BuhTaskLog) и внеплановых (BuhAdhocTask).
 *
 * Время хранится так: paused_seconds это уже накопленная работа (название историческое,
 * паузы там нет), а resumed_at момент последнего запуска. Пока задача running, к накопленному
 * добавляется «с resumed_at до сейчас».
 */
trait HasWorkClock
{
    /** Час по Бишкеку, в который все идущие таймеры встают на паузу. */
    public const CLOCK_DAILY_STOP = '20:00';

    public const CLOCK_TIMEZONE = 'Asia/Bishkek';

    /**
     * Досчитывает работу с последнего запуска до $at в paused_seconds.
     * Статус не меняет: это дело вызывающего.
     */
    public function bankWorkedTime(\DateTimeInterface $at): void
    {
        if ($this->status === 'running' && $this->resumed_at) {
            $this->paused_seconds += max(0, $at->getTimestamp() - $this->resumed_at->timestamp);
        }
    }

    /** Пауза с засчитанным временем до $at. Сохраняет. */
    public function pauseClockAt(\DateTimeInterface $at): void
    {
        $this->bankWorkedTime($at);
        $this->status = 'paused';
        $this->save();
    }

    /**
     * Ближайшие 20:00 по Бишкеку после последнего запуска: до этого момента таймер считает
     * честно, дальше его забыли. Считаем от запуска, а не от «сегодня», чтобы пропущенный
     * вечерний прогон не засчитал человеку целые сутки.
     */
    public function clockDailyStopAfterResume(): ?Carbon
    {
        if (!$this->resumed_at) {
            return null;
        }

        [$hour, $minute] = array_map('intval', explode(':', self::CLOCK_DAILY_STOP));
        $resumed = $this->resumed_at->copy()->setTimezone(self::CLOCK_TIMEZONE);
        $stop    = $resumed->copy()->setTime($hour, $minute);

        if ($stop->lessThanOrEqualTo($resumed)) {
            $stop->addDay();
        }

        return $stop->setTimezone(config('app.timezone'));
    }

    /**
     * Замок на сотруднике: всё, что запускает или останавливает его таймеры, идёт по очереди.
     * Вызывать внутри транзакции.
     */
    public static function lockClockOwner(int $employeeId): void
    {
        Employee::whereKey($employeeId)->lockForUpdate()->first();
    }
}
