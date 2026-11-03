<?php

namespace App\Services\Settlements;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Module;
use App\Models\Tenant;
use App\Support\Impersonation;
use App\Support\TenantContext;

/**
 * Кто видит «Расчёты с клиентами» (договорились 03.11.2026).
 *
 * Флаг фирмы (settlements:access) главный выключатель. Пока он выключен, раздел видит
 * только вендор, зашедший в фирму, а модуля нет ни в меню, ни в карточке сотрудника.
 *
 * Флаг включён: раздел это обычный модуль. Админ и руководитель видят его сами,
 * остальные по галочке модуля в карточке сотрудника. В Fineco галочку ставят главбухам,
 * в других фирмах её не ставят никому, и раздел остаётся у руководства.
 *
 * Клиенты те же, что сотрудник видит в «Клиентах» (Client::visibleTo): админ и
 * руководитель всех, остальные только своих. По своим клиентам доступ полный.
 */
class SettlementsAccess
{
    /** Открыт ли раздел в этой фирме: вендору всегда, остальным по флагу. */
    public static function openHere(): bool
    {
        if (Impersonation::isActive()) {
            return true;
        }

        return (bool) Tenant::find(TenantContext::id())?->settlementsEnabled();
    }

    /** Может ли сотрудник зайти в раздел. */
    public static function canUse(?Employee $employee): bool
    {
        if (Impersonation::isActive()) {
            return true;
        }

        return $employee !== null
            && self::openHere()
            && $employee->hasAccessToModule(Module::SETTLEMENTS);
    }

    /** Свой ли клиент. Вендор видит то же, что сотрудник, под которым вошёл. */
    public static function canSeeClient(?Employee $employee, Client $client): bool
    {
        return $employee !== null && $client->isVisibleTo($employee);
    }
}
