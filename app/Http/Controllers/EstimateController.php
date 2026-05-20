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
    public function edit(Client $client)
    {
        $client->load(['taxSystem', 'tariff.services.taxSystems', 'tariff.services.children']);

        $estimate = Estimate::firstOrCreate(
            ['client_id' => $client->id],
            ['total' => 0]
        );

        $estimate->load(['rootItems.children']);

        // Учитываем только позиции с реальным service_id (не null) и не экстра
        $tariffItems = $estimate->rootItems->filter(fn($i) => !$i->is_extra && $i->service_id !== null);
        $isFirstLoad = $tariffItems->isEmpty();

        // Enabled service_ids from saved estimate
        $savedByServiceId      = $tariffItems->keyBy('service_id');
        $savedChildByServiceId = $tariffItems
            ->flatMap(fn($i) => $i->children)
            ->filter(fn($c) => $c->service_id !== null)
            ->keyBy('service_id');

        // Build tariff BPs with toggle state
        $tariffBPs = [];
        if ($client->tariff_id) {
            $clientTaxSystemId = $client->tax_system_id;
            $rootServices = $client->tariff->services
                ->filter(fn($s) => !$s->parent_id
                    && (
                        !$clientTaxSystemId                              // у клиента не задан РН — показываем всё
                        || $s->taxSystems->isEmpty()                     // у БП ещё нет РН — показываем (обратная совмест.)
                        || $s->taxSystems->contains('id', $clientTaxSystemId) // РН клиента совпадает
                    ))
                ->values();

            foreach ($rootServices as $bp) {
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

                $tariffBPs[] = $bpData;
            }
        }

        // Extra items (added outside tariff)
        $extraItems = $estimate->rootItems->filter(fn($i) => $i->is_extra);
        $extraServiceIds = $extraItems
            ->flatMap(fn($i) => collect([$i->service_id])->merge($i->children->pluck('service_id')))
            ->filter()->unique()->values()->toArray();
        $extraServicesById = $extraServiceIds
            ? Service::whereIn('id', $extraServiceIds)->get()->keyBy('id')
            : collect();

        $extras = $extraItems
            ->map(fn($item) => [
                'service_id'      => $item->service_id,
                'name'            => $item->name,
                'periodicity'     => $item->periodicity ?? '',
                'cost'            => (float) $item->cost,
                'quantity'        => (int) $item->quantity,
                'allows_quantity' => (bool) ($extraServicesById->get($item->service_id)?->allows_quantity ?? false),
                'children'        => $item->children->map(fn($c) => [
                    'service_id'     => $c->service_id,
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

        return view('clients.estimate', compact('client', 'estimate', 'tariffBPs', 'extras', 'allServices'));
    }

    public function show(Client $client)
    {
        $estimate = Estimate::firstOrCreate(
            ['client_id' => $client->id],
            ['total' => 0]
        );

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
                'is_extra'    => false,
                'name'        => $service->name,
                'periodicity' => $service->periodicity,
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
                    'is_extra'    => false,
                    'name'        => $childService->name,
                    'periodicity' => $childService->periodicity,
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

            $parent = $estimate->items()->create([
                'service_id'  => $extraData['service_id'] ?? null,
                'is_extra'    => true,
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
                    'is_extra'    => true,
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

    public function pdf(Client $client)
    {
        $client->load(['taxSystem', 'tariff']);
        $estimate = Estimate::with(['rootItems.children'])->where('client_id', $client->id)->first();

        if (!$estimate) {
            abort(404, 'Смета не найдена');
        }

        $pdf = Pdf::loadView('pdf.estimate', compact('client', 'estimate'))
            ->setPaper('a4', 'portrait');

        $filename = 'smeta_' . preg_replace('/[^a-zA-Z0-9]/', '_', $client->name) . '.pdf';

        return $pdf->download($filename);
    }
}
