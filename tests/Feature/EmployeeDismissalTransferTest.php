<?php

namespace Tests\Feature;

use App\Models\BuhAdhocTask;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\ClientStatus;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Module;
use App\Models\Periodicity;
use App\Models\Role;
use App\Models\Service;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Увольнение вместе с передачей работы.
 *
 * Раньше увольнение меняло только отметку, а работа оставалась на человеке. Позиция,
 * которую уволенная вела у чужого клиента, давала задачи, которых никто не видел (так
 * было с Мунжуровой у БИТУАН). Теперь при увольнении работу отдают одному сотруднику,
 * у остановленных клиентов ответственного снимают, а вернуть такого клиента в работу
 * без нового ответственного нельзя.
 */
class EmployeeDismissalTransferTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $admin;
    private Employee $leaving;
    private Employee $heir;
    private Employee $head;
    private Service $service;
    private ClientStatus $working;
    private ClientStatus $finished;

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
        $accountant = Role::firstOrCreate(['name' => Role::ACCOUNTANT], ['display_name' => 'Бухгалтер']);
        $adminRole  = Role::firstOrCreate(['name' => Role::ADMIN], ['display_name' => 'Администратор']);

        $modules = collect(['employees' => 'Сотрудники', 'clients' => 'Клиенты', 'buhtasks' => 'БухЗадачник'])
            ->map(fn ($title, $name) => Module::firstOrCreate(['name' => $name], ['display_name' => $title, 'is_active' => true])->id);

        $make = fn (string $prefix, int $roleId) => Employee::create([
            'full_name' => 'Тест ' . $prefix, 'position' => $prefix,
            'email' => $prefix . '_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $roleId, 'status' => Employee::STATUS_ACTIVE,
        ]);

        $this->admin   = $make('admin', $adminRole->id);
        $this->leaving = $make('leaving', $accountant->id);
        $this->heir    = $make('heir', $accountant->id);
        $this->head    = $make('head', $accountant->id);
        foreach ([$this->admin, $this->leaving, $this->heir, $this->head] as $e) {
            $e->modules()->syncWithoutDetaching($modules->values()->all());
        }

        $this->service = Service::create([
            'name' => 'Тест отчёт ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_day' => [5], 'is_active' => true,
        ]);

        $this->working  = ClientStatus::create(['name' => 'Тест работа ' . uniqid(), 'stops_tasks' => false, 'sort_order' => 1]);
        $this->finished = ClientStatus::create(['name' => 'Тест конец ' . uniqid(), 'stops_tasks' => true, 'closes_service' => true, 'sort_order' => 2]);
    }

    private function client(Employee $responsible, bool $stopped = false): Client
    {
        return Client::create([
            'name' => 'ТОО Уход ' . uniqid(),
            'inn' => strtoupper(substr(md5(uniqid()), 0, 12)),
            'responsible_employee_id' => $responsible->id,
            'client_status_id' => ($stopped ? $this->finished : $this->working)->id,
        ])->refresh();
    }

    private function item(Client $client, ?Employee $assignee): EstimateItem
    {
        $estimate = Estimate::firstOrCreate(['client_id' => $client->id], ['total' => 0]);

        return $estimate->items()->create([
            'service_id' => $this->service->id, 'type' => 'recurring',
            'name' => $this->service->name, 'periodicity' => 'Ежемесячно',
            'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 0,
            'assignee_id' => $assignee?->id,
        ]);
    }

    private function fire(array $extra = [])
    {
        return $this->actingAs($this->admin, 'employee')
            ->patchJson(route('employees.update-section', $this->leaving), array_merge([
                'section' => 'personal',
                'employment_status' => Employee::EMPLOYMENT_FIRED,
                'fired_at' => now()->toDateString(),
            ], $extra));
    }

    public function test_dismissal_needs_a_date(): void
    {
        $this->fire(['fired_at' => null])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('fired_at');

        $this->assertFalse($this->leaving->fresh()->isFired());
    }

    public function test_dismissal_with_work_needs_a_recipient(): void
    {
        $this->item($this->client($this->head), $this->leaving);

        $this->fire()
            ->assertUnprocessable()
            ->assertJsonValidationErrors('recipient_id');

        $this->assertFalse($this->leaving->fresh()->isFired(), 'Уволили, не передав работу');
    }

    public function test_dismissal_without_work_needs_no_recipient(): void
    {
        $this->fire()->assertOk();

        $this->assertTrue($this->leaving->fresh()->isFired());
    }

    public function test_work_cannot_go_to_a_fired_employee_or_to_himself(): void
    {
        $this->item($this->client($this->head), $this->leaving);
        $gone = Employee::create([
            'full_name' => 'Тест gone', 'position' => 'gone', 'email' => 'gone_' . uniqid() . '@test.kg',
            'password' => bcrypt('x'), 'role_id' => $this->heir->role_id, 'status' => Employee::STATUS_ACTIVE,
            'employment_status' => Employee::EMPLOYMENT_FIRED,
        ]);

        $this->fire(['recipient_id' => $gone->id])->assertUnprocessable()->assertJsonValidationErrors('recipient_id');
        $this->fire(['recipient_id' => $this->leaving->id])->assertUnprocessable()->assertJsonValidationErrors('recipient_id');

        $this->assertFalse($this->leaving->fresh()->isFired());
    }

    /** Главный случай: всё живое уходит одному человеку, остановленное снимается. */
    public function test_dismissal_hands_all_work_to_one_employee(): void
    {
        $own         = $this->client($this->leaving);
        $ownItem     = $this->item($own, $this->leaving);
        $ownFree     = $this->item($own, null);
        $foreign     = $this->client($this->head);
        $foreignItem = $this->item($foreign, $this->leaving);
        $headItem    = $this->item($foreign, $this->head);
        $finished    = $this->client($this->leaving, stopped: true);
        $finishedItem = $this->item($finished, $this->leaving);
        $foreignStopped = $this->client($this->head, stopped: true);
        $foreignStoppedItem = $this->item($foreignStopped, $this->leaving);

        $adhoc = BuhAdhocTask::create([
            'employee_id' => $this->leaving->id, 'client_id' => $foreign->id, 'name' => 'Разовая',
            'year' => now()->year, 'month' => now()->month, 'status' => 'pending',
        ]);
        $log = BuhTaskLog::create([
            'employee_id' => $this->leaving->id, 'client_id' => $foreign->id,
            'estimate_item_id' => $foreignItem->id, 'year' => now()->year, 'month' => now()->month,
            'status' => 'paused', 'paused_seconds' => 600, 'started_at' => now()->subHour(), 'resumed_at' => now()->subHour(),
        ]);

        $this->fire(['recipient_id' => $this->heir->id])->assertOk();

        $this->assertTrue($this->leaving->fresh()->isFired());
        $this->assertNotNull($this->leaving->fresh()->fired_at);

        // Его клиент в работе: целиком к получателю.
        $this->assertSame($this->heir->id, $own->fresh()->responsible_employee_id);
        $this->assertSame($this->heir->id, $ownItem->fresh()->assignee_id);
        $this->assertSame($this->heir->id, $ownFree->fresh()->assignee_id);

        // Его позиция у чужого клиента: к получателю, чужие позиции не трогаем.
        $this->assertSame($this->heir->id, $foreignItem->fresh()->assignee_id);
        $this->assertSame($this->head->id, $headItem->fresh()->assignee_id);
        $this->assertSame($this->head->id, $foreign->fresh()->responsible_employee_id);

        // Остановленные: ответственный и его позиции снимаются, выберут при возврате.
        $this->assertNull($finished->fresh()->responsible_employee_id);
        $this->assertNull($finishedItem->fresh()->assignee_id);
        $this->assertNull($foreignStoppedItem->fresh()->assignee_id);
        $this->assertSame($this->head->id, $foreignStopped->fresh()->responsible_employee_id);

        // Внеплановая и начатая задача.
        $this->assertSame($this->heir->id, (int) $adhoc->fresh()->employee_id);
        $this->assertSame(BuhTaskLog::STATUS_HANDED, $log->fresh()->status);
        $this->assertSame($this->heir->id, (int) $log->fresh()->handed_to_id);
    }

    /** Остановленный клиент, где у него начатая задача: хвост доделывают, он не должен повиснуть. */
    public function test_stopped_client_with_his_started_task_goes_to_recipient(): void
    {
        $finished = $this->client($this->leaving, stopped: true);
        $item = $this->item($finished, $this->leaving);
        BuhTaskLog::create([
            'employee_id' => $this->leaving->id, 'client_id' => $finished->id,
            'estimate_item_id' => $item->id, 'year' => now()->year, 'month' => now()->month,
            'status' => 'paused', 'paused_seconds' => 60, 'started_at' => now()->subHour(), 'resumed_at' => now()->subHour(),
        ]);

        $this->fire(['recipient_id' => $this->heir->id])->assertOk();

        $this->assertSame($this->heir->id, $finished->fresh()->responsible_employee_id);
        $this->assertSame($this->heir->id, $item->fresh()->assignee_id);
    }

    /** Уволенного до окна передачи (как Жусупову) можно разгрузить кнопкой «Передать работу». */
    public function test_already_fired_employee_work_can_be_transferred(): void
    {
        $foreign = $this->client($this->head);
        $item = $this->item($foreign, $this->leaving);
        $this->leaving->update(['employment_status' => Employee::EMPLOYMENT_FIRED, 'fired_at' => now()->subMonth()]);

        $this->actingAs($this->admin, 'employee')
            ->getJson(route('employees.work.preview', $this->leaving))
            ->assertOk()
            ->assertJson(['items' => 1, 'needs_recipient' => true]);

        $this->actingAs($this->admin, 'employee')
            ->postJson(route('employees.work.transfer', $this->leaving), ['recipient_id' => $this->heir->id])
            ->assertOk()
            ->assertJsonPath('employee.work.needs_recipient', false);

        $this->assertSame($this->heir->id, $item->fresh()->assignee_id);
    }

    private function resume(Client $client, array $extra = [])
    {
        return $this->actingAs($this->admin, 'employee')
            ->patchJson(route('clients.update-section', $client), array_merge([
                'section' => 'status',
                'client_status_id' => $this->working->id,
                // Форма присылает прежнюю дату остановки: её снимает модель.
                'service_end_date' => $client->service_end_date?->toDateString(),
            ], $extra));
    }

    public function test_client_cannot_return_to_work_without_a_responsible(): void
    {
        $finished = $this->client($this->leaving, stopped: true);
        $finished->update(['responsible_employee_id' => null]);

        $this->resume($finished)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('responsible_employee_id');

        $this->assertTrue($finished->fresh()->serviceIsStopped(), 'Клиент вернулся в работу ни на ком');
    }

    /** Старые данные: у остановленного клиента ответственным стоит уволенный. */
    public function test_client_cannot_return_to_work_on_a_fired_responsible(): void
    {
        $finished = $this->client($this->leaving, stopped: true);
        $this->leaving->update(['employment_status' => Employee::EMPLOYMENT_FIRED]);

        $this->resume($finished)->assertUnprocessable()->assertJsonValidationErrors('responsible_employee_id');
    }

    public function test_client_returns_to_work_with_the_chosen_responsible(): void
    {
        $finished = $this->client($this->leaving, stopped: true);
        $finished->update(['responsible_employee_id' => null]);
        $item = $this->item($finished, null);

        $this->resume($finished, ['responsible_employee_id' => $this->heir->id])->assertOk();

        $finished->refresh();
        $this->assertFalse($finished->serviceIsStopped());
        $this->assertSame($this->heir->id, $finished->responsible_employee_id);
        $this->assertSame($this->heir->id, $item->fresh()->assignee_id, 'Позиции не перешли к новому ответственному');
    }

    public function test_client_with_a_working_responsible_returns_as_before(): void
    {
        $finished = $this->client($this->head, stopped: true);

        $this->resume($finished)->assertOk();

        $this->assertFalse($finished->fresh()->serviceIsStopped());
        $this->assertSame($this->head->id, $finished->fresh()->responsible_employee_id);
    }
}
