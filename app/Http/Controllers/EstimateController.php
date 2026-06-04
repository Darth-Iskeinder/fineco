<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Service;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;

class EstimateController extends Controller
{
    public function edit(Request $request, Client $client)
    {
        $client->load(['taxSystem']);

        // Одна смета на клиента
        $estimate = Estimate::firstOrCreate(
            ['client_id' => $client->id],
            ['total' => 0]
        );

        $estimate->load(['rootItems.children']);

        $tariffItems   = $estimate->rootItems->filter(fn($i) => $i->type === 'recurring' && $i->service_id !== null);
        $tariffItemIds = $tariffItems->pluck('id');
        $isFirstLoad   = $tariffItems->isEmpty();

        $estimateHasItems = $estimate->items()->exists();

        // Enabled service_ids from saved estimate
        $savedByServiceId      = $tariffItems->keyBy('service_id');
        $savedChildByServiceId = $tariffItems
            ->flatMap(fn($i) => $i->children)
            ->filter(fn($c) => $c->service_id !== null)
            ->keyBy('service_id');

        $clientTaxSystemId = $client->tax_system_id;

        // БП применим к клиенту по режиму налогообложения
        $matchesTaxSystem = fn($s) => !$clientTaxSystemId                  // у клиента не задан РН — показываем всё
            || $s->taxSystems->isEmpty()                                   // у БП ещё нет РН — показываем (обратная совмест.)
            || $s->taxSystems->contains('id', $clientTaxSystemId);         // РН клиента совпадает

        $flagKeys = array_keys(Service::SPECIAL_FLAGS);

        // Сборка структуры БП с состоянием тоглов
        $buildBpData = function ($bp) use ($isFirstLoad, $savedByServiceId, $savedChildByServiceId, $flagKeys) {
            $savedItem = $savedByServiceId->get($bp->id);
            $bpData = [
                'service_id'     => $bp->id,
                'name'           => $bp->name,
                'cost'           => (float) $bp->cost,
                'periodicity'    => $bp->periodicity ?? '',
                'allows_quantity'=> $bp->allows_quantity,
                'enabled'        => $isFirstLoad ? true : $savedByServiceId->has($bp->id),
                'quantity'       => $savedItem ? (int) $savedItem->quantity : 1,
                'children'       => [],
            ];

            foreach ($flagKeys as $fk) {
                $bpData[$fk] = (bool) $bp->$fk;
            }

            foreach ($bp->children as $child) {
                $savedChild = $savedChildByServiceId->get($child->id);
                $bpData['children'][] = [
                    'service_id'     => $child->id,
                    'name'           => $child->name,
                    'cost'           => (float) $child->cost,
                    'periodicity'    => $child->periodicity ?? '',
                    'allows_quantity'=> $child->allows_quantity,
                    'enabled'        => $isFirstLoad ? false : $savedChildByServiceId->has($child->id),
                    'quantity'       => $savedChild ? (int) $savedChild->quantity : 1,
                ];
            }

            return $bpData;
        };

        // «Особый» БП — помеченный хотя бы одним особым условием (ПВТ, ВЭД, …).
        // Такие тянутся только по флагу клиента (ниже), а не по РН.
        $hasAnyFlag = fn($s) => collect($flagKeys)->contains(fn($k) => (bool) $s->$k);

        // Обычные БП (без особых условий), применимые по режиму налогообложения,
        // подтягиваются из всего активного каталога — независимо от тарифа.
        $tariffBPs = [];
        $includedServiceIds = [];
        $rootServices = Service::with(['taxSystems', 'children'])
            ->roots()->active()->ordered()->get()
            ->filter(fn($s) => $matchesTaxSystem($s) && !$hasAnyFlag($s))
            ->values();

        foreach ($rootServices as $bp) {
            $tariffBPs[] = $buildBpData($bp);
            $includedServiceIds[$bp->id] = true;
        }

        // БП по особым условиям: для каждого условия, включённого у клиента, подтягиваем
        // помеченные этим условием активные БП, которые ещё не добавлены. Без РН-фильтра.
        foreach (Service::SPECIAL_FLAGS as $col => $cfg) {
            if (!$client->{$cfg['client']}) {
                continue;
            }

            $flagServices = Service::with('children')
                ->roots()->active()->where($col, true)->ordered()->get()
                ->filter(fn($s) => !isset($includedServiceIds[$s->id]))
                ->values();

            foreach ($flagServices as $bp) {
                $tariffBPs[] = $buildBpData($bp);
                $includedServiceIds[$bp->id] = true;
            }
        }

        // Extra items = всё что не относится к тарифным (recurring с service_id)
        $extraItems = $estimate->rootItems->filter(fn($i) => !$tariffItemIds->contains($i->id));
        $extraServiceIds = $extraItems
            ->flatMap(fn($i) => collect([$i->service_id])->merge($i->children->pluck('service_id')))
            ->filter()->unique()->values()->toArray();
        $extraServicesById = $extraServiceIds
            ? Service::whereIn('id', $extraServiceIds)->get()->keyBy('id')
            : collect();

        $extras = $extraItems
            ->map(fn($item) => [
                'service_id'      => $item->service_id,
                'type'            => $item->type,
                'name'            => $item->name,
                'periodicity'     => $item->periodicity ?? '',
                'cost'            => (float) $item->cost,
                'quantity'        => (int) $item->quantity,
                'allows_quantity' => (bool) ($extraServicesById->get($item->service_id)?->allows_quantity ?? false),
                'children'        => $item->children->map(fn($c) => [
                    'service_id'     => $c->service_id,
                    'type'           => $c->type,
                    'name'           => $c->name,
                    'periodicity'    => $c->periodicity ?? '',
                    'cost'           => (float) $c->cost,
                    'quantity'       => (int) $c->quantity,
                    'allows_quantity'=> (bool) ($extraServicesById->get($c->service_id)?->allows_quantity ?? false),
                    'enabled'        => true,
                ])->values()->toArray(),
            ])->values()->toArray();

        // All catalog BPs for "add extra" modal (root only, with children)
        $allServices = Service::with('children')
            ->roots()
            ->active()
            ->ordered()
            ->get()
            ->map(fn($s) => [
                'id'             => $s->id,
                'name'           => $s->name,
                'periodicity'    => $s->periodicity ?? '',
                'cost'           => (float) $s->cost,
                'allows_quantity'=> $s->allows_quantity,
                'children'       => $s->children->map(fn($c) => [
                    'id'             => $c->id,
                    'name'           => $c->name,
                    'periodicity'    => $c->periodicity ?? '',
                    'cost'           => (float) $c->cost,
                    'allows_quantity'=> $c->allows_quantity,
                ])->values()->toArray(),
            ])->values()->toArray();

        $specialFlags = Service::specialFlagsList();

        return view('clients.estimate', compact(
            'client', 'estimate', 'tariffBPs', 'extras', 'allServices', 'specialFlags',
            'estimateHasItems'
        ));
    }

