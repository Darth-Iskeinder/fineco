<?php

namespace Tests\Feature;

use App\Models\BuhAdhocTask;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Module;
use App\Models\Periodicity;
use App\Models\Role;
use App\Models\Service;
use App\Services\ClientTaskHistory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * История выполненных задач клиента (секция внизу карточки клиента).
 * Проверяем то, ради чего сервис заведён: права, только выполненное, оба источника
 * в одном списке, фильтр по документам (включая документ на подпункте) и пагинация.
 * По боевому mysql в транзакции, как остальные feature-тесты.
 */
class ClientTaskHistoryTest extends TestCase
{
    use DatabaseTransactions;

    private ClientTaskHistory $history;
    private Employee $head;
    private Employee $accountant;
    private Client $client;
    private Estimate $estimate;
    private Module $clientsModule;

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

        $this->history = new ClientTaskHistory();

        Periodicity::firstOrCreate(['name' => 'Ежемесячно'], ['kind' => 'monthly']);
        $this->clientsModule = Module::firstOrCreate(
            ['name' => 'clients'],
            ['display_name' => 'Клиенты', 'is_active' => true],
        );

        $this->head       = $this->makeEmployee(Role::HEAD_ACCOUNTANT, 'Главбух');
        $this->accountant = $this->makeEmployee(Role::ACCOUNTANT, 'Бухгалтер');

        $this->client = Client::create([
            'name'                    => 'ТОО История Тест',
            'inn'                     => strtoupper(substr(md5(uniqid()), 0, 12)),
            'responsible_employee_id' => $this->head->id,
        ]);

