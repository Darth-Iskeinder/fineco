<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Estimate;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\TenantTemplate;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Замена каталога БП действующей фирмы набором из образца.
 *
 * Нужно фирме, которую завели раньше, чем образец привели в порядок: работать
 * по старому набору она не начинала, и каталог проще заменить целиком. Ключевое
 * ограничение — как только на БП кто-то сослался, сносить нельзя: у позиции
 * сметы снимется ссылка, и она превратится в разовую задачу без расписания.
 */
class TenantServicesRefillTest extends TestCase
{
    use DatabaseTransactions;

    private TenantTemplate $copier;
    private Tenant $template;

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

        $this->copier   = new TenantTemplate();
        $this->template = $this->copier->template();

        // Образец начинаем с чистого листа: набор в базе разработчика свой.
        $this->copier->clearTemplate();
    }

    private function newTenant(): Tenant
    {
        return Tenant::create([
            'name'   => 'Тестовая фирма ' . uniqid(),
            'slug'   => 'test-' . uniqid(),
            'status' => Tenant::STATUS_TRIAL,
        ]);
    }

    /** Набор образца: корневой БП с ценой и подпунктом. */
    private function fillTemplate(): void
    {
        DB::table('billings')->insert([
            'tenant_id' => $this->template->id,
            'name' => 'Входит в абонентку', 'code' => 'included',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('service_groups')->insert([
            'tenant_id' => $this->template->id, 'name' => 'Налоги',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        TenantContext::for($this->template, function () {
            $root = Service::create([
                'name' => 'Декларация НДС', 'periodicity' => 'Ежемесячно',
                'start_day' => [20], 'is_active' => true, 'cost' => 5000,
                'billing' => 'Входит в абонентку', 'service_group' => 'Налоги',
            ]);
            Service::create([
                'parent_id' => $root->id,
                'name' => 'Сверка с ЭСФ', 'periodicity' => 'Ежемесячно',
                'start_day' => [20], 'is_active' => true, 'cost' => 1500,
            ]);
        });
    }

    /** Фирма со своим (старым) каталогом БП. */
    private function tenantWithOwnServices(): Tenant
    {
        $tenant = $this->newTenant();

        TenantContext::for($tenant, function () {
            Service::create([
                'name' => 'Старый БП фирмы', 'periodicity' => 'Ежемесячно',
                'start_day' => [5], 'is_active' => true,
            ]);
        });

        return $tenant;
    }

    private function serviceNames(Tenant $tenant): array
    {
        return Service::acrossTenants()
            ->where('tenant_id', $tenant->id)
            ->orderBy('id')
            ->pluck('name')
            ->all();
    }

    public function test_catalog_is_replaced_with_the_template_one(): void
    {
        $this->fillTemplate();
        $target = $this->tenantWithOwnServices();

        $result = $this->copier->refillServices($target);

        $this->assertSame(1, $result['deleted']);
        $this->assertSame(2, $result['copied']);
        $this->assertSame(['Декларация НДС', 'Сверка с ЭСФ'], $this->serviceNames($target));
    }

    /** Подпункт должен прицепиться к новому родителю, а не остаться сиротой. */
    public function test_child_keeps_its_parent(): void
    {
        $this->fillTemplate();
        $target = $this->tenantWithOwnServices();

        $this->copier->refillServices($target);

        $child = Service::acrossTenants()
            ->where('tenant_id', $target->id)
            ->whereNotNull('parent_id')
            ->first();

        $this->assertNotNull($child, 'Подпункт не доехал');
        $this->assertSame(
            $target->id,
            (int) Service::acrossTenants()->find($child->parent_id)->tenant_id,
            'Подпункт прицепился к родителю из чужой фирмы',
        );
    }

    /** Цены — деньги фирмы-донора, другой фирме они не едут. */
    public function test_prices_are_zeroed_by_default(): void
    {
        $this->fillTemplate();
        $target = $this->tenantWithOwnServices();

        $this->copier->refillServices($target);

        $costs = Service::acrossTenants()->where('tenant_id', $target->id)->pluck('cost');

        $this->assertTrue($costs->every(fn ($c) => (float) $c === 0.0), "Цены приехали: {$costs}");
        $this->assertSame(
            0,
            Service::acrossTenants()->where('tenant_id', $target->id)->whereNotNull('rate_id')->count(),
            'У приехавшего БП осталась ссылка на чужую ставку',
        );
    }

    public function test_prices_can_be_kept_on_purpose(): void
    {
        $this->fillTemplate();
        $target = $this->tenantWithOwnServices();

        $this->copier->refillServices($target, resetCost: false);

        $root = Service::acrossTenants()->where('tenant_id', $target->id)->whereNull('parent_id')->first();

        $this->assertSame('5000.00', (string) $root->cost);
    }

    /**
     * Режим тарификации и группа у БП — тексты, а не ссылки. Названия, которых
     * у фирмы нет, досоздаём: без режима цена молча посчиталась бы по cost.
     */
    public function test_missing_dictionary_entries_are_created(): void
    {
        $this->fillTemplate();
        $target = $this->tenantWithOwnServices();

        $result = $this->copier->refillServices($target);

        $billing = DB::table('billings')->where('tenant_id', $target->id)->where('name', 'Входит в абонентку')->first();

        $this->assertNotNull($billing, 'Режим тарификации не досоздан');
        $this->assertSame('included', $billing->code, 'У режима потерялся код — по нему считается цена');
        $this->assertTrue(
            DB::table('service_groups')->where('tenant_id', $target->id)->where('name', 'Налоги')->exists(),
            'Группа не досоздана',
        );
        $this->assertSame([], $result['unresolved']);
    }

    /** Что у фирмы уже есть — не дублируем. */
    public function test_existing_dictionary_entries_are_left_alone(): void
    {
        $this->fillTemplate();
        $target = $this->tenantWithOwnServices();

        DB::table('billings')->insert([
            'tenant_id' => $target->id, 'name' => 'Входит в абонентку', 'code' => 'included',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->copier->refillServices($target);

        $this->assertSame(
            1,
            DB::table('billings')->where('tenant_id', $target->id)->where('name', 'Входит в абонентку')->count(),
            'Режим тарификации задвоился',
        );
    }

    public function test_dictionaries_can_be_left_untouched(): void
    {
        $this->fillTemplate();
        $target = $this->tenantWithOwnServices();

        $result = $this->copier->refillServices($target, fillDictionaries: false);

        $this->assertSame([], $result['dictionaries']);
        $this->assertSame(0, DB::table('billings')->where('tenant_id', $target->id)->count());
    }

    /**
     * Главная защита: у фирмы, которая уже работает, каталог не сносим.
     * Иначе позиция сметы теряет ссылку на БП и остаётся без расписания.
     */
    public function test_refill_is_refused_when_services_are_in_use(): void
    {
        $this->fillTemplate();
        $target = $this->tenantWithOwnServices();

        $service = Service::acrossTenants()->where('tenant_id', $target->id)->first();

        TenantContext::for($target, function () use ($service) {
            $client = Client::create([
                'name' => 'ОсОО Работающий',
                'inn'  => strtoupper(substr(md5(uniqid()), 0, 12)),
            ]);
            $estimate = Estimate::create(['client_id' => $client->id, 'total' => 0]);
            $estimate->items()->create([
                'service_id' => $service->id, 'type' => 'recurring',
                'name' => $service->name, 'periodicity' => 'Ежемесячно',
                'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 0,
            ]);
        });

        $this->assertSame(['позиции смет' => 1], $this->copier->servicesInUse($target));

        try {
            $this->copier->refillServices($target);
            $this->fail('Каталог работающей фирмы снесли — этого быть не должно');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('взяты в работу', $e->getMessage());
        }

        $this->assertSame(['Старый БП фирмы'], $this->serviceNames($target), 'Каталог всё-таки тронули');
    }

    public function test_template_cannot_be_refilled_by_itself(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->copier->refillServices($this->template);
    }
}
