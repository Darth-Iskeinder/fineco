<?php

namespace App\Http\Controllers;

use App\Services\TenantRegistrar;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Регистрация бухфирмы — вход в систему для нового аккаунта.
 *
 * Роут публичный: фирмы пока заводим сами, подтверждения заявки нет
 * (решение 03.08.2026). Вендорская проходная здесь не нужна — регистрация
 * происходит до всякой авторизации.
 */
class RegisterController extends Controller
{
    public function showForm()
    {
        if (Auth::guard('employee')->check()) {
            return redirect('/');
        }

        // Как и на входе: не даём браузеру подставить форму со старым CSRF-токеном
        // при возврате «Назад».
        return response()
            ->view('auth.register')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function register(Request $request, TenantRegistrar $registrar)
    {
        $data = $request->validate([
            'company_name' => ['required', 'string', 'min:2', 'max:255'],
            'full_name'    => ['required', 'string', 'min:2', 'max:255'],
            // Почта — это логин, поэтому она уникальна на всю систему, а не в
            // пределах фирмы. Из-за этого действующий сотрудник чужой фирмы не
            // сможет зарегистрировать свою тем же адресом — говорим об этом прямо,
            // иначе он видит «почта занята» и не понимает, кем именно.
            'email'        => ['required', 'email', 'max:255', 'unique:employees,email'],
            'phone'        => ['nullable', 'string', 'max:50'],
            'password'     => ['required', 'string', 'min:8', 'confirmed'],
            // Кем становится тот, кто регистрирует фирму: с галочкой —
            // руководитель (у него, сверх прав админа, свой дашборд), без неё —
            // администратор.
            'as_manager'   => ['nullable', 'boolean'],
        ], [
            'company_name.required' => 'Введите название фирмы',
            'company_name.min'      => 'Название фирмы должно быть минимум 2 символа',
            'full_name.required'    => 'Введите ваше ФИО',
            'full_name.min'         => 'ФИО должно быть минимум 2 символа',
            'email.required'        => 'Введите email',
            'email.email'           => 'Введите корректный email',
            'email.unique'          => 'Этот email уже используется в системе. Зарегистрируйтесь с другого адреса.',
            'password.required'     => 'Введите пароль',
            'password.min'          => 'Пароль должен быть минимум 8 символов',
            'password.confirmed'    => 'Пароли не совпадают',
        ]);

        $data['as_manager'] = $request->boolean('as_manager');

        $owner = $registrar->register($data);

        Auth::guard('employee')->login($owner);
        $request->session()->regenerate();

        return redirect('/');
    }
}
