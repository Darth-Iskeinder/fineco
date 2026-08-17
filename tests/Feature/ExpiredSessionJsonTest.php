<?php

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Ответ на протухшую сессию для фоновых запросов страницы.
 *
 * Кнопки БухЗадачника ходят на сервер через fetch и ждут JSON. Пока истёкший
 * CSRF-токен разворачивался в редирект на форму логина, такой запрос получал
 * HTML: разбор ответа падал, а кнопка «Принять»/«Вернуть» навсегда оставалась
 * в состоянии «загрузка» и переставала нажиматься.
 */
class ExpiredSessionJsonTest extends TestCase
{
    use DatabaseTransactions;

    protected function connectionsToTransact(): array
    {
        return ['mysql'];
    }

    protected function setUpTraits()
    {
        config([
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'erp_fineco',
            'database.connections.mysql.url' => null,
        ]);
        DB::purge('mysql');

        return parent::setUpTraits();
    }

    public function test_expired_csrf_token_returns_json_for_background_requests(): void
    {
        $request = Request::create('/buhtasks/adhoc/1/review-approve', 'POST');
        $request->headers->set('Accept', 'application/json');

        $response = app(ExceptionHandler::class)->render($request, new TokenMismatchException);

        $this->assertSame(419, $response->getStatusCode());
        $this->assertFalse($response->getData(true)['success']);
        $this->assertStringContainsString('Сессия устарела', $response->getData(true)['message']);
    }

    public function test_expired_csrf_token_still_redirects_a_plain_form(): void
    {
        $request = Request::create('/login', 'POST');

        $response = app(ExceptionHandler::class)->render($request, new TokenMismatchException);

        $this->assertTrue($response->isRedirect(route('login')));
    }

    public function test_guest_background_request_gets_json_not_the_login_page(): void
    {
        $response = $this->postJson('/buhtasks/adhoc', []);

        $response->assertUnauthorized();
        $this->assertJson($response->getContent());
    }
}
