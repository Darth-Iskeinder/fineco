<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Module;
use App\Models\Role;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Поиск по клиентам.
 *
 * Раньше это был простой LIKE по названию и двум ИНН. Отсюда все неудобства: на боевом
 * PostgreSQL поиск различал регистр (в MySQL нет, поэтому локально всё выглядело
 * исправным), запрос сравнивался одной сплошной подстрокой, ё и е считались разными
 * буквами, а телефон и контактное лицо не искались вовсе.
 */
class ClientSearchTest extends TestCase
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

        $role   = Role::firstOrCreate(['name' => Role::ADMIN], ['display_name' => 'Администратор']);
        $module = Module::firstOrCreate(
            ['name' => 'clients'],
            ['display_name' => 'Клиенты', 'is_active' => true],
        );

        $this->admin = Employee::create([
            'full_name' => 'Тест Админ', 'position' => 'Админ',
            'email' => 'search_admin_' . uniqid() . '@test.kg', 'password' => bcrypt('x'),
            'role_id' => $role->id, 'status' => Employee::STATUS_ACTIVE,
        ]);
        $this->admin->modules()->attach($module->id);
    }

    private function client(string $name, array $attributes = []): Client
    {
        return Client::create(array_merge([
            'name' => $name,
            'inn'  => strtoupper(substr(md5($name . uniqid()), 0, 12)),
            'responsible_employee_id' => $this->admin->id,
        ], $attributes));
    }

    /** @return array<int, string> названия найденного, в порядке выдачи */
    private function found(string $query): array
    {
        return Client::search($query)->sortBySearch($query)->pluck('name')->all();
    }

    public function test_case_and_yo_do_not_matter(): void
    {
        $romashka = $this->client('ОсОО «Ромашка» ' . uniqid());
        $vasilek  = $this->client('ОсОО «Василёк» ' . uniqid());

        $this->assertContains($romashka->name, $this->found('ромашка'));
        $this->assertContains($romashka->name, $this->found('РОМАШКА'));
        $this->assertContains($vasilek->name, $this->found('василек'));
        $this->assertContains($vasilek->name, $this->found('ВАСИЛЁК'));
    }

    /**
     * Регистр на боевом PostgreSQL проверить нечем: локальный MySQL сравнивает строки
     * без учёта регистра и зелёный тест выше ничего о нём не доказывает. Поэтому
     * проверяем сам запрос: обе стороны сравнения должны приводиться к нижнему регистру.
     */
    public function test_query_lowercases_both_sides(): void
    {
        $sql = Client::search('Ромашка')->toSql();

        $this->assertStringContainsString('lower(', $sql);
        $this->assertStringNotContainsString("name` like", $sql, 'остался прямой LIKE по колонке');
    }

    public function test_words_are_matched_separately(): void
    {
        $client = $this->client('ОсОО «Ромашка» ' . uniqid(), ['inn' => '12345678901234']);

        // Между «ОсОО» и «Ромашка» стоит кавычка, одной подстрокой такое не найти
        $this->assertContains($client->name, $this->found('осоо ромашка'));
        // Слова из разных полей: название и ИНН
        $this->assertContains($client->name, $this->found('ромашка 12345678901234'));
        // Лишнее слово, которого нет нигде, отсекает строку
        $this->assertNotContains($client->name, $this->found('ромашка вертолёт'));
    }

    public function test_spaces_and_dashes_in_inn_are_ignored(): void
    {
        $client = $this->client('ИП Асанов ' . uniqid(), ['inn' => '22334455667788']);

        $this->assertContains($client->name, $this->found('  22334455667788  '));
        $this->assertContains($client->name, $this->found('2233 4455 667788'));
        $this->assertContains($client->name, $this->found('22334455-667788'));
    }

    public function test_finds_by_contact_and_related_person(): void
    {
        $client = $this->client('ОсОО «Тюльпан» ' . uniqid(), [
            'contacts'        => [['type' => 'phone', 'value' => '+996700998877', 'note' => null]],
            'related_persons' => [['name' => 'Иванова Мария', 'inn' => null, 'role' => null, 'note' => null]],
        ]);

        $this->assertContains($client->name, $this->found('700998877'));
        $this->assertContains($client->name, $this->found('иванова'));
    }

    /** Точное совпадение по ИНН должно быть первым, даже если клиент заведён давно. */
    public function test_exact_inn_comes_first(): void
    {
        $old = $this->client('ОсОО «Точный ИНН» ' . uniqid(), ['inn' => '99887766554433']);
        $old->forceFill(['created_at' => now()->subYear()])->saveQuietly();

        // Свежий клиент, у которого те же цифры попали в название: по отбору он проходит,
        // но наверх должен встать тот, у кого совпал сам ИНН.
        $this->client('ОсОО «Договор 99887766554433» ' . uniqid());

        $this->assertSame($old->name, $this->found('99887766554433')[0] ?? null);
    }

    /** По ссылке /clients?search=... поле поиска должно быть заполнено, иначе фильтры его теряют. */
    public function test_search_from_url_lands_in_the_input(): void
    {
        $client = $this->client('ОсОО «Ссылка» ' . uniqid());

        $this->actingAs($this->admin, 'employee')
            ->get(route('clients.index', ['search' => 'ссылка']))
            ->assertOk()
            ->assertSee("searchQuery: 'ссылка'", false)
            ->assertSee($client->name);
    }
}
