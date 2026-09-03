<?php

namespace Tests\Feature;

use App\Models\Billing;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Module;
use App\Models\Periodicity;
use App\Models\Rate;
use App\Models\Role;
use App\Models\Service;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Количество и цена живут на основном БП, у подпункта их нет.
 *
 * Раньше было наоборот: счётчик у основного БП в смете пропадал, как только включали
 * первый подпункт, количество вводили по каждому подпункту, а сумма строки была суммой
 * подпунктов. Теперь подпункт задаёт только состав работы.
 */
class SubitemHasNoQuantityTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $admin;
    private Rate $rate;
    private string $paidBilling;

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
        $module = Module::firstOrCreate(
            ['name' => 'clients'],
            ['display_name' => 'Клиенты', 'is_active' => true],
        );
        $role = Role::firstOrCreate(['name' => Role::ADMIN], ['display_name' => 'Администратор']);

        $this->admin = Employee::create([
            'full_name' => 'Тест Админ', 'position' => 'Админ',
            'email' => 'qty_admin_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
        $this->admin->modules()->attach($module->id);

        $this->rate = Rate::create(['name' => 'Ставка ' . uniqid(), 'unit' => 'операция', 'price' => 300]);
        $this->paidBilling = Billing::firstOrCreate(
            ['code' => Billing::CODE_BY_QUANTITY],
            ['name' => 'Считается по количеству'],
        )->name;
    }

    private function client(): Client
    {
        return Client::create([
            'name' => 'ТОО Кол-во ' . uniqid(),
            'inn'  => strtoupper(substr(md5(uniqid()), 0, 12)),
            'responsible_employee_id' => $this->admin->id,
        ]);
    }

    /** БП со ставкой, количеством и двумя подпунктами. */
    private function serviceWithChildren(): Service
    {
        $service = Service::create([
            'name'            => 'БП с подпунктами ' . uniqid(),
            'periodicity'     => 'Ежемесячно',
            'start_day'       => [15],
            'due_day'         => 15,
            'billing'         => $this->paidBilling,
            'rate_id'         => $this->rate->id,
            'allows_quantity' => true,
            'is_active'       => true,
        ]);

        foreach (['Подпункт 1', 'Подпункт 2'] as $i => $name) {
            $service->children()->create([
                'name' => $name, 'cost' => 0, 'is_active' => true, 'sort_order' => $i,
                'billing' => $this->paidBilling, 'rate_id' => $this->rate->id,
            ]);
        }

        return $service->fresh('children');
    }

    private function saveEstimate(Client $client, Service $service, int $quantity, array $enabledChildIds): void
    {
        $this->actingAs($this->admin, 'employee')
            ->postJson(route('clients.estimate.save', $client), [
                'tariff_bps' => [[
                    'service_id' => $service->id,
                    'enabled'    => true,
                    'quantity'   => $quantity,
                    'children'   => $service->children->map(fn ($c) => [
                        'service_id' => $c->id,
                        'enabled'    => in_array($c->id, $enabledChildIds, true),
                        // Фронт числа по подпунктам больше не шлёт, но и присланное
                        // сервер обязан игнорировать.
                        'quantity'   => 7,
                    ])->all(),
                ]],
                'extras' => [],
            ])
            ->assertOk();
    }

    public function test_price_is_rate_times_parent_quantity(): void
    {
        $client  = $this->client();
        $service = $this->serviceWithChildren();

        $this->saveEstimate($client, $service, 4, $service->children->pluck('id')->all());

        $parent = EstimateItem::where('service_id', $service->id)->whereNull('parent_id')->firstOrFail();

        // 300 за операцию × 4 операции, независимо от того, сколько подпунктов включено
        $this->assertSame(4, (int) $parent->quantity);
        $this->assertSame('1200.00', (string) $parent->total);
        $this->assertSame('1200.00', (string) Estimate::where('client_id', $client->id)->value('total'));

        $this->assertCount(2, $parent->children);
        foreach ($parent->children as $child) {
            $this->assertSame(1, (int) $child->quantity, 'у подпункта количества нет');
            $this->assertSame('0.00', (string) $child->total, 'подпункт в сумму не складывается');
        }
    }

    /** Состав работы всё же обязателен: без единого подпункта строка стоит ноль. */
    public function test_row_without_chosen_subitems_costs_nothing(): void
    {
        $client  = $this->client();
        $service = $this->serviceWithChildren();

        $this->saveEstimate($client, $service, 4, []);

        $parent = EstimateItem::where('service_id', $service->id)->whereNull('parent_id')->firstOrFail();

        $this->assertSame('0.00', (string) $parent->total);
        $this->assertCount(0, $parent->children);
    }

    /** Факт по количеству ставится у основной задачи, у подпункта такого поля нет. */
    public function test_actual_quantity_is_refused_for_a_subitem(): void
    {
        $client   = $this->client();
        $service  = $this->serviceWithChildren();
        $estimate = Estimate::firstOrCreate(['client_id' => $client->id], ['total' => 0]);

        $parentItem = $estimate->items()->create([
            'service_id' => $service->id, 'type' => 'recurring', 'name' => $service->name,
            'cost' => 300, 'quantity' => 4, 'total' => 1200, 'sort_order' => 0,
        ]);
        $childItem = $estimate->items()->create([
            'parent_id' => $parentItem->id, 'service_id' => $service->children->first()->id,
            'type' => 'recurring', 'name' => 'Подпункт 1',
            'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 0,
        ]);

        $log = fn (EstimateItem $item) => BuhTaskLog::create([
            'employee_id' => $this->admin->id, 'client_id' => $client->id,
            'estimate_item_id' => $item->id, 'year' => now()->year, 'month' => now()->month,
            'status' => 'running', 'paused_seconds' => 0,
        ]);

        $this->actingAs($this->admin, 'employee')
            ->postJson(route('buhtasks.logs.quantity', $log($childItem)->id), ['actual_quantity' => 5])
            ->assertStatus(422);

        $parentLog = $log($parentItem);
        $this->actingAs($this->admin, 'employee')
            ->postJson(route('buhtasks.logs.quantity', $parentLog->id), ['actual_quantity' => 5])
            ->assertOk();

        $this->assertSame(5, (int) $parentLog->fresh()->actual_quantity);
    }
}
