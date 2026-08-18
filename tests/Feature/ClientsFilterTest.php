<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Module;
use App\Models\Role;
use App\Models\TaxSystem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Фильтры списка клиентов. Ключевое требование: страница, живой поиск и выгрузка CSV
 * фильтруют одинаково — иначе человек «нашёл двенадцать, а скачал триста сорок».
 *
 * Значение 'none' («не указан») отдельно проверяется для ответственного: клиент без
 * него не находится обычными средствами, а именно такие ломают работу — задачи по ним
 * никуда не идут.
 */
class ClientsFilterTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $viewer;
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
        \Illuminate\Support\Facades\DB::purge('mysql');

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Фильтры проверяем от админа: он видит всех клиентов, иначе половина
        // тестовых компаний просто не попадёт в список (см. ClientVisibilityTest).
        $role   = Role::firstOrCreate(['name' => Role::ADMIN], ['display_name' => 'Администратор']);
        $module = Module::firstOrCreate(['name' => 'clients'], ['display_name' => 'Клиенты', 'is_active' => true]);

        $this->viewer = Employee::create([
            'full_name' => 'Фильтр Смотрящий', 'position' => 'Администратор',
            'email' => uniqid('flt_') . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
        $this->viewer->modules()->attach($module->id);

        $this->other = Employee::create([
            'full_name' => 'Фильтр Другой', 'position' => 'Бухгалтер',
            'email' => uniqid('flt2_') . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
    }

    private function client(array $attributes = []): Client
    {
        return Client::create(array_merge([
            'name' => 'ТОО Фильтр ' . uniqid(),
            'inn'  => strtoupper(substr(md5(uniqid()), 0, 12)),
            'is_active' => true,
        ], $attributes));
    }

    /** Имена клиентов, которые вернул живой поиск с этими параметрами. */
    private function foundNames(array $params): array
    {
        $rows = $this->actingAs($this->viewer, 'employee')
            ->getJson('/clients/search?' . http_build_query($params))
            ->assertOk()
            ->json();

        return collect($rows)->pluck('name')->all();
    }

    public function test_filters_by_responsible(): void
    {
        $mine     = $this->client(['responsible_employee_id' => $this->viewer->id]);
        $foreign  = $this->client(['responsible_employee_id' => $this->other->id]);

        $names = $this->foundNames(['responsible' => $this->viewer->id]);

        $this->assertContains($mine->name, $names);
        $this->assertNotContains($foreign->name, $names);
    }

    public function test_filters_clients_without_responsible(): void
    {
        $orphan   = $this->client(['responsible_employee_id' => null]);
        $assigned = $this->client(['responsible_employee_id' => $this->viewer->id]);

        $names = $this->foundNames(['responsible' => 'none']);

        $this->assertContains($orphan->name, $names);
        $this->assertNotContains($assigned->name, $names);
    }

    public function test_filters_by_status(): void
    {
        $active   = $this->client(['is_active' => true]);
        $inactive = $this->client(['is_active' => false]);

        $this->assertContains($inactive->name, $this->foundNames(['status' => 'inactive']));
        $this->assertNotContains($active->name, $this->foundNames(['status' => 'inactive']));
        $this->assertContains($active->name, $this->foundNames(['status' => 'active']));
    }

    public function test_filters_by_tax_system(): void
    {
        $taxSystem = TaxSystem::create([
            'code' => strtoupper(substr(md5(uniqid()), 0, 8)),
            'name' => 'РН Фильтр ' . uniqid(),
            'is_active' => true,
        ]);

        $withSystem = $this->client(['tax_system_id' => $taxSystem->id]);
        $without    = $this->client(['tax_system_id' => null]);

        $this->assertSame([$withSystem->name], $this->foundNames(['tax_system' => $taxSystem->id]));
        $this->assertContains($without->name, $this->foundNames(['tax_system' => 'none']));
        $this->assertNotContains($withSystem->name, $this->foundNames(['tax_system' => 'none']));
    }

    /** Фильтры складываются друг с другом и с текстовым поиском. */
    public function test_filters_combine_with_search(): void
    {
        $marker = 'Комбо' . uniqid();
        $target = $this->client(['name' => $marker . ' нужный', 'responsible_employee_id' => $this->viewer->id]);
        $this->client(['name' => $marker . ' чужой', 'responsible_employee_id' => $this->other->id]);
        $this->client(['name' => $marker . ' неактивный', 'responsible_employee_id' => $this->viewer->id, 'is_active' => false]);

        $names = $this->foundNames([
            'search'      => $marker,
            'responsible' => $this->viewer->id,
            'status'      => 'active',
        ]);

        $this->assertSame([$target->name], $names);
    }

    public function test_page_and_export_use_the_same_filter(): void
    {
        $inactive = $this->client(['is_active' => false]);
        $active   = $this->client(['is_active' => true]);

        // Страница
        $clients = $this->actingAs($this->viewer, 'employee')
            ->get(route('clients.index', ['status' => 'inactive']))
            ->assertOk()
            ->viewData('clients');

        $this->assertContains($inactive->name, $clients->pluck('name')->all());
        $this->assertNotContains($active->name, $clients->pluck('name')->all());

        // Выгрузка CSV — тот же фильтр, иначе скачается не то, что на экране
        $csv = $this->actingAs($this->viewer, 'employee')
            ->get(route('clients.export', ['status' => 'inactive']))
            ->assertOk()
            ->streamedContent();

        $this->assertStringContainsString($inactive->name, $csv);
        $this->assertStringNotContainsString($active->name, $csv);
    }

    public function test_search_still_accepts_legacy_q_parameter(): void
    {
        $client = $this->client();

        $this->assertContains($client->name, $this->foundNames(['q' => $client->name]));
    }
}
