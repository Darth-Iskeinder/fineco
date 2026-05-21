<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Client extends Model
{
    use HasFactory, SoftDeletes;

    protected $fillable = [
        // Основная информация
        'name',
        'ownership_form',
        'inn',
        'director_inn',
        'activity_type',
        'activity_type_id',
        'tax_office_code',
        // Налоговые данные
        'tax_system_id',
        'accounting_method',
        'taxpayer_category',
        // Договор и обслуживание
        'service_type',
        'price',
        'tariff_id',
        'contract_with',
        'service_start_date',
        'service_end_date',
        'contract_url',
        'founding_docs_urls',
        'requisites_url',
        // Доверенность
        'power_of_attorney_name',
        'power_of_attorney_expires',
        // ЭЦП и доступы
        'eds_password',
        'eds_expires',
        'cabinet_credentials',
        'esf_user_credentials',
        'ettn_user_credentials',
        // ИТС
        'its_enabled',
        'connection_type',
        'its_credentials',
        'database_path',
        'onec_connect_credentials',
        'its_contact',
        // Банки
        'bank_credentials',
        // Прочее
        'is_active',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'its_enabled' => 'boolean',
        'price' => 'decimal:2',
        // Даты
        'service_start_date' => 'date',
        'service_end_date' => 'date',
        'power_of_attorney_expires' => 'date',
        'eds_expires' => 'date',
        // JSON поля
        'founding_docs_urls' => 'array',
        // Зашифрованные поля (пароли и учётные данные)
        'eds_password' => 'encrypted',
        'cabinet_credentials' => 'encrypted:array',
        'esf_user_credentials' => 'encrypted:array',
        'ettn_user_credentials' => 'encrypted:array',
        'its_credentials' => 'encrypted:array',
        'onec_connect_credentials' => 'encrypted:array',
        'bank_credentials' => 'encrypted:array',
    ];

    // =============================================
    // КОНСТАНТЫ - Формы собственности
    // =============================================
    const OWNERSHIP_IP = 'ip';
    const OWNERSHIP_OOO = 'ooo';
    const OWNERSHIP_AO = 'ao';
    const OWNERSHIP_PAO = 'pao';
    const OWNERSHIP_ZAO = 'zao';
    const OWNERSHIP_OTHER = 'other';

    public static array $ownershipForms = [
        self::OWNERSHIP_IP => 'ИП',
        self::OWNERSHIP_OOO => 'ООО',
        self::OWNERSHIP_AO => 'АО',
        self::OWNERSHIP_PAO => 'ПАО',
        self::OWNERSHIP_ZAO => 'ЗАО',
        self::OWNERSHIP_OTHER => 'Другое',
    ];

    // =============================================
    // КОНСТАНТЫ - Типы обслуживания
    // =============================================
    const SERVICE_FULL = 'full';
    const SERVICE_ACCOUNTING = 'accounting';
    const SERVICE_TAX = 'tax';
    const SERVICE_PAYROLL = 'payroll';
    const SERVICE_CONSULTING = 'consulting';

    public static array $serviceTypes = [
        self::SERVICE_FULL => 'Полное обслуживание',
        self::SERVICE_ACCOUNTING => 'Бухгалтерский учёт',
        self::SERVICE_TAX => 'Налоговый учёт',
        self::SERVICE_PAYROLL => 'Расчёт зарплаты',
        self::SERVICE_CONSULTING => 'Консалтинг',
    ];

    // =============================================
    // КОНСТАНТЫ - Метод учёта ДиР
    // =============================================
    const ACCOUNTING_CASH = 'cash';
    const ACCOUNTING_ACCRUAL = 'accrual';

    public static array $accountingMethods = [
        self::ACCOUNTING_CASH => 'Кассовый метод',
        self::ACCOUNTING_ACCRUAL => 'Метод начисления',
    ];

    // =============================================
    // КОНСТАНТЫ - Категория налогоплательщика
    // =============================================
    const TAXPAYER_SMALL = 'small';
    const TAXPAYER_MEDIUM = 'medium';
    const TAXPAYER_LARGE = 'large';

    public static array $taxpayerCategories = [
        self::TAXPAYER_SMALL => 'Малый',
        self::TAXPAYER_MEDIUM => 'Средний',
        self::TAXPAYER_LARGE => 'Крупный',
    ];

    // =============================================
    // КОНСТАНТЫ - Способ подключения ИТС
    // =============================================
    const CONNECTION_LOCAL = 'local';
    const CONNECTION_CLOUD = 'cloud';
    const CONNECTION_RDP = 'rdp';

    public static array $connectionTypes = [
        self::CONNECTION_LOCAL => 'Локальная база',
        self::CONNECTION_CLOUD => 'Облако (1С:Фреш)',
        self::CONNECTION_RDP => 'Удалённый рабочий стол (RDP)',
    ];

    // =============================================
    // СВЯЗИ
    // =============================================
    public function taxSystem(): BelongsTo
    {
        return $this->belongsTo(TaxSystem::class);
    }

    public function activityType(): BelongsTo
    {
        return $this->belongsTo(ActivityType::class);
    }

    public function tariff(): BelongsTo
    {
        return $this->belongsTo(Tariff::class);
    }

    public function estimate(): HasOne
    {
        return $this->hasOne(Estimate::class)->latestOfMany('id');
    }

    public function estimates(): HasMany
    {
        return $this->hasMany(Estimate::class);
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'client_employee')
            ->withTimestamps();
    }

    // =============================================
    // СКОУПЫ
    // =============================================
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive($query)
    {
        return $query->where('is_active', false);
    }

    public function scopeWithIts($query)
    {
        return $query->where('its_enabled', true);
    }

    public function scopeEdsExpiringSoon($query, int $days = 30)
    {
        return $query->whereNotNull('eds_expires')
            ->where('eds_expires', '<=', now()->addDays($days))
            ->where('eds_expires', '>=', now());
    }

    public function scopeSearch($query, ?string $search)
    {
        if (!$search) {
            return $query;
        }

        return $query->where(function ($q) use ($search) {
            $q->where('name', 'like', "%{$search}%")
              ->orWhere('inn', 'like', "%{$search}%")
              ->orWhere('director_inn', 'like', "%{$search}%");
        });
    }

    // =============================================
    // АКСЕССОРЫ
    // =============================================
    public function getOwnershipFormLabelAttribute(): string
    {
        return self::$ownershipForms[$this->ownership_form] ?? $this->ownership_form ?? '—';
    }

    public function getServiceTypeLabelAttribute(): string
    {
        return self::$serviceTypes[$this->service_type] ?? $this->service_type ?? '—';
    }

    public function getAccountingMethodLabelAttribute(): string
    {
        return self::$accountingMethods[$this->accounting_method] ?? $this->accounting_method ?? '—';
    }

    public function getTaxpayerCategoryLabelAttribute(): string
    {
        return self::$taxpayerCategories[$this->taxpayer_category] ?? $this->taxpayer_category ?? '—';
    }

    public function getConnectionTypeLabelAttribute(): string
    {
        return self::$connectionTypes[$this->connection_type] ?? $this->connection_type ?? '—';
    }

    public function getFormattedPriceAttribute(): string
    {
        return $this->price ? number_format($this->price, 0, ',', ' ') . ' сом' : '—';
    }

    public function getStatusLabelAttribute(): string
    {
        return $this->is_active ? 'Активен' : 'Неактивен';
    }

    // =============================================
    // МЕТОДЫ
    // =============================================

    /**
     * Проверка истекает ли ЭЦП в ближайшие N дней
     */
    public function isEdsExpiringSoon(int $days = 30): bool
    {
        if (!$this->eds_expires) {
            return false;
        }

        return $this->eds_expires->isBetween(now(), now()->addDays($days));
    }

    /**
     * Проверка истёк ли срок ЭЦП
     */
    public function isEdsExpired(): bool
    {
        if (!$this->eds_expires) {
            return false;
        }

        return $this->eds_expires->isPast();
    }

    /**
     * Проверка истекает ли доверенность в ближайшие N дней
     */
    public function isPowerOfAttorneyExpiringSoon(int $days = 30): bool
    {
        if (!$this->power_of_attorney_expires) {
            return false;
        }

        return $this->power_of_attorney_expires->isBetween(now(), now()->addDays($days));
    }

    /**
     * Получить список ответственных сотрудников (имена)
     */
    public function getResponsibleNamesAttribute(): string
    {
        return $this->employees->pluck('full_name')->join(', ') ?: '—';
    }
}
