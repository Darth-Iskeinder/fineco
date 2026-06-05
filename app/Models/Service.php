<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
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
        // ПВТ/ПКИ убраны из особых условий — это режимы налогообложения (РН), а не доп. признаки.
        // Скрыто, возможно к удалению (вместе с колонками is_pvt/is_pki, pvt_mode/pki_mode).
        // 'is_pvt'                 => ['label' => 'ПВТ',                  'client' => 'pvt_mode',                'color' => 'indigo'],
        // 'is_pki'                 => ['label' => 'ПКИ',                  'client' => 'pki_mode',                'color' => 'purple'],
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
        'start_month',
        'start_day',
        'deadline_days',
        'execution_minutes',
        'closing_rule',
        'requires_document',
        'check_type',
        'requires_review',
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
        'requires_document' => 'boolean',
        'requires_review' => 'boolean',
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
        'start_month' => 'array',
        'start_day' => 'array',
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

    // =============================================
    // СРОК ВЫПОЛНЕНИЯ (расчёт дат из правила периодичности)
    // =============================================

    /** Кэш name => kind, чтобы не дёргать справочник на каждый БП. */
    protected static array $periodicityKindCache = [];

    /** Тип периодичности (kind) этого БП — резолвится из справочника по имени. */
    public function periodicityKind(): ?string
    {
        $name = $this->periodicity;
        if (!$name) {
            return null;
        }
        if (!array_key_exists($name, static::$periodicityKindCache)) {
            static::$periodicityKindCache[$name] = Periodicity::query()->where('name', $name)->value('kind');
        }
        return static::$periodicityKindCache[$name];
    }

    /**
     * Конкретные даты срока выполнения в диапазоне [$from, $to] по правилу периодичности.
     * Срок = функция от (kind, месяцы, дни); дат может быть несколько.
     *
     * @param  string|null  $kind    monthly|quarterly|yearly|weekly
     * @param  int[]  $months        выбранные месяцы 1–12 (для quarterly/yearly)
     * @param  int[]  $days          monthly/quarterly/yearly → [день месяца]; weekly → дни недели (1=Пн … 7=Вс)
     * @return CarbonImmutable[]      отсортированные уникальные даты (начало дня)
     */
    public static function computeDueDates(?string $kind, array $months, array $days, CarbonInterface $from, CarbonInterface $to): array
    {
        $from = CarbonImmutable::parse($from)->startOfDay();
        $to   = CarbonImmutable::parse($to)->startOfDay();
        if ($kind === null || $from->gt($to)) {
            return [];
        }

        $dates = [];
        $add = function (int $year, int $month, int $day) use (&$dates, $from, $to) {
            // Зажимаем число под длину месяца: 31 в феврале → 28/29
            $day = max(1, min($day, CarbonImmutable::create($year, $month, 1)->daysInMonth));
            $d = CarbonImmutable::create($year, $month, $day)->startOfDay();
            if ($d->gte($from) && $d->lte($to)) {
                $dates[$d->toDateString()] = $d;
            }
        };

        switch ($kind) {
            case 'monthly':
                $day = $days[0] ?? null;
                if ($day === null) {
                    break;
                }
                for ($c = $from->startOfMonth(); $c->lte($to); $c = $c->addMonth()) {
                    $add($c->year, $c->month, (int) $day);
                }
                break;

            case 'quarterly':
            case 'yearly':
                $day = $days[0] ?? null;
                if ($day === null || empty($months)) {
                    break;
                }
                for ($y = $from->year; $y <= $to->year; $y++) {
                    foreach ($months as $m) {
                        $add($y, (int) $m, (int) $day);
                    }
                }
                break;

            case 'weekly':
                if (empty($days)) {
                    break;
                }
                $wanted = array_map('intval', $days); // 1=Пн … 7=Вс (ISO)
                for ($c = $from; $c->lte($to); $c = $c->addDay()) {
                    if (in_array($c->dayOfWeekIso, $wanted, true)) {
                        $dates[$c->toDateString()] = $c->startOfDay();
                    }
                }
                break;
        }

        ksort($dates);
        return array_values($dates);
    }

    /** Даты срока выполнения этого БП в диапазоне [$from, $to]. */
    public function dueDatesBetween(CarbonInterface $from, CarbonInterface $to): array
    {
        return static::computeDueDates(
            $this->periodicityKind(),
            is_array($this->start_month) ? $this->start_month : [],
            is_array($this->start_day) ? $this->start_day : [],
            $from,
            $to,
        );
    }

    /**
     * Подписи срока выполнения для отображения в списке (компактно, по типу периодичности):
     *  - quarterly/yearly → конкретные даты текущего года ['20.03','20.07', …];
     *  - monthly          → ['15 числа'];
     *  - weekly           → ['Вт','Пт'].
     *
     * @return string[]
     */
    public function deadlineLabels(): array
    {
        $kind   = $this->periodicityKind();
        $days   = is_array($this->start_day) ? $this->start_day : [];
        $months = is_array($this->start_month) ? $this->start_month : [];

        switch ($kind) {
            case 'weekly':
                $names = [1 => 'Пн', 2 => 'Вт', 3 => 'Ср', 4 => 'Чт', 5 => 'Пт', 6 => 'Сб', 7 => 'Вс'];
                return array_values(array_map(fn ($d) => $names[(int) $d] ?? (string) $d, $days));

            case 'monthly':
                return isset($days[0]) ? [$days[0] . ' числа'] : [];

            case 'quarterly':
            case 'yearly':
                return array_map(
                    fn ($d) => $d->format('d.m'),
                    $this->dueDatesBetween(now()->startOfYear(), now()->endOfYear()),
                );
        }

        return [];
    }
}
