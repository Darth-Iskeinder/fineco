<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Module;
use App\Models\Role;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Уволенный не входит и не остаётся внутри.
 *
 * Вход проверял только, открыта ли учётка, а увольнение оформляют отметкой
 * «Уволен», учётку отдельно никто не закрывал. На бою все трое уволенных могли
 * войти. А кто был в системе в момент увольнения, оставался внутри до выхода.
 */
class FiredEmployeeSignInTest extends TestCase
{
    use DatabaseTransactions;

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

        $role = Role::firstOrCreate(['name' => Role::ACCOUNTANT], ['display_name' => 'Бухгалтер']);
        $module = Module::firstOrCreate(['name' => 'buhtasks'], ['display_name' => 'БухЗадачник', 'is_active' => true]);

        $this->employee = Employee::create([
            'full_name' => 'Тест вход', 'position' => 'Бухгалтер',
            'email' => 'signin_' . uniqid() . '@test.kg', 'password' => bcrypt('secret123'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
        $this->employee->modules()->attach($module->id);
    }

    private function signIn()
    {
        return $this->post('/login', ['email' => $this->employee->email, 'password' => 'secret123']);
    }

    public function test_working_employee_signs_in(): void
    {
        $this->signIn()->assertRedirect();

        $this->assertAuthenticatedAs($this->employee, 'employee');
    }

    /** Учётка открыта, а человек уволен: так увольнение и оформляется. */
    public function test_fired_employee_cannot_sign_in(): void
    {
        $this->employee->update(['employment_status' => Employee::EMPLOYMENT_FIRED]);

        $this->signIn()->assertSessionHasErrors(['email' => 'Доступ закрыт. Обратитесь к руководителю.']);

        $this->assertGuest('employee');
    }

    /** Был в системе, когда уволили: выводит на первом же переходе. */
    public function test_signed_in_employee_is_logged_out_once_fired(): void
    {
        $this->actingAs($this->employee, 'employee')
            ->get(route('buhtasks.index'))
            ->assertOk();

        $this->employee->update(['employment_status' => Employee::EMPLOYMENT_FIRED]);

        $this->actingAs($this->employee->fresh(), 'employee')
            ->get(route('buhtasks.index'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest('employee');
    }

    /** Фоновые запросы страницы получают ответ, а не страницу входа вместо данных. */
    public function test_background_request_of_fired_employee_gets_401(): void
    {
        $this->employee->update(['employment_status' => Employee::EMPLOYMENT_FIRED]);

        $this->actingAs($this->employee, 'employee')
            ->getJson(route('buhtasks.index'))
            ->assertUnauthorized()
            ->assertJson(['message' => 'Доступ закрыт. Обратитесь к руководителю.']);
    }

    public function test_rehired_employee_signs_in_again(): void
    {
        $this->employee->update(['employment_status' => Employee::EMPLOYMENT_FIRED]);
        $this->employee->update(['employment_status' => 'employed']);

        $this->signIn()->assertRedirect();

        $this->assertAuthenticatedAs($this->employee, 'employee');
    }

    /** Вендор, вошедший в фирму под уволенным, разбирает его данные: его не выводим. */
    public function test_vendor_acting_as_fired_employee_stays_in(): void
    {
        $this->employee->update(['employment_status' => Employee::EMPLOYMENT_FIRED]);

        $this->actingAs($this->employee->fresh(), 'employee')
            ->withSession([
                'vendor.tenant_id'   => $this->employee->tenant_id ?? 1,
                'vendor.tenant_name' => 'Тест',
                'vendor.last_seen'   => now()->timestamp,
            ])
            ->get(route('buhtasks.index'))
            ->assertOk();
    }
}
