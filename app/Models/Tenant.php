<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Аккаунт — бухфирма, которая пользуется системой. В интерфейсе так и зовём,
 * «аккаунт»: слово «компания» занято, им UI называет обслуживаемого клиента.
 *
 * Пока аккаунт один и разделения данных ещё нет — оно включается следующим
 * этапом. Сейчас модель нужна, чтобы было к чему привязывать строки.
 */
class Tenant extends Model
{
    use SoftDeletes;

    public const STATUS_TRIAL     = 'trial';
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = ['name', 'slug', 'status', 'plan', 'settings'];

    protected $casts = ['settings' => 'array'];

    public function employees(): HasMany
    {
        return $this->hasMany(Employee::class);
    }

    public function clients(): HasMany
    {
        return $this->hasMany(Client::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /** Доступ закрыт: не заплатили или нарушение. Данные при этом остаются на месте. */
    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }
}
