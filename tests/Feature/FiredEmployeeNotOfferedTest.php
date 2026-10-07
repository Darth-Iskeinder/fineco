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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Уволенного не предлагают в исполнители.
 *
 * Статусов у сотрудника два, и путать их дорого: `status` — про вход в систему,
 * `employment_status` — про то, работает ли человек в фирме. Увольнение через
 * карточку меняет второе, аккаунт при этом остаётся активным — а подборки
 * смотрели только на первое и предлагали уволенных наравне со всеми.
 */
class FiredEmployeeNotOfferedTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $head;
    private Employee $working;
    private Employee $fired;
    private Employee $manager;
    private Client $client;

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

        Periodicity::firstOrCreate(['name' => 'Ежемесячно'], ['kind' => 'monthly']);
        $role = Role::firstOrCreate(['name' => Role::ACCOUNTANT], ['display_name' => 'Бухгалтер']);

        foreach (['buhtasks' => 'БухЗадачник', 'clients' => 'Клиенты'] as $name => $title) {
            $module = Module::firstOrCreate(['name' => $name], ['display_name' => $title, 'is_active' => true]);
            $modules[] = $module->id;
        }

        $make = fn (string $prefix, array $extra = []) => Employee::create(array_merge([
            'full_name' => 'Тест ' . $prefix, 'position' => $prefix,
            'email' => $prefix . '_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ], $extra));

        // Исполнителей в смете назначает главбух клиента — им и смотрим.
        $headRole = Role::firstOrCreate(['name' => Role::HEAD_ACCOUNTANT], ['display_name' => 'Главбух']);
        $this->head    = $make('head', ['role_id' => $headRole->id]);
        $this->working = $make('working');
        // Уволен, но аккаунт не заблокирован — так увольнение и оформляется в карточке.
        $this->fired   = $make('fired', ['employment_status' => Employee::EMPLOYMENT_FIRED]);

        // Ответственного меняет и заводит клиентов руководитель.
        $managerRole   = Role::firstOrCreate(['name' => Role::MANAGER], ['display_name' => 'Руководитель']);
        $this->manager = $make('manager', ['role_id' => $managerRole->id]);

        foreach ([$this->head, $this->working, $this->fired, $this->manager] as $e) {
            $e->modules()->syncWithoutDetaching($modules);
        }

        $this->client = Client::create([
            'name' => 'ТОО Увольнение ' . uniqid(),
            'inn' => strtoupper(substr(md5(uniqid()), 0, 12)),
            'responsible_employee_id' => $this->head->id,
        ]);
    }

    /** @return array<int, array<string, mixed>> Кандидаты в исполнители БП на странице сметы. */
    private function assigneeOptions(): array
    {
        return $this->actingAs($this->head, 'employee')
            ->get(route('clients.estimate.edit', $this->client))
            ->assertOk()
            ->viewData('assigneeOptions');
    }

    public function test_estimate_does_not_offer_a_fired_accountant(): void
    {
        $ids = array_column($this->assigneeOptions(), 'id');

        $this->assertContains($this->working->id, $ids, 'Работающий бухгалтер пропал из кандидатов');
        $this->assertNotContains($this->fired->id, $ids, 'Уволенный предлагается в исполнители');
    }

    /**
     * Тот, на ком позиция уже стоит, из списка не исчезает — иначе селект показал бы
     * пустоту вместо реального исполнителя, а сохранение молча переставило бы задачу.
     */
    public function test_already_assigned_fired_employee_stays_in_the_list(): void
    {
        $service = Service::create([
            'name' => 'Тест отчёт ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_day' => [5], 'is_active' => true,
        ]);
        $estimate = Estimate::create(['client_id' => $this->client->id, 'total' => 0]);
        $estimate->items()->create([
            'service_id' => $service->id, 'type' => 'recurring',
            'name' => $service->name, 'periodicity' => 'Ежемесячно',
            'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 0,
            'assignee_id' => $this->fired->id,
        ]);

        $options = collect($this->assigneeOptions())->keyBy('id');

        $this->assertTrue($options->has($this->fired->id), 'Действующий исполнитель пропал из селекта');
        $this->assertTrue($options[$this->fired->id]['fired'], 'Уволенный не помечен: его можно выбрать заново');
        $this->assertFalse($options[$this->working->id]['fired'], 'Работающий помечен уволенным');
    }

    public function test_buhtasks_does_not_offer_a_fired_employee(): void
    {
        $employees = $this->actingAs($this->head, 'employee')
            ->get(route('buhtasks.index'))
            ->assertOk()
            ->viewData('employees');

        $ids = $employees->pluck('id')->all();

        $this->assertContains($this->working->id, $ids, 'Работающий сотрудник пропал из списка');
        $this->assertNotContains($this->fired->id, $ids, 'Уволенный предлагается для назначения задачи');
    }

    /** Список — не единственная дверь: форму можно отправить и мимо него. */
    public function test_adhoc_task_cannot_be_assigned_to_a_fired_employee(): void
    {
        $this->actingAs($this->head, 'employee')
            ->post(route('buhtasks.adhoc.store'), [
                'employee_id' => $this->fired->id,
                'name'        => 'Разовая задача',
                'due_date'    => now()->addWeek()->toDateString(),
            ])
            ->assertNotFound();
    }

    /** @return \Illuminate\Support\Collection<int, array{id:int, full_name:string, note:?string}> */
    private function responsibleOptions()
    {
        return collect($this->actingAs($this->manager, 'employee')
            ->get(route('clients.index'))
            ->assertOk()
            ->viewData('employees'))->keyBy('id');
    }

    /** Данные формы правки клиента: всё как есть, меняется только ответственный. */
    private function clientForm(?int $responsibleId): array
    {
        return [
            'name' => $this->client->name,
            'inn'  => $this->client->inn,
            'responsible_employee_id' => $responsibleId,
        ];
    }

    public function test_client_form_does_not_offer_a_fired_responsible(): void
    {
        $options = $this->responsibleOptions();

        $this->assertTrue($options->has($this->working->id), 'Работающий сотрудник пропал из выбора ответственного');
        $this->assertFalse($options->has($this->fired->id), 'Уволенный предлагается в ответственные');
    }

    /**
     * Уволенный, который уже стоит у клиента, в списке остаётся с пометкой: без него
     * форма открылась бы с пустым полем, а фильтр не нашёл бы его клиентов.
     */
    /** Карточка клиента берёт тот же список: нынешний уволенный в нём есть, с пометкой. */
    public function test_client_card_keeps_its_fired_responsible_with_a_note(): void
    {
        $this->client->update(['responsible_employee_id' => $this->fired->id]);

        $options = collect($this->actingAs($this->manager, 'employee')
            ->get(route('clients.show', $this->client))
            ->assertOk()
            ->viewData('responsibleOptions'))->keyBy('id');

        $this->assertSame('уволен', $options[$this->fired->id]['note'] ?? null);
    }

    public function test_current_fired_responsible_stays_in_the_list_with_a_note(): void
    {
        $this->client->update(['responsible_employee_id' => $this->fired->id]);

        $options = $this->responsibleOptions();

        $this->assertTrue($options->has($this->fired->id), 'Нынешний ответственный пропал из списка');
        $this->assertSame('уволен', $options[$this->fired->id]['note']);
        $this->assertNull($options[$this->working->id]['note']);
    }

    /** Список не единственная дверь: форму можно отправить и мимо него. */
    public function test_new_client_cannot_get_a_fired_responsible(): void
    {
        $this->actingAs($this->manager, 'employee')
            ->post(route('clients.store'), [
                'name' => 'ТОО Новый ' . uniqid(),
                'inn'  => strtoupper(substr(md5(uniqid()), 0, 12)),
                'responsible_employee_id' => $this->fired->id,
            ])
            ->assertSessionHasErrors('responsible_employee_id', null, 'createClient');
    }

    public function test_client_cannot_be_switched_to_a_fired_responsible(): void
    {
        $this->actingAs($this->manager, 'employee')
            ->putJson(route('clients.update', $this->client), $this->clientForm($this->fired->id))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('responsible_employee_id');

        $this->assertSame($this->head->id, $this->client->fresh()->responsible_employee_id);
    }

    public function test_contract_section_cannot_switch_to_a_fired_responsible(): void
    {
        $this->actingAs($this->manager, 'employee')
            ->patchJson(route('clients.update-section', $this->client), [
                'section' => 'contract',
                'responsible_employee_id' => $this->fired->id,
            ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('responsible_employee_id');

        $this->assertSame($this->head->id, $this->client->fresh()->responsible_employee_id);
    }

    /**
     * Ловушка, ради которой правило пропускает нынешнего: клиента с уволенным
     * ответственным сохраняют по другому поводу, и ответственный не должен стереться.
     */
    public function test_saving_a_client_keeps_its_fired_responsible(): void
    {
        $this->client->update(['responsible_employee_id' => $this->fired->id]);

        $this->actingAs($this->manager, 'employee')
            ->putJson(route('clients.update', $this->client), $this->clientForm($this->fired->id))
            ->assertOk();

        $this->assertSame($this->fired->id, $this->client->fresh()->responsible_employee_id);
    }

    public function test_client_can_still_be_switched_to_a_working_employee(): void
    {
        $this->client->update(['responsible_employee_id' => $this->fired->id]);

        $this->actingAs($this->manager, 'employee')
            ->putJson(route('clients.update', $this->client), $this->clientForm($this->working->id))
            ->assertOk();

        $this->assertSame($this->working->id, $this->client->fresh()->responsible_employee_id);
    }
}
