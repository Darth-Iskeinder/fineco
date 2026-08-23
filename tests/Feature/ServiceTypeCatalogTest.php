<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\Service;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Тип обслуживания у бизнес-процесса: бухучёт, налоговый учёт или расчёт ЗП.
 *
 * Первый шаг: поле заводится и хранится, на подтягивание БП в смету оно пока не
 * влияет. Пустой тип означает «общий БП», и таким сейчас в каталоге является
 * каждый — поэтому поведение смет не меняется до тех пор, пока типы не проставят.
 */
class ServiceTypeCatalogTest extends TestCase
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
            'email' => 'stc_admin_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'БП с типом ' . uniqid(),
            'cost' => 1000,
        ], $overrides);
    }

    public function test_store_saves_service_type(): void
    {
        $this->actingAs($this->admin, 'employee')
            ->postJson('/settings/services', $this->payload(['service_type' => 'tax']))
            ->assertSuccessful()
            ->assertJsonPath('item.service_type', 'tax');
    }

    /** Значения — только ключи Service::SERVICE_TYPES: опечатка молча отсекла бы БП у клиентов. */
    public function test_store_rejects_unknown_service_type(): void
    {
        $this->actingAs($this->admin, 'employee')
            ->postJson('/settings/services', $this->payload(['service_type' => 'consulting']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('service_type');
    }

    /** «Полное обслуживание» типом БП не бывает: у клиента это все три отметки сразу. */
    public function test_store_rejects_full_as_service_type(): void
    {
        $this->actingAs($this->admin, 'employee')
            ->postJson('/settings/services', $this->payload(['service_type' => 'full']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('service_type');
    }

    /** Тип не указан — БП общий. Так выглядит весь нынешний каталог. */
    public function test_store_without_service_type_leaves_it_empty(): void
    {
        $response = $this->actingAs($this->admin, 'employee')
            ->postJson('/settings/services', $this->payload())
            ->assertSuccessful();

        $this->assertNull(Service::find($response->json('item.id'))->service_type);
    }

    public function test_update_sets_and_clears_service_type(): void
    {
        $service = Service::create(['name' => 'БП для правки ' . uniqid(), 'cost' => 500]);

        $this->actingAs($this->admin, 'employee')
            ->putJson("/settings/services/{$service->id}", $this->payload([
                'name'         => $service->name,
                'service_type' => 'payroll',
            ]))
            ->assertSuccessful();

        $this->assertSame('payroll', $service->fresh()->service_type);

        // Пустая строка из селектора — это «общий», а не значение типа.
        $this->actingAs($this->admin, 'employee')
            ->putJson("/settings/services/{$service->id}", $this->payload([
                'name'         => $service->name,
                'service_type' => '',
            ]))
            ->assertSuccessful();

        $this->assertNull($service->fresh()->service_type);
    }

    /** Справочник открывается и отдаёт тип во фронт — иначе бейдж и фильтр пустые. */
    public function test_services_page_exposes_service_type(): void
    {
        Service::create([
            'name' => 'БП бухучёта ' . uniqid(), 'cost' => 100, 'service_type' => 'accounting',
        ]);

        $this->actingAs($this->admin, 'employee')
            ->get('/settings/services')
            ->assertSuccessful()
            ->assertSee('Тип обслуживания')
            ->assertSee('"service_type":"accounting"', false);
    }
}
