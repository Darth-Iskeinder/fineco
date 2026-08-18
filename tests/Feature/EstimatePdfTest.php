<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Module;
use App\Models\Role;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * PDF сметы открывается.
 *
 * В шаблоне оставался $periodLabel со времён помесячной сметы: месяц с года из
 * estimates убрали, переменную считать перестали, а вывод в подвале остался.
 * Неопределённая переменная в blade — это ErrorException, поэтому ссылка «PDF»
 * отдавала 500 на любой смете, и вкладка открывалась пустой.
 */
class EstimatePdfTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $admin;

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

        $role = Role::firstOrCreate(['name' => Role::ADMIN], ['display_name' => 'Администратор']);

        $this->admin = Employee::create([
            'full_name' => 'Пдф Админ', 'position' => 'Админ',
            'email' => 'pdf_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
        $this->admin->modules()->attach(
            Module::firstOrCreate(['name' => 'clients'], ['display_name' => 'Клиенты', 'is_active' => true])->id
        );
    }

    private function clientWithEstimate(): Client
    {
        $client = Client::create([
            'name' => 'ТОО Пдф Тест',
            'inn' => strtoupper(substr(md5(uniqid()), 0, 12)),
        ]);

        $estimate = Estimate::create(['client_id' => $client->id, 'total' => 1000]);
        EstimateItem::create([
            'estimate_id' => $estimate->id, 'type' => 'recurring', 'name' => 'Услуга',
            'periodicity' => 'Ежемесячно', 'cost' => 1000, 'quantity' => 1, 'total' => 1000,
        ]);

        return $client;
    }

    public function test_pdf_opens_inline(): void
    {
        $client = $this->clientWithEstimate();

        $response = $this->actingAs($this->admin, 'employee')
            ->get(route('clients.estimate.pdf', $client))
            ->assertOk();

        $this->assertSame('application/pdf', $response->headers->get('content-type'));
        // Ссылки на смету открываются в новой вкладке: вложение оставило бы её пустой
        $this->assertStringStartsWith('inline', $response->headers->get('content-disposition'));
        $this->assertStringStartsWith('%PDF', $response->getContent());
    }

    /** Имя файла из кириллицы: раньше оставались одни подчёркивания. */
    public function test_filename_is_transliterated(): void
    {
        $client = $this->clientWithEstimate();

        $disposition = $this->actingAs($this->admin, 'employee')
            ->get(route('clients.estimate.pdf', $client))
            ->assertOk()
            ->headers->get('content-disposition');

        $this->assertStringContainsString('smeta-too-pdf-test.pdf', $disposition);
    }

    public function test_client_without_estimate_gets_404(): void
    {
        $client = Client::create([
            'name' => 'ТОО Без сметы',
            'inn' => strtoupper(substr(md5(uniqid()), 0, 12)),
        ]);

        $this->actingAs($this->admin, 'employee')
            ->get(route('clients.estimate.pdf', $client))
            ->assertNotFound();
    }
}
