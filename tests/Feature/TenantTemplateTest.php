<?php

namespace Tests\Feature;

use App\Models\Service;
use App\Models\Tenant;
use App\Services\TenantTemplate;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Стартовый набор для нового аккаунта: копируется из аккаунта-образца.
 *
 * Без него новая фирма стартует с пустыми таблицами и не может завести даже
 * первого клиента. Цены (тарифы и ставки) в набор не входят намеренно — они
 * у каждой фирмы свои, и показывать чужие нельзя.
 */
class TenantTemplateTest extends TestCase
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
    }

    private function newTenant(): Tenant
    {
        return Tenant::create([
            'name'   => 'Тестовая фирма ' . uniqid(),
            'slug'   => 'test-' . uniqid(),
            'status' => Tenant::STATUS_TRIAL,
        ]);
    }

    /** Наполняем образец минимальным набором: один БП с подпунктом. */
    private function fillTemplate(): Service
    {
        DB::table('billings')->insert([
            'tenant_id' => $this->template->id,
            'name' => 'Входит в абонентку', 'code' => 'included',
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('spheres')->insert([
            'tenant_id' => $this->template->id, 'name' => 'Тестовая сфера',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        // tenant_id не в $fillable у моделей — это защита от массового присвоения,
        // проставлять его будет трейт на этапе 2. Здесь дописываем напрямую.
        $root = Service::create([
            'name' => 'Образцовый БП', 'periodicity' => 'Ежемесячно',
            'start_day' => [5], 'is_active' => true, 'cost' => 100,
        ]);
        $child = Service::create([
            'parent_id' => $root->id,
            'name' => 'Подпункт образца', 'periodicity' => 'Ежемесячно',
            'start_day' => [5], 'is_active' => true,
        ]);

        DB::table('services')->whereIn('id', [$root->id, $child->id])
            ->update(['tenant_id' => $this->template->id]);

        return $root->refresh();
    }

    public function test_template_account_exists_and_is_the_only_one(): void
    {
        $this->assertTrue($this->template->isTemplate());
        $this->assertSame(1, Tenant::template()->count(), 'Аккаунтов-образцов должно быть ровно один');
    }

    /** В образце не должно быть ни клиентов, ни сотрудников — он не для работы. */
    public function test_template_account_holds_no_working_data(): void
    {
        foreach (['clients', 'employees', 'estimates', 'buh_task_logs'] as $table) {
            $this->assertSame(
                0,
                DB::table($table)->where('tenant_id', $this->template->id)->count(),
                "В образце оказались данные таблицы {$table}",
            );
        }
    }

    public function test_copy_brings_dictionaries_to_a_new_account(): void
    {
        $this->fillTemplate();
        $target = $this->newTenant();

        $copied = $this->copier->copyTo($target);

        $this->assertGreaterThan(0, $copied['billings']);
        $this->assertGreaterThan(0, $copied['spheres']);
        $this->assertGreaterThan(0, $copied['services']);

        $this->assertTrue(
            DB::table('billings')->where('tenant_id', $target->id)->where('code', 'included')->exists(),
            'Режим тарификации не доехал — цена в новом аккаунте считалась бы неправильно',
        );
    }

    /** Подпункты должны прицепиться к новому родителю, а не к чужому. */
    public function test_child_services_keep_their_parent(): void
    {
        $this->fillTemplate();
        $target = $this->newTenant();

        $this->copier->copyTo($target);

        $child = Service::where('tenant_id', $target->id)->whereNotNull('parent_id')->first();

        $this->assertNotNull($child, 'Подпункт не скопировался');
        $this->assertSame(
            $target->id,
            (int) Service::find($child->parent_id)->tenant_id,
            'Подпункт прицепился к родителю из чужого аккаунта',
        );
    }

    /** Цены не копируются: тарифы, ставки и привязка БП к ставке остаются пустыми. */
    public function test_prices_are_not_copied(): void
    {
        $this->fillTemplate();
        $target = $this->newTenant();

        $this->copier->copyTo($target);

        $this->assertSame(0, DB::table('tariffs')->where('tenant_id', $target->id)->count());
        $this->assertSame(0, DB::table('rates')->where('tenant_id', $target->id)->count());
        $this->assertSame(
            0,
            Service::where('tenant_id', $target->id)->whereNotNull('rate_id')->count(),
            'У скопированного БП осталась ссылка на чужую ставку',
        );
    }

    /** Второй раз копировать нельзя — иначе поверх правок фирмы ляжет второй комплект. */
    public function test_second_copy_is_refused(): void
    {
        $this->fillTemplate();
        $target = $this->newTenant();

        $this->copier->copyTo($target);

        $this->expectException(\RuntimeException::class);
        $this->copier->copyTo($target);
    }

    /**
     * Одинаковые коды в разных аккаунтах — норма: у каждой фирмы свой набор.
     * Раньше уникальность была на всю базу, и копия справочников падала на
     * «Duplicate entry». Заодно проверяем главный случай — ИНН клиента: две
     * фирмы обязаны иметь возможность вести одного и того же клиента.
     */
    public function test_same_codes_and_inn_allowed_in_different_accounts(): void
    {
        $first  = $this->newTenant();
        $second = $this->newTenant();
        $code   = 'dup_' . uniqid();
        $inn    = strtoupper(substr(md5(uniqid()), 0, 12));

        foreach ([$first, $second] as $tenant) {
            DB::table('activity_types')->insert([
                'tenant_id' => $tenant->id, 'name' => 'Торговля', 'code' => $code,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('clients')->insert([
                'tenant_id' => $tenant->id, 'name' => 'ОсОО Одинаковый ИНН', 'inn' => $inn,
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $this->assertSame(2, DB::table('activity_types')->where('code', $code)->count());
        $this->assertSame(2, DB::table('clients')->where('inn', $inn)->count());
    }

    /** А внутри одного аккаунта ИНН по-прежнему уникален. */
    public function test_duplicate_inn_inside_one_account_is_still_refused(): void
    {
        $tenant = $this->newTenant();
        $inn    = strtoupper(substr(md5(uniqid()), 0, 12));

        DB::table('clients')->insert([
            'tenant_id' => $tenant->id, 'name' => 'Первый', 'inn' => $inn,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        DB::table('clients')->insert([
            'tenant_id' => $tenant->id, 'name' => 'Второй', 'inn' => $inn,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_template_cannot_copy_into_itself(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->copier->copyTo($this->template);
    }
}
