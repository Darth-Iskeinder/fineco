<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;

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

    /** Ключ в settings: страница автоаудита открыта руководителю фирмы. */
    public const SETTING_AUTO_AUDIT = 'auto_audit';

    /** Ключ в settings: руководитель фирмы видит ответы по находкам автоаудита. */
    public const SETTING_AUTO_AUDIT_FINDINGS = 'auto_audit_findings';

    /** Ключ в settings: первый отчётный месяц, который автоаудит проверяет в фирме, '2026-09'. */
    public const SETTING_AUTO_AUDIT_FROM = 'auto_audit_from';

    /** Ключ в settings: фирма идёт в ночной прогон автоаудита (autoaudit:run --all). */
    public const SETTING_AUTO_AUDIT_NIGHTLY = 'auto_audit_nightly';

    /** Ключ в settings: свои счета ОСВ у проверок автоаудита, см. autoAuditAccounts(). */
    public const SETTING_AUTO_AUDIT_ACCOUNTS = 'auto_audit_accounts';

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

    /**
     * Видит ли руководитель фирмы страницу автоаудита.
     *
     * Канарейка: открываем фирмам по одной, начиная с Fineco. Включает и выключает
     * вендор командой autoaudit:access, без выкатки кода.
     */
    public function autoAuditEnabled(): bool
    {
        return (bool) ($this->settings[self::SETTING_AUTO_AUDIT] ?? false);
    }

    /**
     * Меняем только свой ключ: в settings могут лежать и другие настройки фирмы,
     * их нельзя затереть.
     */
    public function setAutoAuditEnabled(bool $enabled): void
    {
        $this->settings = array_merge($this->settings ?? [], [self::SETTING_AUTO_AUDIT => $enabled]);
        $this->save();
    }

    /**
     * Видит ли руководитель фирмы ответы по находкам автоаудита и кнопки «Принять» и
     * «Не принято». Включаем вместе с тем, как бухгалтеры начнут отвечать, чтобы
     * руководитель не смотрел на пустую колонку. Вендору, зашедшему в фирму, видно всегда.
     */
    public function autoAuditFindingsEnabled(): bool
    {
        return (bool) ($this->settings[self::SETTING_AUTO_AUDIT_FINDINGS] ?? false);
    }

    public function setAutoAuditFindingsEnabled(bool $enabled): void
    {
        $this->settings = array_merge($this->settings ?? [], [self::SETTING_AUTO_AUDIT_FINDINGS => $enabled]);
        $this->save();
    }

    /**
     * С какого отчётного месяца автоаудит проверяет фирму, '2026-09'; null = все месяцы.
     *
     * Фирма, подключённая позже, не получает в первый же день вопросы бухгалтерам за полгода
     * назад. У Fineco не задан: там автоаудит с самого начала.
     */
    public function autoAuditFrom(): ?string
    {
        return $this->settings[self::SETTING_AUTO_AUDIT_FROM] ?? null;
    }

    public function setAutoAuditFrom(?string $month): void
    {
        $this->settings = array_merge($this->settings ?? [], [self::SETTING_AUTO_AUDIT_FROM => $month]);
        $this->save();
    }

    /**
     * Идёт ли фирма в ночной прогон автоаудита.
     *
     * Отдельный флаг, а не «размечены эталонные БП»: разметка ещё не значит, что фирма готова.
     * Без старта (autoAuditFrom) первый же прогон прошёл бы по всем её прошлым месяцам.
     */
    public function autoAuditNightly(): bool
    {
        return (bool) ($this->settings[self::SETTING_AUTO_AUDIT_NIGHTLY] ?? false);
    }

    public function setAutoAuditNightly(bool $enabled): void
    {
        $this->settings = array_merge($this->settings ?? [], [self::SETTING_AUTO_AUDIT_NIGHTLY => $enabled]);
        $this->save();
    }

    /**
     * Свои счета ОСВ у проверок автоаудита: номер проверки => счета, кто и когда задал.
     *
     * Проверки нет в списке, значит она берёт общий счёт (AutoAuditRunner::RULES). Так у
     * всех фирм, пока им ничего не задали, и поэтому настройка ничего не меняет молча.
     *
     * @return array<int, array{accounts: string[], by: ?string, at: ?string}>
     */
    public function autoAuditAccounts(): array
    {
        $saved = $this->settings[self::SETTING_AUTO_AUDIT_ACCOUNTS] ?? [];
        $rules = [];

        foreach (is_array($saved) ? $saved : [] as $rule => $entry) {
            $accounts = array_values(array_filter(array_map('strval', $entry['accounts'] ?? [])));

            if ($accounts) {
                $rules[(int) $rule] = [
                    'accounts' => $accounts,
                    'by'       => $entry['by'] ?? null,
                    'at'       => $entry['at'] ?? null,
                ];
            }
        }

        return $rules;
    }

    /**
     * Задать проверке свои счета; null или пустой список возвращает общий счёт.
     *
     * Меняем только свою проверку: остальные ключи settings и счета других проверок
     * остаются как были.
     *
     * @param string[]|null $accounts
     */
    public function setAutoAuditAccounts(int $rule, ?array $accounts, ?string $by): void
    {
        $saved = $this->settings[self::SETTING_AUTO_AUDIT_ACCOUNTS] ?? [];
        $saved = is_array($saved) ? $saved : [];

        if ($accounts) {
            $saved[(string) $rule] = [
                'accounts' => array_values($accounts),
                'by'       => $by,
                'at'       => now()->toDateString(),
            ];
        } else {
            unset($saved[(string) $rule]);
        }

        $this->settings = array_merge($this->settings ?? [], [self::SETTING_AUTO_AUDIT_ACCOUNTS => $saved]);
        $this->save();
    }

    /**
     * Фирмы ночного прогона, по порядку номера. Образец и приостановленные не берём, даже
     * с флагом: в образце работы нет, приостановленную никто не смотрит.
     *
     * Флаг отбираем в PHP, а не запросом к JSON: фирм единицы, а запрос к полю settings
     * пишется в MySQL (тесты) и PostgreSQL (бой) по-разному.
     *
     * @return Collection<int, self>
     */
    public static function forNightlyAutoAudit(): Collection
    {
        return self::whereNotIn('status', [self::STATUS_TEMPLATE, self::STATUS_SUSPENDED])
            ->orderBy('id')
            ->get()
            ->filter(fn (self $tenant) => $tenant->autoAuditNightly() && !$tenant->isTemplate())
            ->values();
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
