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
 * Тумблер «По событию» в карточке БП.
 *
 * Не периодичность, а надстройка над ней: расписание у БП остаётся своё.
 * Включённый тумблер требует дочернего БП, и дочерним годится только
 * действующий основной БП с периодичностью «По запросу».
 */
class EventTriggerSettingsTest extends TestCase
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
            'email' => 'evt_admin_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);

        Periodicity::firstOrCreate(['name' => 'Ежемесячно'], ['kind' => 'monthly']);
        Periodicity::firstOrCreate(['name' => 'По запросу'], ['kind' => Service::KIND_ON_REQUEST]);
    }

    private function onRequestService(array $extra = []): Service
    {
        return Service::create(array_merge([
            'name' => 'Приём дел ' . uniqid(), 'periodicity' => 'По запросу',
            'deadline_days' => 3, 'is_active' => true, 'cost' => 0,
        ], $extra));
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'БП-родитель ' . uniqid(),
            'cost' => 0,
            'periodicity' => 'По запросу',
            'deadline_days' => 1,
        ], $overrides);
    }

    /** Тумблер и дочерний БП сохраняются. Периодичность родителя при этом не трогается. */
    public function test_toggle_and_child_are_saved(): void
    {
        $child = $this->onRequestService();

        $response = $this->actingAs($this->admin, 'employee')
            ->postJson('/settings/services', $this->payload([
                'triggers_on_event' => true,
                'event_child_service_id' => $child->id,
            ]))
            ->assertOk()
            ->assertJsonPath('item.triggers_on_event', true)
            ->assertJsonPath('item.event_child_service_id', $child->id)
            ->assertJsonPath('item.event_child_name', $child->name)
            ->assertJsonPath('item.periodicity', 'По запросу');

        $saved = Service::find($response->json('item.id'));
        $this->assertTrue($saved->firesOnEvent());
    }

    /** Тумблер включён, дочерний не выбран — сохранить нельзя. */
    public function test_toggle_without_child_is_rejected(): void
    {
        $this->actingAs($this->admin, 'employee')
            ->postJson('/settings/services', $this->payload(['triggers_on_event' => true]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('event_child_service_id')
            ->assertJsonPath('message', 'Включено «По событию» — выберите дочерний БП, задача по которому создастся после выполнения.');
    }

    /** Дочерним может быть только БП «По запросу»: у остальных срок считается датами. */
    public function test_dated_service_cannot_be_a_child(): void
    {
        $dated = Service::create([
            'name' => 'Ежемесячный ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_day' => [5], 'is_active' => true, 'cost' => 0,
        ]);

        $this->actingAs($this->admin, 'employee')
            ->postJson('/settings/services', $this->payload([
                'triggers_on_event' => true,
                'event_child_service_id' => $dated->id,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('event_child_service_id');
    }

    /** Архивный дочерний тоже не годится: задач по нему больше не заводят. */
    public function test_archived_service_cannot_be_a_child(): void
    {
        $archived = $this->onRequestService(['archived_at' => now()->endOfMonth()]);

        $this->actingAs($this->admin, 'employee')
            ->postJson('/settings/services', $this->payload([
                'triggers_on_event' => true,
                'event_child_service_id' => $archived->id,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('event_child_service_id');
    }

    /** Подпункт дочерним быть не может: в разовой задаче он всего лишь галочка в чеклисте. */
    public function test_child_subitem_cannot_be_a_child(): void
    {
        $root    = $this->onRequestService();
        $subitem = $root->children()->create([
            'name' => 'Подпункт', 'cost' => 0, 'periodicity' => 'По запросу', 'is_active' => true, 'sort_order' => 0,
        ]);

        $this->actingAs($this->admin, 'employee')
            ->postJson('/settings/services', $this->payload([
                'triggers_on_event' => true,
                'event_child_service_id' => $subitem->id,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('event_child_service_id');
    }

    /** Сам себе родителем БП быть не может. */
    public function test_service_cannot_point_at_itself(): void
    {
        $service = $this->onRequestService();

        $this->actingAs($this->admin, 'employee')
            ->putJson('/settings/services/' . $service->id, $this->payload([
                'name' => $service->name,
                'triggers_on_event' => true,
                'event_child_service_id' => $service->id,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('event_child_service_id');
    }

    /** Выключили тумблер — ссылка на дочерний гаснет, хвоста не остаётся. */
    public function test_turning_toggle_off_clears_the_child(): void
    {
        $child  = $this->onRequestService();
        $parent = Service::create([
            'name' => 'Родитель ' . uniqid(), 'periodicity' => 'По запросу', 'deadline_days' => 1,
            'is_active' => true, 'cost' => 0,
            'triggers_on_event' => true, 'event_child_service_id' => $child->id,
        ]);

        $this->actingAs($this->admin, 'employee')
            ->putJson('/settings/services/' . $parent->id, $this->payload([
                'name' => $parent->name,
                'triggers_on_event' => false,
                'event_child_service_id' => $child->id, // прислали, но тумблер выключен
            ]))
            ->assertOk()
            ->assertJsonPath('item.triggers_on_event', false)
            ->assertJsonPath('item.event_child_service_id', null);

        $this->assertFalse($parent->fresh()->firesOnEvent());
    }

    /** Страница каталога рисуется, когда в нём есть БП с включённым событием. */
    public function test_catalog_page_renders_with_event_trigger(): void
    {
        $child = $this->onRequestService();
        Service::create([
            'name' => 'Родитель ' . uniqid(), 'periodicity' => 'По запросу', 'deadline_days' => 1,
            'is_active' => true, 'cost' => 0,
            'triggers_on_event' => true, 'event_child_service_id' => $child->id,
        ]);

        $this->actingAs($this->admin, 'employee')
            ->get('/settings/services')
            ->assertOk()
            ->assertSee('По событию')
            // Правило одной ступени должно быть написано в карточке, а не только в голове
            ->assertSee('Цепочка идёт на одну ступень', false);
    }

    /** У подпункта тумблера нет: даже присланный, он не сохраняется. */
    public function test_subitem_never_gets_the_toggle(): void
    {
        $child   = $this->onRequestService();
        $root    = $this->onRequestService();
        $subitem = $root->children()->create([
            'name' => 'Подпункт', 'cost' => 0, 'periodicity' => 'По запросу', 'is_active' => true, 'sort_order' => 0,
        ]);

        $this->actingAs($this->admin, 'employee')
            ->putJson('/settings/services/' . $subitem->id, $this->payload([
                'name' => $subitem->name,
                'triggers_on_event' => true,
                'event_child_service_id' => $child->id,
            ]))
            ->assertOk();

        $fresh = $subitem->fresh();
        $this->assertFalse((bool) $fresh->triggers_on_event);
        $this->assertNull($fresh->event_child_service_id);
    }
}
