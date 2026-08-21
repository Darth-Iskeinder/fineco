<?php

namespace App\Http\Controllers;

use App\Models\ActivityType;
use App\Models\TaskReminder;
use App\Models\EstimateItem;
use App\Models\ClientServiceSchedule;
use App\Models\BuhAdhocTask;
use App\Models\Billing;
use App\Models\Category;
use App\Models\Periodicity;
use App\Models\Rate;
use App\Models\ServiceGroup;
use App\Models\Sphere;
use App\Models\TaxAuthority;
use App\Models\Service;
use App\Models\Tariff;
use App\Models\TaxSystem;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    public function index()
    {
        return redirect()->route('settings.tax-systems');
    }

    // =============================================
    // ПРОСТЫЕ СПРАВОЧНИКИ (общий паттерн)
    // =============================================

    /**
     * $rowsLocked — спрятать правку и удаление у существующих строк. Нужно там,
     * где список общий для всех аккаунтов: тронуть чужую строку нельзя,
     * а добавить недостающую можно.
     */
    private function lookupView(string $title, string $endpoint, $items, string $description = '', array $fields = [], string $nameLabel = 'Название', bool $rowsLocked = false)
    {
        return view('settings.lookup', compact('title', 'description', 'items', 'fields', 'nameLabel', 'rowsLocked') + [
            'pageTitle'    => $title,
            'baseEndpoint' => $endpoint,
        ]);
    }

    private function lookupStore(Request $request, string $model, array $rules = []): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate(['name' => 'required|string|max:255'] + $rules);
        $item = $model::create($validated);
        return response()->json(['success' => true, 'item' => $item]);
    }

    private function lookupUpdate(Request $request, $record, array $rules = []): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate(['name' => 'required|string|max:255'] + $rules);
        $record->update($validated);
        return response()->json(['success' => true, 'item' => $record->fresh()]);
    }

    private function lookupDestroy($record): \Illuminate\Http\JsonResponse
    {
        $record->delete();
        return response()->json(['success' => true]);
    }

    public function spheresPage()             { return $this->lookupView('Сфера',                       '/settings/spheres',               Sphere::orderBy('name')->get()); }
    public function groupsPage()              { return $this->lookupView('Группа',                      '/settings/groups',                ServiceGroup::orderBy('name')->get()); }
    public function billingsPage()            { return $this->lookupView('Биллинг',                     '/settings/billings',              Billing::orderBy('id')->get()); }
    public function taxAuthoritiesPage()      { return $this->lookupView('Коды налоговых органов',      '/settings/tax-authorities',       TaxAuthority::orderBy('code')->get(), 'Справочник кодов районных ГНС, общий для всех компаний. Используется при добавлении филиалов в карточке клиента. Изменить или удалить существующую запись нельзя, недостающую можно добавить.', [
        ['key' => 'code', 'label' => 'Код районной ГНС', 'type' => 'text', 'required' => true],
    ], 'Наименование УГНС', rowsLocked: true); }

    public function storeSphere(Request $r)                          { return $this->lookupStore($r, Sphere::class); }
    public function updateSphere(Request $r, Sphere $sphere)                               { return $this->lookupUpdate($r, $sphere); }
    public function destroySphere(Sphere $sphere)                                          { return $this->lookupDestroy($sphere); }

    public function storeGroup(Request $r)                           { return $this->lookupStore($r, ServiceGroup::class); }
    public function updateGroup(Request $r, ServiceGroup $serviceGroup)                    { return $this->lookupUpdate($r, $serviceGroup); }
    public function destroyGroup(ServiceGroup $serviceGroup)                               { return $this->lookupDestroy($serviceGroup); }

    public function storeBilling(Request $r)                         { return $this->lookupStore($r, Billing::class); }
    public function updateBilling(Request $r, Billing $billing)                            { return $this->lookupUpdate($r, $billing); }
    public function destroyBilling(Billing $billing)                                       { return $this->lookupDestroy($billing); }

    public function storeTaxAuthority(Request $r)                    { return $this->lookupStore($r, TaxAuthority::class, ['code' => ['required', 'string', 'max:10', Rule::unique('tax_authorities', 'code')]]); }

    /**
     * Справочная страница, только просмотр. Список режимов задаёт государство,
     * бухфирма его не настраивает — добавление, правка и удаление убраны
     * вместе с роутами.
     */
    public function taxSystemsPage()
    {
        return view('settings.tax-systems', [
            'taxSystems' => TaxSystem::active()->ordered()->get(),
        ]);
    }

    public function activityTypesPage()
    {
        return view('settings.activity-types', [
            'activityTypes' => ActivityType::ordered()->get(),
        ]);
    }

    public function tariffsPage()
    {
        return view('settings.tariffs', [
            'tariffs' => Tariff::ordered()->get(),
        ]);
    }

    public function ratesPage()
    {
        return view('settings.rates', [
            'rates' => Rate::orderBy('name')->get(),
            'units' => Rate::UNITS,
        ]);
    }

    public function servicesPage()
    {
        return view('settings.services', [
            'taxSystems' => TaxSystem::ordered()->get(),
            'services' => Service::with(['taxSystems', 'children.rate', 'rate'])->roots()->ordered()->get(),
            'specialFlags' => Service::specialFlagsList(),
            'periodicities' => Periodicity::orderBy('name')->get(['name', 'kind'])->values(),
            'categories' => Category::orderBy('name')->pluck('name')->values(),
            'spheres' => Sphere::orderBy('name')->pluck('name')->values(),
            'groups' => ServiceGroup::orderBy('name')->pluck('name')->values(),
            'billings' => Billing::orderBy('id')->get(['name', 'code']),
            'rates' => Rate::orderBy('name')->get(['id', 'name', 'unit', 'price']),
        ]);
    }

    public function showTariff(Tariff $tariff)
    {
        $tariff->load('services');
        $allServices = Service::active()->ordered()->get();

        return view('settings.tariff', compact('tariff', 'allServices'));
    }

    // =============================================
    // TAX SYSTEMS
    // =============================================

    // =============================================
    // ACTIVITY TYPES
    // =============================================

    public function storeActivityType(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            // Коды видов деятельности уникальны в пределах фирмы, а не всей базы.
            'code' => ['required', 'string', 'max:50', Rule::unique('activity_types', 'code')->where('tenant_id', TenantContext::id())],
            'description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['sort_order'] = $validated['sort_order'] ?? 0;

        $activityType = ActivityType::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Вид деятельности создан',
            'item' => $activityType,
        ]);
    }

    public function updateActivityType(Request $request, ActivityType $activityType)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => ['required', 'string', 'max:50', Rule::unique('activity_types', 'code')->where('tenant_id', TenantContext::id())->ignore($activityType->id)],
            'description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        $validated['is_active'] = $request->boolean('is_active', true);

        $activityType->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Вид деятельности обновлён',
            'item' => $activityType->fresh(),
        ]);
    }

    public function destroyActivityType(ActivityType $activityType)
    {
        if ($activityType->clients()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Невозможно удалить: есть связанные клиенты',
            ], 422);
        }

        $activityType->delete();

        return response()->json([
            'success' => true,
            'message' => 'Вид деятельности удалён',
        ]);
    }

    // =============================================
    // RATES (СПРАВОЧНИК СТАВОК)
    // =============================================

    public function storeRate(Request $request)
    {
        $validated = $request->validate([
            'name'       => 'required|string|max:255',
            'unit'       => 'nullable|string|max:100',
            'price'      => 'required|numeric|min:0',
            'conditions' => 'nullable|string|max:1000',
        ]);

        $rate = Rate::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Ставка создана',
            'item'    => $rate,
        ]);
    }

    public function updateRate(Request $request, Rate $rate)
    {
        $validated = $request->validate([
            'name'       => 'required|string|max:255',
            'unit'       => 'nullable|string|max:100',
            'price'      => 'required|numeric|min:0',
            'conditions' => 'nullable|string|max:1000',
        ]);

        $rate->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Ставка обновлена',
            'item'    => $rate->fresh(),
        ]);
    }

    public function destroyRate(Rate $rate)
    {
        $rate->delete();

        return response()->json([
            'success' => true,
            'message' => 'Ставка удалена',
        ]);
    }

    // =============================================
    // TARIFFS
    // =============================================

    public function storeTariff(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ]);

        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['code'] = (string) Str::uuid();
        $validated['price'] = 0;
        $validated['sort_order'] = 0;

        $tariff = Tariff::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Тариф создан',
            'item' => $tariff,
        ]);
    }

    public function updateTariff(Request $request, Tariff $tariff)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
        ]);

        $validated['is_active'] = $request->boolean('is_active', true);

        $tariff->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Тариф обновлён',
            'item' => $tariff->fresh(),
        ]);
    }

    public function destroyTariff(Tariff $tariff)
    {
        if ($tariff->clients()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Невозможно удалить: есть связанные клиенты',
            ], 422);
        }

        $tariff->delete();

        return response()->json([
            'success' => true,
            'message' => 'Тариф удалён',
        ]);
    }

    // =============================================
    // SERVICES (БИЗНЕС ПРОЦЕССЫ)
    // =============================================

    /**
     * Расписание БП заполняется целиком или не заполняется вовсе: периодичность без дня
     * срока даёт ноль дат в Service::computeDueDates, и задачи по такому БП не создаются
     * никогда — молча, без ошибок. Так на проде «Контроль сдачи отчетов» простоял в 40 сметах.
     */
    private const SCHEDULE_MESSAGES = [
        'start_day.required_with' => 'Выбрана периодичность — укажите день срока, иначе задачи по этому БП создаваться не будут.',
    ];

    public function storeService(Request $request)
    {
        $request->validate([
            'name'                          => 'required|string|max:255',
            'tax_systems'                   => 'nullable|array',
            'tax_systems.*'                 => 'required|exists:tax_systems,id',
            'description'                   => 'nullable|string|max:1000',
            'sphere'                        => 'nullable|string|max:255',
            'service_group'                 => 'nullable|string|max:255',
            'business_process'              => 'nullable|string|max:255',
            'category'                      => 'nullable|string|max:255',
            'cost'                          => 'required|numeric|min:0',
            'periodicity'                   => 'nullable|string|max:100',
            'due_day'                       => 'nullable|integer|min:1|max:31',
            'start_month'                   => 'nullable|array',
            'start_month.*'                 => 'integer|min:1|max:12',
            // Периодичность без дня срока = БП молча не порождает задач (см. Service::computeDueDates)
            'start_day'                     => 'nullable|array|required_with:periodicity',
            'start_day.*'                   => 'integer|min:1|max:31',
            'deadline_days'                 => 'nullable|integer|min:0',
            'execution_minutes'             => 'nullable|integer|min:0',
            'closing_rule'                  => 'nullable|string|max:255',
            'requires_document'             => 'boolean',
            'check_type'                    => 'nullable|string|max:255',
            'requires_review'               => 'boolean',
            'billing'                       => 'nullable|string|max:255',
            'rate_id'                       => 'nullable|exists:rates,id',
            'comment'                       => 'nullable|string',
            'allows_quantity'               => 'boolean',
            'sort_order'                    => 'integer',
            'pricing_rules'                 => 'nullable|array',
            'pricing_rules.*.max_qty'       => 'required|integer|min:1',
            'pricing_rules.*.price'         => 'required|numeric|min:0',
            'children'                      => 'nullable|array',
            'children.*.name'               => 'required|string|max:255',
            'children.*.cost'               => 'required|numeric|min:0',
            'children.*.periodicity'        => 'nullable|string|max:100',
        ], self::SCHEDULE_MESSAGES);

        // Новый бизнес-процесс ставим в начало списка (сортировка по sort_order ASC)
        $minSortOrder = (int) Service::roots()->min('sort_order');

        $service = Service::create(array_merge([
            'name'               => $request->name,
            'description'        => $request->description,
            'sphere'             => $request->sphere ?: null,
            'service_group'      => $request->service_group ?: null,
            'business_process'   => $request->business_process ?: null,
            'category'           => $request->category ?: null,
            'cost'               => $request->cost,
            'rate_id'            => $this->resolveRateId($request),
            'pricing_rules'      => $request->input('pricing_rules') ?: null,
            'periodicity'        => $request->periodicity,
            'due_day'            => $request->input('due_day') ?: null,
            'start_month'        => $request->input('start_month') ?: null,
            'start_day'          => $request->input('start_day') ?: null,
            'deadline_days'      => $request->input('deadline_days') ?: null,
            'execution_minutes'  => $request->input('execution_minutes') ?: null,
            'closing_rule'       => $request->closing_rule ?: null,
            'requires_document'  => $request->boolean('requires_document', false),
            'check_type'         => $request->check_type ?: null,
            'requires_review'    => $request->boolean('requires_review', false),
            'billing'            => $request->billing ?: null,
            'comment'            => $request->comment ?: null,
            'is_active'          => true,
            'allows_quantity'    => $request->boolean('allows_quantity', false),
            'splits_by_branch'   => $request->boolean('splits_by_branch', false),
            'sort_order'         => $request->input('sort_order', $minSortOrder - 1),
        ], $this->serviceFlagValues($request)));

        $service->taxSystems()->sync($request->input('tax_systems', []));

        foreach ($request->input('children', []) as $idx => $childData) {
            $service->children()->create([
                'name'            => $childData['name'],
                'cost'            => $childData['cost'],
                // Биллинг, ставка и количество живут только на основном БП — подпункт их наследует.
                'billing'         => $service->billing,
                'rate_id'         => $service->rate_id,
                'periodicity'     => $childData['periodicity'] ?? null,
                'allows_quantity' => $service->allows_quantity,
                'is_active'       => true,
                'sort_order'      => $idx,
            ]);
        }

        $service->load(['taxSystems', 'children.rate', 'rate']);

        return response()->json([
            'success' => true,
            'message' => 'Бизнес-процесс создан',
            'item'    => $this->formatServiceForJson($service),
        ]);
    }

    public function updateService(Request $request, Service $service)
    {
        $request->validate([
            'name'                          => 'required|string|max:255',
            'tax_systems'                   => 'nullable|array',
            'tax_systems.*'                 => 'required|exists:tax_systems,id',
            'description'                   => 'nullable|string|max:1000',
            'sphere'                        => 'nullable|string|max:255',
            'service_group'                 => 'nullable|string|max:255',
            'business_process'              => 'nullable|string|max:255',
            'category'                      => 'nullable|string|max:255',
            'cost'                          => 'required|numeric|min:0',
            'periodicity'                   => 'nullable|string|max:100',
            'due_day'                       => 'nullable|integer|min:1|max:31',
            'start_month'                   => 'nullable|array',
            'start_month.*'                 => 'integer|min:1|max:12',
            // Периодичность без дня срока = БП молча не порождает задач (см. Service::computeDueDates)
            'start_day'                     => 'nullable|array|required_with:periodicity',
            'start_day.*'                   => 'integer|min:1|max:31',
            'deadline_days'                 => 'nullable|integer|min:0',
            'execution_minutes'             => 'nullable|integer|min:0',
            'closing_rule'                  => 'nullable|string|max:255',
            'requires_document'             => 'boolean',
            'check_type'                    => 'nullable|string|max:255',
            'requires_review'               => 'boolean',
            'billing'                       => 'nullable|string|max:255',
            'rate_id'                       => 'nullable|exists:rates,id',
            'comment'                       => 'nullable|string',
            'allows_quantity'               => 'boolean',
            'sort_order'                    => 'integer|min:0',
            'pricing_rules'                 => 'nullable|array',
            'pricing_rules.*.max_qty'       => 'required|integer|min:1',
            'pricing_rules.*.price'         => 'required|numeric|min:0',
            'children'                      => 'nullable|array',
            'children.*.id'                 => 'nullable|integer',
            'children.*.name'               => 'required|string|max:255',
            'children.*.cost'               => 'required|numeric|min:0',
            'children.*.periodicity'        => 'nullable|string|max:100',
        ], self::SCHEDULE_MESSAGES);

        // Биллинг и количество живут только на основном БП: у подпункта они наследуются от родителя.
        $parent    = $service->parent_id ? $service->parent : null;
        $billing   = $parent ? $parent->billing : ($request->billing ?: null);
        $rateId    = $parent ? $parent->rate_id : $this->resolveRateId($request);
        $allowsQty = $parent ? (bool) $parent->allows_quantity : $request->boolean('allows_quantity', false);

        $service->update(array_merge([
            'name'               => $request->name,
            'description'        => $request->description,
            'sphere'             => $request->sphere ?: null,
            'service_group'      => $request->service_group ?: null,
            'business_process'   => $request->business_process ?: null,
            'category'           => $request->category ?: null,
            'cost'               => $request->cost,
            'rate_id'            => $rateId,
            'pricing_rules'      => $request->input('pricing_rules') ?: null,
            'periodicity'        => $request->periodicity,
            'due_day'            => $request->input('due_day') ?: null,
            'start_month'        => $request->input('start_month') ?: null,
            'start_day'          => $request->input('start_day') ?: null,
            'deadline_days'      => $request->input('deadline_days') ?: null,
            'execution_minutes'  => $request->input('execution_minutes') ?: null,
            'closing_rule'       => $request->closing_rule ?: null,
            'requires_document'  => $request->boolean('requires_document', false),
            'check_type'         => $request->check_type ?: null,
            'requires_review'    => $request->boolean('requires_review', false),
            'billing'            => $billing,
            'comment'            => $request->comment ?: null,
            'allows_quantity'    => $allowsQty,
            'splits_by_branch'   => $request->boolean('splits_by_branch', false),
            'sort_order'         => $request->input('sort_order', $service->sort_order),
        ], $this->serviceFlagValues($request)));

        $service->taxSystems()->sync($request->input('tax_systems', []));

        // Sync children: keep those with id, create new, delete removed
        $incomingChildren = collect($request->input('children', []));
        $incomingIds = $incomingChildren->pluck('id')->filter()->values()->toArray();

        $service->children()->whereNotIn('id', $incomingIds)->delete();

        foreach ($incomingChildren as $idx => $childData) {
            if (!empty($childData['id'])) {
                $service->children()->where('id', $childData['id'])->update([
                    'name'            => $childData['name'],
                    'cost'            => $childData['cost'],
                    'billing'         => $service->billing,
                    'rate_id'         => $service->rate_id,
                    'periodicity'     => $childData['periodicity'] ?? null,
                    'allows_quantity' => $service->allows_quantity,
                    'sort_order'      => $idx,
                ]);
            } else {
                $service->children()->create([
                    'name'            => $childData['name'],
                    'cost'            => $childData['cost'],
                    'billing'         => $service->billing,
                    'rate_id'         => $service->rate_id,
                    'periodicity'     => $childData['periodicity'] ?? null,
                    'allows_quantity' => $service->allows_quantity,
                    'is_active'       => true,
                    'sort_order'      => $idx,
                ]);
            }
        }

        $service->load(['taxSystems', 'children.rate', 'rate']);

        return response()->json([
            'success' => true,
            'message' => 'Бизнес-процесс обновлён',
            'item'    => $this->formatServiceForJson($service),
        ]);
    }

    /**
     * Удаление БП — только пока его никто не успел взять в работу.
     *
     * Раньше удаляли молча и всегда. Внешние ключи это переживали (в сметах
     * позиция оставалась со снятой ссылкой), но расписание жило в самом БП:
     * квартальная декларация у трёх десятков клиентов тихо превращалась в
     * ежемесячную задачу, а индивидуальные расписания уходили каскадом. Взамен
     * у работающего БП есть архивация — она закрывает будущее, не трогая прошлое.
     */
    public function destroyService(Service $service)
    {
        if ($this->serviceIsInUse($service)) {
            return response()->json([
                'success' => false,
                'message' => 'Бизнес-процесс уже используется в работе, удалить его нельзя. '
                    . 'Заархивируйте: текущий месяц клиенты доработают, со следующего задачи по нему заводиться не будут.',
            ], 422);
        }

        $service->children()->each(fn($c) => $c->tariffs()->detach());
        $service->children()->delete();
        $service->tariffs()->detach();
        $service->delete();

        return response()->json([
            'success' => true,
            'message' => 'Бизнес-процесс удалён',
        ]);
    }

    /**
     * Взят ли БП в работу хоть где-нибудь.
     *
     * Достаточно одного следа, поэтому не считаем, а спрашиваем «есть ли» —
     * запрос по индексу внешнего ключа и остановка на первой строке.
     * Подпункты проверяем вместе с родителем: удаление родителя уносит и их.
     */
    private function serviceIsInUse(Service $service): bool
    {
        $ids = $service->children()->pluck('id')->push($service->id);

        return EstimateItem::whereIn('service_id', $ids)->exists()
            || TaskReminder::whereIn('service_id', $ids)->exists()
            || BuhAdhocTask::whereIn('service_id', $ids)->exists()
            || ClientServiceSchedule::whereIn('service_id', $ids)->exists();
    }

    /**
     * В архив: БП больше не ведут.
     *
     * Режем по месяцам, а не по сегодняшнему числу: месяц — единица бухгалтерской
     * работы, и отчётность за текущий сдавать всё равно. Поэтому границей ставим
     * конец текущего месяца — незакрытые задачи внутри него остаются, со
     * следующего новых не появляется.
     */
    public function archiveService(Service $service)
    {
        $service->update([
            'is_active'   => false,
            'archived_at' => now()->endOfMonth()->toDateString(),
            'active_from' => null,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Бизнес-процесс заархивирован',
            // Даты — короткой строкой, как их отдаёт страница при загрузке: иначе
            // сюда уезжает полный ISO со временем, и разбор на странице ломается.
            'item'    => $this->servicePeriod($service->fresh()),
        ]);
    }

    /**
     * Обратно в работу — со следующего месяца.
     *
     * Снять архив задним числом нельзя: расписание тут же досчитало бы все слоты
     * за время простоя, и у бухгалтеров разом всплыла бы просрочка за месяцы,
     * когда процесс осознанно не вели.
     */
    public function restoreService(Service $service)
    {
        $service->update([
            'is_active'   => true,
            'archived_at' => null,
            'active_from' => now()->addMonth()->startOfMonth()->toDateString(),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Бизнес-процесс возвращён в работу',
            'item'    => $this->servicePeriod($service->fresh()),
        ]);
    }

    /** @return array{is_active: bool, archived_at: ?string, active_from: ?string} */
    private function servicePeriod(Service $service): array
    {
        return [
            'is_active'   => (bool) $service->is_active,
            'archived_at' => $service->archived_at?->toDateString(),
            'active_from' => $service->active_from?->toDateString(),
        ];
    }

    /**
     * Взят ли БП в работу и скольких клиентов затронет архивация.
     *
     * Спрашивается до открытия окна удаления: если процесс уже ведут, показываем
     * не «удалить», а «заархивировать» — иначе человек жмёт удаление, получает
     * отказ и видит окно, которое будто не сработало.
     */
    public function serviceUsage(Service $service)
    {
        $ids = $service->children()->pluck('id')->push($service->id);

        return response()->json([
            'in_use'  => $this->serviceIsInUse($service),
            'clients' => EstimateItem::whereIn('service_id', $ids)
                ->join('estimates', 'estimates.id', '=', 'estimate_items.estimate_id')
                ->distinct()
                ->count('estimates.client_id'),
        ]);
    }

    /**
     * Ставка к сохранению: только для платных режимов биллинга (by_quantity/addon),
     * иначе null — у «входит в абонентку» / «не тарифицируется» ставки нет.
     */
    private function rateForBilling(?string $billing, $rateId): ?int
    {
        if (!in_array(Billing::codeForName($billing), Billing::PAID_CODES, true)) {
            return null;
        }
        return $rateId ? (int) $rateId : null;
    }

    private function resolveRateId(Request $request): ?int
    {
        return $this->rateForBilling($request->billing ?: null, $request->input('rate_id'));
    }

    /** Значения флагов условий для сохранения (по конфигу Service::SPECIAL_FLAGS). */
    private function serviceFlagValues(Request $request): array
    {
        $values = [];
        foreach (array_keys(Service::SPECIAL_FLAGS) as $col) {
            $values[$col] = $request->boolean($col, false);
        }
        return $values;
    }

    /** Значения флагов условий для JSON-ответа. */
    private function serviceFlagsForJson(Service $service): array
    {
        $values = [];
        foreach (array_keys(Service::SPECIAL_FLAGS) as $col) {
            $values[$col] = (bool) $service->{$col};
        }
        return $values;
    }

    private function formatServiceForJson(Service $service): array
    {
        return array_merge([
            'id'              => $service->id,
            'parent_id'       => $service->parent_id,
            'tax_systems'     => $service->taxSystems->map(fn($ts) => [
                'id'   => $ts->id,
                'name' => $ts->name,
            ])->values(),
            'name'            => $service->name,
            'description'     => $service->description,
            'sphere'           => $service->sphere,
            'service_group'    => $service->service_group,
            'business_process' => $service->business_process,
            'category'         => $service->category,
            'cost'            => $service->cost,
            'rate_id'         => $service->rate_id,
            'rate'            => $service->rate ? [
                'id'    => $service->rate->id,
                'name'  => $service->rate->name,
                'unit'  => $service->rate->unit,
                'price' => $service->rate->price,
            ] : null,
            'pricing_rules'   => $service->pricing_rules ?? [],
            'periodicity'     => $service->periodicity,
            'due_day'         => $service->due_day,
            'start_month'       => $service->start_month,
            'start_day'         => $service->start_day,
            'deadline'          => $service->deadlineLabels(),
            'deadline_days'     => $service->deadline_days,
            'execution_minutes' => $service->execution_minutes,
            'closing_rule'      => $service->closing_rule,
            'requires_document' => $service->requires_document,
            'check_type'        => $service->check_type,
            'requires_review'   => $service->requires_review,
            'billing'           => $service->billing,
            'comment'           => $service->comment,
            'is_active'       => $service->is_active,
            'allows_quantity' => $service->allows_quantity,
            'splits_by_branch'=> $service->splits_by_branch,
            'sort_order'      => $service->sort_order,
            'children'        => $service->children->map(fn($c) => [
                'id'              => $c->id,
                'parent_id'       => $c->parent_id,
                'name'            => $c->name,
                'description'     => $c->description,
                'cost'            => $c->cost,
                'billing'         => $c->billing,
                'rate_id'         => $c->rate_id,
                'rate'            => $c->rate ? ['id' => $c->rate->id, 'name' => $c->rate->name, 'unit' => $c->rate->unit, 'price' => $c->rate->price] : null,
                'periodicity'     => $c->periodicity,
                'is_active'       => $c->is_active,
                'allows_quantity' => $c->allows_quantity,
                'sort_order'      => $c->sort_order,
                'children'        => [],
            ])->values(),
        ], $this->serviceFlagsForJson($service));
    }

    // =============================================
    // TARIFF ↔ SERVICE RELATIONS
    // =============================================

    public function attachService(Request $request, Tariff $tariff)
    {
        $request->validate([
            'service_id' => 'required|exists:services,id',
        ]);

        $tariff->services()->syncWithoutDetaching([$request->service_id]);

        return response()->json([
            'success' => true,
            'message' => 'Услуга добавлена в тариф',
            'service' => Service::find($request->service_id),
        ]);
    }

    public function detachService(Tariff $tariff, Service $service)
    {
        $tariff->services()->detach($service->id);

        return response()->json([
            'success' => true,
            'message' => 'Услуга удалена из тарифа',
        ]);
    }
}
