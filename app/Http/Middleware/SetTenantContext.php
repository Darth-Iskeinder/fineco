<?php

namespace App\Http\Middleware;

use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ставит текущую фирму на время запроса — из авторизованного сотрудника.
 *
 * Идёт после сессии, поэтому к моменту его работы сотрудник уже известен.
 * Гость проходит мимо: на странице логина фильтровать нечего.
 *
 * Контекст снимается после ответа. В обычном PHP это не обязательно (процесс
 * умирает), но под Octane или в очереди процесс живёт дальше, и чужая фирма
 * протекла бы в следующий запрос.
 */
class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $employee = auth('employee')->user();

        if ($employee && $employee->tenant_id) {
            TenantContext::set((int) $employee->tenant_id);
        }

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        TenantContext::forget();
    }
}
