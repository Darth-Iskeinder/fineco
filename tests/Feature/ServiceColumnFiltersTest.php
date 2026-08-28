<?php

namespace Tests\Feature;

use App\Models\Employee;
use App\Models\Periodicity;
use App\Models\Role;
use App\Models\Service;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Фильтры по колонкам в списке БП (/settings/services).
 *
 * Раньше отбор был только по сфере и типу обслуживания, по одному значению каждый.
 * Теперь у каждой фильтруемой колонки своя воронка с мультивыбором, а отбор попадает
 * в адрес страницы, чтобы ссылкой можно было поделиться.
 *
 * Сама фильтрация живёт в Alpine, поэтому здесь проверяем разметку: какие колонки
 * получили воронку, каких у них быть не должно и что старый селект сферы убран.
 */
class ServiceColumnFiltersTest extends TestCase
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

        Periodicity::firstOrCreate(['name' => 'Ежемесячно'], ['kind' => 'monthly']);
        $role = Role::firstOrCreate(['name' => Role::ADMIN], ['display_name' => 'Администратор']);

        $this->admin = Employee::create([
            'full_name' => 'Тест Админ', 'position' => 'Админ',
            'email' => 'filters_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);

        Service::create([
            'name' => 'Декларация НДС ' . uniqid(), 'sphere' => 'Налоги и отчетность',
            'service_group' => 'НДС', 'category' => 'Обязательная', 'billing' => 'Входит в абонентку',
            'periodicity' => 'Ежемесячно', 'start_day' => [20], 'is_active' => true,
        ]);
    }

    private function page()
    {
        return $this->actingAs($this->admin, 'employee')
            ->get(route('settings.services'))
            ->assertOk();
    }

    /** Каждая фильтруемая колонка получила воронку. */
    public function test_filterable_columns_have_a_funnel(): void
    {
        $page = $this->page();

        foreach ([
            'sphere', 'service_group', 'category', 'tax_system',
            'periodicity', 'requires_document', 'requires_review', 'billing',
        ] as $key) {
            $page->assertSee("toggleFilterMenu('{$key}', \$event)", false);
        }
    }

    /** Дедлайн и план по времени фильтром не закрываются: у каждой строки своё значение. */
    public function test_deadline_and_minutes_stay_without_a_filter(): void
    {
        $page = $this->page();

        $page->assertSee('Дедлайн', false);
        $page->assertSee('План (мин.)', false);
        $page->assertDontSee("toggleFilterMenu('deadline'", false);
        $page->assertDontSee("toggleFilterMenu('execution_minutes'", false);
    }

    /** Отбор по сфере переехал в воронку, старого селекта на странице больше нет. */
    public function test_old_sphere_select_is_gone(): void
    {
        $page = $this->page();

        $page->assertDontSee('Все сферы', false);
        $page->assertDontSee('sphereFilter', false);
    }

    /** Мультивыбор, счётчики, чипы и сброс — на месте. */
    public function test_menu_has_multiselect_counts_and_chips(): void
    {
        $page = $this->page();

        $page->assertSee('toggleFacetValue(openFilter, opt.value)', false);
        $page->assertSee('x-text="opt.count"', false);
        $page->assertSee('filterChips', false);
        $page->assertSee('resetFilters()', false);
    }

    /**
     * Поиск внутри меню показывается по полному числу значений.
     *
     * Считали по найденным — и поле исчезало от первой же буквы, как только совпадений
     * становилось меньше порога.
     */
    public function test_menu_search_is_shown_by_total_number_of_values(): void
    {
        $page = $this->page();

        $page->assertSee('x-show="menuTotal > 7"', false);
        $page->assertDontSee('facetOptions(openFilter).length > 7', false);
    }

    /** Список меню берётся из готового набора, а не считается на каждую перерисовку. */
    public function test_menu_uses_a_prepared_list(): void
    {
        $page = $this->page();

        $page->assertSee('x-for="opt in menuOptions"', false);
        $page->assertSee('refreshMenuOptions()', false);
    }

    /**
     * Прокрутка таблицы двигает меню за колонкой, а не закрывает его.
     *
     * Закрытие по прокрутке выглядело как «выбрал значение — меню пропало»: список
     * становился короче, браузер сам поправлял прокрутку и гасил открытое меню.
     */
    public function test_scrolling_moves_the_menu_instead_of_closing_it(): void
    {
        $page = $this->page();

        $page->assertSee('@scroll="onScrollWhileFiltering()"', false);
        $page->assertSee('positionFilterMenu()', false);
        $page->assertDontSee('@scroll="closeFilterMenu()"', false);
    }

    /** «Выбрать все» отмечает то, что сейчас в списке, в том числе найденное поиском. */
    public function test_menu_has_select_all(): void
    {
        $page = $this->page();

        $page->assertSee('toggleMenuAll()', false);
        $page->assertSee('menuAllChecked', false);
    }

    /** Отбор уходит в адрес страницы и читается оттуда при открытии. */
    public function test_filters_are_kept_in_the_url(): void
    {
        $page = $this->page();

        $page->assertSee('syncFiltersToUrl', false);
        $page->assertSee('readFiltersFromUrl', false);
        $page->assertSee('window.history.replaceState', false);
    }
}
