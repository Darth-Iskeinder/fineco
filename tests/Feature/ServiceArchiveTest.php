<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\Module;
use App\Models\Periodicity;
use App\Models\Role;
use App\Models\Service;
use App\Models\TaskReminder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Архивация бизнес-процесса вместо удаления.
 *
 * БП, который уже ведут, удалять нельзя: расписание живёт в самом БП, и его
 * исчезновение молча перекраивает работу у всех клиентов сразу — квартальная
 * декларация превращается в ежемесячную задачу, индивидуальные расписания
 * уходят каскадом. Взамен есть архивация: закрывает будущее, не трогая прошлое.
 *
 * Режем по месяцам, а не по числу: месяц — единица бухгалтерской работы, и
 * отчётность за текущий сдавать всё равно.
 */
class ServiceArchiveTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $admin;
    private Service $service;

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
        Module::firstOrCreate(['name' => 'settings'], ['display_name' => 'Настройки', 'is_active' => true]);

        $this->admin = Employee::create([
            'full_name' => 'Тест Админ', 'position' => 'Админ',
            'email' => 'arch_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => Role::firstOrCreate(['name' => Role::ADMIN], ['display_name' => 'Администратор'])->id,
            'status' => Employee::STATUS_ACTIVE,
        ]);

        $this->service = Service::create([
            'name' => 'Декларация ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_day' => [5], 'is_active' => true,
        ]);
    }

    /** Ставит БП в чью-нибудь смету — то есть «берёт в работу». */
    private function putIntoUse(): Client
    {
        $client = Client::create([
            'name' => 'ООО Архив ' . uniqid(),
            'inn' => strtoupper(substr(md5(uniqid()), 0, 12)),
            'responsible_employee_id' => $this->admin->id,
        ]);

        $estimate = Estimate::create(['client_id' => $client->id, 'total' => 0]);
        $estimate->items()->create([
            'service_id' => $this->service->id, 'type' => 'recurring',
            'name' => $this->service->name, 'periodicity' => 'Ежемесячно',
            'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 0,
        ]);

        return $client;
    }

    public function test_unused_service_is_deleted_as_before(): void
    {
        $this->actingAs($this->admin, 'employee')
            ->deleteJson(route('settings.services.destroy', $this->service))
            ->assertOk()
            ->assertJson(['success' => true]);

        $this->assertNull(Service::find($this->service->id));
    }

    public function test_service_in_use_is_not_deleted(): void
    {
        $this->putIntoUse();

        $this->actingAs($this->admin, 'employee')
            ->deleteJson(route('settings.services.destroy', $this->service))
            ->assertStatus(422)
            ->assertJson(['success' => false]);

        $this->assertNotNull(Service::find($this->service->id), 'Используемый БП удалили');
    }

    /** Подпункт в работе тоже держит родителя: удаление родителя уносит и детей. */
    public function test_parent_is_protected_by_its_child_in_use(): void
    {
        $child = Service::create([
            'name' => 'Подпункт ' . uniqid(), 'parent_id' => $this->service->id, 'is_active' => true,
        ]);

        $client = Client::create([
            'name' => 'ООО Подпункт ' . uniqid(),
            'inn' => strtoupper(substr(md5(uniqid()), 0, 12)),
            'responsible_employee_id' => $this->admin->id,
        ]);
        $estimate = Estimate::create(['client_id' => $client->id, 'total' => 0]);
        $estimate->items()->create([
            'service_id' => $child->id, 'type' => 'recurring', 'name' => $child->name,
            'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 0,
        ]);

        $this->actingAs($this->admin, 'employee')
            ->deleteJson(route('settings.services.destroy', $this->service))
            ->assertStatus(422);

        $this->assertNotNull(Service::find($this->service->id));
    }

    public function test_archiving_closes_the_current_month(): void
    {
        $this->actingAs($this->admin, 'employee')
            ->postJson(route('settings.services.archive', $this->service))
            ->assertOk()
            ->assertJson(['success' => true]);

        $service = $this->service->fresh();

        $this->assertFalse((bool) $service->is_active);
        $this->assertSame(now()->endOfMonth()->toDateString(), $service->archived_at->toDateString());
        $this->assertNull($service->active_from);
        $this->assertTrue($service->isArchived());
    }

    public function test_restoring_starts_next_month(): void
    {
        $this->actingAs($this->admin, 'employee')->postJson(route('settings.services.archive', $this->service));

        $this->actingAs($this->admin, 'employee')
            ->postJson(route('settings.services.restore', $this->service))
            ->assertOk();

        $service = $this->service->fresh();

        $this->assertTrue((bool) $service->is_active);
        $this->assertNull($service->archived_at);
        $this->assertSame(
            now()->addMonth()->startOfMonth()->toDateString(),
            $service->active_from->toDateString(),
            'Возврат задним числом насыпал бы просрочку за время простоя',
        );
    }

    /**
     * Страница спрашивает про использование до открытия окна удаления: если
     * процесс уже ведут, человеку сразу показывают архивацию, а не отказ после
     * нажатия «Удалить».
     */
    /**
     * Даты в ответе — короткой строкой YYYY-MM-DD.
     *
     * Полный ISO со временем страница разбирала как дату и показывала
     * «с 01T00:00:00.000000Z.09.2026».
     */
    public function test_dates_come_back_in_the_short_form(): void
    {
        $archived = $this->actingAs($this->admin, 'employee')
            ->postJson(route('settings.services.archive', $this->service))
            ->assertOk()
            ->json('item');

        $this->assertSame(now()->endOfMonth()->toDateString(), $archived['archived_at']);

        $restored = $this->actingAs($this->admin, 'employee')
            ->postJson(route('settings.services.restore', $this->service))
            ->assertOk()
            ->json('item');

        $this->assertSame(now()->addMonth()->startOfMonth()->toDateString(), $restored['active_from']);
        $this->assertNull($restored['archived_at']);
    }

    public function test_usage_answers_whether_the_service_is_in_use(): void
    {
        $this->actingAs($this->admin, 'employee')
            ->getJson(route('settings.services.usage', $this->service))
            ->assertOk()
            ->assertJson(['in_use' => false, 'clients' => 0]);

        $this->putIntoUse();
        $this->putIntoUse();

        $this->actingAs($this->admin, 'employee')
            ->getJson(route('settings.services.usage', $this->service))
            ->assertOk()
            ->assertJson(['in_use' => true, 'clients' => 2]);
    }

    /** Архивный БП перестаёт заводить напоминания со следующего месяца. */
    public function test_archived_service_stops_generating_next_month(): void
    {
        $client = $this->putIntoUse();
        $client->update(['service_start_date' => '2026-01-01']);
        $client->estimates()->first()->forceFill(['created_at' => '2026-01-01 00:00:00'])->save();

        $this->service->update([
            'is_active'   => false,
            'archived_at' => '2026-08-31',
        ]);

        $this->artisan('tasks:generate', ['--date' => '2026-08-05', '--horizon' => 120, '--lookback' => 0])
            ->assertSuccessful();

        $dates = TaskReminder::where('service_id', $this->service->id)
            ->orderBy('due_date')->pluck('due_date')->map(fn ($d) => $d->toDateString())->all();

        $this->assertSame(['2026-08-05'], $dates, 'Архивный БП продолжил заводить задачи после месяца архивации');
    }
}
