<?php

namespace App\Http\Controllers;

use App\Models\AccountingMethod;
use App\Models\ActivityType;
use App\Models\CheckType;
use App\Models\ClientStatus;
use App\Models\OrganizationForm;
use App\Models\Periodicity;
use App\Models\Rate;
use App\Models\ServiceType;
use App\Models\TaxpayerCategory;
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

    private function lookupView(string $title, string $endpoint, $items, string $description = '')
    {
        return view('settings.lookup', compact('title', 'description', 'items') + [
            'pageTitle'    => $title,
            'baseEndpoint' => $endpoint,
        ]);
    }

    private function lookupStore(Request $request, string $model): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate(['name' => 'required|string|max:255']);
        $item = $model::create($validated);
        return response()->json(['success' => true, 'item' => $item]);
    }

    private function lookupUpdate(Request $request, $record): \Illuminate\Http\JsonResponse
    {
        $validated = $request->validate(['name' => 'required|string|max:255']);
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
    public function periodicitiesPage()       { return $this->lookupView('Периодичность',               '/settings/periodicities',         Periodicity::orderBy('name')->get()); }
    public function checkTypesPage()          { return $this->lookupView('Проверка',                    '/settings/check-types',           CheckType::orderBy('name')->get()); }

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

    public function storePeriodicity(Request $r)                     { return $this->lookupStore($r, Periodicity::class); }
    public function updatePeriodicity(Request $r, Periodicity $periodicity)                { return $this->lookupUpdate($r, $periodicity); }
    public function destroyPeriodicity(Periodicity $periodicity)                           { return $this->lookupDestroy($periodicity); }

    public function storeCheckType(Request $r)                       { return $this->lookupStore($r, CheckType::class); }
    public function updateCheckType(Request $r, CheckType $checkType)                      { return $this->lookupUpdate($r, $checkType); }
    public function destroyCheckType(CheckType $checkType)                                 { return $this->lookupDestroy($checkType); }

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
            'tax_systems'                   => 'required|array|min:1',
            'tax_systems.*'                 => 'required|exists:tax_systems,id',
            'description'                   => 'nullable|string|max:1000',
            'sphere'                        => 'nullable|string|max:255',
            'service_group'                 => 'nullable|string|max:255',
            'business_process'              => 'nullable|string|max:255',
            'category'                      => 'nullable|string|max:255',
            'cost'                          => 'required|numeric|min:0',
            'periodicity'                   => 'nullable|string|max:100',
            'due_day'                       => 'nullable|integer|min:1|max:31',
            'deadline_days'                 => 'nullable|integer|min:0',
            'execution_minutes'             => 'nullable|integer|min:0',
            'closing_rule'                  => 'nullable|string|max:255',
            'check_type'                    => 'nullable|string|max:255',
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

        $service = Service::create([
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
            'deadline_days'      => $request->input('deadline_days') ?: null,
            'execution_minutes'  => $request->input('execution_minutes') ?: null,
            'closing_rule'       => $request->closing_rule ?: null,
            'check_type'         => $request->check_type ?: null,
            'billing'            => $request->billing ?: null,
            'comment'            => $request->comment ?: null,
            'is_active'          => true,
            'allows_quantity'    => $request->boolean('allows_quantity', false),
            'sort_order'         => $request->input('sort_order', $minSortOrder - 1),
        ]);

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
            'tax_systems'                   => 'required|array|min:1',
            'tax_systems.*'                 => 'required|exists:tax_systems,id',
            'description'                   => 'nullable|string|max:1000',
            'sphere'                        => 'nullable|string|max:255',
            'service_group'                 => 'nullable|string|max:255',
            'business_process'              => 'nullable|string|max:255',
            'category'                      => 'nullable|string|max:255',
            'cost'                          => 'required|numeric|min:0',
            'periodicity'                   => 'nullable|string|max:100',
            'due_day'                       => 'nullable|integer|min:1|max:31',
            'deadline_days'                 => 'nullable|integer|min:0',
            'execution_minutes'             => 'nullable|integer|min:0',
            'closing_rule'                  => 'nullable|string|max:255',
            'check_type'                    => 'nullable|string|max:255',
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

        $service->update([
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
            'deadline_days'      => $request->input('deadline_days') ?: null,
            'execution_minutes'  => $request->input('execution_minutes') ?: null,
            'closing_rule'       => $request->closing_rule ?: null,
            'check_type'         => $request->check_type ?: null,
            'billing'            => $request->billing ?: null,
            'comment'            => $request->comment ?: null,
            'allows_quantity'    => $request->boolean('allows_quantity', false),
            'sort_order'         => $request->input('sort_order', $service->sort_order),
        ]);

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

    private function formatServiceForJson(Service $service): array
    {
        return [
            'id'              => $service->id,
            'parent_id'       => $service->parent_id,
            'tax_systems'     => $service->taxSystems->map(fn($ts) => [
                'id'   => $ts->id,
                'name' => $ts->name,
            ])->values(),
            'name'            => $service->name,
            'description'     => $service->description,
            'cost'            => $service->cost,
            'pricing_rules'   => $service->pricing_rules ?? [],
            'periodicity'     => $service->periodicity,
            'due_day'         => $service->due_day,
            'is_active'       => $service->is_active,
            'allows_quantity' => $service->allows_quantity,
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
        ];
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
