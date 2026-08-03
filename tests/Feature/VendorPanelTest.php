<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\VendorUser;
use App\Support\Impersonation;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Панель владельца системы: увидеть все фирмы и зайти в любую из них.
 *
 * Главное, что здесь проверяется, — что вторая проходная не стала дырой в
 * первой: сотрудник фирмы в панель не попадает, а гость не попадает никуда.
 */
class VendorPanelTest extends TestCase
{
    use DatabaseTransactions;

    private VendorUser $vendor;
    private Tenant $tenant;
    private Employee $tenantAdmin;

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

        // Отдельная фирма со своим администратором — в неё и будем заходить.
        $this->tenant = Tenant::create([
            'name'   => 'Фирма для панели ' . uniqid(),
            'slug'   => 'panel-' . uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->tenantAdmin = TenantContext::for($this->tenant, fn () => Employee::create([
            'full_name' => 'Админ чужой фирмы',
            'position'  => 'Администратор',
            'email'     => 'admin.' . uniqid() . '@example.com',
            'password'  => 'secret123',
            'role_id'   => Role::where('name', Role::ADMIN)->value('id'),
            'status'    => Employee::STATUS_ACTIVE,
        ]));
    }

    public function test_guest_is_sent_to_the_vendor_login_not_the_employee_one(): void
    {
        $this->get('/vendor')->assertRedirect('/vendor/login');
    }

    public function test_employee_password_does_not_open_the_vendor_panel(): void
    {
        // Сотрудник фирмы — даже администратор — в панель владельца не входит:
        // это разные списки людей.
        $this->post('/vendor/login', [
            'email'    => $this->tenantAdmin->email,
            'password' => 'secret123',
        ])->assertSessionHasErrors('email');

        $this->assertGuest('vendor');
    }

    public function test_logged_in_employee_cannot_open_the_vendor_panel(): void
    {
        $this->actingAs($this->tenantAdmin, 'employee')
            ->get('/vendor')
            ->assertRedirect('/vendor/login');
    }

    public function test_vendor_signs_in_and_sees_every_firm(): void
    {
        $this->post('/vendor/login', [
            'email'    => $this->vendor->email,
            'password' => 'secret123',
        ])->assertRedirect(route('vendor.index'));

        $this->get('/vendor')
            ->assertOk()
            ->assertSee($this->tenant->name)
            // Первая фирма системы тоже в списке — вендор видит всех.
            ->assertSee(TenantContext::withoutTenant(fn () => Tenant::real()->orderBy('id')->first()->name));
    }

    public function test_template_account_is_hidden_from_the_list(): void
    {
        $template = TenantContext::withoutTenant(fn () => Tenant::template()->firstOrFail());

        $this->actingAs($this->vendor, 'vendor')
            ->get('/vendor')
            ->assertOk()
            ->assertDontSee($template->name);
    }

    public function test_vendor_enters_a_firm_without_knowing_its_password(): void
    {
        $this->actingAs($this->vendor, 'vendor')
            ->post(route('vendor.enter', $this->tenant))
            ->assertRedirect('/');

        // Внутри фирмы вендор работает её администратором.
        $this->assertAuthenticatedAs($this->tenantAdmin, 'employee');
        $this->assertAuthenticatedAs($this->vendor, 'vendor');
        $this->assertSame($this->tenant->id, Impersonation::tenantId());
    }

    public function test_inside_a_firm_the_warning_strip_is_visible(): void
    {
        $this->actingAs($this->vendor, 'vendor')
            ->post(route('vendor.enter', $this->tenant));

        $this->get('/employees')
            ->assertOk()
            ->assertSee('Вы работаете в аккаунте «' . $this->tenant->name . '»', false);
    }

    public function test_leaving_a_firm_keeps_the_vendor_signed_in(): void
    {
        $this->actingAs($this->vendor, 'vendor')
            ->post(route('vendor.enter', $this->tenant));

        $this->post(route('vendor.leave'))->assertRedirect(route('vendor.index'));

        $this->assertGuest('employee');
        $this->assertAuthenticatedAs($this->vendor, 'vendor');
        $this->assertNull(Impersonation::tenantId());
    }

    public function test_half_an_hour_of_idling_closes_the_door(): void
    {
        $this->actingAs($this->vendor, 'vendor')
            ->post(route('vendor.enter', $this->tenant));

        // Отошёл от компьютера с открытой чужой фирмой.
        $this->travel(Impersonation::IDLE_MINUTES + 1)->minutes();

        $this->get('/employees')->assertRedirect(route('vendor.index'));

        $this->assertGuest('employee');
        $this->assertAuthenticatedAs($this->vendor, 'vendor');
    }

    public function test_vendor_logout_also_leaves_the_firm(): void
    {
        $this->actingAs($this->vendor, 'vendor')
            ->post(route('vendor.enter', $this->tenant));

        $this->post(route('vendor.logout'))->assertRedirect(route('vendor.login'));

        $this->assertGuest('employee');
        $this->assertGuest('vendor');
    }

    public function test_firm_without_an_active_admin_says_so_instead_of_breaking(): void
    {
        $orphan = Tenant::create([
            'name'   => 'Фирма без админа ' . uniqid(),
            'slug'   => 'orphan-' . uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->actingAs($this->vendor, 'vendor')
            ->from(route('vendor.index'))
            ->post(route('vendor.enter', $orphan))
            ->assertRedirect(route('vendor.index'))
            ->assertSessionHasErrors('tenant');

        $this->assertGuest('employee');
    }
}
