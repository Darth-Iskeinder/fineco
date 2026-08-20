<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\Module;
use App\Models\Periodicity;
use App\Models\Role;
use App\Models\Service;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Уволенного не предлагают в исполнители.
 *
 * Статусов у сотрудника два, и путать их дорого: `status` — про вход в систему,
 * `employment_status` — про то, работает ли человек в фирме. Увольнение через
 * карточку меняет второе, аккаунт при этом остаётся активным — а подборки
 * смотрели только на первое и предлагали уволенных наравне со всеми.
 */
class FiredEmployeeNotOfferedTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $head;
    private Employee $working;
    private Employee $fired;
    private Client $client;

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
        $role = Role::firstOrCreate(['name' => Role::ACCOUNTANT], ['display_name' => 'Бухгалтер']);

        foreach (['buhtasks' => 'БухЗадачник', 'clients' => 'Клиенты'] as $name => $title) {
            $module = Module::firstOrCreate(['name' => $name], ['display_name' => $title, 'is_active' => true]);
            $modules[] = $module->id;
        }

        $make = fn (string $prefix, array $extra = []) => Employee::create(array_merge([
            'full_name' => 'Тест ' . $prefix, 'position' => $prefix,
            'email' => $prefix . '_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ], $extra));

        // Исполнителей в смете назначает главбух клиента — им и смотрим.
        $headRole = Role::firstOrCreate(['name' => Role::HEAD_ACCOUNTANT], ['display_name' => 'Главбух']);
        $this->head    = $make('head', ['role_id' => $headRole->id]);
        $this->working = $make('working');
        // Уволен, но аккаунт не заблокирован — так увольнение и оформляется в карточке.
        $this->fired   = $make('fired', ['employment_status' => Employee::EMPLOYMENT_FIRED]);

        foreach ([$this->head, $this->working, $this->fired] as $e) {
            $e->modules()->syncWithoutDetaching($modules);
        }

        $this->client = Client::create([
            'name' => 'ТОО Увольнение ' . uniqid(),
            'inn' => strtoupper(substr(md5(uniqid()), 0, 12)),
            'responsible_employee_id' => $this->head->id,
        ]);
    }

    /** @return array<int, array<string, mixed>> Кандидаты в исполнители БП на странице сметы. */
    private function assigneeOptions(): array
    {
        return $this->actingAs($this->head, 'employee')
            ->get(route('clients.estimate.edit', $this->client))
            ->assertOk()
            ->viewData('assigneeOptions');
    }

    public function test_estimate_does_not_offer_a_fired_accountant(): void
    {
        $ids = array_column($this->assigneeOptions(), 'id');

        $this->assertContains($this->working->id, $ids, 'Работающий бухгалтер пропал из кандидатов');
        $this->assertNotContains($this->fired->id, $ids, 'Уволенный предлагается в исполнители');
    }

    /**
     * Тот, на ком позиция уже стоит, из списка не исчезает — иначе селект показал бы
     * пустоту вместо реального исполнителя, а сохранение молча переставило бы задачу.
     */
    public function test_already_assigned_fired_employee_stays_in_the_list(): void
    {
        $service = Service::create([
            'name' => 'Тест отчёт ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_day' => [5], 'is_active' => true,
        ]);
        $estimate = Estimate::create(['client_id' => $this->client->id, 'total' => 0]);
        $estimate->items()->create([
            'service_id' => $service->id, 'type' => 'recurring',
            'name' => $service->name, 'periodicity' => 'Ежемесячно',
            'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 0,
            'assignee_id' => $this->fired->id,
        ]);

        $ids = array_column($this->assigneeOptions(), 'id');

        $this->assertContains($this->fired->id, $ids, 'Действующий исполнитель пропал из селекта');
    }

    public function test_buhtasks_does_not_offer_a_fired_employee(): void
    {
        $employees = $this->actingAs($this->head, 'employee')
            ->get(route('buhtasks.index'))
            ->assertOk()
            ->viewData('employees');

        $ids = $employees->pluck('id')->all();

        $this->assertContains($this->working->id, $ids, 'Работающий сотрудник пропал из списка');
        $this->assertNotContains($this->fired->id, $ids, 'Уволенный предлагается для назначения задачи');
    }

    /** Список — не единственная дверь: форму можно отправить и мимо него. */
    public function test_adhoc_task_cannot_be_assigned_to_a_fired_employee(): void
    {
        $this->actingAs($this->head, 'employee')
            ->post(route('buhtasks.adhoc.store'), [
                'employee_id' => $this->fired->id,
                'name'        => 'Разовая задача',
                'due_date'    => now()->addWeek()->toDateString(),
            ])
            ->assertNotFound();
    }
}
