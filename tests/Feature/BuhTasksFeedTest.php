<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\Periodicity;
use App\Models\Role;
use App\Models\Service;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Эндпоинт серверной пагинации списка задач (buhtasks/feed) через полный HTTP-стек.
 * mysql + транзакция с откатом (как в GenerateTaskRemindersTest — pdo_sqlite в среде нет).
 */
class BuhTasksFeedTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $employee;

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

        Periodicity::firstOrCreate(['name' => 'Ежемесячно'], ['kind' => 'monthly']);
        // admin — чтобы middleware module:buhtasks пропустил (у админа доступ ко всем модулям)
        $role = Role::firstOrCreate(['name' => 'admin'], ['display_name' => 'Администратор']);
        $this->employee = Employee::create([
            'full_name' => 'Feed Tester', 'position' => 'Б',
            'email' => 'feed_' . uniqid() . '@t.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);

        // Один клиент, одна ежемесячная позиция, старт 6 мес назад → ~7 экземпляров задачи
        $svc = Service::create([
            'name' => 'FEED svc', 'periodicity' => 'Ежемесячно',
            'start_month' => [], 'start_day' => [10], 'is_active' => true,
        ]);
        $client = Client::create([
            'name' => 'FEED client', 'inn' => 'FEED' . substr(uniqid(), -7),
            'responsible_employee_id' => $this->employee->id,
            'service_start_date' => now()->subMonths(6)->toDateString(),
        ]);
        $est = Estimate::create(['client_id' => $client->id, 'total' => 0]);
        $est->items()->create([
            'service_id' => $svc->id, 'type' => 'recurring', 'name' => 'FEED svc',
            'periodicity' => 'Ежемесячно', 'cost' => 1000, 'quantity' => 1, 'total' => 1000, 'sort_order' => 0,
        ]);
    }

    public function test_index_loads_and_feed_returns_a_page(): void
    {
        $this->actingAs($this->employee, 'employee');

        // index наполняет кэш и отдаёт первую страницу
        $this->get('/buhtasks')->assertOk();

        // feed отдаёт срез из кэша (JSON, не редирект на логин и не 500)
        $res = $this->getJson('/buhtasks/feed?offset=0&filter=all&sort=');
        $res->assertOk()->assertJsonStructure(['tasks', 'total', 'hasMore']);

        $this->assertGreaterThan(0, $res->json('total'), 'feed должен вернуть ненулевой total');
        $this->assertIsArray($res->json('tasks'));

        // вторая страница тоже отвечает корректно
        $this->getJson('/buhtasks/feed?offset=20&filter=all&sort=')
            ->assertOk()->assertJsonStructure(['tasks', 'total', 'hasMore']);
    }
}
