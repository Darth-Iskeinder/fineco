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
 *
 * Фирма проставляется на КАЖДОМ запросе, в том числе гостевому — там пусто.
 * Это важнее, чем кажется: под Octane процесс живёт дальше, и если оставить
 * значение от прошлого запроса, чужая фирма протекла бы в следующий. Поэтому
 * не «поставить, если есть», а «поставить всегда, хоть и пусто».
 */
class SetTenantContext
{
    public function handle(Request $request, Closure $next): Response
    {
        $employee = auth('employee')->user();

        TenantContext::set($employee?->tenant_id ? (int) $employee->tenant_id : null);

        return $next($request);
    }
}
