<?php

namespace Tests\Feature;

use App\Models\Tenant;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Флаг фирмы «руководитель видит автоаудит» и команда, которая его ставит.
 *
 * Главное здесь: без --on и --off команда ничего не меняет, а смена флага не трогает
 * остальные настройки фирмы в том же поле.
 */
class AutoAuditAccessTest extends TestCase
{
    use DatabaseTransactions;

    private Tenant $tenant;

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

        $this->tenant = Tenant::create([
            'name'     => 'Фирма флага ' . uniqid(),
            'slug'     => 'autoaudit-access-' . uniqid(),
            'status'   => Tenant::STATUS_ACTIVE,
            'settings' => ['other' => 'keep me'],
        ]);
    }

    public function test_new_firm_has_the_page_closed(): void
    {
        $this->assertFalse($this->tenant->autoAuditEnabled());
    }

    public function test_without_on_or_off_it_only_shows_the_state(): void
    {
        $this->artisan('autoaudit:access', ['--tenant' => $this->tenant->id])
            ->expectsOutputToContain('закрыт')
            ->assertSuccessful();

        $this->assertSame(['other' => 'keep me'], $this->tenant->fresh()->settings);
    }

    public function test_on_opens_and_keeps_other_settings(): void
    {
        $this->artisan('autoaudit:access', ['--tenant' => $this->tenant->id, '--on' => true])
            ->expectsOutputToContain('открыт')
            ->assertSuccessful();

        $tenant = $this->tenant->fresh();
        $this->assertTrue($tenant->autoAuditEnabled());
        $this->assertSame('keep me', $tenant->settings['other']);
    }

    public function test_repeated_on_and_then_off(): void
    {
        $this->artisan('autoaudit:access', ['--tenant' => $this->tenant->id, '--on' => true])->assertSuccessful();
        $this->artisan('autoaudit:access', ['--tenant' => $this->tenant->id, '--on' => true])->assertSuccessful();
        $this->assertTrue($this->tenant->fresh()->autoAuditEnabled());

        $this->artisan('autoaudit:access', ['--tenant' => $this->tenant->id, '--off' => true])
            ->expectsOutputToContain('закрыт')
            ->assertSuccessful();

        $tenant = $this->tenant->fresh();
        $this->assertFalse($tenant->autoAuditEnabled());
        $this->assertSame('keep me', $tenant->settings['other']);
    }

    public function test_flag_does_not_leak_to_another_firm(): void
    {
        $other = Tenant::create([
            'name'   => 'Соседняя фирма ' . uniqid(),
            'slug'   => 'autoaudit-other-' . uniqid(),
            'status' => Tenant::STATUS_ACTIVE,
        ]);

        $this->artisan('autoaudit:access', ['--tenant' => $this->tenant->id, '--on' => true])->assertSuccessful();

        $this->assertFalse($other->fresh()->autoAuditEnabled());
    }

    public function test_refuses_without_firm_unknown_firm_or_both_options(): void
    {
        $this->artisan('autoaudit:access')->assertFailed();
        $this->artisan('autoaudit:access', ['--tenant' => 999999999])->assertFailed();
        $this->artisan('autoaudit:access', ['--tenant' => $this->tenant->id, '--on' => true, '--off' => true])
            ->assertFailed();

        $this->assertFalse($this->tenant->fresh()->autoAuditEnabled());
    }

    /** Флаг находок отдельный: не трогает флаг страницы и остальные настройки. */
    public function test_findings_flag_is_separate(): void
    {
        $this->assertFalse($this->tenant->autoAuditFindingsEnabled());

        $this->artisan('autoaudit:access', ['--tenant' => $this->tenant->id, '--findings-on' => true])
            ->expectsOutputToContain('Ответы по находкам руководителю: видны')
            ->assertSuccessful();

        $tenant = $this->tenant->fresh();
        $this->assertTrue($tenant->autoAuditFindingsEnabled());
        $this->assertFalse($tenant->autoAuditEnabled());
        $this->assertSame('keep me', $tenant->settings['other']);

        $this->artisan('autoaudit:access', ['--tenant' => $this->tenant->id, '--on' => true])->assertSuccessful();
        $this->artisan('autoaudit:access', ['--tenant' => $this->tenant->id, '--findings-off' => true])
            ->expectsOutputToContain('скрыты')
            ->assertSuccessful();

        $tenant = $this->tenant->fresh();
        $this->assertFalse($tenant->autoAuditFindingsEnabled());
        $this->assertTrue($tenant->autoAuditEnabled());

        $this->artisan('autoaudit:access', ['--tenant' => $this->tenant->id, '--findings-on' => true, '--findings-off' => true])
            ->assertFailed();
        $this->assertFalse($this->tenant->fresh()->autoAuditFindingsEnabled());
    }
}
