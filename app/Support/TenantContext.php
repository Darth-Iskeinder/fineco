<?php

namespace App\Support;

use App\Models\Tenant;

/**
 * Чья фирма сейчас работает.
 *
 * Ставится один раз за запрос — из авторизованного сотрудника (middleware
 * SetTenantContext). Дальше на него опирается трейт BelongsToTenant: каждый
 * запрос к базе получает фильтр «только эта фирма», а новая строка — пометку.
 *
 * Контроллеры сюда не лезут и tenant_id у сотрудника напрямую не читают:
 * если однажды один человек сможет работать в нескольких фирмах, поменять
 * придётся только это место.
 *
 * Пока контекст не задан (консоль, крон, момент авторизации) фильтр НЕ
 * применяется — поведение как до разделения. Строгую проверку «нет контекста →
 * ошибка» включаем отдельным шагом, после того как научим воркеры выбирать
 * фирму явно. Иначе ночная генерация задач упала бы молча.
 */
class TenantContext
{
    private static ?int $tenantId = null;

    public static function set(int|Tenant|null $tenant): void
    {
        self::$tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;
    }

    public static function id(): ?int
    {
        return self::$tenantId;
    }

    public static function has(): bool
    {
        return self::$tenantId !== null;
    }

    public static function forget(): void
    {
        self::$tenantId = null;
    }

    /** Выполнить кусок работы от имени конкретной фирмы и вернуть контекст как был. */
    public static function for(int|Tenant $tenant, callable $callback): mixed
    {
        $previous = self::$tenantId;
        self::set($tenant);

        try {
            return $callback();
        } finally {
            self::$tenantId = $previous;
        }
    }
}
