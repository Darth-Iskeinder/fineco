<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Module;
use App\Models\Role;
use App\Models\Service;
use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Перегородка между фирмами: сотрудник видит только данные своего аккаунта.
 *
 * Это первый шаг разделения — фильтр работает внутри приложения. Консоль и
 * ночной воркер пока идут без фильтра намеренно: если включить строгую проверку
 * до того, как научим их выбирать фирму, генерация задач упадёт молча, и
 * клиенты пропустят сдачу отчётов.
 */
class TenantIsolationTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $mine;
    private Tenant $theirs;
    private Employee $myEmployee;
    private Client $myClient;
    private Client $theirClient;

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

        TenantContext::forget();

        $this->mine   = Tenant::orderBy('id')->first();
        $this->theirs = Tenant::create([
            'name'   => 'Чужая фирма ' . uniqid(),
            'slug'   => 'other-' . uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $role   = Role::firstOrCreate(['name' => Role::ADMIN], ['display_name' => 'Администратор']);
        $module = Module::firstOrCreate(['name' => 'clients'], ['display_name' => 'Клиенты', 'is_active' => true]);

        $this->myEmployee  = $this->makeEmployee($this->mine, $role);
        $this->myClient    = $this->makeClient($this->mine, 'ОсОО Мой клиент');
        $this->theirClient = $this->makeClient($this->theirs, 'ОсОО Чужой клиент');

        $module->id; // модуль нужен только чтобы доступ к разделу был у обоих
    }

    private function makeEmployee(Tenant $tenant, Role $role): Employee
    {
        $employee = Employee::create([
            'full_name' => 'Сотрудник ' . uniqid(), 'position' => 'Админ',
            'email' => 'iso_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);

        DB::table('employees')->where('id', $employee->id)->update(['tenant_id' => $tenant->id]);

        return $employee->refresh();
    }

    private function makeClient(Tenant $tenant, string $name): Client
    {
        $client = Client::create([
            'name' => $name,
            'inn'  => strtoupper(substr(md5(uniqid()), 0, 12)),
        ]);

        DB::table('clients')->where('id', $client->id)->update(['tenant_id' => $tenant->id]);

        return $client->refresh();
    }

    /** Главное: чужой клиент не показывается в списке. */
    public function test_client_list_shows_only_own_clients(): void
    {
        $clients = $this->actingAs($this->myEmployee, 'employee')
            ->get('/clients')
            ->assertOk()
            ->viewData('clients');

        $ids = $clients->pluck('id')->all();

        $this->assertContains($this->myClient->id, $ids, 'Свой клиент пропал из списка');
        $this->assertNotContains($this->theirClient->id, $ids, 'В списке оказался чужой клиент');
    }

    /** Прямая ссылка на чужого клиента не открывается — 404, а не «нет прав». */
    public function test_other_tenant_client_is_not_reachable_by_url(): void
    {
        $this->actingAs($this->myEmployee, 'employee')
            ->get('/clients/' . $this->theirClient->id)
            ->assertNotFound();
    }

    /** Свой открывается — проверка на случай, если фильтр отрезал лишнее. */
    public function test_own_client_still_opens(): void
    {
        $this->actingAs($this->myEmployee, 'employee')
            ->get('/clients/' . $this->myClient->id)
            ->assertOk();
    }

    /** Поиск тоже фильтруется: обойти список через него нельзя. */
    public function test_search_does_not_leak_other_tenants(): void
    {
        $found = $this->actingAs($this->myEmployee, 'employee')
            ->getJson('/clients/search?q=' . urlencode('ОсОО'))
            ->assertOk()
            ->json();

        $ids = array_column($found, 'id');

        $this->assertNotContains($this->theirClient->id, $ids, 'Поиск выдал чужого клиента');
    }

    /** Новая запись получает фирму того, кто её создал. */
    public function test_created_record_belongs_to_the_authors_tenant(): void
    {
        $this->actingAs($this->myEmployee, 'employee')->post('/clients', [
            'name' => 'ОсОО Новый от меня',
            'inn'  => strtoupper(substr(md5(uniqid()), 0, 12)),
        ]);

        $created = Client::acrossTenants()->where('name', 'ОсОО Новый от меня')->first();

        $this->assertNotNull($created);
        $this->assertSame($this->mine->id, (int) $created->tenant_id);
    }

    /** Справочники тоже разделены — иначе чужие БП попали бы в смету. */
    public function test_dictionaries_are_separated(): void
    {
        $theirService = Service::create([
            'name' => 'Чужой БП ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_day' => [5], 'is_active' => true,
        ]);
        DB::table('services')->where('id', $theirService->id)->update(['tenant_id' => $this->theirs->id]);

        TenantContext::set($this->mine);

        $this->assertNull(
            Service::find($theirService->id),
            'Чужой бизнес-процесс виден из другой фирмы',
        );

        TenantContext::forget();
    }

    /** Осознанный выход за пределы фирмы возможен, но только явным вызовом. */
    public function test_across_tenants_is_an_explicit_opt_out(): void
    {
        TenantContext::set($this->mine);

        $this->assertNull(Client::find($this->theirClient->id));
        $this->assertNotNull(Client::acrossTenants()->find($this->theirClient->id));

        TenantContext::forget();
    }

    /**
     * Ночной воркер обязан ходить по фирмам отдельно. Иначе напоминания второй
     * фирмы создались бы с фирмой по умолчанию — достались бы первой, а вторая
     * осталась бы без задач и пропустила сроки. Молча, без ошибки на экране.
     */
    public function test_worker_walks_tenants_one_by_one(): void
    {
        $this->artisan('tasks:generate', ['--date' => '2026-07-31'])
            ->expectsOutputToContain('Аккаунт:')
            ->assertSuccessful();

        $misplaced = DB::table('task_reminders as r')
            ->join('clients as c', 'r.client_id', '=', 'c.id')
            ->whereColumn('r.tenant_id', '!=', 'c.tenant_id')
            ->count();

        $this->assertSame(0, $misplaced, 'Напоминание оказалось в чужой фирме');
    }

    /** Аккаунт-образец воркер обходит стороной: клиентов и задач в нём нет. */
    public function test_worker_skips_the_template_account(): void
    {
        $template = \App\Models\Tenant::template()->first();

        $this->artisan('tasks:generate', ['--date' => '2026-07-31'])->assertSuccessful();

        $this->assertSame(
            0,
            DB::table('task_reminders')->where('tenant_id', $template->id)->count(),
            'Воркер создал напоминания в аккаунте-образце',
        );
    }

    /** Без контекста фильтра нет — на этом шаге так и задумано (консоль, крон). */
    public function test_console_still_sees_everything_for_now(): void
    {
        TenantContext::forget();

        $this->assertNotNull(Client::find($this->myClient->id));
        $this->assertNotNull(Client::find($this->theirClient->id));
    }
}
