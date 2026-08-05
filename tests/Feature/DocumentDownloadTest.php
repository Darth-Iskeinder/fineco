<?php

namespace Tests\Feature;

use App\Models\BuhTaskLog;
use App\Models\Client;
use App\Models\ClientDocument;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\Module;
use App\Models\Periodicity;
use App\Models\Role;
use App\Models\Service;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Документы лежат на приватном диске и отдаются только через DocumentController.
 * Проверяем то, ради чего затевался переезд: без сессии файла нет, без модуля —
 * 403, с модулем — файл с правильным именем. Плюс inline только для безопасных
 * типов. По боевому mysql в транзакции, как остальные feature-тесты.
 */
class DocumentDownloadTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $accountant;
    private Employee $outsider;
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
        $role = Role::firstOrCreate(['name' => Role::EMPLOYEE], ['display_name' => 'Сотрудник']);
        $tasksModule = Module::firstOrCreate(
            ['name' => 'buhtasks'],
            ['display_name' => 'БухЗадачник', 'is_active' => true],
        );
        $clientsModule = Module::firstOrCreate(
            ['name' => 'clients'],
            ['display_name' => 'Клиенты', 'is_active' => true],
        );

        $this->accountant = Employee::create([
            'full_name' => 'Тест Бухгалтер', 'position' => 'Бухгалтер',
            'email' => 'doc_acc_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
        $this->accountant->modules()->attach([$tasksModule->id, $clientsModule->id]);

        // Сотрудник без единого модуля — проверяем, что доступ закрыт
        $this->outsider = Employee::create([
            'full_name' => 'Тест Без Доступа', 'position' => 'Стажёр',
            'email' => 'doc_out_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);

        $this->client = Client::create([
            'name' => 'ТОО Приватные Документы',
            'inn' => strtoupper(substr(md5(uniqid()), 0, 12)),
            'responsible_employee_id' => $this->accountant->id,
        ]);
    }

    private function makeTaskDocument(): array
    {
        $service = Service::create([
            'name' => 'Тест услуга ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_day' => [5], 'is_active' => true, 'requires_document' => true,
        ]);
        $estimate = Estimate::firstOrCreate(['client_id' => $this->client->id], ['total' => 0]);
        $item = $estimate->items()->create([
            'service_id' => $service->id, 'type' => 'recurring',
            'name' => $service->name, 'quantity' => 1, 'price' => 0, 'total' => 0,
        ]);
        $log = BuhTaskLog::create([
            'estimate_item_id' => $item->id, 'client_id' => $this->client->id,
            'employee_id' => $this->accountant->id, 'year' => 2026, 'month' => 7,
            'status' => 'pending',
        ]);

        Storage::disk('local')->put('buh_task_documents/' . $log->id . '/акт.pdf', '%PDF-1.4 тест');
        $doc = $log->documents()->create([
            'path' => 'buh_task_documents/' . $log->id . '/акт.pdf',
            'name' => 'акт.pdf',
        ]);

        return [$log, $doc];
    }

    private function makeClientDocument(string $mime = 'application/pdf'): ClientDocument
    {
        Storage::disk('local')->put('clients/' . $this->client->id . '/устав.pdf', '%PDF-1.4 тест');

        return $this->client->documents()->create([
            'name' => 'устав.pdf', 'original_name' => 'Устав компании.pdf',
            'path' => 'clients/' . $this->client->id . '/устав.pdf',
            'mime_type' => $mime, 'size' => 13,
        ]);
    }

    public function test_guest_cannot_download_task_document(): void
    {
        [, $doc] = $this->makeTaskDocument();

        $this->get(route('documents.task', $doc))
            ->assertRedirect('/login');
    }

    public function test_guest_cannot_download_client_document(): void
    {
        $doc = $this->makeClientDocument();

        $this->get(route('documents.client', $doc))
            ->assertRedirect('/login');
    }

    public function test_employee_without_module_gets_403(): void
    {
        [, $taskDoc] = $this->makeTaskDocument();
        $clientDoc = $this->makeClientDocument();

        $this->actingAs($this->outsider, 'employee')
            ->get(route('documents.task', $taskDoc))
            ->assertForbidden();

        $this->actingAs($this->outsider, 'employee')
            ->get(route('documents.client', $clientDoc))
            ->assertForbidden();
    }

    public function test_employee_with_module_downloads_file(): void
    {
        [, $doc] = $this->makeTaskDocument();

        $response = $this->actingAs($this->accountant, 'employee')
            ->get(route('documents.task', $doc))
            ->assertOk()
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
        $this->assertSame('%PDF-1.4 тест', $response->streamedContent());
    }

    /**
     * Просмотрщик во вкладке «Выполненные» грузит документ задачи в iframe с ?inline=1.
     * У документов задач mime не хранится в БД, а определяется с диска — своя ветка,
     * поэтому проверяем её отдельно от документа клиента.
     */
    public function test_task_pdf_opens_inline_for_preview(): void
    {
        [, $doc] = $this->makeTaskDocument();

        $response = $this->actingAs($this->accountant, 'employee')
            ->get(route('documents.task', $doc) . '?inline=1')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
    }

    public function test_client_document_downloads_under_original_name(): void
    {
        $doc = $this->makeClientDocument();

        $response = $this->actingAs($this->accountant, 'employee')
            ->get(route('documents.client', $doc))
            ->assertOk();

        $this->assertStringContainsString('Устав', rawurldecode($response->headers->get('Content-Disposition')));
    }

    public function test_pdf_opens_inline_for_preview(): void
    {
        $doc = $this->makeClientDocument();

        $response = $this->actingAs($this->accountant, 'employee')
            ->get(route('documents.client', $doc) . '?inline=1')
            ->assertOk();

        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
    }

    /** SVG — это XML со скриптом внутри; во вкладке приложения его открывать нельзя. */
    public function test_svg_is_never_inline(): void
    {
        $doc = $this->makeClientDocument('image/svg+xml');

        $response = $this->actingAs($this->accountant, 'employee')
            ->get(route('documents.client', $doc) . '?inline=1')
            ->assertOk();

        $this->assertStringContainsString('attachment', $response->headers->get('Content-Disposition'));
    }

    public function test_missing_file_on_disk_gives_404(): void
    {
        [, $doc] = $this->makeTaskDocument();
        Storage::disk('local')->delete($doc->path);

        $this->actingAs($this->accountant, 'employee')
            ->get(route('documents.task', $doc))
            ->assertNotFound();
    }

    /**
     * Старый способ получить файл должен быть мёртв: ни симлинка public/storage,
     * ни служебных роутов Laravel (`serve` у диска выключен).
     */
    public function test_direct_storage_url_is_dead(): void
    {
        [, $doc] = $this->makeTaskDocument();

        $this->actingAs($this->accountant, 'employee')
            ->get('/storage/' . $doc->path)
            ->assertNotFound();

        $this->assertFalse(file_exists(public_path('storage')), 'Симлинк public/storage вернулся');
    }

    /** Путь на диске наружу не отдаётся — во фронт уходит только ссылка. */
    public function test_json_exposes_url_not_path(): void
    {
        $doc = $this->makeClientDocument();

        $json = $doc->fresh()->toArray();

        $this->assertArrayNotHasKey('path', $json);
        $this->assertSame(route('documents.client', $doc), $json['url']);
    }
}
