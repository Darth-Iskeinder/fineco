<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Periodicity;
use App\Models\Role;
use App\Models\Service;
use App\Models\Tenant;
use App\Support\Impersonation;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Эталонный номер БП — опора авто-аудита.
 *
 * Правила сверки ссылаются на БП номером, поэтому номер должен быть неприкосновенным
 * для фирмы: ставит и снимает его только поставщик системы, зашедший в аккаунт.
 * Проверяем и обратное, менее очевидное: обычное сохранение БП администратором
 * не должно номер стирать, хотя поля в его форме нет и в запросе он не приедет.
 */
class ServiceReferenceIdTest extends TestCase
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
            'email' => 'ref_admin_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);

        Periodicity::firstOrCreate(['name' => 'По запросу'], ['kind' => Service::KIND_ON_REQUEST]);
    }

    /** Изображаем вендора, зашедшего в фирму: номер правится только в этом режиме. */
    private function asVendor(): self
    {
        Impersonation::start(Tenant::find($this->admin->tenant_id), $this->admin);

        return $this;
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'БП ' . uniqid(),
            'cost' => 0,
            'periodicity' => 'По запросу',
            'deadline_days' => 3,
        ], $overrides);
    }

    private function service(array $extra = []): Service
    {
        return Service::create(array_merge([
            'name' => 'БП ' . uniqid(), 'periodicity' => 'По запросу',
            'deadline_days' => 3, 'is_active' => true, 'cost' => 0,
        ], $extra));
    }

    /** Вендор ставит номер, и он сохраняется. */
    public function test_vendor_sets_reference_id(): void
    {
        $service = $this->service();

        $this->asVendor()->actingAs($this->admin, 'employee')
            ->putJson("/settings/services/{$service->id}", $this->payload([
                'name'         => $service->name,
                'reference_id' => 901,
            ]))
            ->assertOk();

        $this->assertSame(901, $service->fresh()->reference_id);
    }

    /** Администратор фирмы номер не ставит: поля у него нет, а подделанный запрос игнорируем. */
    public function test_firm_admin_cannot_set_reference_id(): void
    {
        $service = $this->service();

        $this->actingAs($this->admin, 'employee')
            ->putJson("/settings/services/{$service->id}", $this->payload([
                'name'         => $service->name,
                'reference_id' => 901,
            ]))
            ->assertOk();

        $this->assertNull($service->fresh()->reference_id);
    }

    /** Вендор снимает пометку, отправив пустое поле. */
    public function test_vendor_clears_reference_id(): void
    {
        $service = $this->service(['reference_id' => 902]);

        $this->asVendor()->actingAs($this->admin, 'employee')
            ->putJson("/settings/services/{$service->id}", $this->payload([
                'name'         => $service->name,
                'reference_id' => '',
            ]))
            ->assertOk();

        $this->assertNull($service->fresh()->reference_id);
    }

    /** Два БП с одним номером в одной фирме сделали бы выбор документа неоднозначным. */
    public function test_reference_id_is_unique_within_tenant(): void
    {
        $this->service(['reference_id' => 902]);
        $other = $this->service();

        $this->asVendor()->actingAs($this->admin, 'employee')
            ->putJson("/settings/services/{$other->id}", $this->payload([
                'name'         => $other->name,
                'reference_id' => 902,
            ]))
            ->assertStatus(422)
            ->assertJsonValidationErrors('reference_id');
    }

    /**
     * Помеченный БП фирма не правит вовсе — промежуточный, намеренно жёсткий замок.
     *
     * Сверка ссылается на этот БП, и правка изнутри фирмы способна тихо развернуть
     * проверку на другой документ. Пока чтение документа не умеет само убеждаться,
     * что перед ним нужная форма и нужный месяц, дешевле запретить.
     */
    public function test_firm_admin_cannot_edit_reference_service(): void
    {
        $service = $this->service(['reference_id' => 902]);

        $this->actingAs($this->admin, 'employee')
            ->putJson("/settings/services/{$service->id}", $this->payload(['name' => 'Другое название']))
            ->assertStatus(422)
            ->assertJsonPath('success', false);

        $fresh = $service->fresh();
        $this->assertSame($service->name, $fresh->name);
        $this->assertSame(902, $fresh->reference_id);
    }

    /** Удалить помеченный БП нельзя: правило перестало бы находить документ молча. */
    public function test_firm_admin_cannot_delete_reference_service(): void
    {
        $service = $this->service(['reference_id' => 902]);

        $this->actingAs($this->admin, 'employee')
            ->deleteJson("/settings/services/{$service->id}")
            ->assertStatus(422);

        $this->assertNotNull(Service::find($service->id));
    }

    /** Архив тоже закрыт: по заархивированному БП задач не будет, а значит и документов. */
    public function test_firm_admin_cannot_archive_reference_service(): void
    {
        $service = $this->service(['reference_id' => 902]);

        $this->actingAs($this->admin, 'employee')
            ->postJson("/settings/services/{$service->id}/archive")
            ->assertStatus(422);

        $this->assertNull($service->fresh()->archived_at);
    }

    /** Поставщику замок не мешает: он и ставит номера, и правит помеченный БП. */
    public function test_vendor_can_still_edit_reference_service(): void
    {
        $service = $this->service(['reference_id' => 902]);

        $this->asVendor()->actingAs($this->admin, 'employee')
            ->putJson("/settings/services/{$service->id}", $this->payload([
                'name'         => 'Поставщик переименовал',
                'reference_id' => 902,
            ]))
            ->assertOk();

        $this->assertSame('Поставщик переименовал', $service->fresh()->name);
    }

    /** Непомеченный БП фирма правит как раньше: замок не должен задеть остальной каталог. */
    public function test_plain_service_is_not_locked(): void
    {
        $service = $this->service();

        $this->actingAs($this->admin, 'employee')
            ->putJson("/settings/services/{$service->id}", $this->payload(['name' => 'Обычная правка']))
            ->assertOk();

        $this->assertSame('Обычная правка', $service->fresh()->name);
    }
}
