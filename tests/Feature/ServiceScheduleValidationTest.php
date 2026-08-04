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
