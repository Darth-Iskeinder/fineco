<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Carbon\CarbonImmutable;
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
        'serves_accounting',
        'serves_tax',
        'serves_payroll',
        'price',
        'tariff_id',
        'responsible_employee_id',
        'contract_with',
        'service_start_date',
        'service_end_date',
        'tasks_start_from',
        'contract_url',
        'founding_docs_urls',
        'requisites_url',
        // Доверенность
        'power_of_attorney_name',
        'power_of_attorney_expires',
        // ЭЦП и доступы
        'eds_password',
        'eds_expires',
        'tunduk_password',
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

    /**
     * Новый клиент заводится на полном обслуживании: все три отметки.
     * То же значение стоит умолчанием у колонок в базе — держим их согласованными,
     * чтобы модель в памяти и свежая запись показывали одно и то же.
     */
    protected $attributes = [
        'serves_accounting' => true,
        'serves_tax' => true,
        'serves_payroll' => true,
    ];

    /**
     * Смену режима налогообложения запоминаем сами: по ней смета показывает
     * напоминание пройтись по составу БП, потому что само ничего не переключается.
     *
     * Пишем в модели, а не в контроллерах: смену делают из карточки, из попапа на
     * списке клиентов и из импорта, и забыть одно из мест слишком легко.
     */
    protected static function booted(): void
    {
        static::updating(function (Client $client) {
            if (!$client->isDirty('tax_system_id')) {
                return;
            }

            $previous = $client->getOriginal('tax_system_id');

            // Первое заполнение режима сменой не считаем: «нет → ОСНО» никого
            // ни о чём не предупреждает, у такого клиента и сметы обычно ещё нет.
            if (!$previous) {
                return;
            }

            $client->previous_tax_system_id = $previous;
            $client->tax_system_changed_at  = CarbonImmutable::now()->startOfDay();
        });
    }

    protected $casts = [
        'is_active' => 'boolean',
        'its_enabled' => 'boolean',
        // Boolean флаги
        'is_zero_movement' => 'boolean',
        'serves_accounting' => 'boolean',
        'serves_tax' => 'boolean',
        'serves_payroll' => 'boolean',
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
        'tasks_start_from' => 'date',
        'power_of_attorney_expires' => 'date',
        'eds_expires' => 'date',
        'tax_system_changed_at' => 'date',
        // JSON поля
        'power_of_attorney_name' => 'array',
        'founding_docs_urls' => 'array',
        'branches' => 'array',
        'contacts' => 'array',
        'related_persons' => 'array',
        'extra_fields' => 'array',
        // Зашифрованные поля (пароли и учётные данные)
        'eds_password' => 'encrypted',
        'tunduk_password' => 'encrypted',
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

    /**
     * Что мы ведём у клиента: тип обслуживания => колонка с отметкой.
     * Названия типов общие с каталогом БП, лежат в Service::SERVICE_TYPES.
     *
     * Полное обслуживание — это все три отметки; отдельного значения под него нет.
     * Ни одной отметки означает то же самое, что все три: не сузили ничего.
     */
    public const SERVICE_SCOPE_COLUMNS = [
        'accounting' => 'serves_accounting',
        'tax'        => 'serves_tax',
        'payroll'    => 'serves_payroll',
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

    /** Режим, который был у клиента до последней смены. */
    public function previousTaxSystem(): BelongsTo
    {
        return $this->belongsTo(TaxSystem::class, 'previous_tax_system_id');
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
        $relation = $this->belongsToMany(Employee::class, 'client_employee')->withTimestamps();

        return $this->tenant_id
            ? $relation->withPivotValue('tenant_id', $this->tenant_id)
            : $relation;
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

    /**
     * Компании, которые сотрудник вправе видеть.
     *
     * Админ и руководитель видят всех — как и в остальных модулях. Остальные видят
     * только те компании, к которым прикреплены, а прикрепление оформляется тремя
     * способами (тот же перечень, что в профиле сотрудника,
     * EmployeeController::clientsOfEmployee):
     *   - ответственное лицо клиента (clients.responsible_employee_id);
     *   - исполнитель БП в смете (estimate_items.assignee_id);
     *   - сотрудник в команде клиента (client_employee).
     *
     * Следствие: компания без ответственного и без команды не попадает никому,
     * кроме админа и руководителя, — так и договорились.
     */
    public function scopeVisibleTo($query, Employee $employee)
    {
        if ($employee->isAdmin() || $employee->isManager()) {
            return $query;
        }

        return $query->where(function ($q) use ($employee) {
            $q->where('responsible_employee_id', $employee->id)
                ->orWhereHas('employees', fn ($e) => $e->where('employees.id', $employee->id))
                ->orWhereHas('estimates.items', fn ($i) => $i->where('assignee_id', $employee->id));
        });
    }

    /** Тот же вопрос про одну компанию — для проверок доступа в контроллерах. */
    public function isVisibleTo(Employee $employee): bool
    {
        if ($employee->isAdmin() || $employee->isManager()) {
            return true;
        }

        return static::query()->whereKey($this->id)->visibleTo($employee)->exists();
    }

    /** Заводить, импортировать и удалять компании может только админ и руководитель. */
    public static function canBeManagedBy(Employee $employee): bool
    {
        return $employee->isAdmin() || $employee->isManager();
    }

    /**
     * Последний день, за который клиенту ещё положены задачи по смете.
     *
     * Обслуживание — это отрезок: `service_start_date` уже работает нижней
     * границей, это его верхняя. Дату проставляет завершающий статус
     * (ClientController: `closes_service`), поэтому у действующих клиентов здесь
     * null — и окно задач у них остаётся ровно таким, каким было.
     *
     * Смотрим именно на дату, а не на `is_active`: флаг не помнит, когда его
     * сняли, и по нему пришлось бы разом спрятать всю накопленную просрочку.
     * По дате незакрытые хвосты внутри периода обслуживания остаются на виду,
     * а на будущее новых задач не появляется.
     */
    public function serviceEndsAt(): ?CarbonImmutable
    {
        return $this->service_end_date
            ? CarbonImmutable::parse($this->service_end_date)->endOfDay()
            : null;
    }

    /**
     * Первый день, за который клиенту снова положены задачи по смете.
     *
     * Ставится при возврате в работу после перерыва (`serviceResumeAttributes`)
     * и работает нижней границей окна: сроки раньше неё не считает ни генератор
     * напоминаний, ни живой список бухзадачника.
     *
     * Без неё возврат означал бы обвал просрочки за весь простой. Ни генератор,
     * ни список памяти не имеют: каждый прогон они пересчитывают сроки по смете
     * заново, а снятая верхняя граница открывает им весь перерыв разом.
     *
     * Пусто у всех, кто ни разу не останавливался: окно у них прежнее.
     */
    public function tasksStartFrom(): ?CarbonImmutable
    {
        return $this->tasks_start_from
            ? CarbonImmutable::parse($this->tasks_start_from)->startOfDay()
            : null;
    }

    /** Обслуживание сейчас не идёт: клиент на паузе или завершён. */
    public function serviceIsStopped(): bool
    {
        return !$this->is_active || $this->service_end_date !== null;
    }

    /**
     * Поля клиента, обслуживание которого останавливают.
     *
     * Дата остановки закрывает окно сверху: задачи со сроком после неё не
     * заводятся, а незакрытые хвосты до неё остаются на виду.
     *
     * @return array<string, mixed>
     */
    public static function serviceStopAttributes(?string $stoppedAt = null): array
    {
        return [
            'service_end_date' => $stoppedAt ?: now()->toDateString(),
            'is_active'        => false,
        ];
    }

    /**
     * Поля клиента, которого возвращают в работу.
     *
     * Верхнюю границу снимаем, нижнюю поднимаем на месяц вперёд: месяц возврата
     * холостой, как у только что добавленного в смету БП. За перерыв не появится
     * ничего, а первые задачи пойдут с первого числа следующего месяца.
     *
     * Границу двигаем только тому, кто действительно стоял: у работающего
     * клиента возврат статуса ничего не меняет.
     *
     * @return array<string, mixed>
     */
    public function serviceResumeAttributes(): array
    {
        $attributes = [
            'service_end_date' => null,
            'is_active'        => true,
        ];

        if ($this->serviceIsStopped()) {
            $attributes['tasks_start_from'] = self::tasksStartAfterResume()->toDateString();
        }

        return $attributes;
    }

    /** С какого дня пойдут задачи у клиента, которого возвращают прямо сейчас. */
    public static function tasksStartAfterResume(): CarbonImmutable
    {
        // Первое число, потом месяц: иначе возврат 31 числа уехал бы через месяц.
        return CarbonImmutable::now()->startOfMonth()->addMonth();
    }

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

    /**
     * Ключи фильтров списка клиентов — общий словарь для страницы, живого поиска
     * и выгрузки CSV. Контроллеры берут из запроса ровно их: $request->only(Client::FILTER_KEYS).
     */
    public const FILTER_KEYS = ['search', 'responsible', 'tax_system', 'status', 'organization_form'];

    /**
     * Фильтры списка клиентов. Один источник правды для страницы, /clients/search и
     * экспорта — иначе выгрузка разойдётся с тем, что человек видит на экране
     * («нашёл двенадцать, скачал триста сорок»).
     *
     * Пустые значения игнорируются. У селектов есть отдельное значение 'none' —
     * «не указан» (клиент без ответственного или без РН виден только так: задачи
     * по нему никуда не идут, а в смету ничего не подтягивается).
     */
    public function scopeFilter($query, array $filters)
    {
        $query->search($filters['search'] ?? null);

        $byId = function (string $key, string $column) use ($query, $filters) {
            $value = $filters[$key] ?? null;
            if ($value === 'none') {
                $query->whereNull($column);
            } elseif (is_numeric($value)) {
                $query->where($column, (int) $value);
            }
        };

        $byId('responsible', 'responsible_employee_id');
        $byId('tax_system', 'tax_system_id');
        $byId('organization_form', 'organization_form_id');

        // Статус клиента — id из справочника, как у остальных селектов. Старые
        // значения 'active' и 'inactive' продолжают работать: они лежат в
        // сохранённых ссылках, а по флагу «неактивен» это приостановленные и
        // завершённые вместе.
        $status = $filters['status'] ?? null;
        if (in_array($status, ['active', 'inactive'], true)) {
            $query->where('is_active', $status === 'active');
        } else {
            $byId('status', 'client_status_id');
        }

        return $query;
    }

    // =============================================
    // ТИП ОБСЛУЖИВАНИЯ
    // =============================================

    /**
     * Отмеченные типы обслуживания: ['tax', 'payroll'].
     * Пустой массив — не отмечено ничего, то есть сужения нет.
     */
    public function serviceTypeKeys(): array
    {
        return collect(self::SERVICE_SCOPE_COLUMNS)
            ->filter(fn ($column) => (bool) $this->{$column})
            ->keys()
            ->all();
    }

    /**
     * Ведём ли клиента целиком: отмечено всё или не отмечено ничего.
     *
     * Оба случая означают одно и то же и дают одинаковое поведение: БП любого
     * типа проходит. «Не отмечено ничего» — это состояние всех клиентов до того,
     * как теги начали проставлять, поэтому старые клиенты работают как раньше.
     */
    public function servesEverything(): bool
    {
        $selected = count($this->serviceTypeKeys());

        return $selected === 0 || $selected === count(self::SERVICE_SCOPE_COLUMNS);
    }

    /** Названия отмеченных типов для показа. */
    public function serviceTypeLabels(): array
    {
        return array_map(
            fn ($key) => Service::SERVICE_TYPES[$key] ?? $key,
            $this->serviceTypeKeys(),
        );
    }

    // =============================================
    // АКСЕССОРЫ
    // =============================================
    public function getOwnershipFormLabelAttribute(): string
    {
        return self::$ownershipForms[$this->ownership_form] ?? $this->ownership_form ?? '—';
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
     * До какого дня в смете висит напоминание о смене режима налогообложения.
     *
     * Не меньше двух недель и не раньше начала следующего месяца: задачи по
     * обновлённой смете пойдут с 1 числа, и до этого дня напоминание нужно.
     * Смена 3 августа держит его до 1 сентября, смена 30 августа до 13 сентября.
     */
    public function taxSystemNoticeUntil(): ?CarbonImmutable
    {
        if (!$this->tax_system_changed_at) {
            return null;
        }

        $changed   = CarbonImmutable::parse($this->tax_system_changed_at)->startOfDay();
        $twoWeeks  = $changed->addDays(14);
        $nextMonth = $changed->startOfMonth()->addMonth();

        return $twoWeeks->gt($nextMonth) ? $twoWeeks : $nextMonth;
    }

    /** Показывать ли в смете напоминание о смене режима. */
    public function showsTaxSystemNotice(): bool
    {
        $until = $this->taxSystemNoticeUntil();

        return $until !== null && CarbonImmutable::now()->startOfDay()->lt($until);
    }

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
