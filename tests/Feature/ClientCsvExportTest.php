<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Module;
use App\Models\Role;
use App\Models\Tariff;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Выгрузка клиентов в CSV: формат, который потом читает импорт.
 */
class ClientCsvExportTest extends TestCase
{
    use DatabaseTransactions;

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

    private function viewer(): Employee
    {
        $role = Role::firstOrCreate(['name' => 'employee'], ['display_name' => 'Сотрудник']);

        $employee = Employee::create([
            'full_name' => 'Смотрящий', 'position' => 'Бухгалтер',
            'email' => uniqid('emp_') . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);

        $employee->modules()->attach(
            Module::firstOrCreate(['name' => 'clients'], ['display_name' => 'Клиенты', 'is_active' => true])->id
        );

        return $employee;
    }

    private function export(Employee $viewer, string $query = ''): string
    {
        $response = $this->actingAs($viewer, 'employee')->get('/clients/export' . $query);
        $response->assertOk();

        return $response->streamedContent();
    }

    public function test_export_has_bom_and_headers(): void
    {
        $csv = $this->export($this->viewer());

        // Без BOM Excel открывает файл в системной кодировке и показывает кракозябры.
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Название;ИНН', $csv);
        $this->assertStringContainsString('Контактное лицо', $csv);
    }

    public function test_export_writes_reference_books_as_names_not_ids(): void
    {
        $viewer = $this->viewer();
        $tariff = Tariff::create([
            'name' => 'Тариф выгрузки ' . uniqid(), 'code' => uniqid('t_'), 'price' => 0, 'is_active' => true,
        ]);

        $client = Client::create([
            'name'                    => 'Клиент выгрузки ' . uniqid(),
            'inn'                     => (string) random_int(100000000000, 999999999999),
            'tariff_id'               => $tariff->id,
            'responsible_employee_id' => $viewer->id,
            'tax_office_code'         => '007',
            'is_active'               => true,
            'contacts'                => [['type' => 'email', 'value' => 'a@b.kg'], ['type' => 'phone', 'value' => '+996700111222']],
            'related_persons'         => [['name' => 'Петров Пётр', 'role' => 'Директор']],
        ]);

        $csv = $this->export($viewer);

        $this->assertStringContainsString($client->name, $csv);
        $this->assertStringContainsString($tariff->name, $csv);
        $this->assertStringContainsString($viewer->full_name, $csv);
        // Телефон берём первый среди контактов именно телефонного типа, не почту.
        $this->assertStringContainsString('+996700111222', $csv);
        $this->assertStringNotContainsString('a@b.kg', $csv);
        $this->assertStringContainsString('Петров Пётр', $csv);
    }

    public function test_export_respects_the_search_box(): void
    {
        $viewer = $this->viewer();

        $wanted   = Client::create(['name' => 'Нужный ' . uniqid(), 'inn' => (string) random_int(100000000000, 999999999999)]);
        $unwanted = Client::create(['name' => 'Посторонний ' . uniqid(), 'inn' => (string) random_int(100000000000, 999999999999)]);

        $csv = $this->export($viewer, '?search=' . urlencode($wanted->name));

        $this->assertStringContainsString($wanted->name, $csv);
        $this->assertStringNotContainsString($unwanted->name, $csv);
    }

    public function test_formula_in_a_name_cannot_run_in_excel(): void
    {
        $viewer = $this->viewer();

        Client::create([
            'name' => '=HYPERLINK("http://evil.kg")' . uniqid(),
            'inn'  => (string) random_int(100000000000, 999999999999),
        ]);

        $csv = $this->export($viewer);

        // Значение уезжает под апострофом — Excel покажет текст, а не выполнит его.
        $this->assertStringContainsString('\'=HYPERLINK', $csv);
        $this->assertStringNotContainsString(';=HYPERLINK', $csv);
    }

    public function test_template_carries_several_examples(): void
    {
        $response = $this->actingAs($this->viewer(), 'employee')->get('/clients/import/template');
        $response->assertOk();

        $csv = $response->streamedContent();

        $this->assertStringContainsString('Название;ИНН', $csv);
        $this->assertStringContainsString('ОсОО «Ромашка»', $csv);
        $this->assertStringContainsString('ОсОО «Василёк»', $csv);
        $this->assertStringContainsString('ИП Иванов И.И.', $csv);
    }

    public function test_template_passes_the_import_check_it_is_meant_for(): void
    {
        $viewer = $this->viewer();

        // Скачанный шаблон загружаем обратно: система не имеет права ругаться
        // на файл, который сама же и выдала.
        $csv = $this->actingAs($viewer, 'employee')->get('/clients/import/template')->streamedContent();

        $file = \Illuminate\Http\UploadedFile::fake()->createWithContent('clients-template.csv', $csv);

        $plan = $this->actingAs($viewer, 'employee')
            ->post('/clients/import/preview', ['file' => $file])
            ->assertOk()
            ->viewData('plan');

        $this->assertCount(3, $plan);

        foreach ($plan as $row) {
            $this->assertSame('create', $row['verdict'], 'строка ' . $row['line'] . ': ' . $row['reason']);
        }
    }

    public function test_export_needs_the_clients_module(): void
    {
        $role = Role::firstOrCreate(['name' => 'employee'], ['display_name' => 'Сотрудник']);

        $outsider = Employee::create([
            'full_name' => 'Без доступа', 'position' => 'Курьер',
            'email' => uniqid('out_') . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);

        $this->actingAs($outsider, 'employee')->get('/clients/export')->assertForbidden();
    }
}
