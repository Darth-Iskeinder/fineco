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
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Shared\Date as SharedDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xls as XlsWriter;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx as XlsxWriter;
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

    /** Картинка на диске настоящая: mime документа задачи определяется по содержимому. */
    private function makeTaskImageDocument(): array
    {
        [$log, $doc] = $this->makeTaskDocument();

        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
        );
        Storage::disk('local')->put('buh_task_documents/' . $log->id . '/скан.png', $png);
        $image = $log->documents()->create([
            'path' => 'buh_task_documents/' . $log->id . '/скан.png',
            'name' => 'скан.png',
        ]);

        return [$log, $image];
    }

    /** Настоящий .xlsx на диске: просмотр таблицы читает файл, а не запись в БД. */
    private function makeTaskSheetDocument(string $extension = 'xlsx'): array
    {
        [$log, ] = $this->makeTaskDocument();

        $path = 'buh_task_documents/' . $log->id . '/отчёт.' . $extension;
        Storage::disk('local')->put($path, 'placeholder');
        $this->writeSpreadsheet(Storage::disk('local')->path($path), $extension);

        $doc = $log->documents()->create(['path' => $path, 'name' => 'отчёт.' . $extension]);

        return [$log, $doc];
    }

    private function writeSpreadsheet(string $absolutePath, string $extension): void
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Реестр');
        $sheet->fromArray([['Дата', 'Контрагент', 'Сумма']], null, 'A1');
        $sheet->setCellValue('A2', SharedDate::PHPToExcel(new \DateTime('2026-03-05')));
        $sheet->getStyle('A2')->getNumberFormat()->setFormatCode('dd.mm.yyyy');
        $sheet->setCellValueExplicit('B2', '00700123456', DataType::TYPE_STRING);
        $sheet->setCellValue('C2', 1500.5);

        $writer = $extension === 'xls' ? new XlsWriter($book) : new XlsxWriter($book);
        $writer->save($absolutePath);
        $book->disconnectWorksheets();
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

    /**
     * История задач на карточке клиента показывает ссылку на документ и тем, у кого
     * модуля задачника нет (руководитель). Раз ссылку показали — она должна работать.
     */
    public function test_manager_without_tasks_module_downloads_via_client_card(): void
    {
        [, $doc] = $this->makeTaskDocument();

        $managerRole = Role::firstOrCreate(['name' => Role::MANAGER], ['display_name' => 'Руководитель']);
        $manager = Employee::create([
            'full_name' => 'Тест Руководитель', 'position' => 'Руководитель',
            'email' => 'doc_mgr_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $managerRole->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
        $manager->modules()->attach(Module::where('name', 'clients')->value('id'));

        $this->actingAs($manager, 'employee')
            ->get(route('documents.task', $doc))
            ->assertOk();
    }

    /** История задач показывает PDF в окне — inline должен работать и по второму входу. */
    public function test_manager_opens_task_pdf_inline_via_client_card(): void
    {
        [, $doc] = $this->makeTaskDocument();

        $managerRole = Role::firstOrCreate(['name' => Role::MANAGER], ['display_name' => 'Руководитель']);
        $manager = Employee::create([
            'full_name' => 'Тест Руководитель', 'position' => 'Руководитель',
            'email' => 'doc_mgr2_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $managerRole->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
        $manager->modules()->attach(Module::where('name', 'clients')->value('id'));

        $response = $this->actingAs($manager, 'employee')
            ->get(route('documents.task', $doc) . '?inline=1')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf');

        $this->assertStringContainsString('inline', $response->headers->get('Content-Disposition'));
    }

    /** Модуля клиентов мало: чужие задачи по-прежнему закрыты. */
    public function test_clients_module_alone_does_not_open_other_clients_task_document(): void
    {
        [, $doc] = $this->makeTaskDocument();

        $role = Role::firstOrCreate(['name' => Role::EMPLOYEE], ['display_name' => 'Сотрудник']);
        $stranger = Employee::create([
            'full_name' => 'Тест Посторонний', 'position' => 'Менеджер',
            'email' => 'doc_str_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
        $stranger->modules()->attach(Module::where('name', 'clients')->value('id'));

        $this->actingAs($stranger, 'employee')
            ->get(route('documents.task', $doc))
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

    /**
     * Просмотрщик истории задач открывает в окне не только PDF, но и картинки —
     * рисует их <img> по той же ссылке с ?inline=1.
     */
    public function test_task_image_opens_inline_for_preview(): void
    {
        [, $doc] = $this->makeTaskImageDocument();

        $response = $this->actingAs($this->accountant, 'employee')
            ->get(route('documents.task', $doc) . '?inline=1')
            ->assertOk()
            ->assertHeader('Content-Type', 'image/png')
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

    /**
     * Excel браузер не рисует, поэтому просмотрщик просит у сервера содержимое.
     * Проверяем и .xlsx, и старый .xls: клиенты приносят оба.
     */
    public function test_task_spreadsheet_is_returned_as_rows(): void
    {
        foreach (['xlsx', 'xls'] as $extension) {
            [, $doc] = $this->makeTaskSheetDocument($extension);

            $response = $this->actingAs($this->accountant, 'employee')
                ->getJson(route('documents.task.sheet', $doc))
                ->assertOk();

            $response->assertJsonPath('sheets.0.name', 'Реестр');
            $response->assertJsonPath('sheets.0.rows.0', ['Дата', 'Контрагент', 'Сумма']);
            // Дата остаётся датой, а не числом 46086; текстовый номер не теряет нули.
            $response->assertJsonPath('sheets.0.rows.1.0', '05.03.2026');
            $response->assertJsonPath('sheets.0.rows.1.1', '00700123456');
        }
    }

    /** Права те же, что и на сам файл: разбор не должен становиться обходным путём. */
    public function test_employee_without_module_cannot_read_spreadsheet(): void
    {
        [, $doc] = $this->makeTaskSheetDocument();

        $this->actingAs($this->outsider, 'employee')
            ->getJson(route('documents.task.sheet', $doc))
            ->assertForbidden();
    }

    public function test_guest_cannot_read_spreadsheet(): void
    {
        [, $doc] = $this->makeTaskSheetDocument();

        $this->getJson(route('documents.task.sheet', $doc))
            ->assertUnauthorized();
    }

    /** Таблица клиента открывается так же, как таблица задачи. */
    public function test_client_spreadsheet_is_returned_as_rows(): void
    {
        $path = 'clients/' . $this->client->id . '/реестр.xlsx';
        Storage::disk('local')->put($path, 'placeholder');
        $this->writeSpreadsheet(Storage::disk('local')->path($path), 'xlsx');

        $doc = $this->client->documents()->create([
            'name' => 'реестр.xlsx', 'original_name' => 'Реестр платежей.xlsx',
            'path' => $path, 'mime_type' => 'application/zip', 'size' => 1,
        ]);

        $this->actingAs($this->accountant, 'employee')
            ->getJson(route('documents.client.sheet', $doc))
            ->assertOk()
            ->assertJsonPath('sheets.0.rows.0.0', 'Дата');
    }

    /** Не таблица — не разбираем: для PDF есть свой просмотрщик. */
    public function test_non_spreadsheet_is_rejected(): void
    {
        [, $doc] = $this->makeTaskDocument();

        $this->actingAs($this->accountant, 'employee')
            ->getJson(route('documents.task.sheet', $doc))
            ->assertStatus(415);
    }

    /** Битый файл с расширением таблицы — понятное сообщение, а не пятисотка. */
    public function test_broken_spreadsheet_gives_message_instead_of_error(): void
    {
        [$log, ] = $this->makeTaskDocument();

        $path = 'buh_task_documents/' . $log->id . '/битый.xlsx';
        Storage::disk('local')->put($path, 'это совсем не таблица');
        $doc = $log->documents()->create(['path' => $path, 'name' => 'битый.xlsx']);

        $this->actingAs($this->accountant, 'employee')
            ->getJson(route('documents.task.sheet', $doc))
            ->assertStatus(422)
            ->assertJsonStructure(['message']);
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
