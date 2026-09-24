<?php

namespace App\Jobs;

use App\Services\AutoAudit\AutoAuditRunner;
use App\Support\TenantContext;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Прогон автоаудита по фирме под замком и его состояние для страницы.
 *
 * Запускает его только команда `autoaudit:run`. Раньше была ещё кнопка «Проверить сейчас»,
 * она гоняла прогон после ответа браузеру (отсюда имя класса). Кнопку убрали, когда
 * страницу решили открыть руководителю: запуск остался за вендором, в терминале.
 *
 * Состояние прогона лежит в кеше по фирме: идёт, готово или упала. Его показывает страница.
 */
class RunAutoAuditJob
{
    public const RUNNING = 'running';
    public const DONE    = 'done';
    public const FAILED  = 'failed';

    /** Сколько помнить состояние: страница показывает его до следующего прогона. */
    private const KEEP_DAYS = 7;

    /**
     * Сколько держится замок. С большим запасом: на бою прогон по Fineco идёт меньше
     * минуты (замер 23.09.2026). Если процесс умрёт, не сняв замок, он протухнет сам и
     * запуск не заблокируется навсегда.
     */
    private const LOCK_SECONDS = 900;

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

    /**
     * Прогон по фирме под замком: второй такой же по этой же фирме просто не начнётся.
     *
     * Раньше защита была только в контроллере: он смотрел состояние в кеше, а затем ставил
     * отметку «идёт». Между этими двумя шагами ничего не мешало второму запросу пройти ту же
     * проверку, и двойной клик, две вкладки или команда из терминала давали два прогона по
     * одной фирме сразу. Каждый из них сверяет строки фирмы со своими и дописывает разницу,
     * и на боевом PostgreSQL это способно оставить два комплекта результатов.
     *
     * Замок берёт тот, кто действительно начинает работу. Кнопки больше нет, но замок
     * остался: две команды из двух терминалов так же дали бы два прогона сразу.
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
