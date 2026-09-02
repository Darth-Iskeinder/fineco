<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientServiceSchedule;
use App\Models\ClientStatus;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\Module;
use App\Models\Periodicity;
use App\Models\Role;
use App\Models\Service;
use App\Models\TaskReminder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Перерыв в обслуживании: остановили, через полгода вернули.
 *
 * Останавливающий статус («Приостановлен», «Завершен») закрывает окно задач
 * сверху датой остановки, возврат в «Активен» открывает его снизу первым числом
 * следующего месяца. Месяц возврата холостой — то же правило, что у только что
 * добавленного в смету БП.
 *
 * Нижняя граница обязательна: ни генератор напоминаний, ни живой список памяти
 * не имеют, каждый прогон они считают сроки по смете заново. Без неё снятая
 * верхняя граница открыла бы весь простой разом, и клиент, вернувшийся через
 * полгода, получил бы полгода просрочки за месяцы, когда его не обслуживали.
 */
class ServicePauseTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $employee;
    private Client $client;
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
        Module::firstOrCreate(['name' => 'buhtasks'], ['display_name' => 'БухЗадачник', 'is_active' => true]);
        Module::firstOrCreate(['name' => 'clients'], ['display_name' => 'Клиенты', 'is_active' => true]);

        ClientStatus::updateOrCreate(['name' => 'Активен'], ['color' => 'emerald', 'closes_service' => false, 'stops_tasks' => false, 'sort_order' => 1]);
        ClientStatus::updateOrCreate(['name' => 'Приостановлен'], ['color' => 'amber', 'closes_service' => false, 'stops_tasks' => true, 'sort_order' => 2]);
        ClientStatus::updateOrCreate(['name' => 'Завершен'], ['color' => 'slate', 'closes_service' => true, 'stops_tasks' => true, 'sort_order' => 3]);

        $role = Role::firstOrCreate(['name' => Role::ACCOUNTANT], ['display_name' => 'Бухгалтер']);
        $this->employee = Employee::create([
            'full_name' => 'Тест Бухгалтер', 'position' => 'Бухгалтер',
            'email' => 'pause_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
        $this->employee->modules()->attach(Module::whereIn('name', ['buhtasks', 'clients'])->pluck('id'));

        $this->service = Service::create([
            'name' => 'Отчёт ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_day' => [5], 'is_active' => true,
        ]);

        $this->client = Client::create([
            'name' => 'ООО Перерыв ' . uniqid(),
            'inn' => strtoupper(substr(md5(uniqid()), 0, 12)),
            'responsible_employee_id' => $this->employee->id,
            'service_start_date' => '2026-01-01',
            'client_status_id' => $this->clientStatus('Активен')->id,
        ]);

        $estimate = Estimate::create(['client_id' => $this->client->id, 'total' => 0]);
        // Месяц создания сметы холостой (Estimate::tasksStartFrom) — состаряем,
        // иначе задач не будет вовсе и тест сравнивал бы пустоту с пустотой.
        $estimate->forceFill(['created_at' => '2026-01-01 00:00:00'])->save();
        $estimate->items()->create([
            'service_id' => $this->service->id, 'type' => 'recurring',
            'name' => $this->service->name, 'periodicity' => 'Ежемесячно',
            'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 0,
        ]);

        ClientServiceSchedule::create([
            'client_id' => $this->client->id, 'service_id' => $this->service->id,
            'periodicity' => 'Ежемесячно', 'start_month' => [], 'start_day' => [5],
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    private function clientStatus(string $name): ClientStatus
    {
        return ClientStatus::where('name', $name)->firstOrFail();
    }

    /** Смена статуса в карточке клиента. */
    private function setStatus(string $name): void
    {
        $this->actingAs($this->employee, 'employee')
            ->patchJson(route('clients.update-section', $this->client), [
                'section' => 'status',
                'client_status_id' => $this->clientStatus($name)->id,
                'service_start_date' => '2026-01-01',
                'service_end_date' => null,
            ])
            ->assertOk();

        $this->client->refresh();
    }

    private function generate(string $date): void
    {
        $this->artisan('tasks:generate', ['--date' => $date, '--horizon' => 60, '--lookback' => 190])
            ->assertSuccessful();
    }

    /** @return array<int, string> Даты напоминаний этого сотрудника. */
    private function myDates(): array
    {
        return TaskReminder::where('employee_id', $this->employee->id)
            ->orderBy('due_date')->pluck('due_date')
            ->map(fn ($d) => $d->toDateString())->all();
    }

    public function test_pausing_stops_the_service_from_today(): void
    {
        Carbon::setTestNow('2026-09-02 10:00:00');

        $this->setStatus('Приостановлен');

        $this->assertSame('2026-09-02', $this->client->service_end_date->toDateString());
        $this->assertFalse($this->client->is_active, 'Приостановленный клиент остался активным');
        $this->assertNull($this->client->tasks_start_from, 'Нижнюю границу двигает возврат, а не остановка');
    }

    /** Пауза закрывает будущее: сроки после неё не заводятся, просрочка до неё цела. */
    public function test_no_reminders_are_created_after_the_pause(): void
    {
        Carbon::setTestNow('2026-09-02 10:00:00');
        $this->setStatus('Приостановлен');

        $this->generate('2026-09-10');

        $this->assertSame(['2026-07-05', '2026-08-05'], $this->myDates());
    }

    /** Возврат в работу: верхняя граница снимается, нижняя встаёт на следующий месяц. */
    public function test_resuming_moves_the_floor_to_the_next_month(): void
    {
        Carbon::setTestNow('2026-09-02 10:00:00');
        $this->setStatus('Приостановлен');

        Carbon::setTestNow('2027-02-10 10:00:00');
        $this->setStatus('Активен');

        $this->assertNull($this->client->service_end_date, 'Дата остановки осталась после возврата');
        $this->assertTrue($this->client->is_active);
        $this->assertSame('2027-03-01', $this->client->tasks_start_from->toDateString());
    }

    /** Главное: за месяцы перерыва задним числом не приезжает ничего. */
    public function test_the_break_never_comes_back_as_overdue(): void
    {
        Carbon::setTestNow('2026-09-02 10:00:00');
        $this->setStatus('Приостановлен');
        $this->generate('2026-09-10');

        Carbon::setTestNow('2027-02-10 10:00:00');
        $this->setStatus('Активен');
        $this->generate('2027-03-05');

        $dates = $this->myDates();

        $this->assertSame(
            ['2027-03-05', '2027-04-05'],
            array_values(array_filter($dates, fn ($d) => $d >= '2026-09-01')),
            'За перерыв или после возврата появились лишние сроки',
        );
        $this->assertSame(
            ['2026-07-05', '2026-08-05'],
            array_values(array_filter($dates, fn ($d) => $d < '2026-09-01')),
            'Напоминания, созданные до перерыва, пострадали от прунинга',
        );
    }

    /** Живой список бухзадачника держится той же границы, что и генератор. */
    public function test_task_list_is_empty_during_the_break_month(): void
    {
        Carbon::setTestNow('2026-09-02 10:00:00');
        $this->setStatus('Приостановлен');

        Carbon::setTestNow('2027-02-10 10:00:00');
        $this->setStatus('Активен');

        $tasks = collect(
            $this->actingAs($this->employee, 'employee')
                ->get(route('buhtasks.index'))
                ->assertOk()
                ->viewData('tasks')
        )->where('client_id', $this->client->id);

        $this->assertTrue(
            $tasks->isEmpty(),
            'В месяц возврата и за перерыв на экране появились задачи: ' . $tasks->pluck('due_date')->implode(', '),
        );
    }

    /** «Завершен» останавливает так же, как пауза: для задач разницы нет. */
    public function test_closing_status_stops_the_service_too(): void
    {
        Carbon::setTestNow('2026-09-02 10:00:00');

        $this->setStatus('Завершен');

        $this->assertSame('2026-09-02', $this->client->service_end_date->toDateString());
        $this->assertFalse($this->client->is_active);
    }

    /** Правка из списка активностью не распоряжается: этим занят только статус. */
    public function test_inline_edit_does_not_touch_the_active_flag(): void
    {
        $this->actingAs($this->employee, 'employee')
            ->putJson(route('clients.update', $this->client), [
                'name' => $this->client->name,
                'inn' => $this->client->inn,
                'is_active' => 0,
            ])
            ->assertOk();

        $this->assertTrue($this->client->refresh()->is_active, 'Тумблер из списка снова гасит клиента');
    }
}
