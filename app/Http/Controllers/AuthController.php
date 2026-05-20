<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function showLoginForm()
    {
        if (Auth::guard('employee')->check()) {
            return redirect('/');
        }

        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ], [
            'email.required' => 'Введите email',
            'email.email' => 'Введите корректный email',
            'password.required' => 'Введите пароль',
        ]);

        $remember = $request->boolean('remember');

        if (Auth::guard('employee')->attempt($credentials, $remember)) {
            $employee = Auth::guard('employee')->user();

            if (!$employee->isActive()) {
                Auth::guard('employee')->logout();

                return back()
                    ->withInput($request->only('email', 'remember'))
                    ->withErrors(['email' => 'Ваш аккаунт неактивен. Обратитесь к администратору.']);
            }

            $request->session()->regenerate();

            return redirect()->intended('/');
        }

        return back()
            ->withInput($request->only('email', 'remember'))
            ->withErrors(['email' => 'Неверный email или пароль']);
    }

    public function logout(Request $request)
    {
        Auth::guard('employee')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
