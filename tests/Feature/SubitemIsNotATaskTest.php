<?php

namespace Tests\Feature;

use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Module;
use App\Models\Periodicity;
use App\Models\Role;
use App\Models\Service;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Tests\TestCase;

/**
 * Подпункт — строка внутри основного БП, а не самостоятельная задача.
 *
 * Раньше отмеченная галочка подпункта заводила свой лог и вставала во «Выполненных»
 * отдельной строкой рядом со своим же БП, а в акте выполненных работ ещё и добавляла
 * свою цену поверх родительской (родительский total и так сумма подпунктов).
 * И карточка подпункта в настройках позволяла задать ему расписание и проверку,
 * которых у подпункта быть не может.
 */
class SubitemIsNotATaskTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $accountant;
    private Employee $head;
    private Client $client;
    private Estimate $estimate;

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
            ['name' => 'buhtasks'],
            ['display_name' => 'БухЗадачник', 'is_active' => true],
        );

        $smeta = Module::firstOrCreate(
            ['name' => 'buhsmeta'],
            ['display_name' => 'БухСмета', 'is_active' => true],
        );

        $this->accountant = $this->makeEmployee(Role::ACCOUNTANT, 'Бухгалтер');
        $this->head       = $this->makeEmployee(Role::HEAD_ACCOUNTANT, 'Главбух');
        $this->accountant->modules()->attach($module->id);
        $this->head->modules()->attach([$module->id, $smeta->id]);

        $name = 'ТОО Подпункт ' . uniqid();
        $this->client = Client::create([
            'name' => $name, 'inn' => strtoupper(substr(md5($name), 0, 12)),
            'responsible_employee_id' => $this->head->id, 'is_active' => true,
        ]);

        $this->estimate = Estimate::firstOrCreate(['client_id' => $this->client->id], ['total' => 0]);
    }

    private function makeEmployee(string $roleName, string $label): Employee
    {
        $role = Role::firstOrCreate(['name' => $roleName], ['display_name' => $label]);

        return Employee::create([
            'full_name' => 'Тест ' . $label, 'position' => $label,
            'email' => substr($roleName, 0, 4) . '_' . uniqid() . '@test.kg',
            'password' => bcrypt('x'), 'role_id' => $role->id,
            'status' => Employee::STATUS_ACTIVE,
        ]);
    }

    /** Позиция сметы со своим БП. Подпункт — та же пара, но с родителем. */
    private function makeItem(?EstimateItem $parent = null, array $serviceAttributes = []): EstimateItem
    {
        $service = Service::create(array_merge([
            'name'      => ($parent ? 'Подпункт ' : 'БП ') . uniqid(),
            'parent_id' => $parent?->service_id,
            'is_active' => true,
        ], $serviceAttributes));

        return $this->estimate->items()->create([
            'parent_id'  => $parent?->id,
            'service_id' => $service->id,
            'type'       => 'recurring',
            'name'       => $service->name,
            'cost'       => 0,
            'quantity'   => 1,
            'total'      => 0,
            'sort_order' => 0,
        ]);
    }

    private function makeLog(EstimateItem $item, string $status = 'completed'): BuhTaskLog
    {
        $now = now();

        return BuhTaskLog::create([
            'employee_id'      => $this->accountant->id,
            'client_id'        => $this->client->id,
            'estimate_item_id' => $item->id,
            'year'             => $now->year,
            'month'            => $now->month,
            'status'           => $status,
            'completed_at'     => $status === 'completed' ? $now : null,
            'paused_seconds'   => 0,
        ]);
    }

    public function test_completed_subitem_is_not_a_separate_row(): void
    {
        $parent = $this->makeItem();
        $child  = $this->makeItem($parent);
        $this->makeLog($parent);
        $this->makeLog($child);

        $names = array_column(
            $this->actingAs($this->accountant, 'employee')
                ->get(route('buhtasks.index'))
                ->assertOk()
                ->viewData('completed'),
            'name',
        );

        $this->assertContains($parent->name, $names);
        $this->assertNotContains($child->name, $names, 'Подпункт попал во «Выполненные» отдельной строкой');
    }

    /** Акт выполненных работ: цена подпункта уже сидит в строке основного БП. */
    public function test_completed_subitem_is_not_a_line_of_the_act(): void
    {
        $parent = $this->makeItem();
        $child  = $this->makeItem($parent);
        $this->makeLog($parent);
        $this->makeLog($child);

        // Текст готового PDF не прочитать, поэтому перехватываем строки, которые
        // контроллер отдаёт в его шаблон.
        $lines = [];
        View::composer('pdf.avr', function ($view) use (&$lines) {
            $lines = collect($view->getData()['tasks'])->pluck('name')->all();
        });

        $now = now();
        $this->actingAs($this->head, 'employee')->get(
            route('buhsmeta.avr', ['client' => $this->client->id, 'year' => $now->year, 'month' => $now->month])
        )->assertOk();

        $this->assertSame([$parent->name], $lines);
    }

    /** Галочка подпункта закрывает его сразу: отдельного «на проверке» у подпункта нет. */
    public function test_subitem_never_goes_to_review(): void
    {
        $parent = $this->makeItem(null, ['requires_review' => true]);
        $child  = $this->makeItem($parent, ['requires_review' => true]);
        $childLog = $this->makeLog($child, 'running');

        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.logs.complete', $childLog->id))
            ->assertSuccessful();

        $this->assertSame('completed', $childLog->fresh()->status);
        $this->assertNotNull($childLog->fresh()->completed_at);
    }

    /** Родительский БП с проверкой ведёт себя как раньше: уходит в review. */
    public function test_parent_still_goes_to_review(): void
    {
        $parent = $this->makeItem(null, ['requires_review' => true]);
        $log    = $this->makeLog($parent, 'running');

        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.logs.complete', $log->id))
            ->assertSuccessful();

        $this->assertSame('review', $log->fresh()->status);
    }

    /** Настройки: сохранение основного БП гасит у подпунктов расписание и документ. */
    public function test_saving_parent_clears_subitem_schedule_and_document(): void
    {
        $admin = $this->makeEmployee(Role::ADMIN, 'Админ');

        $service = Service::create(['name' => 'БП ' . uniqid(), 'cost' => 0, 'is_active' => true]);
        $child   = $service->children()->create([
            'name' => 'Подпункт', 'cost' => 0, 'is_active' => true,
            'periodicity' => 'Ежемесячно', 'start_day' => [5], 'due_day' => 5,
            'requires_document' => true, 'requires_review' => true,
        ]);

        $this->actingAs($admin, 'employee')->putJson('/settings/services/' . $service->id, [
            'name'     => $service->name,
            'cost'     => 0,
            'children' => [
                ['id' => $child->id, 'name' => 'Подпункт', 'cost' => 0],
            ],
        ])->assertSuccessful();

        $child->refresh();
        $this->assertNull($child->periodicity);
        $this->assertNull($child->due_day);
        $this->assertNull($child->start_day);
        $this->assertFalse((bool) $child->requires_document);
        $this->assertFalse((bool) $child->requires_review);
    }
}
