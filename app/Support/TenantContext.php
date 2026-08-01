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

    /** Явно разрешённая работа поверх фирм: копирование образца, супер-админка. */
    private static bool $withoutTenant = false;

    /** Ручное переключение строгости — нужно тестам, в бою не используется. */
    private static ?bool $strictOverride = null;

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

    /**
     * Осознанная работа поверх всех фирм.
     *
     * Нужна там, где это и есть задача: скопировать образец в новый аккаунт,
     * показать вендору список фирм. Оформляется явно, чтобы в коде было видно,
     * кто выходит за пределы своей фирмы — незаметно проскочить нельзя.
     */
    public static function withoutTenant(callable $callback): mixed
    {
        $previous = self::$withoutTenant;
        self::$withoutTenant = true;

        try {
            return $callback();
        } finally {
            self::$withoutTenant = $previous;
        }
    }

    /**
     * Должен ли запрос без указания фирмы падать с ошибкой.
     *
     * В терминале и кроне — да: там нет вошедшего сотрудника, и запрос по всей
     * базе почти всегда означает забытый контекст, а не задумку.
     *
     * В вебе — нет: до авторизации фирма ещё неизвестна (вход ищет сотрудника
     * по почте), а после авторизации контекст ставит middleware, так что дыры
     * не остаётся. В тестах строгость выключена по умолчанию и включается
     * точечно там, где её и проверяют.
     */
    public static function isStrict(): bool
    {
        if (self::$withoutTenant) {
            return false;
        }

        if (self::$strictOverride !== null) {
            return self::$strictOverride;
        }

        return app()->runningInConsole() && !app()->runningUnitTests();
    }

    /** Только для тестов: включить строгость и вернуть как было. */
    public static function strictly(callable $callback): mixed
    {
        $previous = self::$strictOverride;
        self::$strictOverride = true;

        try {
            return $callback();
        } finally {
            self::$strictOverride = $previous;
        }
    }
}
