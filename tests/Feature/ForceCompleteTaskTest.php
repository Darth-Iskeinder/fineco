<?php

namespace Tests\Feature;

use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\Module;
use App\Models\Periodicity;
use App\Models\Role;
use App\Models\Service;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Принудительное закрытие задачи (POST /buhtasks/logs/{log}/force-complete):
 * в обход документа и подпунктов, с обязательной причиной. Как и DashboardTest,
 * идёт по боевому mysql-соединению в транзакции с откатом.
 */
class ForceCompleteTaskTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $head;
    private Employee $accountant;
    private Client $client;

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
        \Illuminate\Support\Facades\DB::purge('mysql');

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        parent::setUp();

        Periodicity::firstOrCreate(['name' => 'Ежемесячно'], ['kind' => 'monthly']);
        $employeeRole = Role::firstOrCreate(['name' => 'employee'], ['display_name' => 'Сотрудник']);
        $module = Module::firstOrCreate(
            ['name' => 'buhtasks'],
            ['display_name' => 'БухЗадачник', 'is_active' => true],
        );

        $this->head = Employee::create([
            'full_name' => 'Тест Главбух', 'position' => 'Главбух',
            'email' => 'head_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $employeeRole->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
        $this->accountant = Employee::create([
            'full_name' => 'Тест Бухгалтер', 'position' => 'Бухгалтер',
            'email' => 'acc_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $employeeRole->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
        $this->head->modules()->attach($module->id);
        $this->accountant->modules()->attach($module->id);

        $this->client = Client::create([
            'name' => 'ТОО Форс Тест', 'inn' => 'FORCE000000A',
            'responsible_employee_id' => $this->head->id,
        ]);
    }

    /** Лог задачи по БП с указанными флагами услуги. */
    private function makeLog(bool $requiresDocument, bool $requiresReview, ?Employee $doer = null): BuhTaskLog
    {
        $service = Service::create([
            'name' => 'Тест услуга ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_day' => [5], 'is_active' => true,
            'requires_document' => $requiresDocument, 'requires_review' => $requiresReview,
        ]);
        $estimate = Estimate::firstOrCreate(['client_id' => $this->client->id], ['total' => 0]);
        $item = $estimate->items()->create([
            'service_id' => $service->id, 'type' => 'recurring',
            'name' => $service->name, 'periodicity' => 'Ежемесячно',
            'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 0,
        ]);

        return BuhTaskLog::create([
            'employee_id' => ($doer ?? $this->accountant)->id,
            'client_id' => $this->client->id,
            'estimate_item_id' => $item->id,
            'year' => now()->year, 'month' => now()->month,
            'status' => 'pending',
        ]);
    }

    public function test_force_complete_requires_comment(): void
    {
        $log = $this->makeLog(requiresDocument: true, requiresReview: false);

        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.logs.force-complete', $log), ['comment' => ''])
            ->assertStatus(422);

        $this->assertSame('pending', $log->fresh()->status);
    }

    public function test_force_complete_bypasses_document_requirement(): void
    {
        $log = $this->makeLog(requiresDocument: true, requiresReview: false);

        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.logs.force-complete', $log), ['comment' => 'Операций не было'])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('log.status', 'completed')
            ->assertJsonPath('log.force_closed', true)
            ->assertJsonPath('log.force_close_comment', 'Операций не было');

        $fresh = $log->fresh();
        $this->assertTrue($fresh->force_closed);
        $this->assertNotNull($fresh->completed_at);
    }

    public function test_force_complete_still_goes_to_review(): void
    {
        // requires_review + исполнитель не главбух клиента → на проверку, а не сразу completed
        $log = $this->makeLog(requiresDocument: true, requiresReview: true);

        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.logs.force-complete', $log), ['comment' => 'Документа не будет'])
            ->assertOk()
            ->assertJsonPath('log.status', 'review')
            ->assertJsonPath('log.force_closed', true);

        $this->assertNull($log->fresh()->completed_at);
    }

    public function test_foreign_log_forbidden(): void
    {
        $log = $this->makeLog(requiresDocument: false, requiresReview: false, doer: $this->accountant);

        $this->actingAs($this->head, 'employee')
            ->postJson(route('buhtasks.logs.force-complete', $log), ['comment' => 'чужая задача'])
            ->assertForbidden();
    }

    public function test_reset_clears_force_flags(): void
    {
        $log = $this->makeLog(requiresDocument: true, requiresReview: false);
        $log->update([
            'status' => 'completed', 'completed_at' => now(),
            'force_closed' => true, 'force_close_comment' => 'причина',
        ]);

        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.logs.reset', $log))
            ->assertOk();

        $fresh = $log->fresh();
        $this->assertFalse($fresh->force_closed);
        $this->assertNull($fresh->force_close_comment);
    }

    public function test_index_renders_with_force_closed_tasks(): void
    {
        // Выполненная принудительно (вкладка «Выполненные») + на проверке у главбуха
        $done = $this->makeLog(requiresDocument: true, requiresReview: false);
        $done->update([
            'status' => 'completed', 'completed_at' => now(),
            'force_closed' => true, 'force_close_comment' => 'нет операций',
        ]);
        $review = $this->makeLog(requiresDocument: true, requiresReview: true);
        $review->update([
            'status' => 'review', 'review_started_at' => now(),
            'force_closed' => true, 'force_close_comment' => 'документа не будет',
        ]);

        // Страница исполнителя и страница главбуха (review-блок) рендерятся без ошибок
        $this->actingAs($this->accountant, 'employee')
            ->get(route('buhtasks.index'))
            ->assertOk();
        $this->actingAs($this->head, 'employee')
            ->get(route('buhtasks.index'))
            ->assertOk();
    }

    public function test_normal_complete_clears_force_flags_after_rework(): void
    {
        // Force-closed задача вернулась с проверки (rework) и сдана нормально с документом
        $log = $this->makeLog(requiresDocument: true, requiresReview: false);
        $log->update([
            'status' => 'rework',
            'force_closed' => true, 'force_close_comment' => 'первая попытка',
        ]);
        $log->documents()->create(['path' => 'buh_task_documents/test.pdf', 'name' => 'test.pdf']);

        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.logs.complete', $log))
            ->assertOk()
            ->assertJsonPath('log.status', 'completed')
            ->assertJsonPath('log.force_closed', false);

        $this->assertFalse($log->fresh()->force_closed);
    }
}
