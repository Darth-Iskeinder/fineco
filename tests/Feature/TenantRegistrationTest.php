<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Регистрация новой бухфирмы.
 *
 * Один роут делает три вещи сразу: заводит аккаунт, копирует в него стартовый
 * набор справочников и создаёт первого администратора. Все три обязаны случиться
 * вместе: аккаунт без справочников — система, в которой нельзя завести клиента,
 * а аккаунт без администратора — данные, к которым никто не может войти.
 */
class TenantRegistrationTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $template;

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

        $this->template = TenantContext::withoutTenant(fn () => Tenant::template()->firstOrFail());
    }

    /** @return array<string, string> Заполненная форма регистрации. */
    private function form(array $overrides = []): array
    {
        $unique = uniqid();

        return array_merge([
            'company_name'          => 'ОсОО Ромашка ' . $unique,
            'full_name'             => 'Иванова Анна Петровна',
            'email'                 => "anna.{$unique}@example.com",
            'phone'                 => '+996 700 123 456',
            'password'              => 'secret123',
            'password_confirmation' => 'secret123',
        ], $overrides);
    }

    private function tenantByName(string $name): ?Tenant
    {
        return TenantContext::withoutTenant(fn () => Tenant::where('name', $name)->first());
    }

    public function test_guest_sees_the_registration_form(): void
    {
        $this->get('/onboarding')
            ->assertOk()
            ->assertSee('Регистрация фирмы');
    }

    public function test_registration_creates_the_account_its_admin_and_signs_him_in(): void
    {
        $form = $this->form();

        $this->post('/onboarding', $form)
            ->assertRedirect('/')
            ->assertSessionHasNoErrors();

        $tenant = $this->tenantByName($form['company_name']);
        $this->assertNotNull($tenant, 'Аккаунт фирмы не создан');
        $this->assertSame(Tenant::STATUS_ACTIVE, $tenant->status, 'Пробного периода нет — аккаунт сразу рабочий');

        $admin = TenantContext::for($tenant, fn () => Employee::where('email', $form['email'])->first());
        $this->assertNotNull($admin, 'Администратор фирмы не создан');
        $this->assertSame($tenant->id, (int) $admin->tenant_id, 'Администратор не привязан к своей фирме');
        $this->assertTrue($admin->isAdmin(), 'Первый сотрудник должен быть администратором');
        $this->assertSame(Employee::STATUS_ACTIVE, $admin->status);

        $this->assertAuthenticatedAs($admin, 'employee');
    }

    public function test_new_account_gets_the_starter_catalog(): void
    {
        $marker = 'Метка образца ' . uniqid();

        DB::table('billings')->insert([
            'tenant_id'  => $this->template->id,
            'name'       => $marker,
            'code'       => 'included',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $form = $this->form();
        $this->post('/onboarding', $form)->assertSessionHasNoErrors();

        $tenant = $this->tenantByName($form['company_name']);

        $this->assertTrue(
            DB::table('billings')->where('tenant_id', $tenant->id)->where('name', $marker)->exists(),
            'Новый аккаунт не получил стартовый набор из образца — работать в нём не с чем',
        );
    }

    public function test_new_account_starts_without_other_firms_data(): void
    {
        $form = $this->form();
        $this->post('/onboarding', $form)->assertSessionHasNoErrors();

        $tenant = $this->tenantByName($form['company_name']);

        $this->assertSame(
            0,
            TenantContext::for($tenant, fn () => Client::count()),
            'В новом аккаунте видны чужие клиенты',
        );
    }

    public function test_taken_email_is_explained_and_nothing_is_created(): void
    {
        // Почта — это логин, она одна на всю систему. Значит действующий сотрудник
        // чужой фирмы не сможет зарегистрировать свою тем же адресом, и об этом
        // надо сказать прямо, а не отдать сухое «занято».
        $existing = Employee::create([
            'full_name' => 'Уже работает',
            'position'  => 'Бухгалтер',
            'email'     => 'busy.' . uniqid() . '@example.com',
            'password'  => 'secret123',
            'role_id'   => Role::where('name', Role::ADMIN)->value('id'),
            'status'    => Employee::STATUS_ACTIVE,
        ]);

        $before = TenantContext::withoutTenant(fn () => Tenant::count());
        $form   = $this->form(['email' => $existing->email]);

        $this->post('/onboarding', $form)
            ->assertSessionHasErrors(['email' => 'Этот email уже используется в системе. Зарегистрируйтесь с другого адреса.']);

        $this->assertNull($this->tenantByName($form['company_name']), 'Аккаунт создался при занятой почте');
        $this->assertSame($before, TenantContext::withoutTenant(fn () => Tenant::count()));
        $this->assertGuest('employee');
    }

    public function test_passwords_must_match(): void
    {
        $form = $this->form(['password_confirmation' => 'another123']);

        $this->post('/onboarding', $form)->assertSessionHasErrors('password');

        $this->assertNull($this->tenantByName($form['company_name']));
        $this->assertGuest('employee');
    }

    public function test_two_firms_with_the_same_name_get_different_addresses(): void
    {
        $name = 'ОсОО Ромашка ' . uniqid();

        $this->post('/onboarding', $this->form(['company_name' => $name]))->assertSessionHasNoErrors();
        $first = $this->tenantByName($name);

        // Второй регистрируется с чистой сессии — как другой человек в другом браузере.
        $this->flushSession();
        $this->post('/logout');

        $this->post('/onboarding', $this->form(['company_name' => $name]))->assertSessionHasNoErrors();

        $slugs = TenantContext::withoutTenant(fn () => Tenant::where('name', $name)->pluck('slug'));

        $this->assertCount(2, $slugs, 'Вторая фирма с тем же названием не зарегистрировалась');
        $this->assertCount(2, $slugs->unique(), "Адреса аккаунтов совпали: {$slugs->implode(', ')}");
        $this->assertNotNull($first);
    }

    /** В боковой колонке — название своей фирмы: иначе непонятно, в чьих данных находишься. */
    public function test_sidebar_shows_the_name_of_your_own_firm(): void
    {
        $form = $this->form();
        $this->post('/onboarding', $form)->assertSessionHasNoErrors();

        $this->get('/employees')
            ->assertOk()
            ->assertSee($form['company_name'], false);
    }

    public function test_logged_in_employee_is_sent_home_from_the_registration_page(): void
    {
        $employee = Employee::query()->where('status', Employee::STATUS_ACTIVE)->firstOrFail();

        $this->actingAs($employee, 'employee')
            ->get('/onboarding')
            ->assertRedirect('/');
    }
}
