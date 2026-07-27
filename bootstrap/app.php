<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\AuditAccessMiddleware;
use App\Http\Middleware\CheckModuleAccess;
use App\Http\Middleware\ManagerMiddleware;
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
        $middleware->redirectGuestsTo('/login');
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
