<?php

namespace App\Jobs;

use App\Services\AutoAudit\AutoAuditRunner;
use App\Support\TenantContext;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Прогон автоаудита после того, как браузер уже получил ответ.
 *
 * Раньше кнопка «Проверить сейчас» ждала конца прогона. С проверками по форме 161 он стал
 * дольше, чем веб-сервер ждёт ответа, и на бою кнопка кончалась ошибкой 504. Теперь кнопка
 * сразу отвечает, а прогон идёт тем же процессом следом за ответом.
 *
 * Обработчик очереди для этого не нужен: задание запускается через dispatchAfterResponse,
 * без записи в очередь. Работает ли на бою обработчик очереди, мы не знаем.
 *
 * Состояние прогона лежит в кеше по фирме: идёт, готово или упала. Его показывает страница.
 */
class RunAutoAuditJob
{
    use Dispatchable;

    public const RUNNING = 'running';
    public const DONE    = 'done';
    public const FAILED  = 'failed';

    /** Сколько помнить состояние: страница показывает его до следующего прогона. */
    private const KEEP_DAYS = 7;

    /**
     * Сколько держится замок. Столько же, сколько отпущено самому прогону: если процесс
     * умрёт, не сняв замок, он протухнет сам и кнопка не заблокируется навсегда.
     */
    private const LOCK_SECONDS = 900;

    public function __construct(public readonly int $tenantId) {}

    public static function stateKey(int $tenantId): string
    {
        return "auto-audit:state:{$tenantId}";
    }

    /** Замок на фирму: прогоны разных фирм друг другу не мешают. */
    public static function lockKey(int $tenantId): string
    {
        return "auto-audit:lock:{$tenantId}";
    }

    public static function markRunning(int $tenantId): void
    {
        Cache::put(self::stateKey($tenantId), [
            'status'     => self::RUNNING,
            'started_at' => now()->toIso8601String(),
        ], now()->addDays(self::KEEP_DAYS));
    }

    public function handle(AutoAuditRunner $runner): void
    {
        // Ответ уже ушёл, ждать некому: даём прогону столько времени, сколько ему нужно.
        set_time_limit(self::LOCK_SECONDS);

        try {
            self::perform($this->tenantId, $runner);
        } catch (Throwable) {
            // Состояние и журнал сбоев записаны внутри perform, а отдавать ошибку тут некому:
            // браузер получил ответ ещё до начала работы.
        }
    }

    /**
     * Прогон по фирме под замком: второй такой же по этой же фирме просто не начнётся.
     *
     * Раньше защита была только в контроллере: он смотрел состояние в кеше, а затем ставил
     * отметку «идёт». Между этими двумя шагами ничего не мешало второму запросу пройти ту же
     * проверку, и двойной клик, две вкладки или команда из терминала давали два прогона по
     * одной фирме сразу. Каждый из них сначала стирает все строки фирмы, а потом пишет свои,
     * и на боевом PostgreSQL это способно оставить два комплекта результатов.
     *
     * Замок берёт тот, кто действительно начинает работу, поэтому он один и для кнопки, и
     * для команды `autoaudit:run`.
     *
     * @return array<string, int>|null счётчики исходов, или null, если прогон уже идёт
     * @throws Throwable то, что бросил сам прогон: состояние и журнал сбоев уже записаны
     */
    public static function perform(int $tenantId, AutoAuditRunner $runner): ?array
    {
        $lock = Cache::lock(self::lockKey($tenantId), self::LOCK_SECONDS);

        if (!$lock->get()) {
            return null;
        }

        $key     = self::stateKey($tenantId);
        $started = microtime(true);

        try {
            self::markRunning($tenantId);

            $counts = TenantContext::for($tenantId, fn () => $runner->run());

            Cache::put($key, [
                'status'      => self::DONE,
                'finished_at' => now()->toIso8601String(),
                'seconds'     => round(microtime(true) - $started, 1),
                'counts'      => $counts,
            ], now()->addDays(self::KEEP_DAYS));

            return $counts;
        } catch (Throwable $e) {
            Cache::put($key, [
                'status'      => self::FAILED,
                'finished_at' => now()->toIso8601String(),
                'error'       => $e->getMessage(),
            ], now()->addDays(self::KEEP_DAYS));

            // Упавший прогон должен попасть в журнал сбоев, а не только на страницу.
            report($e);

            throw $e;
        } finally {
            $lock->release();
        }
    }
}
