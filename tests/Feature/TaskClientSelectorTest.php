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
 * Селектор компаний в модалке «Добавить задачу» (allClients на странице /buhtasks):
 * админ/руководитель — все активные; остальные (включая главбуха) — только свои:
 * где ответственный или исполнитель БП. По боевому mysql в транзакции (как DashboardTest).
 */
class TaskClientSelectorTest extends TestCase
{
    use DatabaseTransactions;

    private Employee $admin;
    private Employee $manager;
    private Employee $head;
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
        $module = Module::firstOrCreate(
            ['name' => 'buhtasks'],
            ['display_name' => 'БухЗадачник', 'is_active' => true],
        );

        $roles = [
            'admin'    => Role::firstOrCreate(['name' => Role::ADMIN], ['display_name' => 'Админ']),
            'manager'  => Role::firstOrCreate(['name' => Role::MANAGER], ['display_name' => 'Руководитель']),
            'accountant' => Role::firstOrCreate(['name' => Role::ACCOUNTANT], ['display_name' => 'Бухгалтер']),
        ];

        $make = fn (string $role, string $prefix) => Employee::create([
            'full_name' => 'Тест ' . $prefix, 'position' => $prefix,
            'email' => $prefix . '_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $roles[$role]->id, 'status' => Employee::STATUS_ACTIVE,
        ]);

        $this->admin      = $make('admin', 'admin');
        $this->manager    = $make('manager', 'manager');
        $this->head       = $make('accountant', 'head');
        $this->accountant = $make('accountant', 'acc');

        foreach ([$this->head, $this->accountant] as $e) {
            $e->modules()->attach($module->id);
        }
    }

    private function client(string $name, ?int $responsibleId, bool $active = true): Client
    {
        return Client::create([
            'name' => $name, 'inn' => strtoupper(substr(md5($name . uniqid()), 0, 12)),
            'responsible_employee_id' => $responsibleId, 'is_active' => $active,
        ]);
    }

    private function allClientNames(Employee $employee): array
    {
        $response = $this->actingAs($employee, 'employee')
            ->get(route('buhtasks.index'))
            ->assertOk();

        return $response->viewData('allClients')->pluck('name')->all();
    }

    /**
     * Компанию и услугу в модалке выбирают полем с поиском, а не длинным селектом:
     * и компаний, и БП в каталоге сотни, прокруткой нужную не найти.
     */
    public function test_company_and_catalog_are_picked_by_search(): void
    {
        $this->client('ОсОО Поисковая', $this->accountant->id);

        $page = $this->actingAs($this->accountant, 'employee')
            ->get(route('buhtasks.index'))
            ->assertOk();

        // Поля с поиском на месте: подсказка ввода и обработчики выбора.
        $page->assertSee('Начните вводить название...', false);
        $page->assertSee("pickClient(c)", false);
        $page->assertSee("pickService(svc)", false);

        // Старых выпадающих списков в форме не осталось.
        $page->assertDontSee('>Выберите компанию...<', false);
        $page->assertDontSee('>Выберите услугу...<', false);
    }

    /**
     * Поиск компаний в модалке и отбор по компании в таблице — разные списки.
     *
     * Одноимённый геттер уже ломал страницу: дубликат ключа в объекте компонента
     * молча перетирает первый, и список начинал перебирать {rows, total}, показывая
     * «undefined undefined». Имена должны оставаться разными.
     *
     * Отбор по компании теперь живёт в воронке колонки (facetSource + filters.client),
     * а не в селекте над таблицей, поэтому сторожим уже эту пару имён.
     */
    public function test_picker_and_filter_lists_do_not_clash(): void
    {
        $html = $this->actingAs($this->accountant, 'employee')
            ->get(route('buhtasks.index'))
            ->assertOk()
            ->getContent();

        $this->assertSame(1, substr_count($html, 'get clientPickerOptions()'), 'Геттер поиска компаний объявлен не один раз');
        $this->assertSame(1, substr_count($html, 'get facetSource()'), 'Источник значений воронок объявлен не один раз');
        $this->assertStringContainsString("filters['client']", $html, 'Колонка «Компания» потеряла воронку');
    }

    public function test_admin_and_manager_see_all_active_clients(): void
    {
        $foreign  = $this->client('ТОО Чужой Актив ' . uniqid(), $this->head->id);
        $inactive = $this->client('ТОО Архив ' . uniqid(), $this->head->id, active: false);

        foreach ([$this->admin, $this->manager] as $boss) {
            $names = $this->allClientNames($boss);
            $this->assertContains($foreign->name, $names);
            $this->assertNotContains($inactive->name, $names);
        }
    }

    public function test_accountant_sees_only_linked_clients(): void
    {
        $mine    = $this->client('ТОО Мой ' . uniqid(), $this->accountant->id);
        $foreign = $this->client('ТОО Чужой ' . uniqid(), $this->head->id);

        $names = $this->allClientNames($this->accountant);
        $this->assertContains($mine->name, $names);
        $this->assertNotContains($foreign->name, $names);
    }

    public function test_head_sees_only_his_own_clients(): void
    {
        // Клиент главбуха, где бухгалтер — исполнитель БП
        $headClient = $this->client('ТОО Главбуха ' . uniqid(), $this->head->id);
        $service = Service::create([
            'name' => 'Тест услуга ' . uniqid(), 'periodicity' => 'Ежемесячно',
            'start_day' => [5], 'is_active' => true,
        ]);
        Estimate::create(['client_id' => $headClient->id, 'total' => 0])->items()->create([
            'service_id' => $service->id, 'type' => 'recurring',
            'name' => $service->name, 'periodicity' => 'Ежемесячно',
            'cost' => 0, 'quantity' => 1, 'total' => 0, 'sort_order' => 0,
            'assignee_id' => $this->accountant->id,
        ]);

        // Клиент бухгалтера, за которого главбух НЕ ответственен: назначение одного БП
        // не делает бухгалтера «своим» насквозь — чужая компания в селектор не попадает,
        // иначе главбух поставил бы задачу, которую потом нигде не увидит.
        $accClient = $this->client('ТОО Бухгалтера ' . uniqid(), $this->accountant->id);

        $names = $this->allClientNames($this->head);
        $this->assertContains($headClient->name, $names);
        $this->assertNotContains($accClient->name, $names);
    }
}
