<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Periodicity;
use App\Models\Role;
use App\Models\Service;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Подпункты в списке БП (/settings/services) свёрнуты и раскрываются стрелкой.
 *
 * Раньше они рисовались всегда: БП с пятью подпунктами занимал шесть строк,
 * и на полутора сотнях БП список переставал читаться.
 */
class ServiceChildrenCollapseTest extends TestCase
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

        Periodicity::firstOrCreate(['name' => 'Ежемесячно'], ['kind' => 'monthly']);
        $role = Role::firstOrCreate(['name' => Role::ADMIN], ['display_name' => 'Администратор']);

        $this->admin = Employee::create([
            'full_name' => 'Тест Админ', 'position' => 'Админ',
            'email' => 'collapse_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
    }

    public function test_children_are_behind_a_toggle(): void
    {
        $parent = Service::create([
            'name' => 'Валютные операции ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_day' => [5], 'is_active' => true,
        ]);
        Service::create([
            'parent_id' => $parent->id, 'name' => 'до 10 операций',
            'periodicity' => 'Ежемесячно', 'start_day' => [5], 'is_active' => true,
        ]);

        $page = $this->actingAs($this->admin, 'employee')
            ->get(route('settings.services'))
            ->assertOk();

        // Стрелка со счётчиком и признак раскрытия — на месте.
        $page->assertSee('toggleChildren(row.svc.id)', false);
        $page->assertSee('Показать подпункты', false);

        // Строки подпунктов рисуются только у раскрытого БП, а не всегда.
        $page->assertSee("row.type === 'service' && childrenVisible(row.svc)", false);
        $page->assertDontSee("x-for=\"child in (row.type === 'service' ? (row.svc.children || []) : [])\"", false);
    }
}
