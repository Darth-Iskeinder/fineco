<?php

namespace App\Http\Middleware;

use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Автовыход из чужой фирмы после получаса простоя.
 *
 * Идёт раньше SetTenantContext: если дверь пора закрыть, надо сначала выйти из
 * фирмы и только потом определять, чья она — иначе запрос успеет отработать от
 * имени фирмы, из которой вендора уже выгнали.
 */
class EndIdleImpersonation
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!Impersonation::isActive()) {
            return $next($request);
        }

        if (Impersonation::isIdleTooLong()) {
            Impersonation::stop();

            return redirect()
                ->route('vendor.index')
                ->with('status', 'Вы вышли из аккаунта: полчаса без действий.');
        }

        Impersonation::touch();

        return $next($request);
    }
}
