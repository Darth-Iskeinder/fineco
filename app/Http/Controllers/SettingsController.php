<?php

namespace App\Http\Controllers;

use App\Models\AccountingMethod;
use App\Models\ActivityType;
use App\Models\Billing;
use App\Models\Category;
use App\Models\CheckType;
use App\Models\ClientStatus;
use App\Models\OrganizationForm;
use App\Models\Periodicity;
use App\Models\Rate;
use App\Models\ServiceGroup;
use App\Models\ServiceType;
use App\Models\Sphere;
use App\Models\TaxpayerCategory;
use App\Models\TaxAuthority;
use App\Models\Service;
use App\Models\Tariff;
use App\Models\TaxSystem;
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

    private function lookupView(string $title, string $endpoint, $items, string $description = '', array $fields = [], string $nameLabel = 'Название')
    {
        return view('settings.lookup', compact('title', 'description', 'items', 'fields', 'nameLabel') + [
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

    public function organizationFormsPage()   { return $this->lookupView('Форма/тип организации',      '/settings/organization-forms',    OrganizationForm::orderBy('name')->get()); }
    public function clientStatusesPage()      { return $this->lookupView('Статус клиента',              '/settings/client-statuses',       ClientStatus::orderBy('name')->get()); }
    public function taxpayerCategoriesPage()  { return $this->lookupView('Категория налогоплательщика', '/settings/taxpayer-categories',   TaxpayerCategory::orderBy('name')->get()); }
    public function accountingMethodsPage()   { return $this->lookupView('Метод учёта',                 '/settings/accounting-methods',    AccountingMethod::orderBy('name')->get()); }
    public function serviceTypesPage()        { return $this->lookupView('Тип обслуживания',            '/settings/service-types',         ServiceType::orderBy('name')->get()); }
    public function categoriesPage()          { return $this->lookupView('Категория',                   '/settings/categories',            Category::orderBy('name')->get()); }
    public function spheresPage()             { return $this->lookupView('Сфера',                       '/settings/spheres',               Sphere::orderBy('name')->get()); }
    public function groupsPage()              { return $this->lookupView('Группа',                      '/settings/groups',                ServiceGroup::orderBy('name')->get()); }
    public function periodicitiesPage()       { return $this->lookupView('Периодичность',               '/settings/periodicities',         Periodicity::orderBy('name')->get(), 'Тип определяет поведение полей «Месяц» и «День» при создании БП', [
        ['key' => 'kind', 'label' => 'Тип', 'type' => 'select', 'options' => Periodicity::KINDS],
    ]); }
    public function checkTypesPage()          { return $this->lookupView('Проверка',                    '/settings/check-types',           CheckType::orderBy('name')->get()); }
    public function billingsPage()            { return $this->lookupView('Биллинг',                     '/settings/billings',              Billing::orderBy('id')->get()); }
    public function taxAuthoritiesPage()      { return $this->lookupView('Коды налоговых органов',      '/settings/tax-authorities',       TaxAuthority::orderBy('code')->get(), 'Справочник кодов районных ГНС. Используется при добавлении филиалов в карточке клиента.', [
        ['key' => 'code', 'label' => 'Код районной ГНС', 'type' => 'text', 'required' => true],
    ], 'Наименование УГНС'); }

    public function storeOrganizationForm(Request $r)                { return $this->lookupStore($r, OrganizationForm::class); }
    public function updateOrganizationForm(Request $r, OrganizationForm $organizationForm) { return $this->lookupUpdate($r, $organizationForm); }
    public function destroyOrganizationForm(OrganizationForm $organizationForm)            { return $this->lookupDestroy($organizationForm); }

    public function storeClientStatus(Request $r)                    { return $this->lookupStore($r, ClientStatus::class); }
    public function updateClientStatus(Request $r, ClientStatus $clientStatus)             { return $this->lookupUpdate($r, $clientStatus); }
    public function destroyClientStatus(ClientStatus $clientStatus)                        { return $this->lookupDestroy($clientStatus); }

    public function storeTaxpayerCategory(Request $r)                { return $this->lookupStore($r, TaxpayerCategory::class); }
    public function updateTaxpayerCategory(Request $r, TaxpayerCategory $taxpayerCategory){ return $this->lookupUpdate($r, $taxpayerCategory); }
    public function destroyTaxpayerCategory(TaxpayerCategory $taxpayerCategory)           { return $this->lookupDestroy($taxpayerCategory); }

    public function storeAccountingMethod(Request $r)                { return $this->lookupStore($r, AccountingMethod::class); }
    public function updateAccountingMethod(Request $r, AccountingMethod $accountingMethod) { return $this->lookupUpdate($r, $accountingMethod); }
    public function destroyAccountingMethod(AccountingMethod $accountingMethod)            { return $this->lookupDestroy($accountingMethod); }

    public function storeServiceType(Request $r)                     { return $this->lookupStore($r, ServiceType::class); }
    public function updateServiceType(Request $r, ServiceType $serviceType)                { return $this->lookupUpdate($r, $serviceType); }
    public function destroyServiceType(ServiceType $serviceType)                           { return $this->lookupDestroy($serviceType); }

    public function storeCategory(Request $r)                        { return $this->lookupStore($r, Category::class); }
    public function updateCategory(Request $r, Category $category)                         { return $this->lookupUpdate($r, $category); }
    public function destroyCategory(Category $category)                                    { return $this->lookupDestroy($category); }

    public function storeSphere(Request $r)                          { return $this->lookupStore($r, Sphere::class); }
    public function updateSphere(Request $r, Sphere $sphere)                               { return $this->lookupUpdate($r, $sphere); }
    public function destroySphere(Sphere $sphere)                                          { return $this->lookupDestroy($sphere); }

    public function storeGroup(Request $r)                           { return $this->lookupStore($r, ServiceGroup::class); }
    public function updateGroup(Request $r, ServiceGroup $serviceGroup)                    { return $this->lookupUpdate($r, $serviceGroup); }
    public function destroyGroup(ServiceGroup $serviceGroup)                               { return $this->lookupDestroy($serviceGroup); }

    public function storePeriodicity(Request $r)                     { return $this->lookupStore($r, Periodicity::class, ['kind' => 'nullable|string|in:weekly,monthly,quarterly,yearly']); }
    public function updatePeriodicity(Request $r, Periodicity $periodicity)                { return $this->lookupUpdate($r, $periodicity, ['kind' => 'nullable|string|in:weekly,monthly,quarterly,yearly']); }
    public function destroyPeriodicity(Periodicity $periodicity)                           { return $this->lookupDestroy($periodicity); }

    public function storeCheckType(Request $r)                       { return $this->lookupStore($r, CheckType::class); }
    public function updateCheckType(Request $r, CheckType $checkType)                      { return $this->lookupUpdate($r, $checkType); }
    public function destroyCheckType(CheckType $checkType)                                 { return $this->lookupDestroy($checkType); }

    public function storeBilling(Request $r)                         { return $this->lookupStore($r, Billing::class); }
    public function updateBilling(Request $r, Billing $billing)                            { return $this->lookupUpdate($r, $billing); }
    public function destroyBilling(Billing $billing)                                       { return $this->lookupDestroy($billing); }

    public function storeTaxAuthority(Request $r)                    { return $this->lookupStore($r, TaxAuthority::class, ['code' => ['required', 'string', 'max:10', Rule::unique('tax_authorities', 'code')]]); }
    public function updateTaxAuthority(Request $r, TaxAuthority $taxAuthority)             { return $this->lookupUpdate($r, $taxAuthority, ['code' => ['required', 'string', 'max:10', Rule::unique('tax_authorities', 'code')->ignore($taxAuthority->id)]]); }
    public function destroyTaxAuthority(TaxAuthority $taxAuthority)                         { return $this->lookupDestroy($taxAuthority); }

    public function taxSystemsPage()
    {
        return view('settings.tax-systems', [
            'taxSystems' => TaxSystem::ordered()->get(),
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
        ]);
    }

    public function servicesPage()
    {
        return view('settings.services', [
            'taxSystems' => TaxSystem::ordered()->get(),
            'services' => Service::with(['taxSystems', 'children'])->roots()->ordered()->get(),
            'specialFlags' => Service::specialFlagsList(),
            'periodicities' => Periodicity::orderBy('name')->get(['name', 'kind'])->values(),
            'categories' => Category::orderBy('name')->pluck('name')->values(),
            'spheres' => Sphere::orderBy('name')->pluck('name')->values(),
            'groups' => ServiceGroup::orderBy('name')->pluck('name')->values(),
            'billings' => Billing::orderBy('id')->pluck('name')->values(),
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

    public function storeTaxSystem(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:tax_systems,code',
            'description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        $validated['is_active'] = $request->boolean('is_active', true);
        $validated['sort_order'] = $validated['sort_order'] ?? 0;

        $taxSystem = TaxSystem::create($validated);

        return response()->json([
            'success' => true,
            'message' => 'Система налогообложения создана',
            'item' => $taxSystem,
        ]);
    }

    public function updateTaxSystem(Request $request, TaxSystem $taxSystem)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => ['required', 'string', 'max:50', Rule::unique('tax_systems', 'code')->ignore($taxSystem->id)],
            'description' => 'nullable|string|max:500',
            'is_active' => 'boolean',
            'sort_order' => 'integer|min:0',
        ]);

        $validated['is_active'] = $request->boolean('is_active', true);

        $taxSystem->update($validated);

        return response()->json([
            'success' => true,
            'message' => 'Система налогообложения обновлена',
            'item' => $taxSystem->fresh(),
        ]);
    }

    public function destroyTaxSystem(TaxSystem $taxSystem)
    {
        if ($taxSystem->clients()->exists()) {
            return response()->json([
                'success' => false,
                'message' => 'Невозможно удалить: есть связанные клиенты',
            ], 422);
        }

        $taxSystem->delete();

        return response()->json([
            'success' => true,
            'message' => 'Система налогообложения удалена',
        ]);
    }

    // =============================================
    // ACTIVITY TYPES
    // =============================================

    public function storeActivityType(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:activity_types,code',
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
            'code' => ['required', 'string', 'max:50', Rule::unique('activity_types', 'code')->ignore($activityType->id)],
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
            'start_day'                     => 'nullable|array',
            'start_day.*'                   => 'integer|min:1|max:31',
            'deadline_days'                 => 'nullable|integer|min:0',
            'execution_minutes'             => 'nullable|integer|min:0',
            'closing_rule'                  => 'nullable|string|max:255',
            'requires_document'             => 'boolean',
            'check_type'                    => 'nullable|string|max:255',
            'requires_review'               => 'boolean',
            'billing'                       => 'nullable|string|max:255',
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
            'children.*.allows_quantity'    => 'boolean',
        ]);

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
                'periodicity'     => $childData['periodicity'] ?? null,
                'allows_quantity' => (bool) ($childData['allows_quantity'] ?? false),
                'is_active'       => true,
                'sort_order'      => $idx,
            ]);
        }

        $service->load(['taxSystems', 'children']);

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
            'start_day'                     => 'nullable|array',
            'start_day.*'                   => 'integer|min:1|max:31',
            'deadline_days'                 => 'nullable|integer|min:0',
            'execution_minutes'             => 'nullable|integer|min:0',
            'closing_rule'                  => 'nullable|string|max:255',
            'requires_document'             => 'boolean',
            'check_type'                    => 'nullable|string|max:255',
            'requires_review'               => 'boolean',
            'billing'                       => 'nullable|string|max:255',
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
            'children.*.allows_quantity'    => 'boolean',
        ]);

        $service->update(array_merge([
            'name'               => $request->name,
            'description'        => $request->description,
            'sphere'             => $request->sphere ?: null,
            'service_group'      => $request->service_group ?: null,
            'business_process'   => $request->business_process ?: null,
            'category'           => $request->category ?: null,
            'cost'               => $request->cost,
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
            'allows_quantity'    => $request->boolean('allows_quantity', false),
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
                    'periodicity'     => $childData['periodicity'] ?? null,
                    'allows_quantity' => (bool) ($childData['allows_quantity'] ?? false),
                    'sort_order'      => $idx,
                ]);
            } else {
                $service->children()->create([
                    'name'            => $childData['name'],
                    'cost'            => $childData['cost'],
                    'periodicity'     => $childData['periodicity'] ?? null,
                    'allows_quantity' => (bool) ($childData['allows_quantity'] ?? false),
                    'is_active'       => true,
                    'sort_order'      => $idx,
                ]);
            }
        }

        $service->load(['taxSystems', 'children']);

        return response()->json([
            'success' => true,
            'message' => 'Бизнес-процесс обновлён',
            'item'    => $this->formatServiceForJson($service),
        ]);
    }

    public function destroyService(Service $service)
    {
        $service->children()->each(fn($c) => $c->tariffs()->detach());
        $service->children()->delete();
        $service->tariffs()->detach();
        $service->delete();

        return response()->json([
            'success' => true,
            'message' => 'Бизнес-процесс удалён',
        ]);
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