        $this->estimate = Estimate::firstOrCreate(['client_id' => $this->client->id], ['total' => 0]);
    }

    private function makeEmployee(string $roleName, string $label): Employee
    {
        $role = Role::firstOrCreate(['name' => $roleName], ['display_name' => $label]);

        $employee = Employee::create([
            'full_name' => 'Тест ' . $label,
            'position'  => $label,
            'email'     => substr($roleName, 0, 4) . '_' . uniqid() . '@test.kg',
            'password'  => bcrypt('x'),
            'role_id'   => $role->id,
            'status'    => Employee::STATUS_ACTIVE,
        ]);
        $employee->modules()->attach($this->clientsModule->id);

        return $employee;
    }

    private function makeItem(?int $parentId = null, bool $requiresDocument = false): EstimateItem
    {
        $service = Service::create([
            'name'              => 'Тест БП ' . uniqid(),
            'periodicity'       => 'Ежемесячно',
            'start_day'         => [5],
            'is_active'         => true,
            'requires_document' => $requiresDocument,
        ]);

        return $this->estimate->items()->create([
            'parent_id'   => $parentId,
            'service_id'  => $service->id,
            'type'        => 'recurring',
            'name'        => $service->name,
            'periodicity' => 'Ежемесячно',
            'cost'        => 0,
            'quantity'    => 1,
            'total'       => 0,
            'sort_order'  => 0,
        ]);
    }

    private function makeLog(
        EstimateItem $item,
        string $status = 'completed',
        ?string $completedAt = '2026-07-10 12:00:00',
        int $month = 7,
    ): BuhTaskLog {
        return BuhTaskLog::create([
            'employee_id'      => $this->accountant->id,
            'client_id'        => $this->client->id,
            'estimate_item_id' => $item->id,
            'year'             => 2026,
            'month'            => $month,
            'status'           => $status,
            'completed_at'     => $status === 'completed' ? $completedAt : null,
        ]);
    }

    private function makeAdhoc(string $status = 'completed', ?string $completedAt = '2026-07-20 09:00:00'): BuhAdhocTask
    {
        return BuhAdhocTask::create([
            'employee_id'  => $this->accountant->id,
            'client_id'    => $this->client->id,
            'name'         => 'Внеплановая ' . uniqid(),
            'year'         => 2026,
            'month'        => 7,
            'due_day'      => 15,
            'status'       => $status,
            'completed_at' => $status === 'completed' ? $completedAt : null,
        ]);
    }

    private function attachDocument($task, string $name = 'акт.pdf'): void
    {
        $task->documents()->create(['path' => 'task-docs/' . uniqid() . '.pdf', 'name' => $name]);
    }

    // ---------- права ----------

    public function test_admin_and_manager_see_any_client(): void
    {
        $admin   = $this->makeEmployee(Role::ADMIN, 'Админ');
        $manager = $this->makeEmployee(Role::MANAGER, 'Руководитель');

        $this->assertTrue($this->history->canView($admin, $this->client));
        $this->assertTrue($this->history->canView($manager, $this->client));
    }

    public function test_responsible_head_sees_own_client_others_do_not(): void
    {
        $otherHead = $this->makeEmployee(Role::HEAD_ACCOUNTANT, 'Главбух');

        $this->assertTrue($this->history->canView($this->head, $this->client));
        $this->assertFalse($this->history->canView($otherHead, $this->client));
        $this->assertFalse($this->history->canView($this->accountant, $this->client));
    }

    // ---------- состав списка ----------

    public function test_only_completed_tasks_are_listed(): void
    {
        $this->makeLog($this->makeItem(), 'completed');
        $this->makeLog($this->makeItem(), 'running');
        $this->makeLog($this->makeItem(), 'review');
        $this->makeAdhoc('completed');
        $this->makeAdhoc('pending');

        $page = $this->history->page($this->client);

        $this->assertSame(2, $page['total']);
        $this->assertEqualsCanonicalizing(
            ['planned', 'adhoc'],
            array_column($page['items'], 'type'),
        );
    }

    public function test_child_logs_are_not_separate_rows(): void
    {
        $parent = $this->makeItem();
        $child  = $this->makeItem($parent->id);

        $this->makeLog($parent, 'completed');
        $this->makeLog($child, 'completed');

        $page = $this->history->page($this->client);

        $this->assertSame(1, $page['total']);
        $this->assertSame($parent->name, $page['items'][0]['name']);
    }

    public function test_rows_are_sorted_by_completion_desc(): void
    {
        $this->makeLog($this->makeItem(), 'completed', '2026-07-01 10:00:00');
        $this->makeLog($this->makeItem(), 'completed', '2026-07-25 10:00:00');
        $this->makeAdhoc('completed', '2026-07-12 10:00:00');

        $page = $this->history->page($this->client);

        $this->assertSame([
            '2026-07-25 10:00:00',
            '2026-07-12 10:00:00',
            '2026-07-01 10:00:00',
        ], array_column($page['items'], 'completed_at'));
    }

    public function test_row_carries_marks_admin_comes_for(): void
    {
        $item = $this->makeItem(requiresDocument: true);
        $log  = $this->makeLog($item);
        $log->update([
            'force_closed' => true,
            'rework_count' => 2,
            'reviewed_by'  => $this->head->id,
            'reviewed_at'  => '2026-07-11 08:00:00',
        ]);

        $row = $this->history->page($this->client)['items'][0];

        $this->assertTrue($row['force_closed']);
        $this->assertSame(2, $row['rework_count']);
        $this->assertSame($this->head->full_name, $row['reviewer_name']);
        $this->assertSame($this->accountant->full_name, $row['doer_name']);
        $this->assertTrue($row['requires_document']);
        // Срок в июле — отчёт за предыдущий месяц, как и в задачнике.
        $this->assertSame('за июнь', $row['reporting_period']);
    }

    // ---------- фильтр по документам ----------

    public function test_documents_filter_splits_rows(): void
    {
        $withDoc = $this->makeLog($this->makeItem());
        $this->attachDocument($withDoc);
        $this->makeLog($this->makeItem(), 'completed', '2026-07-05 10:00:00');

        $all     = $this->history->page($this->client, ClientTaskHistory::DOCS_ALL);
        $with    = $this->history->page($this->client, ClientTaskHistory::DOCS_WITH);
        $without = $this->history->page($this->client, ClientTaskHistory::DOCS_WITHOUT);

        $this->assertSame(2, $all['total']);
        $this->assertSame(1, $with['total']);
        $this->assertSame(1, $with['items'][0]['documents_count']);
        $this->assertSame(1, $without['total']);
        $this->assertSame(0, $without['items'][0]['documents_count']);
    }

    public function test_document_on_child_counts_for_parent_task(): void
    {
        $parent = $this->makeItem();
        $child  = $this->makeItem($parent->id);

        $this->makeLog($parent, 'completed');
        // Подпункт закрывать необязательно — документ на нём всё равно относится к задаче.
        $childLog = $this->makeLog($child, 'running', null);
        $this->attachDocument($childLog, 'подпункт.pdf');

        $with    = $this->history->page($this->client, ClientTaskHistory::DOCS_WITH);
        $without = $this->history->page($this->client, ClientTaskHistory::DOCS_WITHOUT);

        $this->assertSame(1, $with['total']);
        $this->assertSame(1, $with['items'][0]['documents_count']);
        $this->assertSame(0, $without['total']);
    }

    public function test_adhoc_documents_are_counted(): void
    {
        $adhoc = $this->makeAdhoc();
        $this->attachDocument($adhoc, 'внеплановая.pdf');

        $with = $this->history->page($this->client, ClientTaskHistory::DOCS_WITH);

        $this->assertSame(1, $with['total']);
        $this->assertSame('adhoc', $with['items'][0]['type']);
        $this->assertSame(1, $with['items'][0]['documents_count']);
    }

    // ---------- пагинация ----------

    public function test_pagination_slices_and_reports_meta(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->makeLog($this->makeItem(), 'completed', sprintf('2026-07-%02d 10:00:00', $i));
        }

        $first = $this->history->page($this->client, ClientTaskHistory::DOCS_ALL, page: 1, perPage: 2);
        $last  = $this->history->page($this->client, ClientTaskHistory::DOCS_ALL, page: 3, perPage: 2);

        $this->assertSame(5, $first['total']);
        $this->assertSame(3, $first['last_page']);
        $this->assertCount(2, $first['items']);
        $this->assertSame('2026-07-05 10:00:00', $first['items'][0]['completed_at']);

        $this->assertCount(1, $last['items']);
        $this->assertSame('2026-07-01 10:00:00', $last['items'][0]['completed_at']);
    }

    public function test_page_beyond_last_falls_back_to_last_page(): void
    {
        $this->makeLog($this->makeItem());

        $page = $this->history->page($this->client, ClientTaskHistory::DOCS_ALL, page: 99, perPage: 20);

        $this->assertSame(1, $page['page']);
        $this->assertCount(1, $page['items']);
    }

    public function test_unknown_documents_filter_falls_back_to_all(): void
    {
        $this->makeLog($this->makeItem());

        $page = $this->history->page($this->client, 'мусор');

        $this->assertSame(1, $page['total']);
    }

    // ---------- карточка задачи (попап) ----------

    public function test_details_include_children_and_documents(): void
    {
        $parent = $this->makeItem(requiresDocument: true);
        $child  = $this->makeItem($parent->id);

        $log = $this->makeLog($parent);
        $log->update(['employee_comment' => 'сдал через кабинет', 'review_comment' => 'принято']);
        $this->attachDocument($log, 'отчёт.pdf');

        $childLog = $this->makeLog($child, 'completed');
        $this->attachDocument($childLog, 'приложение.pdf');

        $details = $this->history->details($this->client, 'planned', $log->id);

        $this->assertSame($parent->name, $details['name']);
        $this->assertSame('сдал через кабинет', $details['employee_comment']);
        $this->assertSame('принято', $details['review_comment']);
        $this->assertCount(1, $details['documents']);
        $this->assertSame('отчёт.pdf', $details['documents'][0]['name']);

        $this->assertCount(1, $details['children']);
        $this->assertSame($child->name, $details['children'][0]['name']);
        $this->assertSame('completed', $details['children'][0]['status']);
        $this->assertSame('приложение.pdf', $details['children'][0]['documents'][0]['name']);
    }

    public function test_child_without_log_shows_as_unfinished(): void
    {
        $parent = $this->makeItem();
        $child  = $this->makeItem($parent->id);
        $log    = $this->makeLog($parent);

        $details = $this->history->details($this->client, 'planned', $log->id);

        $this->assertCount(1, $details['children']);
        $this->assertSame($child->name, $details['children'][0]['name']);
        $this->assertSame('pending', $details['children'][0]['status']);
    }

    public function test_adhoc_details_use_checklist_snapshot(): void
    {
        $adhoc = $this->makeAdhoc();
        $adhoc->update([
            'checklist'   => [['name' => 'Собрать документы', 'done' => true], ['name' => 'Отправить', 'done' => false]],
            'description' => 'разовая сверка',
        ]);

        $details = $this->history->details($this->client, 'adhoc', $adhoc->id);

        $this->assertSame('adhoc', $details['type']);
        $this->assertSame('разовая сверка', $details['description']);
        $this->assertCount(2, $details['children']);
        $this->assertSame('completed', $details['children'][0]['status']);
        $this->assertSame('pending', $details['children'][1]['status']);
    }

    public function test_details_reject_other_client_and_unfinished_tasks(): void
    {
        $unfinished = $this->makeLog($this->makeItem(), 'running', null);

        $this->assertNull($this->history->details($this->client, 'planned', $unfinished->id));
        $this->assertNull($this->history->details($this->client, 'planned', 999999));
    }

    public function test_details_route_returns_card_and_guards_access(): void
    {
        $log = $this->makeLog($this->makeItem());

        $this->actingAs($this->head, 'employee')
            ->getJson(route('clients.task-history.show', [$this->client, 'planned', $log->id]))
            ->assertOk()
            ->assertJsonPath('type', 'planned');

        $otherHead = $this->makeEmployee(Role::HEAD_ACCOUNTANT, 'Главбух');
        $this->actingAs($otherHead, 'employee')
            ->getJson(route('clients.task-history.show', [$this->client, 'planned', $log->id]))
            ->assertForbidden();

        $this->actingAs($this->head, 'employee')
            ->getJson(route('clients.task-history.show', [$this->client, 'planned', 999999]))
            ->assertNotFound();
    }

    // ---------- роут и секция ----------

    public function test_route_returns_history_for_permitted_employee(): void
    {
        $log = $this->makeLog($this->makeItem());
        $this->attachDocument($log);

        $response = $this->actingAs($this->head, 'employee')
            ->getJson(route('clients.task-history.index', [$this->client, 'docs' => 'with']));

        $response->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('items.0.documents_count', 1);
    }

    public function test_route_is_forbidden_for_employee_without_rights(): void
    {
        $otherHead = $this->makeEmployee(Role::HEAD_ACCOUNTANT, 'Главбух');

        $this->actingAs($otherHead, 'employee')
            ->getJson(route('clients.task-history.index', $this->client))
            ->assertForbidden();
    }

    public function test_route_rejects_unknown_documents_filter(): void
    {
        $this->actingAs($this->head, 'employee')
            ->getJson(route('clients.task-history.index', [$this->client, 'docs' => 'мусор']))
            ->assertStatus(422);
    }

    public function test_section_is_absent_from_markup_without_rights(): void
    {
        $this->actingAs($this->head, 'employee')
            ->get(route('clients.show', $this->client))
            ->assertOk()
            ->assertSee('История задач');

        // Карточку бухгалтер открывает как участник команды клиента, а вот историю
        // задач видит только ответственный — секции в разметке быть не должно.
        $this->client->employees()->syncWithoutDetaching([$this->accountant->id]);

        $this->actingAs($this->accountant, 'employee')
            ->get(route('clients.show', $this->client))
            ->assertOk()
            ->assertDontSee('История задач');
    }

    public function test_other_clients_tasks_are_not_listed(): void
    {
        $otherClient   = Client::create([
            'name'                    => 'ТОО Чужой',
            'inn'                     => strtoupper(substr(md5(uniqid()), 0, 12)),
            'responsible_employee_id' => $this->head->id,
        ]);
        $otherEstimate = Estimate::firstOrCreate(['client_id' => $otherClient->id], ['total' => 0]);
        $otherItem     = $otherEstimate->items()->create([
            'service_id' => null, 'type' => 'recurring', 'name' => 'Чужой БП',
            'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 0,
        ]);
        BuhTaskLog::create([
            'employee_id'      => $this->accountant->id,
            'client_id'        => $otherClient->id,
            'estimate_item_id' => $otherItem->id,
            'year'             => 2026, 'month' => 7,
            'status'           => 'completed', 'completed_at' => '2026-07-10 12:00:00',
        ]);

        $this->makeLog($this->makeItem());

        $page = $this->history->page($this->client);

        $this->assertSame(1, $page['total']);
        $this->assertNotSame('Чужой БП', $page['items'][0]['name']);
    }
}
