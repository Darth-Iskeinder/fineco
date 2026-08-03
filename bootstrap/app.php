<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\AuditAccessMiddleware;
use App\Http\Middleware\CheckModuleAccess;
use App\Http\Middleware\EndIdleImpersonation;
use App\Http\Middleware\ManagerMiddleware;
use App\Http\Middleware\SetTenantContext;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Гостя отправляем на «свой» вход: у вендора он отдельный.
        $middleware->redirectGuestsTo(
            fn (Illuminate\Http\Request $request) => $request->is('vendor', 'vendor/*')
                ? '/vendor/login'
                : '/login'
        );

        // Текущая фирма ставится на каждый веб-запрос. Порядок здесь важнее,
        // чем кажется, и обойтись обычным append нельзя:
        //   - раньше сессии — сотрудник ещё неизвестен, ставить нечего;
        //   - позже SubstituteBindings — модель по ссылке /clients/{id} уже
        //     достанут из базы без фильтра, и чужой клиент откроется.
        // Поэтому вынимаем SubstituteBindings из группы и возвращаем следом
        // за собой: сессия → текущая фирма → разбор ссылок.
        $middleware->web(remove: [
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);
        // EndIdleImpersonation стоит перед SetTenantContext: если вендора пора
        // выставить из чужой фирмы, сделать это надо до того, как запрос начнёт
        // работать от её имени.
        $middleware->web(append: [
            EndIdleImpersonation::class,
            SetTenantContext::class,
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
        ]);

        $middleware->alias([
            'module' => CheckModuleAccess::class,
            'admin' => AdminMiddleware::class,
            'manager' => ManagerMiddleware::class,
            'audit-access' => AuditAccessMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Истёкший CSRF-токен (419 Page Expired): не показываем страницу ошибки,
        // а возвращаем пользователя на форму логина со свежим токеном.
        $exceptions->render(function (\Illuminate\Session\TokenMismatchException $e, $request) {
            return redirect()
                ->route('login')
                ->withInput($request->except('_token', 'password'))
                ->withErrors(['email' => 'Сессия устарела. Пожалуйста, войдите снова.']);
        });
    })->create();
