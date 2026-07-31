<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Models\OrganizationForm;
use App\Models\TaxpayerCategory;
use App\Models\ClientStatus;
use App\Models\ClientDocument;

class Client extends Model
{
    use HasFactory, SoftDeletes, BelongsToTenant;

    protected $fillable = [
        // Основная информация
        'name',
        'ownership_form',
        'organization_form_id',
        'inn',
        'director_inn',
        'activity_type',
        'activity_type_id',
        'tax_office_code',
        // Налоговые данные
        'tax_system_id',
        'accounting_method',
        'taxpayer_category',
        'taxpayer_category_id',
        // Характеристики бизнеса — объём
        'is_zero_movement',
        'has_employees',
        'employees_count',
        'has_kkm',
        'kkm_count',
        'has_marketplaces',
        'marketplaces_count',
        // Характеристики бизнеса — режимы
        'import_eaeu',
        'import_third_countries',
        'has_export',
        'pvt_mode',
        'pki_mode',
        'has_alcohol',
        'has_insurance_policy',
        'has_mbt',
        'has_crypto_exchange',
        'has_payment_aggregators',
        'has_production',
        'has_management_report',
        // Характеристики бизнеса — с количеством
        'has_fixed_assets',
        'fixed_assets_count',
        'has_fuel',
        'fuel_count',
        'has_loans',
        'loans_count',
        'has_branches',
        'branches',
        // Характеристики бизнеса — переключатели
        'has_excise',
        'has_nonresident_services',
        'has_property',
        'has_bank_client',
        'has_separate_books',
        'has_nonstandard_contracts',
        'has_foreign_trade',
        'has_vat_refund',
        'has_special_reporting',
        'has_currency_operations',
        'edo_operator',
        // Договор и обслуживание
        'service_type',
        'price',
        'tariff_id',
        'responsible_employee_id',
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
        // Контакты и связи
        'contacts',
        'related_persons',
        // Дополнительно
        'client_folder_url',
        'access_instructions',
        'extra_fields',
        // Прочее
        'is_active',
        'client_status_id',
        'notes',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'its_enabled' => 'boolean',
        // Boolean флаги
        'is_zero_movement' => 'boolean',
        'has_employees' => 'boolean',
        'has_kkm' => 'boolean',
        'has_marketplaces' => 'boolean',
        'import_eaeu' => 'boolean',
        'import_third_countries' => 'boolean',
        'has_export' => 'boolean',
        'pvt_mode' => 'boolean',
        'pki_mode' => 'boolean',
        'has_alcohol' => 'boolean',
        'has_insurance_policy' => 'boolean',
        'has_mbt' => 'boolean',
        'has_crypto_exchange' => 'boolean',
        'has_payment_aggregators' => 'boolean',
        'has_production' => 'boolean',
        'has_management_report' => 'boolean',
        'has_fixed_assets' => 'boolean',
        'has_fuel' => 'boolean',
        'has_loans' => 'boolean',
        'has_branches' => 'boolean',
        'has_excise' => 'boolean',
        'has_nonresident_services' => 'boolean',
        'has_property' => 'boolean',
        'has_bank_client' => 'boolean',
        'has_separate_books' => 'boolean',
        'has_nonstandard_contracts' => 'boolean',
        'has_foreign_trade' => 'boolean',
        'has_vat_refund' => 'boolean',
        'has_special_reporting' => 'boolean',
        'has_currency_operations' => 'boolean',
        // Числа
        'employees_count' => 'integer',
        'kkm_count' => 'integer',
        'marketplaces_count' => 'integer',
        'fixed_assets_count' => 'integer',
        'fuel_count' => 'integer',
        'loans_count' => 'integer',
        'price' => 'decimal:2',
        // Даты
        'service_start_date' => 'date',
        'service_end_date' => 'date',
        'power_of_attorney_expires' => 'date',
        'eds_expires' => 'date',
        // JSON поля
        'power_of_attorney_name' => 'array',
        'founding_docs_urls' => 'array',
        'branches' => 'array',
        'contacts' => 'array',
        'related_persons' => 'array',
        'extra_fields' => 'array',
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
    public function organizationForm(): BelongsTo
    {
        return $this->belongsTo(OrganizationForm::class);
    }

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

    /**
     * Позиции сметы верхнего уровня. Нужны для индикатора «смета собрана» в списке клиентов:
     * сама запись estimates создаётся уже при открытии страницы сметы (firstOrCreate),
     * поэтому признаком заполненности служит наличие позиций, а не наличие сметы.
     */
    public function estimateRootItems(): HasManyThrough
    {
        return $this->hasManyThrough(EstimateItem::class, Estimate::class)
            ->whereNull('estimate_items.parent_id');
    }

    /** Индивидуальные расписания БП для этого клиента (override дефолтов БП). */
    public function serviceSchedules(): HasMany
    {
        return $this->hasMany(ClientServiceSchedule::class);
    }

    public function employees(): BelongsToMany
    {
        return $this->belongsToMany(Employee::class, 'client_employee')
            ->withTimestamps();
    }

    public function clientStatus(): BelongsTo
    {
        return $this->belongsTo(ClientStatus::class);
    }

    public function taxpayerCategoryModel(): BelongsTo
    {
        return $this->belongsTo(TaxpayerCategory::class, 'taxpayer_category_id');
    }

    public function responsibleEmployee(): BelongsTo
    {
        return $this->belongsTo(Employee::class, 'responsible_employee_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(ClientDocument::class)->orderBy('created_at', 'desc');
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
     * Имя ответственного лица.
     */
    public function getResponsibleNamesAttribute(): string
    {
        return $this->responsibleEmployee?->full_name ?: '—';
    }
}
