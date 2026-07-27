<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Доступ к модулю «Аудит»: пока только руководитель (плюс админ — служебный доступ).
 * Галочка модуля у сотрудника роли не играет: решение временное, роль аудитора
 * появится позже, тогда условие меняется здесь.
 */
class AuditAccessMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $employee = auth('employee')->user();

        if (!$employee) {
            return redirect()->route('login');
        }

        if (!$employee->isManager() && !$employee->isAdmin()) {
            abort(403, 'Модуль «Аудит» доступен руководителю');
        }

        return $next($request);
    }
}
