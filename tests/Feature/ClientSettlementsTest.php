<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientInvoice;
use App\Models\ClientOpeningBalance;
use App\Models\ClientPayment;
use App\Models\Employee;
use App\Models\Module;
use App\Models\Role;
use App\Models\Tenant;
use App\Services\Settlements\ClientLedger;
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
            ->assertDontSee('Фирме раздел');

        // На показе у счёта две кнопки: «К списку» и «Выставить счёт», без черновика.
        $this->asVendor()->get(route('settlements.show', $this->client()))
            ->assertOk()
            ->assertSee('Выставить счёт')
            ->assertDontSee('Сохранить черновик');
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
            ->assertSee('0 счетов из 1');

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
            ->assertSee('2 счёта из 2');
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

    // ── Оплаты ────────────────────────────────────────────────────────────

    /** Пример Искендера: 48 000 в месяц, заплатил 30 000, потом 50 000. */
    public function test_payments_close_oldest_invoices_first(): void
    {
        $client = $this->client(['name' => 'Тянь-Шань Трейд']);
        $this->issue($client, '48 000', '2026-10');
        $this->pay($client, '30 000', '2026-10-15')->assertSessionHas('success', 'Оплата принята: 30 000 сом');

        $balance = app(ClientLedger::class)->forClient($client);
        $october = $this->invoiceFor($client, '2026-10');
        $this->assertSame(18000.0, $balance->debt());
        $this->assertSame(ClientInvoice::STATUS_PARTIAL, $balance->statusFor($october));

        $this->issue($client, '48 000');
        $this->pay($client, '50 000', '2026-11-03');

        $balance  = app(ClientLedger::class)->forClient($client);
        $november = $this->invoiceFor($client, '2026-11');
        $this->assertSame(16000.0, $balance->debt());
        $this->assertSame(0.0, $balance->advance());
        $this->assertSame(48000.0, $balance->paidFor($october->fresh()));
        $this->assertSame(32000.0, $balance->paidFor($november));
        $this->assertSame(ClientInvoice::STATUS_PAID, $balance->statusFor($october->fresh()));
        $this->assertSame(ClientInvoice::STATUS_PARTIAL, $balance->statusFor($november));

        $this->asVendor()->get(route('settlements.show', $client))
            ->assertOk()
            ->assertSee('Клиент должен всего')
            ->assertSee('16 000')
            ->assertSee('Частично')
            ->assertSee('Оплачен');
    }

    public function test_overpayment_becomes_advance_and_covers_next_invoice(): void
    {
        $client = $this->client();
        $this->issue($client, '10 000', '2026-10');
        $this->pay($client, '14 000', '2026-10-20');

        $balance = app(ClientLedger::class)->forClient($client);
        $this->assertSame(0.0, $balance->debt());
        $this->assertSame(4000.0, $balance->advance());

        $this->issue($client, '10 000');
        $balance = app(ClientLedger::class)->forClient($client);
        $this->assertSame(4000.0, $balance->paidFor($this->invoiceFor($client, '2026-11')));
        $this->assertSame(6000.0, $balance->debt());
    }

    public function test_invoice_with_money_cannot_be_unissued_but_can_be_edited(): void
    {
        $client = $this->client();
        $this->issue($client, '10 000');
        $this->pay($client, '3 000', '2026-11-02');

        $this->asVendor()->get(route('settlements.show', $client))->assertDontSee('Отменить выставление');
        $this->asVendor()->post(route('settlements.unissue', $client))->assertSessionHasErrors('invoice');
        $this->assertNotNull($this->invoiceFor($client, '2026-11')->number);

        // Уменьшили сумму ниже оплаченного: лишнее стало авансом.
        $this->asVendor()->post(route('settlements.save', $client), ['amount' => '2 000', 'action' => 'save']);
        $this->assertSame(1000.0, app(ClientLedger::class)->forClient($client)->advance());
    }

    public function test_deleting_payment_respreads_the_rest(): void
    {
        $client = $this->client();
        $this->issue($client, '10 000');
        $this->pay($client, '10 000', '2026-11-02');
        $payment = ClientPayment::where('client_id', $client->id)->first();

        $this->asVendor()->delete(route('settlements.payments.destroy', [$client, $payment]))
            ->assertSessionHas('success', 'Оплата удалена');

        $this->assertSame(10000.0, app(ClientLedger::class)->forClient($client)->debt());
    }

    public function test_payment_of_another_client_cannot_be_deleted_through_this_one(): void
    {
        $one = $this->client();
        $two = $this->client();
        $this->pay($two, '500', '2026-11-02');
        $payment = ClientPayment::where('client_id', $two->id)->first();

        $this->asVendor()->delete(route('settlements.payments.destroy', [$one, $payment]))->assertNotFound();
        $this->assertNotNull($payment->fresh());
    }

    public function test_payment_validation(): void
    {
        $client = $this->client();

        $this->pay($client, '', '2026-11-02')->assertSessionHasErrors('payment_amount');
        $this->pay($client, '0', '2026-11-02')->assertSessionHasErrors('payment_amount');
        $this->pay($client, '100', '2026-11-04')->assertSessionHasErrors('paid_on');
        $this->asVendor()->post(route('settlements.payments.store', $client), [
            'payment_amount' => '100', 'paid_on' => '2026-11-02', 'method' => 'card',
        ])->assertSessionHasErrors('method');

        $this->assertSame(0, ClientPayment::where('client_id', $client->id)->count());
    }

    public function test_head_accountant_cannot_take_payment_for_foreign_client(): void
    {
        $this->tenant->setSettlementsEnabled(true);
        $chief = $this->employee(Role::HEAD_ACCOUNTANT);
        $chief->modules()->sync([$this->moduleId()]);
        $foreign = $this->client(['responsible_employee_id' => $this->admin->id]);

        $this->actingAs($chief->fresh(), 'employee')->post(route('settlements.payments.store', $foreign), [
            'payment_amount' => '100', 'paid_on' => '2026-11-02', 'method' => ClientPayment::BANK,
        ])->assertNotFound();
    }

    public function test_cards_count_money_by_payment_date_debt_and_overdue(): void
    {
        $active = $this->client(['name' => 'Действующий']);
        $gone   = $this->client(['name' => 'Ушедший']);

        // Ушедший оставил долг за сентябрь: срок оплаты 11.09, к 03.11 просрочка больше 30 дней.
        Carbon::setTestNow('2026-09-01 10:00:00');
        $this->issue($gone, '7 000', '2026-09');
        Carbon::setTestNow('2026-11-03 10:00:00');
        $gone->update(['is_active' => false]);

        $this->issue($active, '20 000');
        $this->pay($active, '5 000', '2026-10-30');   // октябрьские деньги в ноябрь не идут
        $this->pay($active, '10 000', '2026-11-02');

        $this->asVendor()->get(route('settlements.index'))
            ->assertOk()
            ->assertDontSee('Ушедший')
            ->assertSeeInOrder(['Выставлено', '20 000', '1 счёт из 1'])
            ->assertSeeInOrder(['Пришло денег', '10 000', '50% от выставленного'])
            ->assertSeeInOrder(['Дебиторка', '12 000'])
            ->assertSeeInOrder(['Просрочено больше 30 дней', '7 000', '1 клиент'])
            ->assertSeeInOrder(['Не выставлено', '0']);
    }

    // ── Долг на начало ────────────────────────────────────────────────────

    public function test_opening_debt_is_paid_first_and_counts_in_debt(): void
    {
        $client = $this->client(['name' => 'С долгом до Kubik']);
        $this->opening($client, 'debt', '25 000', '2026-11-01')->assertSessionHas('success', 'Долг на начало сохранён');
        $this->issue($client, '48 000');

        $balance = app(ClientLedger::class)->forClient($client);
        $this->assertSame(73000.0, $balance->debt());

        // 30 000: 25 000 закрывают долг на начало, 5 000 уходят в ноябрь.
        $this->pay($client, '30 000', '2026-11-02');
        $balance = app(ClientLedger::class)->forClient($client);
        $this->assertSame(0.0, $balance->openingDebt());
        $this->assertSame(5000.0, $balance->paidFor($this->invoiceFor($client, '2026-11')));
        $this->assertSame(43000.0, $balance->debt());

        $this->asVendor()->get(route('settlements.show', $client))
            ->assertOk()
            ->assertSee('Долг на начало')
            ->assertSee('на 01.11.2026');
        $this->asVendor()->get(route('settlements.index'))->assertSeeInOrder(['Дебиторка', '43 000']);
    }

    public function test_opening_debt_alone_counts_without_invoices(): void
    {
        $client = $this->client();
        $this->opening($client, 'debt', '12 000', '2026-09-01');

        $balance = app(ClientLedger::class)->forClient($client);
        $this->assertSame(12000.0, $balance->debt());
        // С 01.09 прошло больше 30 дней.
        $this->assertSame(12000.0, $balance->overdue());

        $this->asVendor()->get(route('settlements.show', $client))->assertSee('Не оплачен');
    }

    public function test_opening_advance_goes_into_first_invoice(): void
    {
        $client = $this->client();
        $this->opening($client, 'advance', '3 000', '2026-11-01')->assertSessionHas('success', 'Аванс на начало сохранён');
        $this->assertSame(3000.0, app(ClientLedger::class)->forClient($client)->advance());

        $this->issue($client, '10 000');
        $balance = app(ClientLedger::class)->forClient($client);
        $this->assertSame(3000.0, $balance->paidFor($this->invoiceFor($client, '2026-11')));
        $this->assertSame(7000.0, $balance->debt());
    }

    public function test_opening_can_be_changed_and_removed(): void
    {
        $client = $this->client();
        $this->opening($client, 'debt', '5 000', '2026-11-01');
        $this->opening($client, 'debt', '6 000', '2026-10-01');
        $this->assertSame(1, ClientOpeningBalance::where('client_id', $client->id)->count());
        $this->assertSame(6000.0, app(ClientLedger::class)->forClient($client)->debt());

        $this->opening($client, 'debt', '', '2026-10-01')->assertSessionHas('success', 'Долг на начало убран');
        $this->assertSame(0, ClientOpeningBalance::where('client_id', $client->id)->count());
    }

    public function test_opening_validation(): void
    {
        $client = $this->client();

        $this->opening($client, 'debt', '1 000', '2026-11-04')->assertSessionHasErrors('opening_as_of');
        $this->opening($client, 'loan', '1 000', '2026-11-01')->assertSessionHasErrors('opening_kind');
        $this->opening($client, 'debt', 'много', '2026-11-01')->assertSessionHasErrors('opening_amount');
        $this->assertSame(0, ClientOpeningBalance::where('client_id', $client->id)->count());
    }

    // ── Выставить всем (этап 2) ───────────────────────────────────────────

    public function test_bulk_issues_checked_clients_in_alphabetical_order(): void
    {
        $b = $this->client(['name' => 'Бета']);
        $a = $this->client(['name' => 'Альфа']);
        $c = $this->client(['name' => 'Вега']);
        $d = $this->client(['name' => 'Гамма']);

        $this->asVendor()->post(route('settlements.bulk'), ['bulk' => [
            $b->id => ['on' => '1', 'amount' => '20 000'],
            $a->id => ['on' => '1', 'amount' => '15000,50'],
            $c->id => ['amount' => '9000'],            // галочку сняли
            $d->id => ['on' => '1', 'amount' => ''],   // суммы нет
        ]])->assertRedirect(route('settlements.index'))
            ->assertSessionHas('success', 'Выставлено 2 счёта, № 1–2');

        $this->assertSame(1, $this->invoiceFor($a, '2026-11')->number);
        $this->assertSame('15000.50', $this->invoiceFor($a, '2026-11')->total);
        $this->assertSame(2, $this->invoiceFor($b, '2026-11')->number);
        $this->assertSame('2026-11-13', $this->invoiceFor($b, '2026-11')->due_on->toDateString());
        $this->assertSame(0, ClientInvoice::whereIn('client_id', [$c->id, $d->id])->count());
    }

    public function test_bulk_keeps_extras_of_a_draft(): void
    {
        $client = $this->client();
        $this->asVendor()->post(route('settlements.save', $client), [
            'amount' => '10000', 'extras' => [['name' => 'Кадровые документы', 'amount' => '1500']], 'action' => 'save',
        ])->assertSessionHasNoErrors();

        $this->asVendor()->get(route('settlements.index'))->assertViewHas('bulkLines', fn ($lines) => $lines[0]['extra'] === 1500.0
            && $lines[0]['amount'] === '10 000' && $lines[0]['hint'] === 'из черновика' && $lines[0]['on'] === true);

        $this->asVendor()->post(route('settlements.bulk'), ['bulk' => [$client->id => ['on' => '1', 'amount' => '12000']]])
            ->assertSessionHasNoErrors();

        $invoice = $this->invoiceFor($client, '2026-11');
        $this->assertSame('12000.00', $invoice->amount);
        $this->assertSame('13500.00', $invoice->total);
        $this->assertCount(1, $invoice->extras);
    }

    public function test_bulk_lines_come_from_last_invoice_and_new_client_is_unchecked(): void
    {
        $old = $this->client(['name' => 'Альфа']);
        $new = $this->client(['name' => 'Бета']);
        $this->issue($old, '48000', '2026-10');

        $this->asVendor()->get(route('settlements.index'))
            ->assertSee('Выставить за ноябрь: 2')
            ->assertViewHas('bulkLines', fn ($lines) => count($lines) === 2
                && $lines[0]['id'] === $old->id && $lines[0]['amount'] === '48 000' && $lines[0]['was'] === 48000.0 && $lines[0]['on'] === true
                && $lines[1]['id'] === $new->id && $lines[1]['amount'] === '' && $lines[1]['was'] === null && $lines[1]['on'] === false);

        $this->issue($old, '48000');
        $this->issue($new, '5000');

        $this->asVendor()->get(route('settlements.index'))
            ->assertDontSee('Выставить за ноябрь:')
            ->assertViewHas('bulkLines', []);
    }

    public function test_bulk_does_not_issue_twice(): void
    {
        $first  = $this->client(['name' => 'Альфа']);
        $second = $this->client(['name' => 'Бета']);
        $this->issue($first, '10000');

        // Окно открыли до того, как Альфе выставили счёт, и нажали ещё раз.
        $this->asVendor()->post(route('settlements.bulk'), ['bulk' => [
            $first->id  => ['on' => '1', 'amount' => '99999'],
            $second->id => ['on' => '1', 'amount' => '8000'],
        ]])->assertSessionHas('success', 'Выставлено 1 счёт, № 2. Пропущено: 1, им счёт уже выставили, пока окно было открыто');

        $this->assertSame(1, $this->invoiceFor($first, '2026-11')->number);
        $this->assertSame('10000.00', $this->invoiceFor($first, '2026-11')->amount);

        $this->asVendor()->post(route('settlements.bulk'), ['bulk' => [$second->id => ['on' => '1', 'amount' => '8000']]])
            ->assertSessionHasErrors(['bulk' => 'Этим клиентам счёт уже выставили, пока окно было открыто']);
        $this->assertSame(2, (int) ClientInvoice::max('number'));
    }

    public function test_bulk_with_bad_amount_issues_nothing(): void
    {
        $good = $this->client(['name' => 'Альфа']);
        $bad  = $this->client(['name' => 'Бета']);

        $this->asVendor()->post(route('settlements.bulk'), ['bulk' => [
            $good->id => ['on' => '1', 'amount' => '10000'],
            $bad->id  => ['on' => '1', 'amount' => 'двадцать'],
        ]])->assertSessionHasErrors(['bulk' => 'Бета: сумма записана непонятно. Ничего не выставлено.']);

        $this->assertSame(0, ClientInvoice::whereIn('client_id', [$good->id, $bad->id])->count());

        $this->asVendor()->post(route('settlements.bulk'), ['bulk' => [$good->id => ['amount' => '10000']]])
            ->assertSessionHasErrors(['bulk' => 'Не отмечено ни одного клиента с суммой']);
    }

    public function test_bulk_ignores_foreign_and_unknown_clients(): void
    {
        $this->tenant->setSettlementsEnabled(true);

        $chief = $this->employee(Role::HEAD_ACCOUNTANT);
        $chief->modules()->sync([$this->moduleId()]);
        $own      = $this->client(['name' => 'Свой', 'responsible_employee_id' => $chief->id]);
        $foreign  = $this->client(['name' => 'Чужой', 'responsible_employee_id' => $this->admin->id]);
        $inactive = $this->client(['name' => 'Ушедший', 'responsible_employee_id' => $chief->id, 'is_active' => false]);

        $this->actingAs($chief->fresh(), 'employee')->post(route('settlements.bulk'), ['bulk' => [
            $own->id      => ['on' => '1', 'amount' => '5000'],
            $foreign->id  => ['on' => '1', 'amount' => '5000'],
            $inactive->id => ['on' => '1', 'amount' => '5000'],
            999999999     => ['on' => '1', 'amount' => '5000'],
        ]])->assertSessionHas('success', 'Выставлено 1 счёт, № 1');

        $this->assertSame(1, ClientInvoice::count());
        $this->assertSame($own->id, ClientInvoice::first()->client_id);

        $accountant = $this->employee(Role::ACCOUNTANT);
        $this->actingAs($accountant->fresh(), 'employee')->post(route('settlements.bulk'), ['bulk' => [
            $foreign->id => ['on' => '1', 'amount' => '5000'],
        ]])->assertNotFound();
    }

    public function test_list_has_filters_and_clickable_cards(): void
    {
        $client = $this->client(['name' => 'Альфа']);
        $this->opening($client, 'debt', '10000', '2026-09-01')->assertSessionHasNoErrors();

        $this->asVendor()->get(route('settlements.index'))
            ->assertOk()
            ->assertSee('data-filter="no"', false)
            ->assertSee('Просрочены 1')
            ->assertSee('Не выставлены 1')
            ->assertSee('показать, кому');
    }

    public function test_history_shows_missed_month(): void
    {
        $client = $this->client();
        $this->issue($client, '10000', '2026-08');
        $this->issue($client, '10000', '2026-10');

        $this->asVendor()->get(route('settlements.show', $client))
            ->assertOk()
            ->assertSee('data-history="2026-09" data-missed', false)
            ->assertSee('Счёт за этот месяц не выставлен')
            ->assertDontSee('data-history="2026-11" data-missed', false)
            ->assertDontSee('data-history="2026-07"', false);
    }

    private function opening(Client $client, string $kind, string $amount, string $date)
    {
        return $this->asVendor()->post(route('settlements.opening', $client), [
            'opening_kind' => $kind, 'opening_amount' => $amount, 'opening_as_of' => $date,
        ]);
    }

    private function issue(Client $client, string $amount, string $month = '2026-11'): void
    {
        $this->asVendor()->post(route('settlements.save', [$client, 'month' => $month]), ['amount' => $amount, 'action' => 'issue'])
            ->assertSessionHasNoErrors();
    }

    private function pay(Client $client, string $amount, string $date)
    {
        return $this->asVendor()->post(route('settlements.payments.store', $client), [
            'payment_amount' => $amount, 'paid_on' => $date, 'method' => ClientPayment::BANK,
        ]);
    }

    private function invoiceFor(Client $client, string $month): ClientInvoice
    {
        return ClientInvoice::where('client_id', $client->id)->where('period', $month . '-01')->firstOrFail();
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
