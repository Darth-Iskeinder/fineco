<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Client;
use App\Models\ClientStatus;
use App\Models\Employee;
use App\Models\OrganizationForm;
use App\Models\Periodicity;
use App\Models\Role;
use App\Models\TaxpayerCategory;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Форма организации и статус клиента убраны из настроек совсем.
 *
 * Форму задаёт государство. Статус — не список, а механика: на флаге
 * closes_service висит закрытие обслуживания, а выставить его из настроек
 * было нельзя, только название. Свой статус выглядел рабочим, но обслуживание
 * не закрывал — клиент числился активным, и по нему продолжали идти задачи.
 *
 * Сами таблицы остались: оба значения выбираются селектором в карточке клиента.
 */
class HiddenSettingsSectionsTest extends TestCase
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
            'email' => 'hs_admin_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
    }

    /**
     * Списки уходят в Alpine через @json, а он экранирует кириллицу как \uXXXX —
     * без разэкранирования искать в разметке бесполезно.
     */
    private function decodeUnicode(string $html): string
    {
        return preg_replace_callback(
            '/\\\\u([0-9a-fA-F]{4})/',
            fn ($m) => mb_convert_encoding(pack('H*', $m[1]), 'UTF-8', 'UTF-16BE'),
            $html,
        );
    }

    /** Страниц нет — ни на чтение, ни на запись. */
    public function test_sections_are_gone(): void
    {
        $urls = [
            '/settings/organization-forms',
            '/settings/client-statuses',
            '/settings/taxpayer-categories',
            '/settings/accounting-methods',
            '/settings/service-types',
            '/settings/categories',
            '/settings/periodicities',
            '/settings/check-types',
        ];

        foreach ($urls as $url) {
            $this->actingAs($this->admin, 'employee')->get($url)->assertNotFound();
            $this->actingAs($this->admin, 'employee')->postJson($url, ['name' => 'Новое'])->assertNotFound();
        }
    }

    /** В меню настроек этих пунктов больше нет. */
    public function test_menu_has_no_links(): void
    {
        $html = $this->actingAs($this->admin, 'employee')
            ->get('/settings/tax-systems')
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('Форма/тип организации', $html);
        $this->assertStringNotContainsString('Статус клиента', $html);
        $this->assertStringNotContainsString('Категория налогоплательщика', $html);
        $this->assertStringNotContainsString('Метод учёта', $html);
        $this->assertStringNotContainsString('Тип обслуживания', $html);
        $this->assertStringNotContainsString('Периодичность', $html);
    }

    /**
     * Периодичность — механика: по её типу считаются сроки сдачи отчётов.
     * Раздел убран, но список обязан по-прежнему кормить форму бизнес-процессов,
     * иначе у нового БП нельзя будет задать периодичность.
     */
    public function test_periodicities_still_feed_the_services_form(): void
    {
        $html = $this->decodeUnicode(
            $this->actingAs($this->admin, 'employee')
                ->get('/settings/services')
                ->assertOk()
                ->getContent()
        );

        foreach (Periodicity::orderBy('name')->pluck('name') as $name) {
            $this->assertStringContainsString($name, $html, "Периодичность «{$name}» не доехала до формы БП");
        }
    }

    /**
     * Категории БП — не настройка, а механика: код ищет их по точному названию
     * и решает, что подтягивать в смету. Раздел убран, но список по-прежнему
     * кормит форму бизнес-процессов, иначе категорию нельзя было бы выбрать.
     */
    public function test_categories_still_feed_the_services_form(): void
    {
        $html = $this->actingAs($this->admin, 'employee')
            ->get('/settings/services')
            ->assertOk()
            ->getContent();

        $html = $this->decodeUnicode($html);

        foreach (Category::orderBy('name')->pluck('name') as $name) {
            $this->assertStringContainsString($name, $html, "Категория «{$name}» не доехала до формы БП");
        }
    }

    /**
     * Метод учёта в карточке клиента всегда работал на списке из кода, а
     * справочник accounting_methods стоял пустой и ни к чему не подключённый —
     * правка в нём ни на что не влияла. Карточка от удаления раздела не страдает.
     */
    public function test_accounting_method_still_selectable_on_client_card(): void
    {
        $client = Client::create([
            'name' => 'ТОО Метод Учёта',
            'inn'  => strtoupper(substr(md5(uniqid()), 0, 12)),
        ]);

        $html = $this->actingAs($this->admin, 'employee')
            ->get('/clients/' . $client->id)
            ->assertOk()
            ->getContent();

        $html = $this->decodeUnicode($html);

        $this->assertStringContainsString('Кассовый метод', $html);
        $this->assertStringContainsString('Метод начисления', $html);
    }

    /**
     * Категория налогоплательщика была наполовину переведена со старого
     * зашитого списка на справочник: показывалась из одного места, а выбиралась
     * из другого, пустого. Теперь справочник заполнен, и выбрать можно.
     */
    public function test_taxpayer_categories_are_filled(): void
    {
        $names = TaxpayerCategory::orderBy('id')->pluck('name')->all();

        $this->assertSame(['Малый', 'Средний', 'Крупный'], $names);
    }

    /** Клиенты со старым текстовым значением переехали на справочник. */
    public function test_legacy_taxpayer_values_were_moved(): void
    {
        $stranded = Client::whereNotNull('taxpayer_category')
            ->where('taxpayer_category', '!=', '')
            ->whereNull('taxpayer_category_id')
            ->count();

        $this->assertSame(0, $stranded, 'Есть клиенты, оставшиеся на старом поле');
    }

    /** Данные на месте: карточка клиента выбирает их селектором. */
    public function test_data_is_still_there(): void
    {
        $this->assertTrue(OrganizationForm::query()->exists(), 'Формы организации пропали');
        $this->assertTrue(ClientStatus::query()->exists(), 'Статусы клиента пропали');

        // Ровно один статус закрывает обслуживание — на нём держится вся логика
        $this->assertSame(1, ClientStatus::where('closes_service', true)->count());
    }

    /** Новый клиент по-прежнему получает открытый статус автоматически. */
    public function test_new_client_gets_open_status(): void
    {
        $this->actingAs($this->admin, 'employee')->post('/clients', [
            'name' => 'ТОО Проверка Статуса',
            'inn'  => strtoupper(substr(md5(uniqid()), 0, 12)),
        ]);

        $client = Client::where('name', 'ТОО Проверка Статуса')->first();

        $this->assertNotNull($client);
        $this->assertNotNull($client->client_status_id, 'Клиенту не проставился статус');
        $this->assertFalse($client->clientStatus->closes_service);
    }
}
