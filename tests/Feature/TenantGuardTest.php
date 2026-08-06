<?php

namespace Tests\Feature;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Сторож разделения по фирмам.
 *
 * Через полгода кто-нибудь заведёт новую таблицу с данными и забудет про
 * пометку «чей». Никакой ошибки при этом не будет — просто данные всех фирм
 * лягут в одну кучу, и заметят это, когда одна фирма увидит чужое.
 *
 * Поэтому список таблиц без пометки задан здесь явно. Появилась новая — тест
 * падает и заставляет ответить на вопрос: она общая или у каждой фирмы своя.
 * Ответ дописывается сюда осознанно, а не забывается.
 */
class TenantGuardTest extends TestCase
{
    use DatabaseTransactions;

    /** Служебные таблицы Laravel — к данным фирм отношения не имеют. */
    private const SYSTEM_TABLES = [
        'cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs',
        'migrations', 'password_reset_tokens', 'sessions', 'users',
        'tenants',
        // Владельцы системы: живут вне фирм, привязывать их не к чему.
        'vendor_users',
    ];

    /**
     * Общие справочники: список задаёт государство или сама программа,
     * он одинаков для всех фирм и правится централизованно.
     */
    private const SHARED_TABLES = [
        'tax_systems',          // режимы налогообложения
        'tax_authorities',      // коды районных ГНС
        'organization_forms',   // ОсОО, ИП, филиал
        'client_statuses',      // на closes_service висит закрытие обслуживания
        'taxpayer_categories',  // малый, средний, крупный
        'periodicities',        // от kind считаются сроки сдачи
        'categories',           // код сверяет их по точному названию
        'roles',                // шесть системных ролей
        'modules',              // разделы системы
    ];

    /** Пустые и никем не используемые — разделять нечего. */
    private const DEAD_TABLES = [
        'accounting_methods',
        'check_types',
        'service_types',
    ];

    /**
     * Таблицы связей без своей пометки: обе стороны уже тенантные, и добраться
     * до строки можно только через родителя, у которого пометка есть.
     */
    private const PIVOTS_WITHOUT_TENANT = [
        'service_tariff',
        'service_tax_system',
        // Построчный журнал загрузки клиентов: строка живёт только внутри
        // client_imports и достаётся через него, а фирма помечена там.
        'client_import_rows',
    ];

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

    /** Главное: новая таблица без пометки должна быть объявлена здесь осознанно. */
    public function test_no_unexplained_table_without_a_tenant_mark(): void
    {
        $allowed = array_merge(
            self::SYSTEM_TABLES,
            self::SHARED_TABLES,
            self::DEAD_TABLES,
            self::PIVOTS_WITHOUT_TENANT,
        );

        $unexplained = collect(Schema::getTables())
            ->pluck('name')
            ->reject(fn (string $table) => Schema::hasColumn($table, 'tenant_id'))
            ->reject(fn (string $table) => in_array($table, $allowed, true))
            ->values();

        $this->assertTrue(
            $unexplained->isEmpty(),
            "Таблицы без пометки «чей»: {$unexplained->implode(', ')}. " .
            'Если данные принадлежат фирме — добавьте tenant_id и трейт BelongsToTenant. ' .
            'Если таблица общая для всех — впишите её в список в этом тесте.',
        );
    }

    /** Модель над тенантной таблицей обязана подключать трейт, иначе фильтра нет. */
    public function test_every_tenant_model_uses_the_trait(): void
    {
        $missing = [];

        foreach ($this->models() as $class) {
            $table = (new $class)->getTable();

            if (!Schema::hasTable($table) || !Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            if (!in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
                $missing[] = class_basename($class);
            }
        }

        $this->assertSame(
            [],
            $missing,
            'Моделям не хватает трейта BelongsToTenant: ' . implode(', ', $missing) .
            '. Без него запросы этой модели идут по всем фирмам сразу.',
        );
    }

    /** И наоборот: трейт без колонки — сломанный запрос при первом же обращении. */
    public function test_trait_is_not_used_where_there_is_no_column(): void
    {
        $wrong = [];

        foreach ($this->models() as $class) {
            if (!in_array(BelongsToTenant::class, class_uses_recursive($class), true)) {
                continue;
            }

            $table = (new $class)->getTable();

            if (!Schema::hasColumn($table, 'tenant_id')) {
                $wrong[] = class_basename($class);
            }
        }

        $this->assertSame([], $wrong, 'Трейт есть, а колонки нет: ' . implode(', ', $wrong));
    }

    /** Ни одна строка не должна остаться без хозяина. */
    public function test_no_orphan_rows_anywhere(): void
    {
        $orphans = [];

        foreach (collect(Schema::getTables())->pluck('name') as $table) {
            if (!Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            $count = DB::table($table)->whereNull('tenant_id')->count();

            if ($count > 0) {
                $orphans[] = "{$table}: {$count}";
            }
        }

        $this->assertSame([], $orphans, 'Строки без фирмы: ' . implode(', ', $orphans));
    }

    /** @return class-string<Model>[] */
    private function models(): array
    {
        return collect(glob(app_path('Models/*.php')))
            ->map(fn (string $file) => 'App\\Models\\' . basename($file, '.php'))
            ->filter(fn (string $class) => class_exists($class) && is_subclass_of($class, Model::class))
            ->values()
            ->all();
    }
}