    public function show(Request $request, Client $client)
    {
        $estimate = Estimate::where('client_id', $client->id)->first();

        if (!$estimate) {
            return response()->json(['id' => null, 'total' => 0, 'notes' => '', 'updated_at' => null]);
        }

        return response()->json([
            'id'         => $estimate->id,
            'total'      => $estimate->total,
            'notes'      => $estimate->notes,
            'updated_at' => $estimate->updated_at?->format('d.m.Y H:i'),
        ]);
    }

    public function save(Request $request, Client $client)
    {
        $request->validate([
            'notes'                                => 'nullable|string|max:1000',
            'tariff_bps'                           => 'nullable|array',
            'tariff_bps.*.service_id'              => 'required|integer',
            'tariff_bps.*.enabled'                 => 'boolean',
            'tariff_bps.*.quantity'                => 'nullable|integer|min:1',
            'tariff_bps.*.children'                => 'nullable|array',
            'tariff_bps.*.children.*.service_id'   => 'required|integer',
            'tariff_bps.*.children.*.enabled'      => 'boolean',
            'tariff_bps.*.children.*.quantity'     => 'nullable|integer|min:1',
            'extras'                               => 'nullable|array',
            'extras.*.name'                        => 'required|string|max:255',
            'extras.*.cost'                        => 'nullable|numeric|min:0',
            'extras.*.quantity'                    => 'nullable|integer|min:1',
        ]);

        $estimate = Estimate::firstOrCreate(
            ['client_id' => $client->id],
            ['total' => 0]
        );

        $estimate->items()->delete();

        $total = 0;
        $sortOrder = 0;

        // Tariff BPs (only enabled ones)
        foreach ($request->input('tariff_bps', []) as $bpData) {
            if (empty($bpData['enabled'])) continue;

            $service = Service::find($bpData['service_id']);
            if (!$service) continue;

            $qty       = (int) ($bpData['quantity'] ?? 1);
            $children  = collect($bpData['children'] ?? [])->filter(fn($c) => !empty($c['enabled']));

            $parent = $estimate->items()->create([
                'service_id'  => $service->id,
                'type'        => 'recurring',
                'name'        => $service->name,
                'periodicity' => $service->periodicity,
                'due_day'     => $service->due_day,
                'cost'        => $service->cost,
                'quantity'    => $qty,
                'total'       => 0, // filled after children
                'sort_order'  => $sortOrder++,
            ]);

            $childTotal = 0;
            $childOrder = 0;
            foreach ($children as $childData) {
                $childService = Service::find($childData['service_id']);
                if (!$childService) continue;

                $cqty  = (int) ($childData['quantity'] ?? 1);
                $cTotal = round((float) $childService->cost * $cqty, 2);
                $childTotal += $cTotal;

                $estimate->items()->create([
                    'parent_id'   => $parent->id,
                    'service_id'  => $childService->id,
                    'type'        => 'recurring',
                    'name'        => $childService->name,
                    'periodicity' => $childService->periodicity,
                    'due_day'     => $childService->due_day,
                    'cost'        => $childService->cost,
                    'quantity'    => $cqty,
                    'total'       => $cTotal,
                    'sort_order'  => $childOrder++,
                ]);
            }

            $parentTotal = $children->isNotEmpty()
                ? $childTotal
                : round((float) $service->cost * $qty, 2);

            $parent->update(['total' => $parentTotal]);
            $total += $parentTotal;
        }

        // Extra items
        foreach ($request->input('extras', []) as $extraData) {
            if (empty($extraData['name'])) continue;

            $cost  = (float) ($extraData['cost'] ?? 0);
            $qty   = (int) ($extraData['quantity'] ?? 1);
            $rowTotal = round($cost * $qty, 2);

            $extraType = in_array($extraData['type'] ?? '', ['recurring', 'one_time'])
                ? $extraData['type'] : 'one_time';

            $parent = $estimate->items()->create([
                'service_id'  => $extraData['service_id'] ?? null,
                'type'        => $extraType,
                'name'        => $extraData['name'],
                'periodicity' => $extraData['periodicity'] ?? null,
                'cost'        => $cost,
                'quantity'    => $qty,
                'total'       => $rowTotal,
                'sort_order'  => $sortOrder++,
            ]);

            $childTotal = 0;
            foreach ($extraData['children'] ?? [] as $cidx => $childData) {
                if (empty($childData['name'])) continue;
                $cc  = (float) ($childData['cost'] ?? 0);
                $cq  = (int) ($childData['quantity'] ?? 1);
                $ct  = round($cc * $cq, 2);
                $childTotal += $ct;
                $estimate->items()->create([
                    'parent_id'   => $parent->id,
                    'service_id'  => $childData['service_id'] ?? null,
                    'type'        => $extraType,
                    'name'        => $childData['name'],
                    'periodicity' => $childData['periodicity'] ?? null,
                    'cost'        => $cc,
                    'quantity'    => $cq,
                    'total'       => $ct,
                    'sort_order'  => $cidx,
                ]);
            }

            if (!empty($extraData['children'])) {
                $parent->update(['total' => $childTotal]);
                $total += $childTotal;
            } else {
                $total += $rowTotal;
            }
        }

        $estimate->notes = $request->notes;
        $estimate->total = $total;
        $estimate->save();

        return response()->json([
            'success'    => true,
            'message'    => 'Смета сохранена',
            'total'      => $estimate->total,
            'updated_at' => $estimate->updated_at->format('d.m.Y H:i'),
        ]);
    }

    public function pdf(Request $request, Client $client)
    {
        $client->load(['taxSystem', 'tariff']);
        $estimate = Estimate::with(['rootItems.children'])
            ->where('client_id', $client->id)
            ->first();

        if (!$estimate) {
            abort(404, 'Смета не найдена');
        }

        $pdf = Pdf::loadView('pdf.estimate', compact('client', 'estimate'))
            ->setPaper('a4', 'portrait');

        $filename = 'smeta_' . preg_replace('/[^a-zA-Z0-9]/', '_', $client->name) . '.pdf';

        return $pdf->download($filename);
    }
}
