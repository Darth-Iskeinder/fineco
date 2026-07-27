<?php

namespace Tests\Feature;

use App\Models\Audit;
use App\Models\AuditChecklistItem;
use App\Models\AuditChecklistTemplate;
use App\Models\AuditTaskReview;
use App\Models\BuhAdhocTask;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\Module;
use App\Models\Role;
use App\Models\Service;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Модуль «Аудит»: создание аудита с копией чек-листа, вердикты по закрытым БП,
 * правка чек-листа и завершение с подсчётом балла.
 * Как и остальные feature-тесты, идёт по боевому mysql в транзакции с откатом.
 */
class AuditModuleTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $auditor;
    private Employee $accountant;
    private Client $client;
    private AuditChecklistTemplate $template;
    private BuhTaskLog $log;

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

        $role    = Role::firstOrCreate(['name' => 'employee'], ['display_name' => 'Сотрудник']);
        $manager = Role::firstOrCreate(['name' => Role::MANAGER], ['display_name' => 'Руководитель']);
        $module  = Module::firstOrCreate(['name' => 'audit'], ['display_name' => 'Аудит', 'is_active' => true]);

        // Доступ к модулю пока только у руководителя
        $this->auditor = Employee::create([
            'full_name' => 'Тест Руководитель', 'position' => 'Руководитель',
            'email' => 'manager_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $manager->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
        $this->accountant = Employee::create([
            'full_name' => 'Тест Бухгалтер', 'position' => 'Бухгалтер',
            'email' => 'acc_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
        $this->auditor->modules()->attach($module->id);

        $this->client = Client::create([
            'name' => 'Тест Клиент Аудит ' . uniqid(),
            'inn' => (string) random_int(100000000000, 999999999999),
            'responsible_employee_id' => $this->accountant->id,
        ]);

        $service = Service::create([
            'name' => 'Тест БП Банк ' . uniqid(),
            'service_group' => 'Банк',
            'periodicity' => 'Ежемесячно',
            'cost' => 0,
            'is_active' => true,
        ]);

        $estimate = Estimate::create(['client_id' => $this->client->id, 'total' => 0]);
        $item = $estimate->items()->create([
            'service_id' => $service->id, 'type' => 'recurring',
            'name' => 'Разнесение банковской выписки',
            'periodicity' => 'Ежемесячно', 'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 0,
        ]);

        // Закрытая задача внутри периода аудита
        $this->log = BuhTaskLog::create([
            'employee_id' => $this->accountant->id,
            'client_id' => $this->client->id,
            'estimate_item_id' => $item->id,
            'year' => 2026, 'month' => 2,
            'status' => 'completed',
            'completed_at' => '2026-03-10 12:00:00',
        ]);

        // Задача вне периода — не должна попасть в аудит
        BuhTaskLog::create([
            'employee_id' => $this->accountant->id,
            'client_id' => $this->client->id,
            'estimate_item_id' => $item->id,
            'year' => 2026, 'month' => 9,
            'status' => 'completed',
            'completed_at' => '2026-10-10 12:00:00',
        ]);

        // Стандарт не выбирают при создании — аудит берёт единственный действующий,
        // поэтому на время теста прочие выводим из обращения (транзакция всё откатит).
        AuditChecklistTemplate::query()->update(['is_active' => false]);
        $this->template = AuditChecklistTemplate::create(['name' => 'Тест стандарт ' . uniqid()]);
        $this->template->items()->create(['section' => 'Банк', 'point' => 'Сверка 1210', 'sort_order' => 0]);
        $this->template->items()->create(['section' => 'Касса', 'point' => 'Остаток кассы', 'sort_order' => 1]);
    }

    private function createAudit(): Audit
    {
        $this->actingAs($this->auditor, 'employee')
            ->post('/audit', [
                'client_id'    => $this->client->id,
                'period_start' => '2026-01-01',
                'period_end'   => '2026-04-30',
            ])
            ->assertRedirect();

        return Audit::where('client_id', $this->client->id)->latest('id')->firstOrFail();
    }

    public function test_audit_creation_copies_checklist_from_template(): void
    {
        $audit = $this->createAudit();

        $this->assertSame(Audit::STATUS_IN_PROGRESS, $audit->status);
        $this->assertSame($this->template->id, $audit->template_id);
        $this->assertSame(2, $audit->checklistItems()->count());
        $this->assertSame('Сверка 1210', $audit->checklistItems()->first()->point);

        // Правка чек-листа аудита не меняет стандарт
        $audit->checklistItems()->first()->update(['point' => 'Изменено']);
        $this->assertSame('Сверка 1210', $this->template->items()->first()->point);
    }

    public function test_only_closed_tasks_of_period_get_into_audit(): void
    {
        $audit = $this->createAudit();
        $logs  = $audit->closedTaskLogs()->get();

        $this->assertCount(1, $logs);
        $this->assertSame($this->log->id, $logs->first()->id);
    }

    public function test_non_manager_gets_403_even_with_module_access(): void
    {
        $module = Module::where('name', 'audit')->first();
        $this->accountant->modules()->syncWithoutDetaching([$module->id]);

        $this->actingAs($this->accountant, 'employee')
            ->get('/audit')
            ->assertForbidden();
    }

    public function test_verdict_saved_and_cleared(): void
    {
        $audit = $this->createAudit();

        $this->actingAs($this->auditor, 'employee')
            ->postJson("/audit/{$audit->id}/verdict", [
                'buh_task_log_id' => $this->log->id,
                'verdict'  => AuditTaskReview::VERDICT_FINDING,
                'severity' => 'critical',
                'comment'  => 'Платёж без договора',
            ])
            ->assertOk()
            ->assertJsonPath('review.severity', 'critical')
            ->assertJsonPath('stats.critical', 1);

        // Снимок названия и участка сохранён вместе с вердиктом
        $review = $audit->taskReviews()->first();
        $this->assertSame('Разнесение банковской выписки', $review->task_name);
        $this->assertSame('Банк', $review->section);

        $this->actingAs($this->auditor, 'employee')
            ->postJson("/audit/{$audit->id}/verdict/delete", ['buh_task_log_id' => $this->log->id])
            ->assertOk()
            ->assertJsonPath('stats.critical', 0);

        $this->assertSame(0, $audit->taskReviews()->count());
    }

    public function test_finding_without_severity_is_rejected(): void
    {
        $audit = $this->createAudit();

        $this->actingAs($this->auditor, 'employee')
            ->postJson("/audit/{$audit->id}/verdict", [
                'buh_task_log_id' => $this->log->id,
                'verdict' => AuditTaskReview::VERDICT_FINDING,
            ])
            ->assertStatus(422);
    }

    public function test_verdict_for_another_clients_task_is_forbidden(): void
    {
        $audit = $this->createAudit();

        $otherClient = Client::create([
            'name' => 'Чужой клиент ' . uniqid(),
            'inn' => (string) random_int(100000000000, 999999999999),
        ]);
        $otherLog = BuhTaskLog::create([
            'employee_id' => $this->accountant->id,
            'client_id' => $otherClient->id,
            'estimate_item_id' => $this->log->estimate_item_id,
            'year' => 2026, 'month' => 2, 'status' => 'completed',
        ]);

        $this->actingAs($this->auditor, 'employee')
            ->postJson("/audit/{$audit->id}/verdict", [
                'buh_task_log_id' => $otherLog->id,
                'verdict' => AuditTaskReview::VERDICT_OK,
            ])
            ->assertForbidden();
    }

    public function test_checklist_cell_edit_add_and_delete(): void
    {
        $audit = $this->createAudit();
        $item  = $audit->checklistItems()->first();

        $this->actingAs($this->auditor, 'employee')
            ->putJson("/audit/{$audit->id}/checklist/{$item->id}", ['status' => AuditChecklistItem::STATUS_ERROR, 'comment' => 'Расхождение'])
            ->assertOk()
            ->assertJsonPath('stats.checklist_errors', 1);

        // Пустая строка = «не проверено», а не ошибка валидации
        $this->actingAs($this->auditor, 'employee')
            ->putJson("/audit/{$audit->id}/checklist/{$item->id}", ['status' => ''])
            ->assertOk();
        $this->assertNull($item->fresh()->status);

        $this->actingAs($this->auditor, 'employee')
            ->putJson("/audit/{$audit->id}/checklist/{$item->id}", ['status' => 'мусор'])
            ->assertStatus(422);

        $created = $this->actingAs($this->auditor, 'employee')
            ->postJson("/audit/{$audit->id}/checklist", ['section' => 'Новый раздел'])
            ->assertOk()
            ->json('item.id');

        $this->assertSame(3, $audit->checklistItems()->count());

        $this->actingAs($this->auditor, 'employee')
            ->deleteJson("/audit/{$audit->id}/checklist/{$created}")
            ->assertOk();

        $this->assertNull(AuditChecklistItem::find($created));
    }

    public function test_checklist_item_of_another_audit_is_not_editable(): void
    {
        $audit = $this->createAudit();
        $other = $this->createAudit();

        $foreignItem = $other->checklistItems()->first();

        $this->actingAs($this->auditor, 'employee')
            ->putJson("/audit/{$audit->id}/checklist/{$foreignItem->id}", ['comment' => 'нельзя'])
            ->assertNotFound();
    }

    public function test_section_rename_and_delete(): void
    {
        $audit = $this->createAudit();

        $this->actingAs($this->auditor, 'employee')
            ->postJson("/audit/{$audit->id}/checklist/section/rename", ['from' => 'Банк', 'to' => 'Банк и касса'])
            ->assertOk();

        $this->assertTrue($audit->checklistItems()->where('section', 'Банк и касса')->exists());

        $this->actingAs($this->auditor, 'employee')
            ->postJson("/audit/{$audit->id}/checklist/section/delete", ['section' => 'Банк и касса'])
            ->assertOk();

        $this->assertFalse($audit->checklistItems()->where('section', 'Банк и касса')->exists());
    }

    public function test_completion_locks_edits_and_returns_to_list(): void
    {
        $audit = $this->createAudit();

        $this->actingAs($this->auditor, 'employee')
            ->postJson("/audit/{$audit->id}/verdict", [
                'buh_task_log_id' => $this->log->id,
                'verdict' => AuditTaskReview::VERDICT_FINDING,
                'severity' => 'critical',
            ])->assertOk();

        // Чек-лист должен быть закрыт целиком, иначе завершение не пройдёт
        $items = $audit->checklistItems()->get();
        $items[0]->update(['status' => AuditChecklistItem::STATUS_ERROR]);
        $items[1]->update(['status' => AuditChecklistItem::STATUS_NA]);

        $this->actingAs($this->auditor, 'employee')
            ->post("/audit/{$audit->id}/complete", ['summary' => 'Итог'])
            ->assertRedirect(route('audit.index'));

        $audit->refresh();
        $this->assertTrue($audit->isCompleted());
        $this->assertSame('Итог', $audit->summary);
        $this->assertNotNull($audit->completed_at);

        // Завершённый аудит правкам не поддаётся
        $this->actingAs($this->auditor, 'employee')
            ->putJson("/audit/{$audit->id}/checklist/{$items[0]->id}", ['comment' => 'после завершения'])
            ->assertStatus(422);

        // Возврат в работу снимает балл
        $this->actingAs($this->auditor, 'employee')
            ->post("/audit/{$audit->id}/reopen")
            ->assertRedirect();

        $audit->refresh();
        $this->assertFalse($audit->isCompleted());
    }

    public function test_completion_is_blocked_while_checklist_has_unchecked_items(): void
    {
        $audit = $this->createAudit();

        // Ни одного статуса не проставлено — завершать нельзя
        $this->actingAs($this->auditor, 'employee')
            ->post("/audit/{$audit->id}/complete", ['summary' => 'Рано'])
            ->assertRedirect(route('audit.show', $audit))
            ->assertSessionHas('error');

        $this->assertFalse($audit->fresh()->isCompleted());

        // Один закрыли, второй нет — всё ещё нельзя
        $audit->checklistItems()->first()->update(['status' => AuditChecklistItem::STATUS_OK]);

        $this->actingAs($this->auditor, 'employee')
            ->post("/audit/{$audit->id}/complete")
            ->assertSessionHas('error');

        $this->assertFalse($audit->fresh()->isCompleted());

        // Осознанное завершение через «Всё равно завершить»
        $this->actingAs($this->auditor, 'employee')
            ->post("/audit/{$audit->id}/complete", ['force' => 1])
            ->assertRedirect(route('audit.index'))
            ->assertSessionHas('success');

        $this->assertTrue($audit->fresh()->isCompleted());
    }

    /** Полный цикл замечания: передали → бухгалтер закрыл → аудитор вернул → закрыл. */
    public function test_finding_remediation_cycle(): void
    {
        $audit = $this->createAudit();

        $this->actingAs($this->auditor, 'employee')
            ->postJson("/audit/{$audit->id}/verdict", [
                'buh_task_log_id' => $this->log->id,
                'verdict'  => AuditTaskReview::VERDICT_FINDING,
                'severity' => 'critical',
                'comment'  => 'Платёж без договора',
            ])->assertOk();

        $review = $audit->taskReviews()->first();
        $this->assertSame(AuditTaskReview::STATE_DRAFT, $review->state);

        // Закрываем чек-лист и завершаем аудит с передачей замечания
        $audit->checklistItems()->update(['status' => AuditChecklistItem::STATUS_NA]);

        $this->actingAs($this->auditor, 'employee')
            ->post("/audit/{$audit->id}/complete", [
                'summary'  => 'Итог',
                'findings' => [
                    $review->id => ['send' => 1, 'assignee_id' => $this->accountant->id, 'due_date' => '2026-08-15'],
                ],
            ])
            ->assertRedirect(route('audit.index'));

        $review->refresh();
        $this->assertNotNull($review->sent_at);
        $this->assertSame($this->accountant->id, $review->assignee_id);
        $this->assertSame(AuditTaskReview::STATE_OPEN, $review->state);

        // У бухгалтера появилась задача на исправление, проверки главбуха она не требует
        $adhoc = BuhAdhocTask::find($review->adhoc_task_id);
        $this->assertNotNull($adhoc);
        $this->assertSame($this->accountant->id, $adhoc->employee_id);
        $this->assertFalse((bool) $adhoc->requires_review);
        $this->assertStringContainsString('Платёж без договора', $adhoc->description);

        // Бухгалтер закрыл задачу → замечание ждёт проверки аудитора
        $adhoc->update(['status' => 'completed', 'completed_at' => now(), 'employee_comment' => 'Договор приложен']);
        $this->assertSame(AuditTaskReview::STATE_SUBMITTED, $review->fresh()->state);

        // Аудитор вернул — задача снова открыта, замечание опять на исправлении
        $this->actingAs($this->auditor, 'employee')
            ->post(route('audit.findings.return', $review), ['comment' => 'Приложен не тот документ'])
            ->assertRedirect();

        $review->refresh();
        $adhoc->refresh();
        $this->assertSame('pending', $adhoc->status);
        $this->assertSame(1, $adhoc->rework_count);
        $this->assertSame('Приложен не тот документ', $adhoc->review_comment);
        $this->assertSame(1, $review->returns_count);
        $this->assertSame(AuditTaskReview::STATE_OPEN, $review->state);

        // Исправил повторно → аудитор закрыл
        $adhoc->update(['status' => 'completed', 'completed_at' => now()]);
        $this->actingAs($this->auditor, 'employee')
            ->post(route('audit.findings.resolve', $review))
            ->assertRedirect();

        $review->refresh();
        $this->assertSame(AuditTaskReview::STATE_RESOLVED, $review->state);
        $this->assertSame($this->auditor->id, $review->resolved_by);
    }

    public function test_finding_can_be_sent_and_reassigned_from_registry(): void
    {
        $audit = $this->createAudit();

        $this->actingAs($this->auditor, 'employee')
            ->postJson("/audit/{$audit->id}/verdict", [
                'buh_task_log_id' => $this->log->id,
                'verdict' => AuditTaskReview::VERDICT_FINDING,
                'severity' => 'major',
            ])->assertOk();

        $review = $audit->taskReviews()->first();

        // Завершаем без передачи — замечание осталось наблюдением
        $audit->checklistItems()->update(['status' => AuditChecklistItem::STATUS_NA]);
        $this->actingAs($this->auditor, 'employee')->post("/audit/{$audit->id}/complete")->assertRedirect();
        $this->assertSame(AuditTaskReview::STATE_DRAFT, $review->fresh()->state);

        // Передаём из реестра
        $this->actingAs($this->auditor, 'employee')
            ->post(route('audit.findings.send', $review), [
                'assignee_id' => $this->accountant->id,
                'due_date'    => '2026-08-20',
            ])->assertRedirect();

        $this->assertSame(AuditTaskReview::STATE_OPEN, $review->fresh()->state);

        // Повторно передать нельзя
        $this->actingAs($this->auditor, 'employee')
            ->post(route('audit.findings.send', $review), [
                'assignee_id' => $this->accountant->id,
                'due_date'    => '2026-08-20',
            ])->assertStatus(422);

        // Переназначение меняет исполнителя и у замечания, и у задачи бухгалтера
        $this->actingAs($this->auditor, 'employee')
            ->post(route('audit.findings.reassign', $review), ['assignee_id' => $this->auditor->id])
            ->assertRedirect();

        $review->refresh();
        $this->assertSame($this->auditor->id, $review->assignee_id);
        $this->assertSame($this->auditor->id, BuhAdhocTask::find($review->adhoc_task_id)->employee_id);
    }

    public function test_findings_registry_is_manager_only(): void
    {
        $this->actingAs($this->accountant, 'employee')->get('/audit/findings')->assertForbidden();
        $this->actingAs($this->auditor, 'employee')->get('/audit/findings')->assertOk();
    }

    public function test_audit_page_renders_sections_and_checklist(): void
    {
        $audit = $this->createAudit();

        $this->actingAs($this->auditor, 'employee')
            ->get("/audit/{$audit->id}")
            ->assertOk()
            ->assertSee('Чек-лист проверки')
            ->assertSee('Секции и бизнес-процессы')
            ->assertSee('Разнесение банковской выписки');
    }
}
