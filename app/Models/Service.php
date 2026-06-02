<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Service extends Model
{
    use HasFactory;

    /**
     * Особые условия БП: колонка услуги => [label, client (колонка-триггер у клиента), color].
     * По этому конфигу генерируются: галочки в форме БП, бейджи, подтягивание в смету и секции.
     * Порядок важен — определяет приоритет секции, если у БП несколько условий.
     */
    public const SPECIAL_FLAGS = [
        'is_pvt'                 => ['label' => 'ПВТ',                  'client' => 'pvt_mode',                'color' => 'indigo'],
        'is_pki'                 => ['label' => 'ПКИ',                  'client' => 'pki_mode',                'color' => 'purple'],
        'is_employees'           => ['label' => 'Сотрудники',          'client' => 'has_employees',           'color' => 'emerald'],
        'is_insurance_policy'    => ['label' => 'ИП страховой полис',  'client' => 'has_insurance_policy',    'color' => 'rose'],
        'is_marketplaces'        => ['label' => 'Маркетплейсы',        'client' => 'has_marketplaces',        'color' => 'violet'],
        'is_mbt'                 => ['label' => 'МБТ',                  'client' => 'has_mbt',                 'color' => 'amber'],
        'is_crypto_exchange'     => ['label' => 'Криптообменник',      'client' => 'has_crypto_exchange',     'color' => 'orange'],
        'is_import_eaeu'         => ['label' => 'Импорт ЕАЭС',         'client' => 'import_eaeu',             'color' => 'teal'],
        'is_import_third'        => ['label' => 'Импорт третьи страны','client' => 'import_third_countries',  'color' => 'cyan'],
        'is_export'              => ['label' => 'Экспорт',             'client' => 'has_export',              'color' => 'sky'],
        'is_payment_aggregators' => ['label' => 'Платёжные агрегаторы','client' => 'has_payment_aggregators', 'color' => 'fuchsia'],
        'is_production'          => ['label' => 'Производство',        'client' => 'has_production',          'color' => 'lime'],
        'is_management_report'   => ['label' => 'Управленческий отчёт','client' => 'has_management_report',   'color' => 'slate'],
    ];

    /** Конфиг условий в виде упорядоченного списка для JSON/вью. */
    public static function specialFlagsList(): array
    {
        return collect(self::SPECIAL_FLAGS)
            ->map(fn($cfg, $key) => ['key' => $key] + $cfg)
            ->values()
            ->all();
    }

    protected $fillable = [
        'parent_id',
        'name',
        'description',
        'sphere',
        'service_group',
        'business_process',
        'category',
        'cost',
        'pricing_rules',
        'periodicity',
        'due_day',
        'deadline_days',
        'execution_minutes',
        'closing_rule',
        'check_type',
        'billing',
        'comment',
        'is_active',
        'allows_quantity',
        'is_pvt',
        'is_pki',
        'is_employees',
        'is_insurance_policy',
        'is_marketplaces',
        'is_mbt',
        'is_crypto_exchange',
        'is_import_eaeu',
        'is_import_third',
        'is_export',
        'is_payment_aggregators',
        'is_production',
        'is_management_report',
        'sort_order',
    ];

    protected $casts = [
        'cost' => 'decimal:2',
        'pricing_rules' => 'array',
        'is_active' => 'boolean',
        'allows_quantity' => 'boolean',
        'is_pvt' => 'boolean',
        'is_pki' => 'boolean',
        'is_employees' => 'boolean',
        'is_insurance_policy' => 'boolean',
        'is_marketplaces' => 'boolean',
        'is_mbt' => 'boolean',
        'is_crypto_exchange' => 'boolean',
        'is_import_eaeu' => 'boolean',
        'is_import_third' => 'boolean',
        'is_export' => 'boolean',
        'is_payment_aggregators' => 'boolean',
        'is_production' => 'boolean',
        'is_management_report' => 'boolean',
        'due_day' => 'integer',
        'deadline_days' => 'integer',
        'execution_minutes' => 'integer',
    ];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Service::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Service::class, 'parent_id')->orderBy('sort_order')->orderBy('name');
    }

    public function tariffs(): BelongsToMany
    {
        return $this->belongsToMany(Tariff::class)
            ->withPivot('free_limit', 'price_override');
    }

    public function taxSystems(): BelongsToMany
    {
        return $this->belongsToMany(TaxSystem::class, 'service_tax_system');
    }

    public function scopeRoots($query)
    {
        return $query->whereNull('parent_id');
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered($query)
    {
        return $query->orderBy('sort_order')->orderBy('name');
    }

    public function getFormattedCostAttribute(): string
    {
        return number_format($this->cost, 0, ',', ' ') . ' сом';
    }
}
