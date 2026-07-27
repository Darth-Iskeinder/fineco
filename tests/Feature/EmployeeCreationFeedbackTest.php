<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Module;
use App\Models\Role;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Обратная связь при создании сотрудника: раньше форма при ошибке валидации молча
 * возвращала на список — сотрудник не создавался, и выглядело это как «создал,
 * а его нет в списке». Проверяем, что ошибка видна и введённое не теряется.
 */
class EmployeeCreationFeedbackTest extends TestCase
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
        \Illuminate\Support\Facades\DB::purge('mysql');

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
        return Role::firstOrCreate(['name' => 'employee'], ['display_name' => 'Сотрудник'])->id;
    }

    public function test_password_mismatch_shows_error_and_keeps_input(): void
    {
        $email = uniqid('new_') . '@test.kg';

        $this->actingAs($this->admin, 'employee')
            ->post('/employees', [
                'full_name' => 'Новый Сотрудник',
                'email' => $email,
                'password' => 'password123',
                'password_confirmation' => 'password124',
                'role_id' => $this->employeeRoleId(),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('password');

        $this->assertFalse(Employee::where('email', $email)->exists());
    }

    public function test_list_page_renders_validation_errors_and_reopens_form(): void
    {
        $email = uniqid('keep_') . '@test.kg';

        // Неудачная отправка кладёт ошибки и старый ввод во флеш-сессию
        $this->actingAs($this->admin, 'employee')
            ->from('/employees')
            ->post('/employees', [
                'full_name' => 'Новый Сотрудник',
                'email' => $email,
                'password' => 'password123',
                'password_confirmation' => 'password124',
                'role_id' => $this->employeeRoleId(),
            ])
            ->assertRedirect('/employees');

        // Следующий заход на список показывает ошибку и возвращает введённое в форму
        $html = $this->actingAs($this->admin, 'employee')
            ->get('/employees')
            ->assertOk()
            ->getContent();

        $this->assertStringContainsString('Не удалось сохранить', $html);
        $this->assertStringContainsString('Пароли не совпадают', $html);
        $this->assertStringContainsString('showCreateModal: true', $html);
        $this->assertStringContainsString('Новый Сотрудник', $html);
        $this->assertStringContainsString($email, $html);
    }

    public function test_duplicate_email_is_rejected(): void
    {
        $this->actingAs($this->admin, 'employee')
            ->post('/employees', [
                'full_name' => 'Дубль',
                'email' => $this->admin->email,
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'role_id' => $this->employeeRoleId(),
            ])
            ->assertRedirect()
            ->assertSessionHasErrors('email');

        $this->assertSame(1, Employee::where('email', $this->admin->email)->count());
    }

    public function test_valid_employee_is_created_and_appears_in_list(): void
    {
        $email = uniqid('ok_') . '@test.kg';

        $this->actingAs($this->admin, 'employee')
            ->post('/employees', [
                'full_name' => 'Успешный Сотрудник',
                'email' => $email,
                'password' => 'password123',
                'password_confirmation' => 'password123',
                'role_id' => $this->employeeRoleId(),
            ])
            ->assertRedirect(route('employees.index'))
            ->assertSessionHas('success');

        $this->assertTrue(Employee::where('email', $email)->exists());

        $this->actingAs($this->admin, 'employee')
            ->get('/employees')
            ->assertOk()
            ->assertSee('Успешный Сотрудник', false);
    }
}
