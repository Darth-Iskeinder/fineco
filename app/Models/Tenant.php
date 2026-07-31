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

    /** Служебный аккаунт-образец: в него не входят, из него копируют. */
    public const STATUS_TEMPLATE = 'template';

    protected $fillable = ['name', 'slug', 'status', 'plan', 'settings', 'is_template'];

    protected $casts = [
        'settings'    => 'array',
        'is_template' => 'boolean',
    ];

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

    /** Образец, из которого новые аккаунты получают стартовый набор. */
    public function isTemplate(): bool
    {
        return (bool) $this->is_template;
    }

    /** Аккаунт-образец. Он один; если их окажется больше — это ошибка данных. */
    public function scopeTemplate($query)
    {
        return $query->where('is_template', true);
    }

    /** Живые аккаунты фирм — всё, кроме образца. */
    public function scopeReal($query)
    {
        return $query->where('is_template', false);
    }

    /** Доступ закрыт: не заплатили или нарушение. Данные при этом остаются на месте. */
    public function isSuspended(): bool
    {
        return $this->status === self::STATUS_SUSPENDED;
    }
}
