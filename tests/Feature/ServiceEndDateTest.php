<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientServiceSchedule;
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
 * Завершение обслуживания закрывает будущее, но не прячет прошлое.
 *
 * Обслуживание — отрезок: `service_start_date` давно работает нижней границей
 * задач, `service_end_date` теперь верхняя. Дату проставляет завершающий статус
 * клиента, поэтому у действующих клиентов она пуста и окно задач у них прежнее.
 *
 * Смотрим на дату, а не на флаг `is_active`: флаг не помнит, когда его сняли, и
 * по нему пришлось бы разом спрятать всю накопленную просрочку — незакрытые
 * обязательства исчезли бы с экрана, оставшись обязательствами.
 */
class ServiceEndDateTest extends TestCase
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
        Module::firstOrCreate(['name' => 'buhtasks'], ['display_name' => 'БухЗадачник', 'is_active' => true]);

        $role = Role::firstOrCreate(['name' => Role::ACCOUNTANT], ['display_name' => 'Бухгалтер']);
        $this->employee = Employee::create([
            'full_name' => 'Тест Бухгалтер', 'position' => 'Бухгалтер',
            'email' => 'end_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
        $this->employee->modules()->attach(Module::where('name', 'buhtasks')->value('id'));

        $this->service = Service::create([
            'name' => 'Отчёт ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_day' => [5], 'is_active' => true,
        ]);

        $this->client = Client::create([
            'name' => 'ООО Обслуживание ' . uniqid(),
            'inn' => strtoupper(substr(md5(uniqid()), 0, 12)),
            'responsible_employee_id' => $this->employee->id,
            'service_start_date' => '2026-01-01',
        ]);

        $this->estimate = Estimate::create(['client_id' => $this->client->id, 'total' => 0]);
        // Месяц создания сметы холостой (Estimate::tasksStartFrom) — состаряем,
        // иначе задачи не появятся вовсе и тест проверял бы пустоту против пустоты.
        $this->estimate->forceFill(['created_at' => '2026-01-01 00:00:00'])->save();
        $this->estimate->items()->create([
            'service_id' => $this->service->id, 'type' => 'recurring',
            'name' => $this->service->name, 'periodicity' => 'Ежемесячно',
            'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 0,
        ]);

        ClientServiceSchedule::create([
            'client_id' => $this->client->id, 'service_id' => $this->service->id,
            'periodicity' => 'Ежемесячно', 'start_month' => [], 'start_day' => [5],
        ]);
    }

    private function generate(): void
    {
        $this->artisan('tasks:generate', ['--date' => '2026-07-05', '--horizon' => 120, '--lookback' => 0])
            ->assertSuccessful();
    }

    /** @return array<int, string> Даты напоминаний этого сотрудника. */
    private function myDates(): array
    {
        return TaskReminder::where('employee_id', $this->employee->id)
            ->orderBy('due_date')->pluck('due_date')
            ->map(fn ($d) => $d->toDateString())->all();
    }

    public function test_client_without_end_date_keeps_the_old_window(): void
    {
        $this->generate();

        $this->assertSame(
            ['2026-07-05', '2026-08-05', '2026-09-05', '2026-10-05'],
            $this->myDates(),
            'У действующего клиента окно задач изменилось — правка задела не только завершённых',
        );
    }

    public function test_no_reminders_are_created_past_the_end_of_service(): void
    {
        $this->client->update(['service_end_date' => '2026-08-31']);

        $this->generate();

        $this->assertSame(['2026-07-05', '2026-08-05'], $this->myDates());
    }

    /** Клиента завершили после того, как напоминания уже создались — будущие убираем. */
    public function test_reminders_past_the_end_are_pruned_on_the_next_run(): void
    {
        $this->generate();
        $this->assertCount(4, $this->myDates());

        $this->client->update(['service_end_date' => '2026-08-31']);
        $this->generate();

        $this->assertSame(['2026-07-05', '2026-08-05'], $this->myDates());
    }

    /** Выполненное не трогаем никогда: это факт работы, а не план. */
    public function test_completed_reminder_past_the_end_survives(): void
    {
        $this->generate();

        TaskReminder::where('employee_id', $this->employee->id)
            ->where('due_date', '2026-10-05')
            ->update(['status' => TaskReminder::STATUS_DONE]);

        $this->client->update(['service_end_date' => '2026-08-31']);
        $this->generate();

        $this->assertContains('2026-10-05', $this->myDates(), 'Выполненное напоминание удалили');
    }

    /** @return array<int, array<string, mixed>> Плановые задачи сотрудника в живом списке. */
    private function plannedTasks(): array
    {
        return collect(
            $this->actingAs($this->employee, 'employee')
                ->get(route('buhtasks.index'))
                ->assertOk()
                ->viewData('tasks')
        )->where('type', 'planned')->values()->all();
    }

    /**
     * Живой список: задачи после даты завершения не показываем, а незакрытые
     * внутри периода обслуживания остаются — доделать хвосты можно.
     */
    public function test_task_list_stops_at_the_end_of_service(): void
    {
        $before = collect($this->plannedTasks())
            ->where('client_id', $this->client->id)
            ->map(fn ($t) => $t['year'] . '-' . $t['month']);

        $this->assertTrue($before->isNotEmpty(), 'У действующего клиента задач нет — проверять нечего');

        // Завершаем прошлым месяцем: июльские хвосты остаются, дальше — пусто.
        $this->client->update(['service_end_date' => '2026-07-31']);

        $after = collect($this->plannedTasks())
            ->where('client_id', $this->client->id)
            ->map(fn ($t) => $t['year'] . '-' . $t['month']);

        $this->assertContains('2026-7', $after->all(), 'Незакрытая июльская задача пропала вместе с будущими');
        $this->assertNotContains('2026-8', $after->all(), 'После завершения обслуживания задачи продолжают появляться');
    }
}
