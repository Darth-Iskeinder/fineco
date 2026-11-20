<?php

namespace App\Http\Middleware;

use App\Support\PageTimer;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Засекает, сколько сервер готовил ответ, и отдаёт цифру PageTimer.
 *
 * Время считаем от LARAVEL_START (самое начало index.php), то есть вместе с запуском
 * фреймворка: человек ждёт и его. Запись в базу в terminate, когда страница уже ушла.
 */
class TimePage
{
    private const ATTR = 'page_timing';

    public function handle(Request $request, Closure $next): Response
    {
        // Запросы к базе считаем с этого места: всё, что раньше (запуск
        // фреймворка), к базе почти не ходит.
        PageTimer::startQueryCount();

        $response = $next($request);

        try {
            if (PageTimer::enabled() && ($route = $request->route())) {
                $start = defined('LARAVEL_START') ? LARAVEL_START : $request->server('REQUEST_TIME_FLOAT');

                $request->attributes->set(self::ATTR, [
                    'tenant' => TenantContext::has() ? (int) TenantContext::id() : 0,
                    'method' => $request->method(),
                    'route'  => $route->uri(),
                    'ms'     => (int) round((microtime(true) - (float) $start) * 1000),
                    // Снимок до записи в terminate: её собственные запросы не в счёт.
                    'queries' => PageTimer::$queries,
                    'db_ms'   => (int) round(PageTimer::$dbMs),
                ]);
            }
        } catch (Throwable $ignored) {
        }

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        $t = $request->attributes->get(self::ATTR);

        if (is_array($t)) {
            PageTimer::record($t['tenant'], $t['method'], $t['route'], $t['ms'], $t['queries'], $t['db_ms']);
        }
    }
}
