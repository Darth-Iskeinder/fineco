<?php

namespace Tests;

use App\Models\Tenant;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Каждый тест идёт от имени первой живой фирмы — так же, как в жизни любой
     * запрос идёт от имени вошедшего сотрудника, а у него фирма всегда есть.
     *
     * Раньше фирму подставляла подпорка в базе (значение по умолчанию), и тесты
     * про неё не знали. Подпорку убрали: строка без хозяина не должна появляться
     * никакими путями, в том числе из теста.
     *
     * Тесты, которым нужно поведение между фирмами, меняют контекст сами —
     * см. TenantIsolationTest.
     */
    protected function setUp(): void
    {
        parent::setUp();

        if ($tenant = TenantContext::withoutTenant(fn () => Tenant::real()->orderBy('id')->first())) {
            TenantContext::set($tenant);
        }
    }

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }
}
