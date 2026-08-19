<?php

use App\Http\Middleware\AdminMiddleware;
use App\Http\Middleware\AuditAccessMiddleware;
use App\Http\Middleware\CheckModuleAccess;
use App\Http\Middleware\EndIdleImpersonation;
use App\Http\Middleware\ManagerMiddleware;
use App\Http\Middleware\SetTenantContext;
use App\Support\ErrorReporter;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

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
        // Каждое исключение — ещё и строкой в журнал сбоев (/vendor/errors).
        // Файловый лог остаётся на месте, но лежит он на сервере, и заглядывает
        // туда кто-то только после жалобы клиента. Журнал видно сразу и всем,
        // кто обслуживает систему. Шум (404, 403, валидация) ErrorReporter
        // отсеивает сам, и сам же молчит, если записать не вышло.
        $exceptions->report(function (Throwable $e) {
            ErrorReporter::server($e, request());
        });

        // Истёкший CSRF-токен (419 Page Expired).
        //
        // Ловим именно HttpException со статусом 419, а не TokenMismatchException:
        // фреймворк подменяет её на HttpException в prepareException() ещё до того,
        // как дело дойдёт до этих колбэков, поэтому обработчик по исходному классу
        // молча не срабатывал вовсе.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }

            // Фоновый запрос со страницы (кнопки задач ходят через fetch) ждёт JSON:
            // страницу ошибки или редирект на форму логина он разобрать не может,
            // и кнопка остаётся в состоянии «загрузка» навсегда.
            if ($request->expectsJson()) {
                return response()->json([
                    'success' => false,
                    'message' => 'Сессия устарела. Обновите страницу и войдите заново.',
                ], 419);
            }

            // Обычная форма: не показываем страницу ошибки, а возвращаем
            // пользователя на вход со свежим токеном.
            return redirect()
                ->route('login')
                ->withInput($request->except('_token', 'password'))
                ->withErrors(['email' => 'Сессия устарела. Пожалуйста, войдите снова.']);
        });
    })->create();
