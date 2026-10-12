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

    /** Ночной прогон по Бишкеку: гасит то, что запустили после вечерней паузы. */
    public const CLOCK_NIGHT_STOP = '03:00';

    /** Сколько максимум засчитывается за один ночной запуск. */
    public const CLOCK_NIGHT_MAX_SECONDS = 2 * 3600;

    /**
     * До какого момента таймер считает честно, дальше его забыли. Считаем от последнего
     * запуска, а не от «сегодня», чтобы пропущенный прогон не засчитал человеку целые сутки.
     *
     * Днём (с 03:00 до 20:00) это ближайшие 20:00. Ночью (с 20:00 до 03:00) не больше двух
     * часов и не дальше конца ночи: полуночи для вечернего запуска, 03:00 для запуска после
     * полуночи. Раньше ночной запуск шёл до 20:00 следующего дня, и «Старт» в 20:02 с уходом
     * домой давал 13 часов.
     */
    public function clockDailyStopAfterResume(): ?Carbon
    {
        if (!$this->resumed_at) {
            return null;
        }

        $resumed = $this->resumed_at->copy()->setTimezone(self::CLOCK_TIMEZONE);
        $evening = self::clockTimeOn($resumed, self::CLOCK_DAILY_STOP);
        $night   = self::clockTimeOn($resumed, self::CLOCK_NIGHT_STOP);

        if ($resumed->greaterThanOrEqualTo($night) && $resumed->lessThan($evening)) {
            $stop = $evening;
        } else {
            $nightEnd = $resumed->greaterThanOrEqualTo($evening) ? $resumed->copy()->addDay()->startOfDay() : $night;
            $stop     = $resumed->copy()->addSeconds(self::CLOCK_NIGHT_MAX_SECONDS)->min($nightEnd);
        }

        return $stop->setTimezone(config('app.timezone'));
    }

    private static function clockTimeOn(Carbon $day, string $time): Carbon
    {
        [$hour, $minute] = array_map('intval', explode(':', $time));

        return $day->copy()->setTime($hour, $minute);
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
