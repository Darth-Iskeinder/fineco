<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Support\Impersonation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Вход для владельца системы. Отдельный от входа сотрудников: здесь свой
 * список людей, и попасть в него можно только командой в терминале.
 */
class VendorAuthController extends Controller
{
    public function showLoginForm()
    {
        if (Auth::guard('vendor')->check()) {
            return redirect()->route('vendor.index');
        }

        return response()
            ->view('vendor-panel.login')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required'],
        ], [
            'email.required'    => 'Введите email',
            'email.email'       => 'Введите корректный email',
            'password.required' => 'Введите пароль',
        ]);

        if (!Auth::guard('vendor')->attempt($credentials)) {
            return back()
                ->withInput($request->only('email'))
                ->withErrors(['email' => 'Неверный email или пароль']);
        }

        $request->session()->regenerate();

        return redirect()->route('vendor.index');
    }

    public function logout(Request $request)
    {
        // Если вендор сидел внутри чужой фирмы — выходим и оттуда, иначе после
        // выхода остался бы висеть вход сотрудником этой фирмы.
        Impersonation::stop();

        Auth::guard('vendor')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('vendor.login');
    }
}
