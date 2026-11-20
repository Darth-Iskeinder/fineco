<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Копит время страниц в page_timings, чтобы знать, что реально тормозит у людей.
 *
 * Правило то же, что у ErrorReporter: счётчик никогда не мешает. Любая ошибка
 * внутри гасится молча, страница от него не ломается. Пишем уже после того, как
 * ответ ушёл человеку (terminate), поэтому ждать его никто не будет.
 *
 * Выключатель: файл storage/framework/page-timing-off (команда page-timing --off).
 * Файл, а не настройка в базе: проверка на каждый запрос должна быть бесплатной.
 */
class PageTimer
{
    public const FAST_MS = 300;
    public const OK_MS   = 1000;

    public static function offFlag(): string
    {
        return storage_path('framework/page-timing-off');
    }

    public static function enabled(): bool
    {
        return !is_file(self::offFlag());
    }

    /** Сколько запросов к базе и сколько мс на них с начала текущего открытия. */
    public static int $queries = 0;
    public static float $dbMs = 0.0;

    /**
     * Обнулить счёт базы перед открытием и слушать запросы. Слушатель ставим один
     * раз на приложение (в тестах их много подряд, у каждого свой диспетчер).
     */
    public static function startQueryCount(): void
    {
        self::$queries = 0;
        self::$dbMs = 0.0;

        try {
            if (!app()->bound(self::class . '.listening')) {
                app()->instance(self::class . '.listening', true);

                DB::listen(function ($query) {
                    self::$queries++;
                    self::$dbMs += (float) $query->time;
                });
            }
        } catch (Throwable $ignored) {
        }
    }

    /** Засчитать одно открытие. route это шаблон адреса (clients/{client}), а не сам адрес. */
    public static function record(int $tenantId, string $method, string $route, int $ms, int $queries = 0, int $dbMs = 0): void
    {
        try {
            $key = [
                'day'       => now()->toDateString(),
                'tenant_id' => $tenantId,
                'method'    => substr($method, 0, 8),
                'route'     => substr($route, 0, 191),
            ];

            $add = [
                'hits'       => 1,
                'total_ms'   => $ms,
                'under_300'  => $ms < self::FAST_MS ? 1 : 0,
                'under_1000' => $ms < self::OK_MS ? 1 : 0,
                'total_queries' => $queries,
                'total_db_ms'   => $dbMs,
            ];

            // Сначала прибавляем к строке дня: это почти всегда так. incrementEach
            // сам квотирует имена колонок, запрос одинаков для MySQL и PostgreSQL.
            if (DB::table('page_timings')->where($key)->incrementEach($add) === 0) {
                try {
                    DB::table('page_timings')->insert($key + $add + ['max_ms' => $ms]);

                    return;
                } catch (Throwable $raced) {
                    // Два первых открытия за день пришли одновременно: второй упёрся
                    // в уникальный ключ. Строка уже есть, просто прибавляем.
                    DB::table('page_timings')->where($key)->incrementEach($add);
                }
            }

            DB::table('page_timings')->where($key)->where('max_ms', '<', $ms)->update(['max_ms' => $ms]);
        } catch (Throwable $ignored) {
            // Нет таблицы, нет базы: счётчику сейчас не время, страница важнее.
        }
    }
}
