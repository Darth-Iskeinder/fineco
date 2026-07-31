<?php

namespace Tests\Feature;

use App\Models\BuhAdhocTask;
use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\Module;
use App\Models\Periodicity;
use App\Models\Role;
use App\Models\Service;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Несколько документов на задачу (buh_task_documents): загрузка добавляет,
 * удаление до закрытия/проверки, запрет опасных расширений, лимит, проверка
 * «нельзя закрыть без документа» по новой таблице. По боевому mysql в транзакции.
 */
class TaskMultiDocumentTest extends TestCase
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

        Storage::fake('local');

        Periodicity::firstOrCreate(['name' => 'Ежемесячно'], ['kind' => 'monthly']);
        $employeeRole = Role::firstOrCreate(['name' => Role::EMPLOYEE], ['display_name' => 'Сотрудник']);
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
            'name' => 'ТОО Документы Тест', 'inn' => strtoupper(substr(md5(uniqid()), 0, 12)),
            'responsible_employee_id' => $this->head->id,
        ]);
    }

    private function makeLog(bool $requiresDocument = true, string $status = 'pending'): BuhTaskLog
    {
        $service = Service::create([
            'name' => 'Тест услуга ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_day' => [5], 'is_active' => true,
            'requires_document' => $requiresDocument,
        ]);
        $estimate = Estimate::firstOrCreate(['client_id' => $this->client->id], ['total' => 0]);
        $item = $estimate->items()->create([
            'service_id' => $service->id, 'type' => 'recurring',
            'name' => $service->name, 'periodicity' => 'Ежемесячно',
            'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 0,
        ]);

        return BuhTaskLog::create([
            'employee_id' => $this->accountant->id,
            'client_id' => $this->client->id,
            'estimate_item_id' => $item->id,
            'year' => now()->year, 'month' => now()->month,
            'status' => $status,
        ]);
    }

    private function upload(BuhTaskLog $log, string $filename = 'акт.pdf')
    {
        return $this->actingAs($this->accountant, 'employee')
            ->post(route('buhtasks.logs.document', $log), [
                'file' => UploadedFile::fake()->create($filename, 100),
            ], ['Accept' => 'application/json']);
    }

    public function test_upload_appends_documents(): void
    {
        $log = $this->makeLog();

        $this->upload($log, 'акт_1.pdf')->assertOk();
        $r = $this->upload($log, 'акт_2.xlsx')->assertOk();

        $docs = $r->json('log.documents');
        $this->assertCount(2, $docs);
        $this->assertSame(['акт_1.pdf', 'акт_2.xlsx'], array_column($docs, 'name'));

        // Оба файла реально лежат на приватном диске. Путь берём из БД:
        // наружу отдаётся только ссылка на скачивание, но не путь на диске.
        foreach ($log->documents()->pluck('path') as $path) {
            Storage::disk('local')->assertExists($path);
        }
    }

    public function test_upload_rejects_executable_extensions(): void
    {
        $log = $this->makeLog();

        foreach (['shell.php', 'evil.phtml', 'x.sh', 'x.html', 'x.svg'] as $bad) {
            $this->upload($log, $bad)->assertStatus(422);
        }
        $this->assertSame(0, $log->documents()->count());
    }

    public function test_upload_respects_limit(): void
    {
        $log = $this->makeLog();
        for ($i = 0; $i < 10; $i++) {
            $log->documents()->create(['path' => "buh_task_documents/{$log->id}/f{$i}.pdf", 'name' => "f{$i}.pdf"]);
        }

        $this->upload($log, 'одиннадцатый.pdf')->assertStatus(422);
        $this->assertSame(10, $log->documents()->count());
    }

    public function test_complete_requires_document_checks_new_table(): void
    {
        $log = $this->makeLog(requiresDocument: true);

        // Без документов закрыть нельзя
        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.logs.complete', $log))
            ->assertStatus(422)
            ->assertJsonPath('requires_document', true);

        // С документом в новой таблице — можно
        $log->documents()->create(['path' => 'buh_task_documents/x.pdf', 'name' => 'x.pdf']);
        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.logs.complete', $log))
            ->assertOk()
            ->assertJsonPath('log.status', 'completed');
    }

    public function test_delete_document_while_open(): void
    {
        $log = $this->makeLog();
        Storage::disk('local')->put('buh_task_documents/del.pdf', 'x');
        $doc = $log->documents()->create(['path' => 'buh_task_documents/del.pdf', 'name' => 'del.pdf']);

        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.logs.document-delete', [$log, $doc]))
            ->assertOk()
            ->assertJsonPath('log.documents', []);

        Storage::disk('local')->assertMissing('buh_task_documents/del.pdf');
        $this->assertSame(0, $log->documents()->count());
    }

    public function test_delete_blocked_for_completed_and_review(): void
    {
        foreach (['completed', 'review'] as $status) {
            $log = $this->makeLog(status: $status);
            $doc = $log->documents()->create(['path' => 'buh_task_documents/keep.pdf', 'name' => 'keep.pdf']);

            $this->actingAs($this->accountant, 'employee')
                ->postJson(route('buhtasks.logs.document-delete', [$log, $doc]))
                ->assertStatus(422);
            $this->assertSame(1, $log->documents()->count());
        }
    }

    public function test_upload_blocked_for_review(): void
    {
        $log = $this->makeLog(status: 'review');
        $this->upload($log)->assertStatus(422);
        $this->assertSame(0, $log->documents()->count());
    }

    public function test_foreign_employee_cannot_touch_documents(): void
    {
        $log = $this->makeLog();
        $doc = $log->documents()->create(['path' => 'buh_task_documents/z.pdf', 'name' => 'z.pdf']);

        $this->actingAs($this->head, 'employee')
            ->post(route('buhtasks.logs.document', $log), [
                'file' => UploadedFile::fake()->create('a.pdf', 10),
            ], ['Accept' => 'application/json'])
            ->assertForbidden();
        $this->actingAs($this->head, 'employee')
            ->postJson(route('buhtasks.logs.document-delete', [$log, $doc]))
            ->assertForbidden();
    }

    public function test_document_from_another_task_returns_404(): void
    {
        $logA = $this->makeLog();
        $logB = $this->makeLog();
        $docB = $logB->documents()->create(['path' => 'buh_task_documents/b.pdf', 'name' => 'b.pdf']);

        // Документ чужой задачи через свой лог удалить нельзя
        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.logs.document-delete', [$logA, $docB]))
            ->assertNotFound();
        $this->assertSame(1, $logB->documents()->count());
    }

    public function test_child_documents_locked_when_parent_closed(): void
    {
        // Родительский БП с подпунктом; подпункт отмечен (completed), родитель закрыт
        $service = Service::create([
            'name' => 'Родитель ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_day' => [5], 'is_active' => true,
        ]);
        $estimate = Estimate::firstOrCreate(['client_id' => $this->client->id], ['total' => 0]);
        $parentItem = $estimate->items()->create([
            'service_id' => $service->id, 'type' => 'recurring',
            'name' => 'Родитель', 'periodicity' => 'Ежемесячно',
            'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 0,
        ]);
        $childItem = $estimate->items()->create([
            'service_id' => $service->id, 'type' => 'recurring', 'parent_id' => $parentItem->id,
            'name' => 'Подпункт', 'periodicity' => 'Ежемесячно',
            'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 0,
        ]);
        $mk = fn ($itemId, $status) => BuhTaskLog::create([
            'employee_id' => $this->accountant->id, 'client_id' => $this->client->id,
            'estimate_item_id' => $itemId, 'year' => now()->year, 'month' => now()->month,
            'status' => $status,
        ]);
        $childLog = $mk($childItem->id, 'completed');
        $doc = $childLog->documents()->create(['path' => 'buh_task_documents/c.pdf', 'name' => 'c.pdf']);

        // Родитель открыт → документы подпункта можно менять (даже после галочки)
        $mk($parentItem->id, 'running');
        $this->upload($childLog, 'ещё.pdf')->assertOk();

        // Родитель закрыт → и загрузка, и удаление у подпункта блокируются
        BuhTaskLog::where('estimate_item_id', $parentItem->id)->update(['status' => 'completed']);
        $this->upload($childLog, 'поздно.pdf')->assertStatus(422);
        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.logs.document-delete', [$childLog, $doc]))
            ->assertStatus(422);
    }

    public function test_adhoc_documents_flow(): void
    {
        $task = BuhAdhocTask::create([
            'employee_id' => $this->accountant->id, 'client_id' => $this->client->id,
            'name' => 'Внеплановая с документами', 'year' => now()->year, 'month' => now()->month,
            'status' => 'pending', 'paused_seconds' => 0,
        ]);

        $r = $this->actingAs($this->accountant, 'employee')
            ->post(route('buhtasks.adhoc.document', $task), [
                'file' => UploadedFile::fake()->create('справка.pdf', 50),
            ], ['Accept' => 'application/json'])
            ->assertOk();
        $this->assertCount(1, $r->json('log.documents'));

        $docId = $r->json('log.documents.0.id');
        $this->actingAs($this->accountant, 'employee')
            ->postJson(route('buhtasks.adhoc.document-delete', [$task, $docId]))
            ->assertOk()
            ->assertJsonPath('log.documents', []);
    }
}
