<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\Module;
use App\Models\Role;
use App\Models\Service;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Список клиентов: порядок «последний созданный сверху» и индикатор наличия сметы.
 * Запись estimates создаётся уже при открытии страницы сметы (firstOrCreate), поэтому
 * признаком «смета собрана» служат позиции, а не сама запись.
 */
class ClientsIndexListTest extends TestCase
{
    use DatabaseTransactions;

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

    /** Список смотрит админ: он видит всех клиентов (зона видимости — в ClientVisibilityTest). */
    private function viewer(): Employee
    {
        $role = Role::firstOrCreate(['name' => Role::ADMIN], ['display_name' => 'Администратор']);

        $employee = Employee::create([
            'full_name' => 'Смотрящий', 'position' => 'Администратор',
            'email' => uniqid('emp_') . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);

        $employee->modules()->attach(
            Module::firstOrCreate(['name' => 'clients'], ['display_name' => 'Клиенты', 'is_active' => true])->id
        );

        return $employee;
    }

    private function client(string $name, ?string $createdAt = null): Client
    {
        $client = Client::create([
            'name' => $name . ' ' . uniqid(),
            'inn'  => (string) random_int(100000000000, 999999999999),
        ]);

        if ($createdAt) {
            $client->forceFill(['created_at' => $createdAt])->save();
            $client->refresh();
        }

        return $client;
    }

    private function fillEstimate(Client $client, int $items): Estimate
    {
        $estimate = Estimate::create(['client_id' => $client->id, 'total' => 0]);
        $service  = Service::create(['name' => 'Тест БП ' . uniqid(), 'periodicity' => 'Ежемесячно', 'cost' => 0, 'is_active' => true]);

        for ($i = 0; $i < $items; $i++) {
            $estimate->items()->create([
                'service_id' => $service->id, 'type' => 'recurring', 'name' => 'Позиция ' . $i,
                'periodicity' => 'Ежемесячно', 'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => $i,
            ]);
        }

        return $estimate;
    }

    public function test_clients_with_identical_created_at_are_ordered_newest_first(): void
    {
        $viewer = $this->viewer();

        // Одинаковый created_at до секунды — как у импортированных клиентов
        $stamp = '2026-01-01 10:00:00';
        $first  = $this->client('Импорт первый', $stamp);
        $second = $this->client('Импорт второй', $stamp);
        $third  = $this->client('Импорт третий', $stamp);

        $ids = [$first->id, $second->id, $third->id];

        $clients = $this->actingAs($viewer, 'employee')->get('/clients')->assertOk()->viewData('clients');

        $ordered = $clients->pluck('id')->intersect($ids)->values()->all();

        $this->assertSame([$third->id, $second->id, $first->id], $ordered);
    }

    public function test_estimate_indicator_counts_only_estimates_with_items(): void
    {
        $viewer = $this->viewer();

        $filled = $this->client('Со сметой');
        $this->fillEstimate($filled, 3);

        // Смету только открывали — запись есть, позиций нет
        $opened = $this->client('Смета пустая');
        Estimate::create(['client_id' => $opened->id, 'total' => 0]);

        $none = $this->client('Без сметы');

        $clients = $this->actingAs($viewer, 'employee')->get('/clients')->assertOk()->viewData('clients');
        $counts  = $clients->keyBy('id');

        $this->assertSame(3, $counts[$filled->id]->estimate_root_items_count);
        $this->assertSame(0, $counts[$opened->id]->estimate_root_items_count);
        $this->assertSame(0, $counts[$none->id]->estimate_root_items_count);
    }

    public function test_child_positions_still_count_as_a_filled_estimate(): void
    {
        $viewer = $this->viewer();

        // Позиция с потомком: в смете 2 строки, корневая одна — плашка обязана быть
        $client   = $this->client('Смета с подпозицией');
        $estimate = $this->fillEstimate($client, 1);
        $root     = $estimate->items()->first();
        $estimate->items()->create([
            'parent_id' => $root->id, 'service_id' => $root->service_id, 'type' => 'recurring',
            'name' => 'Филиал', 'periodicity' => 'Ежемесячно', 'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 1,
        ]);

        $clients = $this->actingAs($viewer, 'employee')->get('/clients')->assertOk()->viewData('clients');

        $this->assertSame(1, $clients->keyBy('id')[$client->id]->estimate_root_items_count);
    }

    public function test_indicator_reaches_the_rendered_page(): void
    {
        $viewer = $this->viewer();

        $filled = $this->client('Отрисовка со сметой');
        $this->fillEstimate($filled, 3);
        $empty = $this->client('Отрисовка без сметы');

        $html = $this->actingAs($viewer, 'employee')->get('/clients')->assertOk()->getContent();

        // Данные строк уходят в Alpine через @js, а он экранирует кавычки как " — разэкранируем
        $html = str_replace(chr(92) . 'u0022', chr(34), $html);

        $q = chr(34);
        $row = fn (int $id, int $count) => '/'
            . preg_quote("{$q}id{$q}:{$id},", '/') . '.*?'
            . preg_quote("{$q}estimate_items_count{$q}:{$count}", '/') . '/';

        $this->assertStringContainsString("{$q}id{$q}:{$filled->id},", $html);
        $this->assertMatchesRegularExpression($row($filled->id, 3), $html);
        $this->assertMatchesRegularExpression($row($empty->id, 0), $html);
        $this->assertStringContainsString("client.estimate_items_count > 0", $html);
        $this->assertStringContainsString("'/clients/' + client.id + '/estimate/edit'", $html);
    }

    public function test_search_returns_estimate_indicator_too(): void
    {
        $viewer = $this->viewer();

        $filled = $this->client('Поисковый со сметой');
        $this->fillEstimate($filled, 2);

        $response = $this->actingAs($viewer, 'employee')
            ->get('/clients/search?q=' . urlencode($filled->name))
            ->assertOk();

        $row = collect($response->json())->firstWhere('id', $filled->id);

        $this->assertNotNull($row, 'Клиент должен находиться поиском');
        $this->assertSame(2, $row['estimate_items_count']);
    }
}
