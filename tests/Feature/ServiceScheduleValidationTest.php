<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Periodicity;
use App\Models\Role;
use App\Models\Service;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Расписание БП заполняется целиком или не заполняется вовсе.
 *
 * Периодичность без дня срока даёт ноль дат в Service::computeDueDates: ветка monthly
 * первым делом берёт $days[0] и при null делает break. При этом «расписание есть»
 * определяется по непустой периодичности, поэтому такой БП не проваливается и в ветку
 * «задача текущего месяца» — он не порождает задач вообще никогда, молча.
 *
 * На проде так простоял ежемесячный «Контроль сдачи отчетов и оплаты налогов»:
 * назначен примерно по 40 клиентам на четырёх главбухов, ни одной задачи за всё время.
 */
class ServiceScheduleValidationTest extends TestCase
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
            'email' => 'ssv_admin_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);

        Periodicity::firstOrCreate(['name' => 'Ежемесячно'], ['kind' => 'monthly']);
        Periodicity::firstOrCreate(['name' => 'По запросу'], ['kind' => Service::KIND_ON_REQUEST]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Тестовый БП ' . uniqid(),
            'cost' => 1000,
        ], $overrides);
    }

    // === Создание БП ===

    /**
     * Форма настроек показывает тостом поле `message` ответа (services.blade.php:826),
     * а Laravel кладёт туда первую ошибку валидации — значит сотрудник увидит именно
     * объяснение с последствием, а не общее «Ошибка сохранения».
     */
    public function test_store_rejects_periodicity_without_day(): void
    {
        $this->actingAs($this->admin, 'employee')
            ->postJson('/settings/services', $this->payload(['periodicity' => 'Ежемесячно']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_day')
            ->assertJsonPath('message', 'Выбрана периодичность — укажите день срока, иначе задачи по этому БП создаваться не будут.');
    }

    public function test_store_rejects_periodicity_with_empty_day_array(): void
    {
        $this->actingAs($this->admin, 'employee')
            ->postJson('/settings/services', $this->payload([
                'periodicity' => 'Ежемесячно',
                'start_day'   => [],
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_day');
    }

    public function test_store_allows_periodicity_with_day(): void
    {
        $this->actingAs($this->admin, 'employee')
            ->postJson('/settings/services', $this->payload([
                'periodicity' => 'Ежемесячно',
                'start_day'   => [15],
            ]))
            ->assertSuccessful();
    }

    /** БП без периодичности работает и без дня — он показывается как задача текущего месяца. */
    public function test_store_allows_no_periodicity_and_no_day(): void
    {
        $this->actingAs($this->admin, 'employee')
            ->postJson('/settings/services', $this->payload())
            ->assertSuccessful();
    }

    /** Пустая строка периодичности — это «не выбрана», а не «выбрана и пустая». */
    public function test_store_allows_empty_periodicity_string(): void
    {
        $this->actingAs($this->admin, 'employee')
            ->postJson('/settings/services', $this->payload(['periodicity' => '']))
            ->assertSuccessful();
    }

    // === «По запросу»: дат нет, вместо них обязательный срок в днях ===

    public function test_store_rejects_on_request_without_deadline_days(): void
    {
        $this->actingAs($this->admin, 'employee')
            ->postJson('/settings/services', $this->payload(['periodicity' => 'По запросу']))
            ->assertStatus(422)
            ->assertJsonValidationErrors('deadline_days')
            ->assertJsonPath('message', 'Выбрана периодичность «По запросу» — укажите срок выполнения в днях, от него считается дата задачи.');
    }

    public function test_store_rejects_on_request_with_zero_days(): void
    {
        $this->actingAs($this->admin, 'employee')
            ->postJson('/settings/services', $this->payload([
                'periodicity'   => 'По запросу',
                'deadline_days' => 0,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('deadline_days');
    }

    /** День срока у «По запросу» не спрашивается: дат у такой периодичности нет. */
    public function test_store_allows_on_request_with_days_and_without_start_day(): void
    {
        $name = 'БП по запросу ' . uniqid();

        $this->actingAs($this->admin, 'employee')
            ->postJson('/settings/services', $this->payload([
                'name'          => $name,
                'periodicity'   => 'По запросу',
                'deadline_days' => 3,
            ]))
            ->assertSuccessful();

        $service = Service::where('name', $name)->firstOrFail();
        $this->assertSame(3, $service->deadline_days);
        $this->assertTrue($service->isOnRequest());
        $this->assertSame([], $service->dueDatesBetween(now()->startOfYear(), now()->endOfYear()));
    }

    /** Смена периодичности на датированную гасит срок в днях, и наоборот. */
    public function test_switching_periodicity_clears_the_other_schedule_fields(): void
    {
        $name = 'БП со сменой периодичности ' . uniqid();

        $this->actingAs($this->admin, 'employee')
            ->postJson('/settings/services', $this->payload([
                'name'        => $name,
                'periodicity' => 'Ежемесячно',
                'start_day'   => [15],
            ]))
            ->assertSuccessful();

        $service = Service::where('name', $name)->firstOrFail();

        $this->actingAs($this->admin, 'employee')
            ->putJson('/settings/services/' . $service->id, $this->payload([
                'name'          => $name,
                'periodicity'   => 'По запросу',
                'deadline_days' => 5,
            ]))
            ->assertSuccessful();

        $service->refresh();
        $this->assertSame(5, $service->deadline_days);
        $this->assertNull($service->start_day);

        $this->actingAs($this->admin, 'employee')
            ->putJson('/settings/services/' . $service->id, $this->payload([
                'name'        => $name,
                'periodicity' => 'Ежемесячно',
                'start_day'   => [15],
            ]))
            ->assertSuccessful();

        $service->refresh();
        $this->assertNull($service->deadline_days);
        $this->assertSame([15], $service->start_day);
    }

    // === Редактирование БП ===

    public function test_update_rejects_periodicity_without_day(): void
    {
        $service = Service::create([
            'name' => 'БП для правки ' . uniqid(), 'cost' => 500,
        ]);

        $this->actingAs($this->admin, 'employee')
            ->putJson('/settings/services/' . $service->id, $this->payload([
                'name'        => $service->name,
                'periodicity' => 'Ежемесячно',
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_day');
    }

    public function test_update_allows_periodicity_with_day(): void
    {
        $service = Service::create([
            'name' => 'БП для правки ' . uniqid(), 'cost' => 500,
        ]);

        $this->actingAs($this->admin, 'employee')
            ->putJson('/settings/services/' . $service->id, $this->payload([
                'name'        => $service->name,
                'periodicity' => 'Ежемесячно',
                'start_day'   => [10],
            ]))
            ->assertSuccessful();

        $this->assertSame([10], $service->fresh()->start_day);
    }

    // === Индивидуальное расписание клиента ===
    // Отдельная дверь в ту же дыру: без проверки здесь БП с полным расписанием
    // можно было переопределить у клиента периодичностью без дня.

    public function test_client_override_rejects_periodicity_without_day(): void
    {
        $client  = Client::first();
        $service = Service::first();

        if (!$client || !$service) {
            $this->markTestSkipped('Нет клиента или БП в базе для проверки override.');
        }

        $this->actingAs($this->admin, 'employee')
            ->putJson("/clients/{$client->id}/services/{$service->id}/schedule", [
                'periodicity' => 'Ежемесячно',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('start_day');
    }

    public function test_client_override_allows_periodicity_with_day(): void
    {
        $client  = Client::first();
        $service = Service::first();

        if (!$client || !$service) {
            $this->markTestSkipped('Нет клиента или БП в базе для проверки override.');
        }

        $this->actingAs($this->admin, 'employee')
            ->putJson("/clients/{$client->id}/services/{$service->id}/schedule", [
                'periodicity' => 'Ежемесячно',
                'start_day'   => [20],
            ])
            ->assertSuccessful();
    }

    /** У «По запросу» дня срока нет, и требовать его в override нельзя. */
    public function test_client_override_allows_on_request_without_day(): void
    {
        $client  = Client::first();
        $service = Service::first();

        if (!$client || !$service) {
            $this->markTestSkipped('Нет клиента или БП в базе для проверки override.');
        }

        $this->actingAs($this->admin, 'employee')
            ->putJson("/clients/{$client->id}/services/{$service->id}/schedule", [
                'periodicity' => 'По запросу',
            ])
            ->assertSuccessful();
    }

    /** Сброс расписания (периодичность не передана) валидацию не задевает. */
    public function test_client_override_allows_clearing_schedule(): void
    {
        $client  = Client::first();
        $service = Service::first();

        if (!$client || !$service) {
            $this->markTestSkipped('Нет клиента или БП в базе для проверки override.');
        }

        $this->actingAs($this->admin, 'employee')
            ->putJson("/clients/{$client->id}/services/{$service->id}/schedule", [])
            ->assertSuccessful();
    }
}
