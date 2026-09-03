<?php

namespace Tests\Feature;

use App\Models\Billing;
use App\Models\Employee;
use App\Models\Rate;
use App\Models\Role;
use App\Models\Service;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Биллинг живёт только на основном БП.
 *
 * Раньше режим тарификации и ставку у БП с подпунктами несли подпункты, а родитель
 * оставался без биллинга. Теперь наоборот: режим задают на основном БП, подпункты его
 * наследуют — иначе в смете цена одного БП собиралась из разнобоя режимов по подпунктам.
 */
class ServiceBillingInheritanceTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $admin;
    private Rate $rate;
    private string $paidBilling;
    private string $freeBilling;

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
            'email' => 'sbi_admin_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);

        $this->rate = Rate::create(['name' => 'Ставка ' . uniqid(), 'unit' => 'операция', 'price' => 300]);

        $this->paidBilling = Billing::firstOrCreate(['code' => Billing::CODE_BY_QUANTITY], ['name' => 'Считается по количеству'])->name;
        $this->freeBilling = Billing::firstOrCreate(['code' => Billing::CODE_NONE], ['name' => 'Не тарифицируется'])->name;
    }

    public function test_children_inherit_billing_on_create(): void
    {
        $response = $this->actingAs($this->admin, 'employee')->postJson('/settings/services', [
            'name'     => 'БП с подпунктами ' . uniqid(),
            'cost'     => 0,
            'billing'  => $this->paidBilling,
            'rate_id'  => $this->rate->id,
            'allows_quantity' => true,
            'children' => [
                ['name' => 'Подпункт 1', 'cost' => 0],
                ['name' => 'Подпункт 2', 'cost' => 0],
            ],
        ])->assertSuccessful();

        $service = Service::with('children')->find($response->json('item.id'));

        // Наличие подпунктов больше не обнуляет биллинг родителя
        $this->assertSame($this->paidBilling, $service->billing);
        $this->assertSame($this->rate->id, $service->rate_id);

        $this->assertCount(2, $service->children);
        foreach ($service->children as $child) {
            $this->assertSame($this->paidBilling, $child->billing);
            $this->assertSame($this->rate->id, $child->rate_id);
            // Количество не наследуется: число одно, на основном БП.
            $this->assertFalse((bool) $child->allows_quantity);
        }
    }

    public function test_children_follow_parent_billing_on_update(): void
    {
        $service = Service::create([
            'name' => 'БП ' . uniqid(), 'cost' => 0,
            'billing' => $this->paidBilling, 'rate_id' => $this->rate->id,
        ]);
        $child = $service->children()->create([
            'name' => 'Подпункт', 'cost' => 0, 'is_active' => true,
            'billing' => $this->paidBilling, 'rate_id' => $this->rate->id,
        ]);

        $this->actingAs($this->admin, 'employee')->putJson('/settings/services/' . $service->id, [
            'name'     => $service->name,
            'cost'     => 0,
            'billing'  => $this->freeBilling,
            'children' => [
                ['id' => $child->id, 'name' => 'Подпункт', 'cost' => 0],
            ],
        ])->assertSuccessful();

        $this->assertSame($this->freeBilling, $service->fresh()->billing);
        $this->assertSame($this->freeBilling, $child->fresh()->billing);
        $this->assertNull($child->fresh()->rate_id);
    }

    /**
     * Флаг «можно указывать количество» живёт только на основном БП.
     *
     * Раньше он наследовался подпунктами, и в смете количество вводили по каждому из них,
     * а цена строки собиралась из подпунктов. Теперь число одно, у основного БП, а подпункт
     * задаёт только состав работы.
     */
    public function test_quantity_flag_never_lands_on_children(): void
    {
        $service = Service::create([
            'name' => 'БП ' . uniqid(), 'cost' => 0,
            'billing' => $this->paidBilling, 'rate_id' => $this->rate->id,
            'allows_quantity' => true,
        ]);
        $child = $service->children()->create([
            'name' => 'Подпункт', 'cost' => 0, 'is_active' => true,
            'billing' => $this->paidBilling, 'rate_id' => $this->rate->id,
            'allows_quantity' => true,
        ]);

        $this->actingAs($this->admin, 'employee')->putJson('/settings/services/' . $service->id, [
            'name'            => $service->name,
            'cost'            => 0,
            'billing'         => $this->paidBilling,
            'rate_id'         => $this->rate->id,
            'allows_quantity' => true,
            'children'        => [
                ['id' => $child->id, 'name' => 'Подпункт', 'cost' => 0],
            ],
        ])->assertSuccessful();

        $this->assertTrue((bool) $service->fresh()->allows_quantity);
        $this->assertFalse((bool) $child->fresh()->allows_quantity);
    }

    /** Своей карточки у подпункта нет, но и прямой запрос не даст ему свой биллинг и количество. */
    public function test_child_cannot_set_own_billing(): void
    {
        $service = Service::create([
            'name' => 'БП ' . uniqid(), 'cost' => 0,
            'billing' => $this->paidBilling, 'rate_id' => $this->rate->id,
            'allows_quantity' => true,
        ]);
        $child = $service->children()->create([
            'name' => 'Подпункт', 'cost' => 0, 'is_active' => true,
            'billing' => $this->paidBilling, 'rate_id' => $this->rate->id,
            'allows_quantity' => true,
        ]);

        $this->actingAs($this->admin, 'employee')->putJson('/settings/services/' . $child->id, [
            'name'            => 'Подпункт переименован',
            'cost'            => 0,
            'billing'         => $this->freeBilling,
            'rate_id'         => null,
            'allows_quantity' => false,
        ])->assertSuccessful();

        $child->refresh();
        $this->assertSame('Подпункт переименован', $child->name);
        $this->assertSame($this->paidBilling, $child->billing);
        $this->assertSame($this->rate->id, $child->rate_id);
        $this->assertFalse((bool) $child->allows_quantity);
    }
}
