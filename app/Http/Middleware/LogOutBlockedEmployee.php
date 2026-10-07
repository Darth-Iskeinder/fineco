<?php

namespace App\Http\Middleware;

use App\Support\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Выход того, кому больше нельзя работать: уволенного или с закрытой учёткой.
 *
 * Вход это проверяет, но только в момент входа. Кто был в системе, когда его
 * уволили, или вошёл с «Запомнить меня», оставался внутри, пока сам не выйдет.
 * Теперь его выводит на первом же переходе.
 *
 * Вендора, вошедшего в фирму под этим сотрудником, не трогаем: он разбирает
 * данные уволенного, и в систему вошёл он сам, а не уволенный.
 */
class LogOutBlockedEmployee
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Impersonation::isActive()) {
            return $next($request);
        }

        $refusal = Auth::guard('employee')->user()?->signInRefusal();

        if ($refusal === null) {
            return $next($request);
        }

        Auth::guard('employee')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($request->expectsJson()) {
            return response()->json(['message' => $refusal], 401);
        }

        return redirect()->route('login')->withErrors(['email' => $refusal]);
    }
}
