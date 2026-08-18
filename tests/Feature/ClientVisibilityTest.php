<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Module;
use App\Models\Role;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Зона видимости в модуле «Клиенты».
 *
 * Раньше список и карточка отдавали всех клиентов всем, у кого открыт модуль:
 * бухгалтер видел компании чужого главбуха, а по прямой ссылке — и карточку,
 * и смету любой из них.
 *
 * Теперь сотрудник видит только те компании, к которым прикреплён (ответственный,
 * исполнитель БП в смете, команда клиента), а админ и руководитель — всех.
 */
class ClientVisibilityTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $admin;
    private Employee $accountant;
    private Employee $other;

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

        $module = Module::firstOrCreate(
            ['name' => 'clients'],
            ['display_name' => 'Клиенты', 'is_active' => true],
        );

        $make = function (string $prefix, string $roleName) use ($module) {
            $role = Role::firstOrCreate(['name' => $roleName], ['display_name' => $roleName]);

            $employee = Employee::create([
                'full_name' => 'Тест ' . $prefix, 'position' => $prefix,
                'email' => 'cv_' . $prefix . '_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
                'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
            ]);
            $employee->modules()->attach($module->id);

            return $employee;
        };

        $this->admin      = $make('admin', Role::ADMIN);
        $this->accountant = $make('acc', Role::ACCOUNTANT);
        $this->other      = $make('other', Role::ACCOUNTANT);
    }

    private function client(string $name, ?int $responsibleId = null): Client
    {
        return Client::create([
            'name' => $name,
            'inn' => strtoupper(substr(md5($name . uniqid()), 0, 12)),
            'responsible_employee_id' => $responsibleId,
            'is_active' => true,
        ]);
    }

    public function test_list_shows_only_own_clients(): void
    {
        $mine    = $this->client('Моя компания', $this->accountant->id);
        $foreign = $this->client('Чужая компания', $this->other->id);
        $nobody  = $this->client('Ничья компания');

        $response = $this->actingAs($this->accountant, 'employee')->get('/clients')->assertSuccessful();

        $ids = $response->viewData('clients')->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($foreign->id));
        // Компания без ответственного и команды не достаётся никому, кроме админа и руководителя
        $this->assertFalse($ids->contains($nobody->id));
    }

    /** Прикрепление бывает трёх видов — команда клиента и исполнитель БП тоже считаются. */
    public function test_team_member_and_estimate_assignee_see_client(): void
    {
        $viaTeam = $this->client('Компания с командой', $this->other->id);
        $viaTeam->employees()->attach($this->accountant->id);

        $viaEstimate = $this->client('Компания со сметой', $this->other->id);
        $estimate = Estimate::create(['client_id' => $viaEstimate->id, 'total' => 0]);
        EstimateItem::create([
            'estimate_id' => $estimate->id,
            'assignee_id' => $this->accountant->id,
            'type' => 'recurring',
            'name' => 'БП',
            'cost' => 0,
            'quantity' => 1,
            'total' => 0,
        ]);

        $ids = $this->actingAs($this->accountant, 'employee')
            ->get('/clients')->assertSuccessful()
            ->viewData('clients')->pluck('id');

        $this->assertTrue($ids->contains($viaTeam->id));
        $this->assertTrue($ids->contains($viaEstimate->id));
    }

    public function test_admin_sees_every_client(): void
    {
        $foreign = $this->client('Чужая компания', $this->other->id);
        $nobody  = $this->client('Ничья компания');

        $ids = $this->actingAs($this->admin, 'employee')
            ->get('/clients')->assertSuccessful()
            ->viewData('clients')->pluck('id');

        $this->assertTrue($ids->contains($foreign->id));
        $this->assertTrue($ids->contains($nobody->id));
    }

    /** Прямая ссылка на чужую компанию — 403, а не «просто нет в списке». */
    public function test_foreign_client_pages_are_forbidden(): void
    {
        $foreign = $this->client('Чужая компания', $this->other->id);

        $this->actingAs($this->accountant, 'employee')->get('/clients/' . $foreign->id)->assertForbidden();
        $this->actingAs($this->accountant, 'employee')->get('/clients/' . $foreign->id . '/estimate/edit')->assertForbidden();
        $this->actingAs($this->accountant, 'employee')
            ->patchJson('/clients/' . $foreign->id, ['section' => 'main', 'name' => 'Взлом'])
            ->assertForbidden();

        $this->assertSame('Чужая компания', $foreign->fresh()->name);
    }

    public function test_own_client_card_opens(): void
    {
        $mine = $this->client('Моя компания', $this->accountant->id);

        $this->actingAs($this->accountant, 'employee')->get('/clients/' . $mine->id)->assertSuccessful();
    }

    /** Поиск — та же зона видимости, что и список: иначе чужие компании утекают в JSON. */
    public function test_search_is_scoped(): void
    {
        $mine    = $this->client('Поисковая моя', $this->accountant->id);
        $foreign = $this->client('Поисковая чужая', $this->other->id);

        $ids = collect($this->actingAs($this->accountant, 'employee')
            ->getJson('/clients/search?search=Поисковая')
            ->assertSuccessful()
            ->json())->pluck('id');

        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($foreign->id));
    }

    /** Заводить, импортировать и удалять компании может только админ и руководитель. */
    public function test_regular_employee_cannot_create_import_or_delete(): void
    {
        $mine = $this->client('Моя компания', $this->accountant->id);

        $this->actingAs($this->accountant, 'employee')
            ->post('/clients', ['name' => 'Новая', 'inn' => '1234567890'])
            ->assertForbidden();

        $this->actingAs($this->accountant, 'employee')->post('/clients/import/preview')->assertForbidden();
        $this->actingAs($this->accountant, 'employee')->get('/clients/imports')->assertForbidden();
        $this->actingAs($this->accountant, 'employee')->delete('/clients/' . $mine->id)->assertForbidden();

        $this->assertNotNull($mine->fresh());
    }

    public function test_admin_can_create_client(): void
    {
        $this->actingAs($this->admin, 'employee')
            ->post('/clients', ['name' => 'Новая компания', 'inn' => strtoupper(substr(md5(uniqid()), 0, 12))])
            ->assertRedirect();
    }
}
