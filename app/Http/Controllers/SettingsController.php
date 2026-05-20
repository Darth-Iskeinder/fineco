<?php

namespace App\Http\Controllers;

use App\Models\ActivityType;
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
        return view('settings.index', [
            'taxSystems' => TaxSystem::ordered()->get(),
            'activityTypes' => ActivityType::ordered()->get(),
            'tariffs' => Tariff::ordered()->get(),
            'services' => Service::with(['tariffs', 'taxSystems', 'children.tariffs'])->roots()->ordered()->get(),
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
            'cost'                          => 'required|numeric|min:0',
            'periodicity'                   => 'nullable|string|max:100',
            'due_day'                       => 'nullable|integer|min:1|max:31',
            'allows_quantity'               => 'boolean',
            'sort_order'                    => 'integer|min:0',
            'tariffs'                       => 'required|array|min:1',
            'tariffs.*.id'                  => 'required|exists:tariffs,id',
            'tariffs.*.free_limit'          => 'nullable|integer|min:0',
            'tariffs.*.price_override'      => 'nullable|numeric|min:0',
            'pricing_rules'                 => 'nullable|array',
            'pricing_rules.*.max_qty'       => 'required|integer|min:1',
            'pricing_rules.*.price'         => 'required|numeric|min:0',
            'children'                      => 'nullable|array',
            'children.*.name'               => 'required|string|max:255',
            'children.*.cost'               => 'required|numeric|min:0',
            'children.*.periodicity'        => 'nullable|string|max:100',
            'children.*.allows_quantity'    => 'boolean',
        ]);

        $service = Service::create([
            'name'            => $request->name,
            'description'     => $request->description,
            'cost'            => $request->cost,
            'pricing_rules'   => $request->input('pricing_rules') ?: null,
            'periodicity'     => $request->periodicity,
            'due_day'         => $request->input('due_day') ?: null,
            'is_active'       => true,
            'allows_quantity' => $request->boolean('allows_quantity', false),
            'sort_order'      => $request->input('sort_order', 0),
        ]);

        $service->tariffs()->sync($this->buildTariffSyncData($request->input('tariffs', [])));
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

        $service->load(['tariffs', 'taxSystems', 'children']);

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
            'cost'                          => 'required|numeric|min:0',
            'periodicity'                   => 'nullable|string|max:100',
            'due_day'                       => 'nullable|integer|min:1|max:31',
            'allows_quantity'               => 'boolean',
            'sort_order'                    => 'integer|min:0',
            'tariffs'                       => 'required|array|min:1',
            'tariffs.*.id'                  => 'required|exists:tariffs,id',
            'tariffs.*.free_limit'          => 'nullable|integer|min:0',
            'tariffs.*.price_override'      => 'nullable|numeric|min:0',
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
            'name'            => $request->name,
            'description'     => $request->description,
            'cost'            => $request->cost,
            'pricing_rules'   => $request->input('pricing_rules') ?: null,
            'periodicity'     => $request->periodicity,
            'due_day'         => $request->input('due_day') ?: null,
            'allows_quantity' => $request->boolean('allows_quantity', false),
            'sort_order'      => $request->input('sort_order', $service->sort_order),
        ]);

        $service->tariffs()->sync($this->buildTariffSyncData($request->input('tariffs', [])));
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

        $service->load(['tariffs', 'taxSystems', 'children']);

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

    private function buildTariffSyncData(array $tariffs): array
    {
        $sync = [];
        foreach ($tariffs as $t) {
            $sync[$t['id']] = [
                'free_limit'     => (int) ($t['free_limit'] ?? 0),
                'price_override' => isset($t['price_override']) && $t['price_override'] !== ''
                                        ? (float) $t['price_override']
                                        : null,
            ];
        }
        return $sync;
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
            'tariffs'         => $service->tariffs->map(fn($t) => [
                'id'             => $t->id,
                'name'           => $t->name,
                'free_limit'     => (int) $t->pivot->free_limit,
                'price_override' => $t->pivot->price_override !== null ? (float) $t->pivot->price_override : null,
            ])->values(),
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
                'tariffs'         => [],
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
