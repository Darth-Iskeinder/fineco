<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\ErrorReport;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\VendorUser;
use App\Support\ErrorReporter;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

/**
 * Журнал сбоев: узнавать о поломке раньше, чем о ней сообщит клиент.
 *
 * Заведён после ошибки «Unexpected end of JSON input» при создании задачи из
 * каталога — тогда в логах не оказалось ничего, потому что сбой случился в
 * браузере, а браузерные ошибки не собирались вовсе.
 *
 * Проверяем три вещи: что настоящие поломки в журнал попадают, что обычная
 * жизнь приложения (404, отказ доступа, валидация) его не засоряет и что
 * читает журнал только владелец системы.
 */
class ErrorJournalTest extends TestCase
{
    use DatabaseTransactions;

    private VendorUser $vendor;
    private Employee $employee;

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

    protected function setUp(): void
    {
        parent::setUp();

        $this->vendor = VendorUser::create([
            'name'     => 'Владелец',
            'email'    => 'owner.' . uniqid() . '@example.com',
            'password' => 'secret123',
        ]);

        $role = Role::firstOrCreate(['name' => Role::ACCOUNTANT], ['display_name' => 'Бухгалтер']);

        $this->employee = Employee::create([
            'full_name' => 'Тест Сотрудник', 'position' => 'Бухгалтер',
            'email' => 'err_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
    }

    // === Что попадает в журнал ===

    public function test_server_exception_is_recorded(): void
    {
        ErrorReporter::server(new RuntimeException('Всё сломалось'));

        $report = ErrorReport::latest('id')->first();

        $this->assertSame(ErrorReport::KIND_SERVER, $report->kind);
        $this->assertStringContainsString('Всё сломалось', $report->message);
        $this->assertSame(500, $report->status);
        $this->assertSame(1, $report->count);
    }

    /** Проводка через bootstrap/app.php: падение настоящего запроса, а не прямой вызов. */
    public function test_failing_request_reaches_the_journal(): void
    {
        Route::get('/__boom', fn () => throw new RuntimeException('Упало в запросе'));

        $before = ErrorReport::count();

        $this->get('/__boom')->assertStatus(500);

        $this->assertSame($before + 1, ErrorReport::count());
        $this->assertStringContainsString('Упало в запросе', ErrorReport::latest('id')->first()->message);
    }

    public function test_repeat_increments_the_counter_instead_of_adding_a_row(): void
    {
        $before = ErrorReport::count();

        // Одно и то же исключение: отпечаток считается в том числе по месту,
        // где оно возникло, а три `new` в трёх строках — это три разных места.
        $e = new RuntimeException('Повторяющийся сбой');

        ErrorReporter::server($e);
        ErrorReporter::server($e);
        ErrorReporter::server($e);

        $this->assertSame($before + 1, ErrorReport::count());
        $this->assertSame(3, ErrorReport::latest('id')->first()->count);
    }

    /** Разобранная ошибка вернулась — снова в работу, иначе её больше никто не увидит. */
    public function test_repeat_reopens_a_resolved_error(): void
    {
        $e = new RuntimeException('Вернувшийся сбой');

        ErrorReporter::server($e);
        $report = ErrorReport::latest('id')->first();
        $report->update(['resolved_at' => now()]);

        ErrorReporter::server($e);

        $this->assertNull($report->fresh()->resolved_at);
    }

    // === Что журнал не засоряет ===

    public function test_routine_http_errors_are_not_recorded(): void
    {
        $before = ErrorReport::count();

        ErrorReporter::server(new NotFoundHttpException('Нет такой страницы'));
        ErrorReporter::server(new \Illuminate\Auth\AuthenticationException());
        ErrorReporter::server(\Illuminate\Validation\ValidationException::withMessages(['e' => 'плохо']));

        $this->assertSame($before, ErrorReport::count());
    }

    // === Ошибки из браузера ===

    public function test_browser_error_is_accepted_from_the_page(): void
    {
        $this->actingAs($this->employee, 'employee')
            ->postJson(route('client-errors.store'), [
                'message' => 'Сервер ответил не JSON (код 502)',
                'source'  => '/buhtasks/adhoc',
                'url'     => 'https://erp.test/buhtasks',
                'status'  => 502,
                'context' => '(пустое тело ответа)',
            ])
            ->assertOk();

        $report = ErrorReport::latest('id')->first();

        $this->assertSame(ErrorReport::KIND_BROWSER, $report->kind);
        $this->assertSame('Сервер ответил не JSON (код 502)', $report->message);
        $this->assertSame(502, $report->status);
        $this->assertSame($this->employee->id, $report->employee_id);
    }

    public function test_guest_cannot_send_browser_errors(): void
    {
        $this->postJson(route('client-errors.store'), ['message' => 'Чужой'])
            ->assertStatus(401);
    }

    // === Кто видит журнал ===

    public function test_vendor_sees_the_journal(): void
    {
        ErrorReporter::server(new RuntimeException('Видно владельцу'));

        $this->actingAs($this->vendor, 'vendor')
            ->get(route('vendor.errors.index'))
            ->assertOk()
            ->assertSee('Видно владельцу', false);
    }

    public function test_employee_cannot_open_the_journal(): void
    {
        $this->actingAs($this->employee, 'employee')
            ->get(route('vendor.errors.index'))
            ->assertRedirect('/vendor/login');
    }

    public function test_vendor_can_resolve_and_reopen(): void
    {
        ErrorReporter::server(new RuntimeException('Разберём и вернём'));
        $report = ErrorReport::latest('id')->first();

        $this->actingAs($this->vendor, 'vendor')
            ->post(route('vendor.errors.resolve', $report))
            ->assertRedirect();
        $this->assertNotNull($report->fresh()->resolved_at);

        $this->actingAs($this->vendor, 'vendor')
            ->post(route('vendor.errors.reopen', $report))
            ->assertRedirect();
        $this->assertNull($report->fresh()->resolved_at);
    }

    /** Разобранные из ленты уходят, но по ссылке остаются доступны. */
    public function test_resolved_are_hidden_by_default(): void
    {
        ErrorReporter::server(new RuntimeException('Уже разобрано'));
        ErrorReport::latest('id')->first()->update(['resolved_at' => now()]);

        $this->actingAs($this->vendor, 'vendor')
            ->get(route('vendor.errors.index'))
            ->assertDontSee('Уже разобрано', false);

        $this->actingAs($this->vendor, 'vendor')
            ->get(route('vendor.errors.index', ['resolved' => 1]))
            ->assertSee('Уже разобрано', false);
    }
}
