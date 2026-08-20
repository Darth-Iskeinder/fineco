<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientServiceSchedule;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\Periodicity;
use App\Models\Role;
use App\Models\Service;
use App\Models\TaskReminder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Идёт по боевому mysql-соединению в транзакции с откатом (pdo_sqlite в среде нет,
 * а RefreshDatabase на боевой БД её бы дропнул). Все фикстуры откатываются.
 */
class GenerateTaskRemindersTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $employee;
    private Client $client;
    private Service $service;
    private Estimate $estimate;

    protected function connectionsToTransact(): array
    {
        return ['mysql'];
    }

    /** Переключаем на боевой mysql ДО старта транзакции трейта (phpunit.xml форсит sqlite/:memory:). */
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

        Periodicity::firstOrCreate(['name' => 'Ежеквартально'], ['kind' => 'quarterly']);
        Periodicity::firstOrCreate(['name' => 'Ежемесячно'], ['kind' => 'monthly']);

        $role = Role::firstOrCreate(['name' => Role::ACCOUNTANT], ['display_name' => 'Бухгалтер']);
        $this->employee = Employee::create([
            'full_name' => 'Тест Бухгалтер', 'position' => 'Бухгалтер',
            'email' => 'worker_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);

        $this->service = Service::create([
            'name' => 'Декларация НДС', 'periodicity' => 'Ежеквартально',
            'start_month' => [3, 6, 9, 12], 'start_day' => [20], 'is_active' => true,
        ]);

        $this->client = Client::create([
            'name' => 'ООО Альфа', 'inn' => '00000000000A',
            'responsible_employee_id' => $this->employee->id,
        ]);

        $this->estimate = Estimate::create(['client_id' => $this->client->id, 'total' => 0]);
        $this->estimate->items()->create([
            'service_id' => $this->service->id, 'type' => 'recurring',
            'name' => 'Декларация НДС', 'periodicity' => 'Ежеквартально',
            'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 0,
        ]);
    }

    private function generate(): void
    {
        // lookback=0 — эти кейсы проверяют генерацию вперёд и прунинг; бэкфилл прошлого тестируется отдельно
        $this->artisan('tasks:generate', ['--date' => '2026-06-05', '--horizon' => 120, '--lookback' => 0])
            ->assertSuccessful();
    }

    /** Напоминания только этого сотрудника (боевая БД может содержать чужие строки). */
    private function mine()
    {
        return TaskReminder::where('employee_id', $this->employee->id);
    }

    private function myDates(): array
    {
        return $this->mine()->orderBy('due_date')->pluck('due_date')
            ->map(fn ($d) => $d->toDateString())->all();
    }

    public function test_generates_reminders_on_default_schedule(): void
    {
        $this->generate();

        // Ежеквартально 20-го в окне 05.06–03.10 → июнь и сентябрь
        $this->assertSame(['2026-06-20', '2026-09-20'], $this->myDates());
        $this->assertSame(TaskReminder::STATUS_PENDING, $this->mine()->first()->status);
    }

    public function test_individual_schedule_overrides_default(): void
    {
        ClientServiceSchedule::create([
            'client_id' => $this->client->id, 'service_id' => $this->service->id,
            'periodicity' => 'Ежемесячно', 'start_month' => [], 'start_day' => [5],
        ]);

        $this->generate();

        $this->assertSame(['2026-06-05', '2026-07-05', '2026-08-05', '2026-09-05'], $this->myDates());
    }

    public function test_is_idempotent(): void
    {
        $this->generate();
        $this->generate();

        $this->assertSame(2, $this->mine()->count(), 'Повторный запуск не должен создавать дубли');
    }

    public function test_skips_service_without_schedule(): void
    {
        $this->service->update(['periodicity' => null, 'start_month' => null, 'start_day' => null]);

        $this->generate();

        $this->assertSame(0, $this->mine()->count());
    }

    public function test_prunes_future_pending_but_keeps_completed(): void
    {
        $this->generate();
        $this->assertSame(2, $this->mine()->count());

        // Один выполнили, БП убрали из сметы
        $done = $this->mine()->orderBy('due_date')->first(); // 2026-06-20
        $done->update(['status' => TaskReminder::STATUS_DONE, 'completed_at' => now()]);
        $this->estimate->items()->delete();

        $this->generate();

        // Выполненное осталось, будущее pending (сентябрь) убрано
        $this->assertDatabaseHas('task_reminders', ['id' => $done->id, 'status' => 'done']);
        $this->assertSame(0, $this->mine()->where('status', TaskReminder::STATUS_PENDING)->count());
        $this->assertSame(1, $this->mine()->count());
    }

    public function test_backfills_past_overdue_within_lookback(): void
    {
        // База 2026-06-05, назад 180 дн (≈ 2025-12-07), вперёд 30 дн (до 2026-07-05)
        $this->artisan('tasks:generate', ['--date' => '2026-06-05', '--horizon' => 30, '--lookback' => 180])
            ->assertSuccessful();

        // Ежеквартально 20-го: прошлые 2025-12-20 и 2026-03-20 (просрочка) + ближайшее 2026-06-20
        $this->assertSame(['2025-12-20', '2026-03-20', '2026-06-20'], $this->myDates());
        $this->assertSame(TaskReminder::STATUS_PENDING, $this->mine()->first()->status);
    }

    public function test_skips_inactive_employee(): void
    {
        $this->employee->update(['status' => Employee::STATUS_INACTIVE]);

        $this->generate();

        $this->assertSame(0, $this->mine()->count());
    }
}
