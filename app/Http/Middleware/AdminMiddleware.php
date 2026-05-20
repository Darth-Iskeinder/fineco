<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $employee = auth('employee')->user();

        if (!$employee) {
            return redirect()->route('login');
        }

        if (!$employee->isAdmin()) {
            abort(403, 'Доступ только для администраторов');
        }

        return $next($request);
    }
}
