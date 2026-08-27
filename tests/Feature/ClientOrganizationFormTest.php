<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Module;
use App\Models\OrganizationForm;
use App\Models\Role;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Форма собственности задаётся при заведении клиента, а не дописывается потом
 * руками в карточке. В названии остаётся только название.
 */
class ClientOrganizationFormTest extends TestCase
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

        $role = Role::firstOrCreate(['name' => Role::ADMIN], ['display_name' => 'Администратор']);

        $this->admin = Employee::create([
            'full_name' => 'Тест Админ', 'position' => 'Админ',
            'email' => 'orgform_admin_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);

        Module::firstOrCreate(['name' => 'clients'], ['display_name' => 'Клиенты', 'is_active' => true]);
    }

    private function form(): OrganizationForm
    {
        return OrganizationForm::firstOrCreate(['name' => 'ОсОО']);
    }

    private function inn(): string
    {
        return strtoupper(substr(md5(uniqid()), 0, 12));
    }

    /** Попап создания передаёт форму, и она сохраняется. */
    public function test_client_is_created_with_organization_form(): void
    {
        $form = $this->form();

        $this->actingAs($this->admin, 'employee')
            ->post(route('clients.store'), [
                'name' => 'Ромашка',
                'inn' => $this->inn(),
                'organization_form_id' => $form->id,
            ])
            ->assertRedirect();

        $client = Client::where('name', 'Ромашка')->latest('id')->first();

        $this->assertNotNull($client);
        $this->assertSame($form->id, $client->organization_form_id);
    }

    /** Поле необязательное: без него клиент заводится как и раньше. */
    public function test_client_is_created_without_organization_form(): void
    {
        $this->actingAs($this->admin, 'employee')
            ->post(route('clients.store'), [
                'name' => 'Без формы',
                'inn' => $this->inn(),
            ])
            ->assertRedirect();

        $client = Client::where('name', 'Без формы')->latest('id')->first();

        $this->assertNotNull($client);
        $this->assertNull($client->organization_form_id);
    }

    /** Чужой или выдуманный id справочника не проходит. */
    public function test_unknown_organization_form_is_rejected(): void
    {
        $this->actingAs($this->admin, 'employee')
            ->post(route('clients.store'), [
                'name' => 'Выдумка',
                'inn' => $this->inn(),
                'organization_form_id' => 999999,
            ])
            ->assertSessionHasErrors('organization_form_id', null, 'createClient');

        $this->assertNull(Client::where('name', 'Выдумка')->first());
    }

    /** Правка из списка клиентов тоже сохраняет форму. */
    public function test_organization_form_is_saved_from_edit_modal(): void
    {
        $form = $this->form();

        $client = Client::create([
            'name' => 'Василёк',
            'inn' => $this->inn(),
            'responsible_employee_id' => $this->admin->id,
        ]);

        $this->actingAs($this->admin, 'employee')
            ->putJson(route('clients.update', $client), [
                'name' => 'Василёк',
                'inn' => $client->inn,
                'organization_form_id' => $form->id,
            ])
            ->assertOk();

        $this->assertSame($form->id, $client->fresh()->organization_form_id);
    }
}
