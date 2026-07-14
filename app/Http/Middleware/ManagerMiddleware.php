<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ManagerMiddleware
{
    /** Доступ строго для роли «Руководитель» (в т.ч. админ не проходит). */
    public function handle(Request $request, Closure $next): Response
    {
        $employee = auth('employee')->user();

        if (!$employee) {
            return redirect()->route('login');
        }

        if (!$employee->isManager()) {
            abort(403, 'Доступ только для руководителя');
        }

        return $next($request);
    }
}
