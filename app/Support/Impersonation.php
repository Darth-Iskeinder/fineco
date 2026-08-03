<?php

namespace App\Support;

use App\Models\Tenant;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

/**
 * «Вендор сейчас работает внутри фирмы».
 *
 * Хранится в сессии, а не в базе: заход внутрь — это состояние одной вкладки
 * браузера, а не факт о фирме.
 *
 * Метки две — какая фирма и когда вендор последний раз что-то делал. Вторая
 * нужна для автовыхода: отошёл от компьютера с открытой чужой фирмой — через
 * полчаса система сама закроет дверь.
 */
class Impersonation
{
    /** Через сколько минут простоя вендора выкидывает из чужой фирмы. */
    public const IDLE_MINUTES = 30;

    private const KEY_TENANT = 'vendor.tenant_id';
    private const KEY_NAME   = 'vendor.tenant_name';
    private const KEY_SEEN   = 'vendor.last_seen';

    public static function start(Tenant $tenant): void
    {
        Session::put(self::KEY_TENANT, $tenant->id);
        Session::put(self::KEY_NAME, $tenant->name);
        self::touch();
    }

    /** Закончить работу внутри фирмы: выйти из неё, вендором остаться. */
    public static function stop(): void
    {
        Auth::guard('employee')->logout();

        Session::forget([self::KEY_TENANT, self::KEY_NAME, self::KEY_SEEN]);
    }

    public static function isActive(): bool
    {
        return Session::has(self::KEY_TENANT);
    }

    public static function tenantId(): ?int
    {
        $id = Session::get(self::KEY_TENANT);

        return $id === null ? null : (int) $id;
    }

    /** Название фирмы для полосы наверху — чтобы не ходить за ним в базу на каждой странице. */
    public static function tenantName(): ?string
    {
        return Session::get(self::KEY_NAME);
    }

    public static function touch(): void
    {
        Session::put(self::KEY_SEEN, now()->timestamp);
    }

    /** Простой дольше отведённого — пора закрывать. */
    public static function isIdleTooLong(): bool
    {
        $seen = Session::get(self::KEY_SEEN);

        if ($seen === null) {
            return true;
        }

        return now()->timestamp - (int) $seen > self::IDLE_MINUTES * 60;
    }
}
