<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Этап 1 мультитенанта: у рабочих данных появилась пометка «чей это ряд».
 * Поведение системы пока не меняется — разделение по аккаунтам включается позже.
 * Здесь проверяем ровно две вещи: пометка есть везде и строк без хозяина нет.
 */
class TenantSchemaTest extends TestCase
{
    use DatabaseTransactions;

    /** Рабочие данные фирмы. Справочники сюда намеренно не входят. */
    private const WORKING_TABLES = [
        'clients',
        'client_documents',
        'client_employee',
        'client_service_schedules',
        'employees',
        'employee_module',
        'estimates',
        'estimate_items',
        'buh_task_logs',
        'buh_adhoc_tasks',
        'buh_task_documents',
        'task_reminders',
        'audits',
        'audit_task_reviews',
        'audit_checklist_items',
    ];

    /**
     * Справочники, которые решено оставить фирме на редактирование, — значит у
     * каждой свой набор. Пополняется по мере разбора настроек. Справочники,
     * закрытые на просмотр (режимы налогообложения), остаются общими и сюда
     * не попадают.
     */
    private const TENANT_DICTIONARIES = [
        'activity_types',
        'tariffs',
        'rates',
        'services',
    ];

    /** Все таблицы, которым положена пометка «чей это ряд». */
    private function taggedTables(): array
    {
        return array_merge(self::WORKING_TABLES, self::TENANT_DICTIONARIES);
    }

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

    public function test_first_account_exists(): void
    {
        $tenant = Tenant::orderBy('id')->first();

        $this->assertNotNull($tenant, 'Нет ни одного аккаунта — привязывать данные не к чему');
        $this->assertTrue($tenant->isActive());
    }

    public function test_every_working_table_has_tenant_column(): void
    {
        foreach ($this->taggedTables() as $table) {
            $this->assertTrue(
                Schema::hasColumn($table, 'tenant_id'),
                "В таблице {$table} нет пометки tenant_id",
            );
        }
    }

    public function test_no_rows_without_an_owner(): void
    {
        foreach ($this->taggedTables() as $table) {
            $orphans = DB::table($table)->whereNull('tenant_id')->count();

            $this->assertSame(0, $orphans, "В таблице {$table} есть строки без хозяина");
        }
    }

    /**
     * Пока код нигде не проставляет tenant_id, за него это делает значение по
     * умолчанию — иначе создание клиента падало бы с ошибкой. Когда появится
     * трейт BelongsToTenant, default снимаем, и этот тест меняем на проверку
     * «привязка проставлена явно».
     */
    public function test_new_row_gets_an_owner_automatically(): void
    {
        $client = Client::create([
            'name' => 'ТОО Проверка Аккаунта',
            'inn'  => strtoupper(substr(md5(uniqid()), 0, 12)),
        ]);

        $this->assertNotNull($client->fresh()->tenant_id);
    }

    /** Аккаунт нельзя удалить, пока за ним числятся данные. */
    public function test_account_with_data_cannot_be_deleted(): void
    {
        $tenant = Tenant::orderBy('id')->first();

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('tenants')->where('id', $tenant->id)->delete();
    }
}
