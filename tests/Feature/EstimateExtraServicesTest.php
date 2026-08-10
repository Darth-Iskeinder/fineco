<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\Module;
use App\Models\Periodicity;
use App\Models\Role;
use App\Models\Service;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

/**
 * Дополнительная услуга с типом «Постоянная» — такая же строка сметы, как тарифный БП:
 * у неё виден срок (расписание её БП с учётом индивидуального расписания клиента),
 * назначается исполнитель, и она остаётся в блоке доп. услуг после сохранения.
 *
 * Раньше тарифной считалась любая строка «recurring + service_id», поэтому доп. постоянная
 * из каталога после перезагрузки либо всплывала в блоке тарифа, либо пропадала с экрана
 * (оставаясь в базе), а следующим сохранением удалялась вместе с логами задач.
 *
 * По боевому mysql в транзакции: страница сметы поднимает слишком много связей для sqlite.
 */
class EstimateExtraServicesTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $admin;
    private Employee $accountant;

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

        Periodicity::firstOrCreate(['name' => 'Ежемесячно'], ['kind' => 'monthly']);

        $adminRole = Role::firstOrCreate(['name' => Role::ADMIN], ['display_name' => 'Администратор']);
        $accRole   = Role::firstOrCreate(['name' => Role::ACCOUNTANT], ['display_name' => 'Бухгалтер']);
        $module    = Module::firstOrCreate(
            ['name' => 'clients'],
            ['display_name' => 'Клиенты', 'is_active' => true],
        );

        $this->admin = Employee::create([
            'full_name' => 'Тест Админ', 'position' => 'Админ',
            'email' => 'extra_admin_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $adminRole->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
        $this->admin->modules()->attach($module->id);

        $this->accountant = Employee::create([
            'full_name' => 'Тест Бухгалтер', 'position' => 'Бухгалтер',
            'email' => 'extra_acc_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $accRole->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
    }

    private function client(): Client
    {
        return Client::create([
            'name' => 'ТОО Доп ' . uniqid(),
            'inn' => strtoupper(substr(md5(uniqid()), 0, 12)),
            'responsible_employee_id' => $this->admin->id,
        ]);
    }

    /** БП, который сам клиенту не подтягивается (без РН, не обязательный) — только как доп. услуга. */
    private function catalogService(): Service
    {
        return Service::create([
            'name' => 'Доп услуга ' . uniqid(),
            'periodicity' => 'Ежемесячно',
            'start_day' => [15],
            'due_day' => 15,
            'is_active' => true,
        ]);
    }

    /** Сохранить смету с одной доп. услугой и вернуть данные страницы после перезагрузки. */
    private function saveExtraAndReload(Client $client, array $extra): array
    {
        $this->actingAs($this->admin, 'employee')
            ->postJson(route('clients.estimate.save', $client), [
                'tariff_bps' => [],
                'extras'     => [$extra],
            ])
            ->assertOk();

        $response = $this->actingAs($this->admin, 'employee')
            ->get(route('clients.estimate.edit', $client))
            ->assertOk()
            // Блок срока и исполнителя подключён к строке доп. услуги, как и к тарифной
            ->assertSee('openSchedule(extra)', false)
            ->assertSee('openSchedule(bp)', false);

        return [
            'extras'    => $response->viewData('extras'),
            'tariffBPs' => $response->viewData('tariffBPs'),
        ];
    }

    public function test_recurring_catalog_extra_stays_in_extras_with_deadline(): void
    {
        $client  = $this->client();
        $service = $this->catalogService();

        $data = $this->saveExtraAndReload($client, [
            'service_id' => $service->id,
            'type'       => 'recurring',
            'name'       => $service->name,
            'cost'       => 500,
            'quantity'   => 1,
        ]);

        $this->assertCount(1, $data['extras'], 'доп. постоянная должна остаться в блоке доп. услуг');
        $this->assertSame([], collect($data['tariffBPs'])->where('service_id', $service->id)->values()->all());

        // Срок виден: подписи расписания БП приходят на страницу
        $extra = $data['extras'][0];
        $this->assertNotNull($extra['schedule']);
        $this->assertNotEmpty($extra['schedule']['labels']);
        $this->assertFalse($extra['schedule']['is_custom']);
    }

    public function test_recurring_catalog_extra_survives_second_save(): void
    {
        $client  = $this->client();
        $service = $this->catalogService();

        $extra = [
            'service_id' => $service->id,
            'type'       => 'recurring',
            'name'       => $service->name,
            'cost'       => 500,
            'quantity'   => 1,
        ];

        $this->saveExtraAndReload($client, $extra);
        // Второе сохранение с тем же составом: позиция не должна исчезнуть (раньше её
        // удаляло вместе с логами задач, потому что на экран она не попадала)
        $data = $this->saveExtraAndReload($client, $extra);

        $this->assertCount(1, $data['extras']);

        $items = Estimate::where('client_id', $client->id)->first()->rootItems;
        $this->assertCount(1, $items);
    }

    public function test_extra_saves_assignee_and_due_day(): void
    {
        $client  = $this->client();
        $service = $this->catalogService();

        $data = $this->saveExtraAndReload($client, [
            'service_id'  => $service->id,
            'type'        => 'recurring',
            'name'        => $service->name,
            'cost'        => 500,
            'quantity'    => 1,
            'assignee_id' => $this->accountant->id,
        ]);

        $this->assertSame($this->accountant->id, $data['extras'][0]['assignee_id']);
        $this->assertSame($this->accountant->full_name, $data['extras'][0]['assignee_name']);

        $item = Estimate::where('client_id', $client->id)->first()->rootItems->first();
        $this->assertSame($this->accountant->id, $item->assignee_id);
        $this->assertSame(15, (int) $item->due_day);
        $this->assertSame('Ежемесячно', $item->periodicity);
    }

    /** Тот же БП и в тарифе, и вручную в допах: две отдельные строки, обе переживают пересохранение. */
    public function test_same_service_in_tariff_and_extras_keeps_both_rows(): void
    {
        $client  = $this->client();
        $service = Service::create([
            'name' => 'Обязательный БП ' . uniqid(),
            'periodicity' => 'Ежемесячно',
            'start_day' => [10],
            'due_day' => 10,
            'category' => Service::MANDATORY_CATEGORIES[0], // подтягивается клиенту сам
            'is_active' => true,
        ]);

        $payload = [
            'tariff_bps' => [[
                'service_id' => $service->id,
                'enabled'    => true,
                'quantity'   => 1,
            ]],
            'extras' => [[
                'service_id' => $service->id,
                'type'       => 'recurring',
                'name'       => $service->name,
                'cost'       => 700,
                'quantity'   => 1,
            ]],
        ];

        $this->actingAs($this->admin, 'employee')
            ->postJson(route('clients.estimate.save', $client), $payload)->assertOk();

        $response = $this->actingAs($this->admin, 'employee')
            ->get(route('clients.estimate.edit', $client))->assertOk();

        $this->assertTrue(collect($response->viewData('tariffBPs'))
            ->firstWhere('service_id', $service->id)['enabled']);
        $this->assertCount(1, $response->viewData('extras'));

        $idsBefore = Estimate::where('client_id', $client->id)->first()
            ->rootItems->pluck('id')->sort()->values()->all();
        $this->assertCount(2, $idsBefore);

        // Пересохранение тем же составом не должно пересоздавать строки:
        // их id держат логи задач (buh_task_logs), пересоздание стирает историю.
        $this->actingAs($this->admin, 'employee')
            ->postJson(route('clients.estimate.save', $client), $payload)->assertOk();

        $idsAfter = Estimate::where('client_id', $client->id)->first()
            ->rootItems->pluck('id')->sort()->values()->all();
        $this->assertSame($idsBefore, $idsAfter);
    }

    public function test_custom_extra_has_no_schedule(): void
    {
        $client = $this->client();

        $data = $this->saveExtraAndReload($client, [
            'service_id' => null,
            'type'       => 'recurring',
            'name'       => 'Своя услуга без БП',
            'cost'       => 300,
            'quantity'   => 1,
        ]);

        $this->assertCount(1, $data['extras']);
        $this->assertNull($data['extras'][0]['schedule']);
        $this->assertNull($data['extras'][0]['assignee_id']);
    }
}
