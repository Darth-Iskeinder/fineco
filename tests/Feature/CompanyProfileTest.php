<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Module;
use App\Models\Role;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Профиль фирмы: название, логотип и реквизиты, которые уходят в документы.
 */
class CompanyProfileTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

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

        $this->tenant = Tenant::create([
            'name'   => 'Фирма профиля ' . uniqid(),
            'slug'   => 'profile-' . uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);
    }

    private function employee(string $roleName): Employee
    {
        $role   = Role::firstOrCreate(['name' => $roleName], ['display_name' => $roleName]);
        $module = Module::firstOrCreate(['name' => 'settings'], ['display_name' => 'Настройки', 'is_active' => true]);

        $employee = TenantContext::for($this->tenant, fn () => Employee::create([
            'full_name' => 'Сотрудник ' . $roleName, 'position' => $roleName,
            'email' => uniqid($roleName . '_') . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]));

        $employee->modules()->attach($module->id);

        return $employee;
    }


    /** Настоящий однопиксельный PNG: GD в окружении нет, image() не сработает. */
    private function pngFile(string $name = 'logo.png'): UploadedFile
    {
        $bytes = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );

        return UploadedFile::fake()->createWithContent($name, $bytes);
    }

    public function test_manager_can_change_name_and_requisites(): void
    {
        $manager = $this->employee(Role::MANAGER);

        $this->actingAs($manager, 'employee')->put(route('settings.profile.update'), [
            'name'          => 'Новое название',
            'legal_name'    => 'ОсОО «Новое название»',
            'inn'           => '01234567890123',
            'address'       => 'Бишкек, ул. Примерная 1',
            'phone'         => '+996700000000',
            'email'         => 'firm@test.kg',
            'director_name' => 'Иванов И.И.',
            'bank_account'  => '1234567890',
        ])->assertRedirect();

        $this->tenant->refresh();

        $this->assertSame('Новое название', $this->tenant->name);
        $this->assertSame('01234567890123', $this->tenant->inn);
        $this->assertSame('Иванов И.И.', $this->tenant->director_name);
    }

    public function test_admin_can_change_it_too(): void
    {
        $admin = $this->employee(Role::ADMIN);

        $this->actingAs($admin, 'employee')
            ->put(route('settings.profile.update'), ['name' => 'От админа'])
            ->assertRedirect();

        $this->assertSame('От админа', $this->tenant->refresh()->name);
    }

    public function test_head_accountant_sees_the_page_but_cannot_change_anything(): void
    {
        $head = $this->employee(Role::HEAD_ACCOUNTANT);
        $was  = $this->tenant->name;

        // Смотреть реквизиты своей фирмы полезно всем допущенным в настройки.
        $this->actingAs($head, 'employee')
            ->get(route('settings.profile'))
            ->assertOk()
            ->assertSee('Данные фирмы меняют руководитель и администратор');

        $this->actingAs($head, 'employee')
            ->put(route('settings.profile.update'), ['name' => 'Подмена'])
            ->assertForbidden();

        $this->assertSame($was, $this->tenant->refresh()->name);
    }

    public function test_name_cannot_be_emptied(): void
    {
        $manager = $this->employee(Role::MANAGER);

        $this->actingAs($manager, 'employee')
            ->put(route('settings.profile.update'), ['name' => ''])
            ->assertSessionHasErrors('name');
    }

    /**
     * Числовые поля проверяем по существу: эти значения уходят в акт клиенту,
     * и ошибка в них обнаружится уже на его стороне.
     */
    public function test_numeric_fields_refuse_letters(): void
    {
        $manager = $this->employee(Role::MANAGER);

        $this->actingAs($manager, 'employee')->put(route('settings.profile.update'), [
            'name'          => 'Фирма',
            'inn'           => '0123ABC7890123',
            'bank_account'  => 'счёт 12345',
            'bank_bik'      => 'бик',
            'phone'         => 'позвоните нам',
            'director_name' => 'Иванов 2',
        ])->assertSessionHasErrors(['inn', 'bank_account', 'bank_bik', 'phone', 'director_name']);

        $this->assertNull($this->tenant->refresh()->inn);
    }

    public function test_inn_must_be_exactly_fourteen_digits(): void
    {
        $manager = $this->employee(Role::MANAGER);

        $this->actingAs($manager, 'employee')
            ->put(route('settings.profile.update'), ['name' => 'Фирма', 'inn' => '123'])
            ->assertSessionHasErrors('inn');

        $this->actingAs($manager, 'employee')
            ->put(route('settings.profile.update'), ['name' => 'Фирма', 'inn' => '01234567890123'])
            ->assertSessionHasNoErrors();
    }

    /** Телефон живой человек пишет со скобками и плюсом — это должно проходить. */
    public function test_phone_allows_the_usual_punctuation(): void
    {
        $manager = $this->employee(Role::MANAGER);

        $this->actingAs($manager, 'employee')
            ->put(route('settings.profile.update'), ['name' => 'Фирма', 'phone' => '+996 (700) 12-34-56'])
            ->assertSessionHasNoErrors();

        $this->assertSame('+996 (700) 12-34-56', $this->tenant->refresh()->phone);
    }

    /**
     * Пока логотипа нет, в меню стоит значок с буквой фирмы, а не логотип
     * какой-то одной компании: система многофирменная.
     */
    public function test_without_a_logo_the_firms_initial_is_shown(): void
    {
        $manager = $this->employee(Role::MANAGER);

        $this->assertSame(mb_substr($this->tenant->name, 0, 1), $this->tenant->initial());

        $this->actingAs($manager, 'employee')
            ->get(route('settings.profile'))
            ->assertOk()
            ->assertSee('Логотип не загружен')
            ->assertDontSee('Fineco-logo.png');
    }

    public function test_logo_is_uploaded_and_served_from_a_closed_disk(): void
    {
        Storage::fake('local');

        $manager = $this->employee(Role::MANAGER);

        $this->actingAs($manager, 'employee')->post(route('settings.profile.logo'), [
            'logo' => $this->pngFile(),
        ])->assertRedirect();

        $this->tenant->refresh();

        $this->assertNotNull($this->tenant->logo_path);
        Storage::disk('local')->assertExists($this->tenant->logo_path);

        // Отдаём своим маршрутом, а не ссылкой в public: symlink storage не нужен.
        $this->actingAs($manager, 'employee')->get(route('company.logo'))->assertOk();
    }

    public function test_executable_image_types_are_refused(): void
    {
        Storage::fake('local');

        $manager = $this->employee(Role::MANAGER);

        // SVG умеет исполнять скрипты, а логотип отдаётся с нашего домена.
        $this->actingAs($manager, 'employee')->post(route('settings.profile.logo'), [
            'logo' => UploadedFile::fake()->create('logo.svg', 10, 'image/svg+xml'),
        ])->assertSessionHasErrors('logo');

        $this->assertNull($this->tenant->refresh()->logo_path);
    }

    public function test_logo_can_be_removed(): void
    {
        Storage::fake('local');

        $manager = $this->employee(Role::MANAGER);

        $this->actingAs($manager, 'employee')->post(route('settings.profile.logo'), [
            'logo' => $this->pngFile(),
        ]);

        $path = $this->tenant->refresh()->logo_path;

        $this->actingAs($manager, 'employee')->delete(route('settings.profile.logo.destroy'))->assertRedirect();

        $this->assertNull($this->tenant->refresh()->logo_path);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_accountant_cannot_upload_a_logo(): void
    {
        Storage::fake('local');

        $accountant = $this->employee(Role::ACCOUNTANT);

        $this->actingAs($accountant, 'employee')->post(route('settings.profile.logo'), [
            'logo' => $this->pngFile(),
        ])->assertForbidden();
    }

    public function test_profile_belongs_to_the_firm_of_the_signed_in_employee(): void
    {
        $otherTenant = Tenant::create([
            'name'   => 'Чужая фирма ' . uniqid(),
            'slug'   => 'other-profile-' . uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $manager = $this->employee(Role::MANAGER);

        $this->actingAs($manager, 'employee')
            ->put(route('settings.profile.update'), ['name' => 'Правим свою']);

        $this->assertSame('Правим свою', $this->tenant->refresh()->name);
        $this->assertStringStartsWith('Чужая фирма', $otherTenant->refresh()->name);
    }
}
