<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Module;
use App\Models\Periodicity;
use App\Models\Role;
use App\Models\Service;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * БП с периодичностью «По запросу»: дат срока у него нет вовсе.
 *
 * Плановые задачи по такому БП не создаются ни в задачнике, ни в напоминаниях:
 * его добавляют руками из каталога в БухЗадачнике, и дата считается от дня
 * добавления плюс deadline_days календарных дней. Каталог для формы отдаёт эти
 * два признака, иначе форме нечем подставить дату.
 */
class OnRequestServiceTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $employee;

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

        Periodicity::firstOrCreate(['name' => 'По запросу'], ['kind' => Service::KIND_ON_REQUEST]);

        $module = Module::firstOrCreate(
            ['name' => 'buhtasks'],
            ['display_name' => 'БухЗадачник', 'is_active' => true],
        );
        $role = Role::firstOrCreate(['name' => Role::ACCOUNTANT], ['display_name' => 'Бухгалтер']);

        $this->employee = Employee::create([
            'full_name' => 'Тест Бухгалтер', 'position' => 'Бухгалтер',
            'email' => 'onreq_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
        $this->employee->modules()->attach($module->id);
    }

    private function onRequestService(?int $days = 3): Service
    {
        return Service::create([
            'name'          => 'Справка для банка ' . uniqid(),
            'periodicity'   => 'По запросу',
            'deadline_days' => $days,
            'is_active'     => true,
            'cost'          => 500,
        ]);
    }

    /** Дат нет ни в каком окне: задача по такому БП сама не появится. */
    public function test_on_request_service_has_no_due_dates(): void
    {
        $service = $this->onRequestService();

        $this->assertTrue($service->isOnRequest());
        $this->assertSame([], $service->dueDatesBetween(now()->subYear(), now()->addYear()));
    }

    /** В списке каталога вместо прочерка видно, через сколько дней наступит срок. */
    public function test_deadline_label_shows_days_instead_of_dates(): void
    {
        $this->assertSame(['по запросу, 3 дн.'], $this->onRequestService()->deadlineLabels());
        $this->assertSame(['по запросу'], $this->onRequestService(null)->deadlineLabels());
    }

    /** Каталог формы «Добавить задачу» отдаёт признак и срок в днях. */
    public function test_buhtasks_catalog_exposes_on_request_and_days(): void
    {
        $service = $this->onRequestService();

        $response = $this->actingAs($this->employee, 'employee')
            ->get('/buhtasks')
            ->assertOk();

        $row = collect($response->viewData('catalog'))
            ->firstWhere('id', $service->id);

        $this->assertNotNull($row, 'БП «по запросу» должен быть в каталоге формы');
        $this->assertTrue($row['on_request']);
        $this->assertSame(3, $row['deadline_days']);
    }

    /** У обычного БП признака нет, и форма ничего не подставляет. */
    public function test_dated_service_is_not_marked_as_on_request(): void
    {
        Periodicity::firstOrCreate(['name' => 'Ежемесячно'], ['kind' => 'monthly']);

        $service = Service::create([
            'name' => 'Ежемесячный БП ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_day' => [15], 'is_active' => true, 'cost' => 0,
        ]);

        $response = $this->actingAs($this->employee, 'employee')
            ->get('/buhtasks')
            ->assertOk();

        $row = collect($response->viewData('catalog'))->firstWhere('id', $service->id);

        $this->assertFalse($row['on_request']);
        $this->assertNull($row['deadline_days']);
    }
}
