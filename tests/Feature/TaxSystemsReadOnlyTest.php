<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Role;
use App\Models\TaxSystem;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Режимы налогообложения — справочная страница без действий.
 *
 * Список задаёт государство, а не бухфирма: он одинаков у всех аккаунтов и
 * меняется централизованно. Плюс удаление режима молча стирало его привязки
 * у всех БП, и смета переставала их подтягивать без единой ошибки на экране.
 */
class TaxSystemsReadOnlyTest extends TestCase
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
            'full_name' => 'Тест Админ', 'position' => 'Админ',
            'email' => 'ts_admin_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
    }

    private function makeTaxSystem(bool $active = true): TaxSystem
    {
        return TaxSystem::create([
            'name'      => 'Тестовый режим ' . uniqid(),
            'code'      => 'test_' . uniqid(),
            'is_active' => $active,
        ]);
    }

    public function test_page_shows_the_list(): void
    {
        $system = $this->makeTaxSystem();

        $this->actingAs($this->admin, 'employee')
            ->get('/settings/tax-systems')
            ->assertOk()
            ->assertSee($system->name)
            ->assertSee('Только просмотр');
    }

    /** Кнопок действий на странице быть не должно. */
    public function test_page_has_no_actions(): void
    {
        $html = $this->actingAs($this->admin, 'employee')
            ->get('/settings/tax-systems')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('openCreate()', $html);
        $this->assertStringNotContainsString('openEdit(', $html);
        $this->assertStringNotContainsString('openDelete(', $html);
        $this->assertStringNotContainsString('Добавить', $html);
    }

    /** Выключенные режимы не показываем: страница отражает то, что реально доступно в карточке клиента. */
    public function test_inactive_systems_are_hidden(): void
    {
        $hidden = $this->makeTaxSystem(active: false);

        $this->actingAs($this->admin, 'employee')
            ->get('/settings/tax-systems')
            ->assertOk()
            ->assertDontSee($hidden->name);
    }

    /**
     * СЭЗ добавляется миграцией один раз и пустой: повторный запуск не плодит
     * двойника, а привязок к БП у нового режима нет.
     */
    public function test_sez_migration_adds_one_empty_system(): void
    {
        $migration = require database_path('migrations/2026_11_13_000001_add_sez_tax_system.php');

        DB::table('tax_systems')->where('code', 'sez')->delete();

        $migration->up();
        $migration->up();

        $rows = DB::table('tax_systems')->where('code', 'sez')->get();

        $this->assertCount(1, $rows);
        $this->assertSame('СЭЗ', $rows[0]->name);
        $this->assertTrue((bool) $rows[0]->is_active);
        $this->assertSame(0, DB::table('service_tax_system')->where('tax_system_id', $rows[0]->id)->count());

        $this->actingAs($this->admin, 'employee')
            ->get('/settings/tax-systems')
            ->assertOk()
            ->assertSee('Свободная экономическая зона');
    }

    /** Режим, заведённый руками под другим кодом, миграция за двойника не принимает. */
    public function test_sez_migration_skips_when_added_by_hand(): void
    {
        $migration = require database_path('migrations/2026_11_13_000001_add_sez_tax_system.php');

        DB::table('tax_systems')->where('code', 'sez')->delete();
        TaxSystem::create(['name' => 'Сэз', 'code' => 'sez_manual_' . uniqid(), 'is_active' => true]);

        $migration->up();

        $this->assertSame(0, DB::table('tax_systems')->where('code', 'sez')->count());
    }

    /**
     * Главное в этой задаче: записи нет даже в обход интерфейса. Роуты удалены,
     * поэтому запрос не доходит до контроллера — 404, а не «нет прав».
     */
    public function test_write_routes_are_gone(): void
    {
        $system = $this->makeTaxSystem();

        // Адрес существует, но только на чтение — отсюда 405, а не 404.
        $this->actingAs($this->admin, 'employee')
            ->postJson('/settings/tax-systems', ['name' => 'Новый', 'code' => 'new_one'])
            ->assertStatus(405);

        $this->actingAs($this->admin, 'employee')
            ->putJson('/settings/tax-systems/' . $system->id, ['name' => 'Другое', 'code' => 'other'])
            ->assertNotFound();

        $this->actingAs($this->admin, 'employee')
            ->deleteJson('/settings/tax-systems/' . $system->id)
            ->assertNotFound();

        $this->assertDatabaseHas('tax_systems', ['id' => $system->id, 'name' => $system->name]);
    }
}
