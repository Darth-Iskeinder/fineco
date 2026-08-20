<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Module;
use App\Models\Role;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Телефон сотрудника: девять национальных цифр на входе, единый вид в базе.
 *
 * Форма отдаёт цифры без кода страны — он подписан у рамки. Но в поле попадает
 * и вставка из мессенджера, и старая запись из базы (её подставляет карточка),
 * поэтому лишнее снимаем, а ошибку показываем только на действительно неполном
 * номере.
 */
class EmployeePhoneTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $admin;

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

        Module::firstOrCreate(['name' => 'employees'], ['display_name' => 'Сотрудники', 'is_active' => true]);

        $this->admin = Employee::create([
            'full_name' => 'Тест Админ', 'position' => 'Админ',
            'email' => uniqid('adm_') . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Администратор'])->id,
            'status' => Employee::STATUS_ACTIVE,
        ]);
    }

    private function employeeRoleId(): int
    {
        return Role::firstOrCreate(['name' => Role::ACCOUNTANT], ['display_name' => 'Бухгалтер'])->id;
    }

    /** @return array<string, mixed> Заполненная форма создания сотрудника. */
    private function form(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Новый Сотрудник',
            'email' => uniqid('new_') . '@test.kg',
            'password' => 'password123',
            'password_confirmation' => 'password123',
            'role_id' => $this->employeeRoleId(),
        ], $overrides);
    }

    public function test_created_employee_keeps_the_number_in_one_shape(): void
    {
        $form = $this->form(['phone' => '779 779 979']);

        $this->actingAs($this->admin, 'employee')
            ->post('/employees', $form)
            ->assertSessionHasNoErrors();

        $this->assertSame('+996 (779) 779-979', Employee::where('email', $form['email'])->value('phone'));
    }

    public function test_pasted_number_with_country_code_is_accepted(): void
    {
        foreach (['+996 779 779 979', '0779779979', '+996 (779) 779-979'] as $pasted) {
            $form = $this->form(['phone' => $pasted]);

            $this->actingAs($this->admin, 'employee')
                ->post('/employees', $form)
                ->assertSessionHasNoErrors();

            $this->assertSame(
                '+996 (779) 779-979',
                Employee::where('email', $form['email'])->value('phone'),
                "Не разобран номер «{$pasted}»",
            );
        }
    }

    public function test_short_number_is_rejected_and_employee_is_not_created(): void
    {
        $form = $this->form(['phone' => '779 779 97']);

        $this->actingAs($this->admin, 'employee')
            ->post('/employees', $form)
            ->assertSessionHasErrors(['phone' => 'Номер телефона — 9 цифр после +996, например 779 779 979']);

        $this->assertFalse(Employee::where('email', $form['email'])->exists());
    }

    public function test_phone_stays_optional(): void
    {
        $form = $this->form(['phone' => '']);

        $this->actingAs($this->admin, 'employee')
            ->post('/employees', $form)
            ->assertSessionHasNoErrors();

        $this->assertNull(Employee::where('email', $form['email'])->value('phone'));
    }

    /** @param array<string, mixed> $overrides */
    private function existingEmployee(array $overrides = []): Employee
    {
        return Employee::create(array_merge([
            'full_name' => 'Действующий Сотрудник',
            'position'  => 'Бухгалтер',
            'email'     => uniqid('old_') . '@test.kg',
            'password'  => bcrypt('x'),
            'role_id'   => $this->employeeRoleId(),
            'status'    => Employee::STATUS_ACTIVE,
        ], $overrides));
    }

    /** Карточка сотрудника: тот же разбор, что и в форме создания. */
    public function test_card_saves_the_number_in_the_same_shape(): void
    {
        $employee = $this->existingEmployee();

        $this->actingAs($this->admin, 'employee')
            ->patchJson('/employees/' . $employee->id, [
                'section' => 'info',
                'full_name' => $employee->full_name,
                'role_id' => $employee->role_id,
                'email' => $employee->email,
                'phone' => '779 779 979',
            ])
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertSame('+996 (779) 779-979', $employee->refresh()->phone);
    }

    /**
     * Неполный номер карточка обязана отдать ошибкой валидации: страница показывает
     * её текст, иначе раздел остаётся открытым и это выглядит как залипшая кнопка.
     */
    public function test_card_reports_a_short_number(): void
    {
        $employee = $this->existingEmployee(['phone' => '+996 (779) 779-979']);

        $this->actingAs($this->admin, 'employee')
            ->patchJson('/employees/' . $employee->id, [
                'section' => 'info',
                'full_name' => $employee->full_name,
                'role_id' => $employee->role_id,
                'email' => $employee->email,
                'phone' => '779 779 97',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['phone' => 'Номер телефона — 9 цифр после +996, например 779 779 979']);

        $this->assertSame('+996 (779) 779-979', $employee->refresh()->phone, 'Номер изменился, хотя сохранение не прошло');
    }
}
