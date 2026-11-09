<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\BuhAdhocTask;
use App\Models\ClientInvoice;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Tenant;
use App\Services\Settlements\ClientPayback;
use App\Support\TenantContext;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Окупаемость на странице «Руководитель» (06.11.2026): деньги из счёта месяца, часы из
 * разреза дашборда, «мало данных» ниже половины задач с таймером. 09.11.2026 долг из блока
 * убрали, а в текущем месяце блок показывает прошлый, закрытый.
 *
 * Сегодня 06.11.2026, смотрим октябрь.
 */
class ClientPaybackTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

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

        Carbon::setTestNow('2026-11-06 10:00:00');

        $this->tenant = Tenant::create([
            'name'   => 'Фирма окупаемости ' . uniqid(),
            'slug'   => 'payback-' . uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);
        TenantContext::set($this->tenant);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_rate_average_tiers_and_groups(): void
    {
        $cheap  = $this->client('Дешёвый');
        $fair   = $this->client('Средний');
        $rich   = $this->client('Выгодный');
        $sloppy = $this->client('Без таймеров');
        $free   = $this->client('Без счёта');

        $this->invoice($cheap, 10000);   // 10 ч → 1 000 сом/ч
        $this->invoice($fair, 20000);    // 10 ч → 2 000
        $this->invoice($rich, 60000);    // 10 ч → 6 000
        $this->invoice($sloppy, 30000);  // таймер у 1 из 4 начатых

        $result = (new ClientPayback())->build([
            $cheap->id  => $this->row('Дешёвый', 10 * 3600, total: 5, pending: 1, withTime: 4),
            $fair->id   => $this->row('Средний', 10 * 3600, total: 4, pending: 0, withTime: 2),
            $rich->id   => $this->row('Выгодный', 10 * 3600, total: 3, pending: 0, withTime: 3),
            $sloppy->id => $this->row('Без таймеров', 2 * 3600, total: 4, pending: 0, withTime: 1),
            $free->id   => $this->row('Без счёта', 3 * 3600, total: 2, pending: 0, withTime: 2),
            0           => $this->row('Без компании', 3600, total: 1, pending: 0, withTime: 1),
        ], CarbonImmutable::create(2026, 10, 1));

        // Средний только по клиентам с данными: 90 000 / 30 ч
        $this->assertSame(3000, $result['summary']['avg']);
        $this->assertSame(['Дешёвый', 'Средний', 'Выгодный'], array_column($result['rated'], 'name'));
        $this->assertSame([1000, 2000, 6000], array_column($result['rated'], 'rate'));
        $this->assertSame(['worst', 'below', 'above'], array_column($result['rated'], 'tier'));

        $this->assertSame(['Без таймеров'], array_column($result['weak'], 'name'));
        $this->assertSame(25, $result['weak'][0]['share']);
        $this->assertNull($result['weak'][0]['rate']);

        // «Без компании» в таблицу не идёт
        $this->assertSame(['Без счёта'], array_column($result['unbilled'], 'name'));

        $this->assertSame(120000.0, $result['summary']['billed']);
        $this->assertSame(4, $result['summary']['billedCount']);
        // Начатых 4+4+3+4+2 = 17, с таймером 4+2+3+1+2 = 12
        $this->assertSame(71, $result['summary']['timedShare']);
    }

    public function test_unbilled_group_needs_hours_and_debt_is_not_shown(): void
    {
        $worked  = $this->client('Работали без счёта');
        $debtor  = $this->client('Только старый долг');

        // Старый неоплаченный счёт: долг есть, но в октябре ни счёта, ни часов
        $this->invoice($debtor, 15000, '2026-08-01', '2026-08-01');

        $result = (new ClientPayback())->build([
            $worked->id => $this->row('Работали без счёта', 2 * 3600, total: 1, pending: 0, withTime: 1),
            $debtor->id => $this->row('Только старый долг', 0, total: 0, pending: 0, withTime: 0),
        ], CarbonImmutable::create(2026, 10, 1));

        $this->assertSame(['Работали без счёта'], array_column($result['unbilled'], 'name'));
        $this->assertSame([], $result['rated']);
        $this->assertSame([], $result['weak']);
        $this->assertArrayNotHasKey('debt', $result['summary']);
    }

    public function test_current_month_shows_previous_closed_month(): void
    {
        $this->tenant->setSettlementsEnabled(true);
        $this->tenant->save();

        $client = $this->client('Октябрьский');
        $this->invoice($client, 20000);
        $this->invoice($client, 20000, '2026-11-01', '2026-11-01');

        $manager = $this->manager();

        foreach ([[10, 4 * 3600], [11, 3600]] as [$month, $seconds]) {
            BuhAdhocTask::create([
                'employee_id' => $manager->id, 'client_id' => $client->id, 'name' => 'Работа ' . $month,
                'cost' => 0, 'year' => 2026, 'month' => $month, 'due_day' => 5,
                'status' => 'completed', 'started_at' => '2026-' . $month . '-02 10:00:00',
                'completed_at' => '2026-' . $month . '-03 10:00:00', 'paused_seconds' => $seconds,
            ]);
        }

        // Ноябрь (текущий): блок про октябрь, 20 000 за 4 часа
        $payback = $this->actingAs($manager, 'employee')->get(route('dashboard.index'))
            ->assertOk()
            ->assertSee('Текущий месяц ещё идёт')
            ->viewData('payback');
        $this->assertSame('Октябрь 2026', $payback['monthLabel']);
        $this->assertTrue($payback['shifted']);
        $this->assertSame([5000], array_column($payback['rated'], 'rate'));

        // Октябрь, выбранный руками: те же числа, без пометки
        $payback = $this->actingAs($manager, 'employee')->get(route('dashboard.index', ['year' => 2026, 'month' => 10]))
            ->assertOk()
            ->assertDontSee('Текущий месяц ещё идёт')
            ->viewData('payback');
        $this->assertFalse($payback['shifted']);
        $this->assertSame([5000], array_column($payback['rated'], 'rate'));
    }

    public function test_dashboard_shows_block_only_with_settlements(): void
    {
        $manager = $this->manager();

        $this->actingAs($manager, 'employee')->get(route('dashboard.index'))
            ->assertOk()
            ->assertDontSee('Окупаемость')
            ->assertDontSee('Куда уходит время')
            ->assertDontSee('Дисциплина по месяцам');

        $this->tenant->setSettlementsEnabled(true);
        $this->tenant->save();

        $this->actingAs($manager, 'employee')->get(route('dashboard.index'))
            ->assertOk()
            ->assertSee('Окупаемость')
            ->assertSee('Окупаемость');
    }

    private function manager(): Employee
    {
        return Employee::create([
            'full_name' => 'Руководитель окупаемости', 'position' => 'Директор',
            'email' => 'payback.' . uniqid() . '@example.com', 'password' => 'secret123',
            'role_id' => Role::where('name', Role::MANAGER)->value('id'),
            'status' => Employee::STATUS_ACTIVE,
        ]);
    }

    private function row(string $name, int $elapsed, int $total, int $pending, int $withTime): array
    {
        return ['name' => $name, 'elapsed' => $elapsed, 'total' => $total, 'pending' => $pending, 'with_time' => $withTime];
    }

    private function invoice(Client $client, float $total, string $period = '2026-10-01', string $issued = '2026-10-01'): ClientInvoice
    {
        static $number = 0;

        return ClientInvoice::create([
            'client_id' => $client->id,
            'period'    => $period,
            'number'    => ++$number,
            'issued_on' => $issued,
            'due_on'    => CarbonImmutable::parse($issued)->addDays(ClientInvoice::DUE_DAYS)->toDateString(),
            'amount'    => $total,
            'total'     => $total,
        ]);
    }

    private function client(string $name, array $attributes = []): Client
    {
        return Client::create(array_merge([
            'name'      => $name,
            'inn'       => '0611120262' . str_pad((string) (++self::$inn), 4, '0', STR_PAD_LEFT),
            'is_active' => true,
        ], $attributes));
    }
}
