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

    protected $fillable = [
        'name', 'slug', 'status', 'plan', 'settings', 'is_template',
        // Профиль фирмы: правится в настройках, уходит в акты и сметы.
        'legal_name', 'logo_path', 'inn', 'address', 'phone', 'email',
        'director_name', 'bank_name', 'bank_account', 'bank_bik',
    ];

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

    /**
     * Ссылка на логотип фирмы; null — фирма его не загрузила.
     *
     * Файл лежит на закрытом диске и отдаётся маршрутом, а не из public: так
     * логотип не зависит от symlink «storage» и не виден посторонним. В хвосте
     * метка времени — иначе браузер продолжит показывать прежнюю картинку.
     */
    public function logoUrl(): ?string
    {
        if (!$this->logo_path) {
            return null;
        }

        return route('company.logo', ['v' => $this->updated_at?->timestamp ?? 1]);
    }

    /**
     * Буква для значка-заглушки, пока фирма не загрузила логотип.
     *
     * Так делают все многофирменные системы: чужой логотип показывать нельзя,
     * пустое место выглядит поломкой, а инициал сразу отвечает «где я».
     */
    public function initial(): string
    {
        $name = trim((string) $this->name);

        return $name === '' ? '?' : mb_strtoupper(mb_substr($name, 0, 1));
    }

    /** Название для документов: полное юридическое, если заполнено. */
    public function documentName(): string
    {
        return $this->legal_name ?: $this->name;
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
