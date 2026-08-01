<?php

namespace Database\Seeders;

use App\Models\Tenant;
use App\Support\TenantContext;

/**
 * Сидер работает с данными фирмы, значит фирму надо назвать.
 *
 * В терминале вошедшего сотрудника нет, и без явного указания запрос ушёл бы
 * по всей базе. Берём первый живой аккаунт — при установке с нуля он один,
 * его создаёт миграция вместе с таблицей аккаунтов.
 */
trait RunsInFirstTenant
{
    protected function inFirstTenant(callable $callback): mixed
    {
        $tenant = TenantContext::withoutTenant(fn () => Tenant::real()->orderBy('id')->first());

        if (!$tenant) {
            $this->command?->error('Нет ни одного аккаунта — сначала запустите миграции.');

            return null;
        }

        return TenantContext::for($tenant, $callback);
    }
}
