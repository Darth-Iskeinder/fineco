<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientInvoice;
use App\Models\ClientSettlementComment;
use App\Models\Employee;
use App\Models\Module;
use App\Models\Role;
use App\Models\Tenant;
use App\Support\TenantContext;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Комментарий про деньги клиента в «Расчётах с клиентами» (09.11.2026): один на клиента,
 * без истории, правится из списка (JSON) и со страницы клиента (форма).
 */
class ClientSettlementCommentTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;
    private Employee $manager;

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

        Carbon::setTestNow('2026-11-09 10:00:00');

        $this->tenant = Tenant::create([
            'name'   => 'Фирма комментариев ' . uniqid(),
            'slug'   => 'comments-' . uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);
        TenantContext::set($this->tenant);
        $this->tenant->setSettlementsEnabled(true);
        $this->tenant->save();

        $this->manager = $this->employee(Role::MANAGER, 'Эркин Руководитель');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_comment_from_list_is_saved_shown_and_searchable(): void
    {
        $client = $this->client('Ромашка');

        $this->actingAs($this->manager, 'employee')
            ->postJson(route('settlements.comment', $client), ['comment' => "  Платит наличными\nв конце месяца  "])
            ->assertOk()
            ->assertJson(['comment' => "Платит наличными\nв конце месяца", 'signature' => 'Эркин Руководитель, 09.11.2026']);

        $page = $this->actingAs($this->manager, 'employee')->get(route('settlements.index'))->assertOk();
        $page->assertSee('Комментарий');
        $meta = collect($page->viewData('comments'));
        $this->assertSame("Платит наличными\nв конце месяца", $meta[$client->id]->body);
        // Поиск в списке идёт и по комментарию
        $page->assertSee('платит наличными', false);
    }

    public function test_same_text_keeps_author_and_empty_text_removes(): void
    {
        $client = $this->client('Василёк');
        $other  = $this->employee(Role::ADMIN, 'Другой Админ');

        $this->actingAs($this->manager, 'employee')
            ->postJson(route('settlements.comment', $client), ['comment' => 'Обещал до 20-го'])->assertOk();

        Carbon::setTestNow('2026-11-12 10:00:00');
        $this->actingAs($other, 'employee')
            ->postJson(route('settlements.comment', $client), ['comment' => 'Обещал до 20-го'])
            ->assertJson(['signature' => 'Эркин Руководитель, 09.11.2026']);

        $this->actingAs($other, 'employee')
            ->postJson(route('settlements.comment', $client), ['comment' => 'Обещал до 25-го'])
            ->assertJson(['signature' => 'Другой Админ, 12.11.2026']);
        $this->assertSame(1, ClientSettlementComment::where('client_id', $client->id)->count());

        $this->actingAs($other, 'employee')
            ->postJson(route('settlements.comment', $client), ['comment' => '   '])
            ->assertJson(['comment' => '', 'signature' => '']);
        $this->assertSame(0, ClientSettlementComment::where('client_id', $client->id)->count());
    }

    public function test_client_page_form_and_too_long_text(): void
    {
        $client = $this->client('Асанов');

        $this->actingAs($this->manager, 'employee')
            ->post(route('settlements.comment', [$client, 'month' => '2026-10']), ['comment' => 'Скидка 20% до января'])
            ->assertRedirect(route('settlements.show', [$client, 'month' => '2026-10']))
            ->assertSessionHas('success', 'Комментарий сохранён');

        $this->actingAs($this->manager, 'employee')->get(route('settlements.show', $client))
            ->assertOk()->assertSee('Скидка 20% до января')->assertSee('Эркин Руководитель, 09.11.2026');

        $this->actingAs($this->manager, 'employee')
            ->postJson(route('settlements.comment', $client), ['comment' => str_repeat('а', ClientSettlementComment::MAX_LENGTH + 1)])
            ->assertStatus(422)->assertJsonValidationErrors('comment');
        $this->assertSame('Скидка 20% до января', ClientSettlementComment::where('client_id', $client->id)->value('body'));
    }

    public function test_head_accountant_comments_only_own_clients(): void
    {
        $chief = $this->employee(Role::HEAD_ACCOUNTANT, 'Главбух');
        $chief->modules()->sync([(int) Module::where('name', Module::SETTLEMENTS)->value('id')]);
        $own     = $this->client('Свой', ['responsible_employee_id' => $chief->id]);
        $foreign = $this->client('Чужой', ['responsible_employee_id' => $this->manager->id]);

        $this->actingAs($chief->fresh(), 'employee')
            ->postJson(route('settlements.comment', $own), ['comment' => 'Можно'])->assertOk();
        $this->actingAs($chief->fresh(), 'employee')
            ->postJson(route('settlements.comment', $foreign), ['comment' => 'Нельзя'])->assertNotFound();
        $this->assertSame(0, ClientSettlementComment::where('client_id', $foreign->id)->count());

        // Бухгалтер без модуля раздел не видит вовсе
        $accountant = $this->employee(Role::ACCOUNTANT, 'Бухгалтер');
        $this->actingAs($accountant, 'employee')
            ->postJson(route('settlements.comment', $own), ['comment' => 'Нельзя'])->assertNotFound();
    }

    public function test_bulk_window_shows_comment_of_unbilled_client(): void
    {
        $client = $this->client('Без счёта');
        ClientInvoice::create([
            'client_id' => $client->id, 'period' => '2026-10-01', 'number' => 1,
            'issued_on' => '2026-10-01', 'due_on' => '2026-10-11', 'amount' => 5000, 'total' => 5000,
        ]);
        ClientSettlementComment::saveFor($client, 'В ноябре не выставлять, договорились', $this->manager);

        $lines = $this->actingAs($this->manager, 'employee')->get(route('settlements.index'))->viewData('bulkLines');

        $this->assertSame('В ноябре не выставлять, договорились', collect($lines)->firstWhere('id', $client->id)['comment']);
    }

    private function employee(string $role, string $name): Employee
    {
        return Employee::create([
            'full_name' => $name, 'position' => $role, 'password' => 'secret123',
            'email' => 'comments.' . uniqid() . '@example.com',
            'role_id' => Role::where('name', $role)->value('id'),
            'status' => Employee::STATUS_ACTIVE,
        ]);
    }

    private function client(string $name, array $attributes = []): Client
    {
        return Client::create(array_merge([
            'name'      => $name . ' ' . uniqid(),
            'inn'       => '0911120262' . str_pad((string) (++self::$inn), 4, '0', STR_PAD_LEFT),
            'is_active' => true,
        ], $attributes));
    }
}
