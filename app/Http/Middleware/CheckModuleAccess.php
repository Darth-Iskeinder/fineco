<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckModuleAccess
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        $employee = auth('employee')->user();

        if (!$employee) {
            return redirect()->route('login');
        }

        if (!$employee->hasAccessToModule($module)) {
            abort(403, 'У вас нет доступа к этому модулю');
        }

        return $next($request);
    }
}
