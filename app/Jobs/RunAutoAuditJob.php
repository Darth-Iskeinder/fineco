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

    public function __construct(public readonly int $tenantId) {}

    public static function stateKey(int $tenantId): string
    {
        return "auto-audit:state:{$tenantId}";
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
        set_time_limit(900);

        $key     = self::stateKey($this->tenantId);
        $started = microtime(true);

        try {
            $counts = TenantContext::for($this->tenantId, fn () => $runner->run());

            Cache::put($key, [
                'status'      => self::DONE,
                'finished_at' => now()->toIso8601String(),
                'seconds'     => round(microtime(true) - $started, 1),
                'counts'      => $counts,
            ], now()->addDays(self::KEEP_DAYS));
        } catch (Throwable $e) {
            Cache::put($key, [
                'status'      => self::FAILED,
                'finished_at' => now()->toIso8601String(),
                'error'       => $e->getMessage(),
            ], now()->addDays(self::KEEP_DAYS));

            // Упавший прогон должен попасть в журнал сбоев, а не только на страницу.
            report($e);
        }
    }
}
