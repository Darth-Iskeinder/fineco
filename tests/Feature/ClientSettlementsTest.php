<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientInvoice;
use App\Models\Employee;
use App\Models\Module;
use App\Models\Role;
use App\Models\Tenant;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * «Расчёты с клиентами», этап 1: кто видит раздел, список месяца, выставление счёта,
 * номер, подсказка суммы из прошлого счёта и отмена выставления.
 *
 * Сегодня 03.11.2026.
 */
class ClientSettlementsTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Employee $admin;

    private static int $inn = 0;

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

        Carbon::setTestNow('2026-11-03 10:00:00');

        $this->tenant = Tenant::create([
            'name'   => 'Фирма расчётов ' . uniqid(),
            'slug'   => 'settlements-' . uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);
        TenantContext::set($this->tenant);

        $this->admin = $this->employee(Role::ADMIN);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    // ── Доступ ────────────────────────────────────────────────────────────

    public function test_vendor_sees_the_section(): void
    {
        $this->asVendor()->get(route('settlements.index'))
            ->assertOk()
            ->assertSee('Счета за ноябрь 2026')
            ->assertSee('Фирме раздел пока не открыт, его видите только вы.');
    }

    public function test_nobody_but_vendor_sees_it_until_the_firm_flag(): void
    {
        $manager = $this->employee(Role::MANAGER);
        $chief   = $this->employee(Role::HEAD_ACCOUNTANT);
        $chief->modules()->sync([$this->moduleId()]);

        foreach ([$this->admin, $manager, $chief] as $who) {
            $this->actingAs($who->fresh(), 'employee')->get(route('settlements.index'))->assertNotFound();
        }
        $this->actingAs($manager->fresh(), 'employee')->get(route('dashboard.index'))->assertDontSee('Расчёты с клиентами');
    }

    public function test_with_flag_admin_and_manager_see_all_staff_only_by_module(): void
    {
        $this->tenant->setSettlementsEnabled(true);

        $manager    = $this->employee(Role::MANAGER);
        $chief      = $this->employee(Role::HEAD_ACCOUNTANT);
        $accountant = $this->employee(Role::ACCOUNTANT);
        $chief->modules()->sync([$this->moduleId()]);

        $this->actingAs($this->admin->fresh(), 'employee')->get(route('settlements.index'))->assertOk();
        $this->actingAs($manager->fresh(), 'employee')->get(route('settlements.index'))->assertOk()->assertSee('Расчёты с клиентами');
        $this->actingAs($chief->fresh(), 'employee')->get(route('settlements.index'))->assertOk();
        $this->actingAs($accountant->fresh(), 'employee')->get(route('settlements.index'))->assertNotFound();
    }

    public function test_head_accountant_sees_and_bills_only_own_clients(): void
    {
        $this->tenant->setSettlementsEnabled(true);

        $chief = $this->employee(Role::HEAD_ACCOUNTANT);
        $chief->modules()->sync([$this->moduleId()]);
        $own     = $this->client(['name' => 'Свой клиент', 'responsible_employee_id' => $chief->id]);
        $foreign = $this->client(['name' => 'Чужой клиент', 'responsible_employee_id' => $this->admin->id]);

        $this->actingAs($chief->fresh(), 'employee')->get(route('settlements.index'))
            ->assertOk()->assertSee('Свой клиент')->assertDontSee('Чужой клиент')
            ->assertSee('Выставлено 0 из 1 клиента');

        $this->actingAs($chief->fresh(), 'employee')
            ->post(route('settlements.save', $own), ['amount' => '9000', 'action' => 'issue'])
            ->assertSessionHas('success', 'Счёт № 1 выставлен');

        $this->actingAs($chief->fresh(), 'employee')->get(route('settlements.show', $foreign))->assertNotFound();
        $this->actingAs($chief->fresh(), 'employee')
            ->post(route('settlements.save', $foreign), ['amount' => '9000', 'action' => 'issue'])->assertNotFound();
        $this->assertSame(0, ClientInvoice::where('client_id', $foreign->id)->count());
    }

    public function test_module_is_hidden_in_employee_card_until_flag_and_kept_on_save(): void
    {
        $chief = $this->employee(Role::HEAD_ACCOUNTANT);
        $other = Module::where('name', 'clients')->value('id');
        $chief->modules()->sync([$this->moduleId(), $other]);

        $this->actingAs($this->admin, 'employee')->get(route('employees.show', $chief))
            ->assertOk()->assertDontSee('Расчёты с клиентами');

        // Форма не знает про скрытый модуль: сохранение не должно снять выданный доступ.
        $this->actingAs($this->admin, 'employee')
            ->patch(route('employees.update-section', $chief), ['section' => 'access', 'modules' => [$other]]);
        $this->assertTrue($chief->fresh()->modules->contains('id', $this->moduleId()));

        // И выдать скрытый модуль запросом мимо формы нельзя.
        $accountant = $this->employee(Role::ACCOUNTANT);
        $this->actingAs($this->admin, 'employee')
            ->patch(route('employees.update-section', $accountant), ['section' => 'access', 'modules' => [$this->moduleId()]]);
        $this->assertFalse($accountant->fresh()->modules->contains('id', $this->moduleId()));

        $this->tenant->setSettlementsEnabled(true);
        $this->actingAs($this->admin, 'employee')->get(route('employees.show', $chief))
            ->assertOk()->assertSee('Расчёты с клиентами');
    }

    public function test_client_of_another_firm_is_not_found(): void
    {
        $other = Tenant::create(['name' => 'Чужая ' . uniqid(), 'slug' => 'other-' . uniqid(), 'status' => Tenant::STATUS_ACTIVE]);
        $foreign = TenantContext::for($other, fn () => $this->client());

        $this->asVendor()->get(route('settlements.show', $foreign))->assertNotFound();
        $this->asVendor()->post(route('settlements.save', $foreign), ['amount' => '1000', 'action' => 'issue'])->assertNotFound();
    }

    // ── Список месяца ─────────────────────────────────────────────────────

    public function test_list_has_active_clients_and_asks_for_first_amount(): void
    {
        $new      = $this->client(['name' => 'Новый клиент']);
        $inactive = $this->client(['name' => 'Ушедший клиент', 'is_active' => false]);
        $later    = $this->client(['name' => 'С декабря', 'service_start_date' => '2026-12-01']);

        $this->asVendor()->get(route('settlements.index'))
            ->assertOk()
            ->assertSee('Новый клиент')
            ->assertSee('впишите')
            ->assertSee('новый клиент')
            ->assertDontSee('Ушедший клиент')
            ->assertDontSee('С декабря');
    }

    public function test_amount_comes_from_last_issued_invoice_without_extras(): void
    {
        $client = $this->client(['name' => 'Тянь-Шань Трейд']);
        $this->asVendor()->post(route('settlements.save', [$client, 'month' => '2026-10']), [
            'amount' => '48 000',
            'extras' => [['name' => 'Заявление о ввозе', 'amount' => '3000']],
            'action' => 'issue',
        ])->assertRedirect();

        $this->asVendor()->get(route('settlements.index'))
            ->assertSee('48 000')
            ->assertSee('из счёта за октябрь');

        $this->asVendor()->get(route('settlements.show', $client))
            ->assertOk()
            ->assertSee('Счёт за ноябрь 2026')
            ->assertSee('из счёта за октябрь')
            ->assertSee("'48 000'", false)
            ->assertDontSee('Заявление о ввозе');
    }

    // ── Выставление ───────────────────────────────────────────────────────

    public function test_issue_gives_number_date_due_and_total(): void
    {
        $first  = $this->client();
        $second = $this->client();

        $this->asVendor()->post(route('settlements.save', $first), [
            'amount' => '20 000',
            'extras' => [
                ['name' => 'Кадровые документы', 'amount' => '1 000,50'],
                ['name' => '', 'amount' => ''],
            ],
            'action' => 'issue',
        ])->assertRedirect(route('settlements.show', [$first, 'month' => '2026-11']))
            ->assertSessionHas('success', 'Счёт № 1 выставлен');

        $invoice = ClientInvoice::where('client_id', $first->id)->with('extras')->first();
        $this->assertSame(1, $invoice->number);
        $this->assertSame('2026-11-01', $invoice->period->toDateString());
        $this->assertSame('2026-11-03', $invoice->issued_on->toDateString());
        $this->assertSame('2026-11-13', $invoice->due_on->toDateString());
        $this->assertSame('21000.50', $invoice->total);
        $this->assertCount(1, $invoice->extras);
        $this->assertSame($this->admin->id, $invoice->issued_by);

        $this->asVendor()->post(route('settlements.save', $second), ['amount' => '15000', 'action' => 'issue']);
        $this->assertSame(2, ClientInvoice::where('client_id', $second->id)->value('number'));

        $this->asVendor()->get(route('settlements.index'))
            ->assertSee('№ 1 от 03.11.2026')
            ->assertSee('Выставлено 2 из 2 клиентов');
    }

    public function test_cannot_issue_without_amount_or_with_half_filled_extra(): void
    {
        $client = $this->client();

        $this->asVendor()->post(route('settlements.save', $client), ['amount' => '', 'action' => 'issue'])
            ->assertSessionHasErrors('amount');

        $this->asVendor()->post(route('settlements.save', $client), [
            'amount' => '5000', 'extras' => [['name' => 'Без суммы', 'amount' => '']], 'action' => 'issue',
        ])->assertSessionHasErrors('extras');

        $this->assertSame(0, ClientInvoice::where('client_id', $client->id)->count());
    }

    public function test_draft_keeps_amount_without_number(): void
    {
        $client = $this->client();

        $this->asVendor()->post(route('settlements.save', $client), ['amount' => '7000', 'action' => 'save'])
            ->assertSessionHas('success', 'Сохранено');

        $invoice = ClientInvoice::where('client_id', $client->id)->first();
        $this->assertNull($invoice->number);
        $this->assertSame('7000.00', $invoice->amount);
    }

    public function test_edit_after_issue_keeps_number_and_unissue_frees_it(): void
    {
        $client = $this->client();
        $this->asVendor()->post(route('settlements.save', $client), ['amount' => '10000', 'action' => 'issue']);

        $this->asVendor()->post(route('settlements.save', $client), ['amount' => '12000', 'action' => 'save']);
        $invoice = ClientInvoice::where('client_id', $client->id)->first();
        $this->assertSame(1, $invoice->number);
        $this->assertSame('12000.00', $invoice->total);

        $this->asVendor()->post(route('settlements.unissue', $client))->assertRedirect();
        $invoice->refresh();
        $this->assertNull($invoice->number);
        $this->assertNull($invoice->issued_on);

        // Номер освободился и достаётся следующему.
        $this->asVendor()->post(route('settlements.save', $client), ['amount' => '12000', 'action' => 'issue']);
        $this->assertSame(1, $invoice->fresh()->number);
    }

    public function test_future_month_in_address_falls_back_to_current(): void
    {
        $client = $this->client();

        $this->asVendor()->post(route('settlements.save', [$client, 'month' => '2027-01']), ['amount' => '1000', 'action' => 'issue']);

        $this->assertSame('2026-11-01', ClientInvoice::where('client_id', $client->id)->first()->period->toDateString());
    }

    // ── Помощники ─────────────────────────────────────────────────────────

    private function employee(string $role, ?string $login = null): Employee
    {
        return Employee::firstOrCreate(
            ['email' => 'settlements.' . ($login ?? $role . '.' . uniqid()) . '@example.com'],
            [
                'full_name' => 'Сотрудник ' . $role, 'position' => $role, 'password' => 'secret123',
                'role_id' => Role::where('name', $role)->value('id'),
                'status' => Employee::STATUS_ACTIVE,
            ],
        );
    }

    private function moduleId(): int
    {
        return (int) Module::where('name', Module::SETTLEMENTS)->value('id');
    }

    private function asVendor(): static
    {
        return $this->actingAs($this->admin, 'employee')->withSession([
            'vendor.tenant_id'   => $this->tenant->id,
            'vendor.tenant_name' => $this->tenant->name,
            'vendor.last_seen'   => now()->timestamp,
        ]);
    }

    private function client(array $attributes = []): Client
    {
        return Client::create(array_merge([
            'name'      => 'ООО Клиент ' . uniqid(),
            'inn'       => '0311120262' . str_pad((string) (++self::$inn), 4, '0', STR_PAD_LEFT),
            'is_active' => true,
        ], $attributes));
    }
}
