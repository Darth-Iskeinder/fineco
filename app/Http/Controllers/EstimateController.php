<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Employee;
use App\Models\Estimate;
use App\Models\EstimateItem;
use App\Models\Role;
use App\Models\Service;
use App\Services\ClientServiceCatalog;
use App\Services\PricingCalculator;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class EstimateController extends Controller
{
    /** Смета чужой компании недоступна — правило видимости то же, что в списке клиентов. */
    private function authorizeClient(Client $client): void
    {
        abort_unless($client->isVisibleTo(auth('employee')->user()), 403, 'Это не ваш клиент');
    }

    /**
     * Может ли текущий пользователь переназначать исполнителей БП в смете клиента.
     * Только главбух этого клиента (он же ответственный) или админ.
     */
    private function canAssign(Client $client): bool
    {
        $user = auth('employee')->user();
        if (!$user) {
            return false;
        }

        return $user->isAdmin()
            || ($user->id === $client->responsible_employee_id && $user->isHeadAccountant());
    }

    public function edit(Request $request, Client $client)
    {
        $this->authorizeClient($client);

        $client->load(['taxSystem']);

        // Одна смета на клиента
        $estimate = Estimate::firstOrCreate(
            ['client_id' => $client->id],
            ['total' => 0]
        );

        $estimate->load(['rootItems.children']);

        // Первая загрузка = смету ещё ни разу не сохраняли. Только тогда действуют дефолты
        // тумблеров (рекомендательные выключены, подпункты выключены); дальше побеждает
        // сохранённое состояние — даже если все тарифные БП сняли, а остались только доп. услуги.
        $isFirstLoad = $estimate->rootItems->isEmpty();

        $estimateHasItems = $estimate->items()->exists();

        // Налоговые органы клиента для филиальных БП: основной + филиалы.
        // Название филиала берём из справочника по коду НО (единый источник с карточкой клиента),
        // а не из снимка branches.city — иначе устаревший city разойдётся с кодом.
        $taxAuthorityNames = \App\Models\TaxAuthority::pluck('name', 'code');
        $clientHasBranches = $client->has_branches
            && collect($client->branches ?? [])->contains(fn($b) => !empty($b['no_code']));
        $branchTargets = collect([['code' => $client->tax_office_code, 'label' => 'основной']])
            ->concat(collect($client->branches ?? [])
                ->filter(fn($b) => !empty($b['no_code']))
                ->map(fn($b) => [
                    'code'  => $b['no_code'],
                    'label' => $taxAuthorityNames->get($b['no_code']) ?? $b['no_code'],
                ]))
            ->values();

        $flagKeys = array_keys(Service::SPECIAL_FLAGS);
        $pricing  = new PricingCalculator();
        $responsibleId = $client->responsible_employee_id; // дефолтный исполнитель (главбух)

        // Реальные исполнители сохранённых позиций + ответственный: имена нужны для честного
        // отображения «на ком стоит задача» (в т.ч. read-only для тех, кто не может назначать).
        $savedAssigneeIds = $estimate->rootItems->pluck('assignee_id')->filter()->unique()->values();
        $assigneeNames = Employee::whereIn(
            'id',
            $savedAssigneeIds->concat([$responsibleId])->filter()->unique()
        )->pluck('full_name', 'id');

        // Индивидуальные расписания БП этого клиента (override дефолтов), keyed by service_id
        $overrides = $client->serviceSchedules()->get()->keyBy('service_id');

        // Расписание строки сметы: эффективное (дефолт БП или индивидуальное) + готовые
        // подписи срока. Одинаково для тарифных БП и для доп. услуг из каталога.
        $scheduleData = function (Service $svc) use ($overrides) {
            $override = $overrides->get($svc->id);
            $resolved = $svc->resolveForClient($override);

            return [
                'is_custom'   => $override !== null,
                'periodicity' => $resolved['periodicity'] ?? '',
                'start_month' => $resolved['months'],
                'start_day'   => $resolved['days'],
                'labels'      => $svc->deadlineLabelsForClient($override),
            ];
        };

        $tariffBPs = [];

        // Сборка структуры БП с состоянием тоглов.
        // $savedItem — сохранённая строка сметы для этого БП и НО (или null);
        // $taxOfficeCode/$branchLabel заданы только для филиальных копий.
        $buildBpData = function ($bp, $savedItem, $taxOfficeCode = null, $branchLabel = null) use ($isFirstLoad, $flagKeys, $scheduleData, $pricing, $responsibleId, $assigneeNames) {
            $assigneeId = $savedItem?->assignee_id ?? $responsibleId;
            $bpData = [
                'service_id'      => $bp->id,
                'row_key'         => $taxOfficeCode !== null ? $bp->id . ':' . $taxOfficeCode : (string) $bp->id,
                'assignee_id'     => $assigneeId,
                'assignee_name'   => $assigneeId ? $assigneeNames->get($assigneeId) : null,
                'tax_office_code' => $taxOfficeCode,
                'branch_label'    => $branchLabel,
                'name'            => $bp->name,
                'sphere'          => $bp->sphere,
                'cost'            => $pricing->unitPrice($bp),
                'unit'            => $bp->rate?->unit,
                'periodicity'     => $bp->periodicity ?? '',
                'allows_quantity' => $bp->allows_quantity,
                // Рекомендательные/контрольные БП на первой загрузке приходят выключенными;
                // обычные — включёнными. На повторных загрузках всегда побеждает сохранённое состояние.
                'enabled'         => $isFirstLoad
                    ? !Service::isRecommendedCategory($bp->category)
                    : ($savedItem !== null),
                'quantity'        => $savedItem ? (int) $savedItem->quantity : 1,
                'children'        => [],
            ];

            foreach ($flagKeys as $fk) {
                $bpData[$fk] = (bool) $bp->$fk;
            }

            // Индивидуальное расписание клиента (или дефолт БП, если override нет)
            $bpData['schedule'] = $scheduleData($bp);

            $savedChildren = $savedItem ? $savedItem->children->keyBy('service_id') : collect();
            foreach ($bp->children as $child) {
                $savedChild = $savedChildren->get($child->id);
                $bpData['children'][] = [
                    'service_id'     => $child->id,
                    'name'           => $child->name,
                    'cost'           => $pricing->unitPrice($child),
                    'unit'           => $child->rate?->unit,
                    'periodicity'    => $child->periodicity ?? '',
                    'allows_quantity'=> $child->allows_quantity,
                    'enabled'        => $isFirstLoad ? false : ($savedChild !== null),
                    'quantity'       => $savedChild ? (int) $savedChild->quantity : 1,
                ];
            }

            return $bpData;
        };

        // Наметить строку тарифа: филиальный БП (splits_by_branch) с филиалами — размножаем
        // по НО, иначе одна строка. Сами строки собираются ниже, когда известен полный
        // список подтянутых БП: только по нему отличаем тарифные позиции от доп. услуг.
        $bpPlans = [];
        $pushBp = function ($bp) use (&$bpPlans, $clientHasBranches, $branchTargets) {
            if ($bp->splits_by_branch && $clientHasBranches) {
                foreach ($branchTargets as $t) {
                    $bpPlans[] = [$bp, $t['code'], $t['label']];
                }
            } else {
                $bpPlans[] = [$bp, null, null];
            }
        };

        // Какие БП подтягиваются этому клиенту — см. ClientServiceCatalog.
        // Порядок ответа значим: в нём БП и встают в смету.
        foreach ((new ClientServiceCatalog())->rootsFor($client) as $bp) {
            $pushBp($bp);
        }

        // Тарифная позиция — только та, чей БП реально подтянулся клиенту в этот раз
        // (ключ = service_id + НО: у филиальных БП на один service_id несколько строк).
        // Раньше тарифной считалась любая строка «recurring + service_id», и доп. услуга
        // из каталога с типом «Постоянная» после сохранения либо всплывала в блоке тарифа,
        // либо пропадала с экрана (оставаясь в базе и порождая задачи), а следующим
        // сохранением удалялась вместе со своими логами задач.
        $tariffKeys = collect($bpPlans)
            ->map(fn($p) => $p[0]->id . ':' . ($p[1] ?? ''))
            ->flip();

        // На один ключ приходится ровно одна тарифная строка (строки идут по sort_order,
        // тарифные сохраняются первыми). Вторая строка того же БП — это доп. услуга,
        // добавленная вручную: показываем её в блоке доп. услуг, а не теряем.
        $savedByKey  = collect();
        $extraItems  = collect();
        foreach ($estimate->rootItems as $item) {
            $key = $item->service_id . ':' . ($item->tax_office_code ?? '');
            $isTariffRow = $item->type === 'recurring'
                && $item->service_id !== null
                && $tariffKeys->has($key)
                && !$savedByKey->has($key);

            if ($isTariffRow) {
                $savedByKey[$key] = $item;
            } else {
                $extraItems->push($item);
            }
        }

        foreach ($bpPlans as [$bp, $taxOfficeCode, $branchLabel]) {
            $tariffBPs[] = $buildBpData(
                $bp,
                $savedByKey->get($bp->id . ':' . ($taxOfficeCode ?? '')),
                $taxOfficeCode,
                $branchLabel,
            );
        }

        $extraServiceIds = $extraItems
            ->flatMap(fn($i) => collect([$i->service_id])->merge($i->children->pluck('service_id')))
            ->filter()->unique()->values()->toArray();
        $extraServicesById = $extraServiceIds
            ? Service::with('rate')->whereIn('id', $extraServiceIds)->get()->keyBy('id')
            : collect();

        // Доп. услуга из каталога — такая же строка сметы, как тарифная: у неё есть срок
        // (расписание её БП с учётом override клиента) и исполнитель. У своей услуги
        // (без service_id) расписания нет — блок срока для неё не показывается.
        $extras = $extraItems
            ->map(function ($item) use ($extraServicesById, $scheduleData, $responsibleId, $assigneeNames) {
                $svc        = $item->service_id ? $extraServicesById->get($item->service_id) : null;
                $assigneeId = $svc ? ($item->assignee_id ?? $responsibleId) : null;

                return [
                    'service_id'      => $item->service_id,
                    'type'            => $item->type,
                    'name'            => $item->name,
                    'periodicity'     => $item->periodicity ?? '',
                    'cost'            => (float) $item->cost,
                    'unit'            => $svc?->rate?->unit,
                    'quantity'        => (int) $item->quantity,
                    'allows_quantity' => (bool) ($svc?->allows_quantity ?? false),
                    'schedule'        => $svc ? $scheduleData($svc) : null,
                    'assignee_id'     => $assigneeId,
                    'assignee_name'   => $assigneeId ? $assigneeNames->get($assigneeId) : null,
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
                ];
            })->values()->toArray();

        // All catalog BPs for "add extra" modal (root only, with children)
        $allServices = Service::with(['children.rate', 'rate'])
            ->roots()
            ->active()
            ->ordered()
            ->get()
            ->map(fn($s) => [
                'id'             => $s->id,
                'name'           => $s->name,
                'periodicity'    => $s->periodicity ?? '',
                'cost'           => $pricing->unitPrice($s),
                'unit'           => $s->rate?->unit,
                'allows_quantity'=> $s->allows_quantity,
                // Срок и исполнитель по умолчанию: чтобы у только что добавленной
                // доп. услуги они были видны сразу, а не после сохранения.
                'schedule'       => $scheduleData($s),
                'assignee_id'    => $responsibleId,
                'assignee_name'  => $responsibleId ? $assigneeNames->get($responsibleId) : null,
                'children'       => $s->children->map(fn($c) => [
                    'id'             => $c->id,
                    'name'           => $c->name,
                    'periodicity'    => $c->periodicity ?? '',
                    'cost'           => $pricing->unitPrice($c),
                    'unit'           => $c->rate?->unit,
                    'allows_quantity'=> $c->allows_quantity,
                ])->values()->toArray(),
            ])->values()->toArray();

        // Переназначение исполнителей: доступно только главбуху клиента (+ админу).
        // Кандидаты — активные бухгалтеры + сам главбух (ответственный).
        $canAssign = $this->canAssign($client);
        $assigneeOptions = [];
        if ($canAssign) {
            // Кандидаты: работающие бухгалтеры + ответственный. Плюс уже назначенные
            // исполнители сохранённых позиций (даже уволенные) — иначе селект не сможет
            // показать, на ком реально стоит задача.
            $assigneeOptions = Employee::query()
                ->with('role')
                ->where(function ($q) use ($client, $savedAssigneeIds) {
                    $q->where(function ($q2) use ($client) {
                        $q2->assignable()
                           ->where(function ($q3) use ($client) {
                               $q3->whereHas('role', fn ($r) => $r->where('name', Role::ACCOUNTANT))
                                  ->orWhere('id', $client->responsible_employee_id);
                           });
                    })->orWhereIn('id', $savedAssigneeIds);
                })
                ->orderBy('full_name')
                ->get()
                ->map(fn ($e) => [
                    'id'        => $e->id,
                    'full_name' => $e->full_name,
                    'role'      => $e->role?->display_name,
                ])
                ->values()
                ->toArray();
        }

        $specialFlags = Service::specialFlagsList();

        // Справочник периодичностей для редактора расписания (name + kind)
        $periodicities = \App\Models\Periodicity::orderBy('id')
            ->get(['name', 'kind'])
            ->map(fn ($p) => ['name' => $p->name, 'kind' => $p->kind])
            ->values()->toArray();

        // Подпись для предупреждения перед сохранением: с какого дня пойдут задачи
        // по тому, что добавят сейчас. Локаль приложения — en, месяц склоняем сами.
        $tasksStart = EstimateItem::tasksStartForNew();
        $months     = ['января', 'февраля', 'марта', 'апреля', 'мая', 'июня',
                       'июля', 'августа', 'сентября', 'октября', 'ноября', 'декабря'];
        $tasksStartLabel = $tasksStart->day . ' ' . $months[$tasksStart->month - 1] . ' ' . $tasksStart->year;

        // Урезанный тип обслуживания объясняет, почему БП меньше, чем ожидали.
        // У клиента на полном обслуживании список пуст, и подсказка не показывается.
        $serviceScopeLabels = $client->servesEverything() ? [] : $client->serviceTypeLabels();

        return view('clients.estimate', compact(
            'client', 'estimate', 'tariffBPs', 'extras', 'allServices', 'specialFlags',
            'estimateHasItems', 'periodicities', 'canAssign', 'assigneeOptions', 'tasksStartLabel',
            'serviceScopeLabels'
        ));
    }

    public function show(Request $request, Client $client)
    {
        $this->authorizeClient($client);

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
        $this->authorizeClient($client);

        $request->validate([
            'notes'                                => 'nullable|string|max:1000',
            'tariff_bps'                           => 'nullable|array',
            'tariff_bps.*.service_id'              => 'required|integer',
            'tariff_bps.*.tax_office_code'         => 'nullable|string|max:10',
            'tariff_bps.*.branch_label'            => 'nullable|string|max:255',
            'tariff_bps.*.enabled'                 => 'boolean',
            'tariff_bps.*.quantity'                => 'nullable|integer|min:1',
            'tariff_bps.*.assignee_id'             => 'nullable|integer|exists:employees,id',
            'tariff_bps.*.children'                => 'nullable|array',
            'tariff_bps.*.children.*.service_id'   => 'required|integer',
            'tariff_bps.*.children.*.enabled'      => 'boolean',
            'tariff_bps.*.children.*.quantity'     => 'nullable|integer|min:1',
            'extras'                               => 'nullable|array',
            'extras.*.name'                        => 'required|string|max:255',
            'extras.*.cost'                        => 'nullable|numeric|min:0',
            'extras.*.quantity'                    => 'nullable|integer|min:1',
            'extras.*.assignee_id'                 => 'nullable|integer|exists:employees,id',
        ]);

        return DB::transaction(function () use ($request, $client) {
            $estimate = Estimate::firstOrCreate(
                ['client_id' => $client->id],
                ['total' => 0]
            );

            // РЕКОНСИЛ вместо «снести всё и создать заново»: совпавшие по стабильному ключу позиции
            // ОБНОВЛЯЕМ на месте (id сохраняется → логи задач buh_task_logs целы, в т.ч. «на проверке»),
            // недостающие создаём, реально исчезнувшие удаляем в конце (их логи уходят каскадом — БП убрали).
            // Это чинит потерю истории при любом сохранении сметы, включая переназначение исполнителя.
            // На один ключ может приходиться несколько строк: тот же БП добавили и в тариф,
            // и вручную в доп. услуги. Держим очередь и разбираем по порядку (тарифные
            // обрабатываются первыми) — иначе строки перетасовываются между сохранениями
            // и теряют id вместе с логами задач.
            $existingRoots = $estimate->items()->whereNull('parent_id')->with('children')->orderBy('sort_order')->get();
            $rootByKey = [];
            foreach ($existingRoots as $r) {
                $rootByKey[$this->itemKey($r->service_id, $r->tax_office_code, $r->branch_label, $r->name, $r->type)][] = $r;
            }

            $canAssign = $this->canAssign($client);

            // Кого вообще можно поставить исполнителем присланным payload'ом.
            // Селект показывает работающих бухгалтеров, ответственного и тех, на ком
            // позиции уже стоят, — но форму можно отправить и мимо селекта, поэтому
            // тот же круг проверяем на сервере. Уже назначенных оставляем: иначе
            // смета с уволенным исполнителем перестала бы сохраняться, пока его не
            // заменят, а замена — отдельное решение главбуха.
            $assignable = Employee::assignable()->pluck('id')
                ->merge($existingRoots->pluck('assignee_id')->filter())
                ->push($client->responsible_employee_id)
                ->filter()
                ->map(fn ($id) => (int) $id)
                ->flip();

            $pricing     = new PricingCalculator();
            $total       = 0;
            $sortOrder   = 0;
            $keptRootIds = [];

            // Tariff BPs (only enabled ones)
            foreach ($request->input('tariff_bps', []) as $bpData) {
                if (empty($bpData['enabled'])) continue;

                $service = Service::with('rate')->find($bpData['service_id']);
                if (!$service) continue;

                $qty      = (int) ($bpData['quantity'] ?? 1);
                $children = collect($bpData['children'] ?? [])->filter(fn($c) => !empty($c['enabled']));

                $key      = $this->itemKey($service->id, $bpData['tax_office_code'] ?? null, $bpData['branch_label'] ?? null, $service->name, 'recurring');
                // Забираем строку из очереди — «использована», второй раз не сматчится
                $existing = !empty($rootByKey[$key]) ? array_shift($rootByKey[$key]) : null;

                // Исполнитель БП. Главбух может задать явно (payload); иначе сохраняем прежнего
                // (при обновлении на месте он уже стоит), по умолчанию — ответственный клиента.
                if ($canAssign && !empty($bpData['assignee_id']) && $assignable->has((int) $bpData['assignee_id'])) {
                    $assigneeId = (int) $bpData['assignee_id'];
                } else {
                    $assigneeId = $existing?->assignee_id ?? $client->responsible_employee_id;
                }

                $attrs = [
                    'service_id'      => $service->id,
                    'assignee_id'     => $assigneeId,
                    'tax_office_code' => $bpData['tax_office_code'] ?? null,
                    'branch_label'    => $bpData['branch_label'] ?? null,
                    'type'            => 'recurring',
                    'name'            => $service->name,
                    'periodicity'     => $service->periodicity,
                    'due_day'         => $service->due_day,
                    'cost'            => $pricing->unitPrice($service),
                    'quantity'        => $qty,
                    'sort_order'      => $sortOrder++,
                ];

                if ($existing) {
                    // Обновление на месте: границу задач не трогаем — БП уже ведут.
                    $existing->update($attrs);
                    $parent = $existing;
                } else {
                    // Новая позиция: задачи по ней пойдут со следующего месяца, а не
                    // задним числом за сроки, которые в этом месяце уже прошли.
                    $parent = $estimate->items()->create($attrs + [
                        'total'            => 0,
                        'tasks_start_from' => EstimateItem::tasksStartForNew()->toDateString(),
                    ]);
                }

                $childTotal = 0;
                $childOrder = 0;
                $childByKey = [];
                foreach (($existing->children ?? collect()) as $c) {
                    $childByKey[$this->itemKey($c->service_id, null, null, $c->name, $c->type)] = $c;
                }
                $keptChildIds = [];

                foreach ($children as $childData) {
                    $childService = Service::with('rate')->find($childData['service_id']);
                    if (!$childService) continue;

                    $cqty   = (int) ($childData['quantity'] ?? 1);
                    $cTotal = $pricing->lineTotal($childService, $cqty);
                    $childTotal += $cTotal;

                    $cAttrs = [
                        'parent_id'   => $parent->id,
                        'service_id'  => $childService->id,
                        'type'        => 'recurring',
                        'name'        => $childService->name,
                        'periodicity' => $childService->periodicity,
                        'due_day'     => $childService->due_day,
                        'cost'        => $pricing->unitPrice($childService),
                        'quantity'    => $cqty,
                        'total'       => $cTotal,
                        'sort_order'  => $childOrder++,
                    ];
                    $ckey  = $this->itemKey($childService->id, null, null, $childService->name, 'recurring');
                    $child = $childByKey[$ckey] ?? null;
                    if ($child) {
                        $child->update($cAttrs);
                    } else {
                        $child = $estimate->items()->create($cAttrs);
                    }
                    $keptChildIds[] = $child->id;
                }

                // Убрать подпункты, которых больше нет (их логи уйдут каскадом)
                if ($existing) {
                    $parent->children()->whereNotIn('id', $keptChildIds ?: [0])->delete();
                }

                $parentTotal = $children->isNotEmpty()
                    ? $childTotal
                    : $pricing->lineTotal($service, $qty);

                $parent->update(['total' => $parentTotal]);
                $total += $parentTotal;
                $keptRootIds[] = $parent->id;
            }

            // Extra items
            foreach ($request->input('extras', []) as $extraData) {
                if (empty($extraData['name'])) continue;

                $cost     = (float) ($extraData['cost'] ?? 0);
                $qty      = (int) ($extraData['quantity'] ?? 1);
                $rowTotal = round($cost * $qty, 2);

                $extraType = in_array($extraData['type'] ?? '', ['recurring', 'one_time'])
                    ? $extraData['type'] : 'one_time';

                $key      = $this->itemKey($extraData['service_id'] ?? null, null, null, $extraData['name'], $extraType);
                $existing = !empty($rootByKey[$key]) ? array_shift($rootByKey[$key]) : null;

                // Доп. услуга из каталога — полноценная позиция сметы: периодичность и день
                // срока берём из её БП (как у тарифных), а не из присланных данных.
                $extraService = !empty($extraData['service_id'])
                    ? Service::find($extraData['service_id'])
                    : null;

                // Исполнителя может задать только главбух клиента/админ; иначе оставляем
                // прежнего. Пусто — задача уйдёт ответственному (см. BuhTasksController).
                if ($canAssign && !empty($extraData['assignee_id']) && $assignable->has((int) $extraData['assignee_id'])) {
                    $extraAssigneeId = (int) $extraData['assignee_id'];
                } else {
                    $extraAssigneeId = $existing?->assignee_id;
                }

                $attrs = [
                    'service_id'  => $extraData['service_id'] ?? null,
                    'assignee_id' => $extraAssigneeId,
                    'type'        => $extraType,
                    'name'        => $extraData['name'],
                    'periodicity' => $extraService?->periodicity ?? ($extraData['periodicity'] ?? null),
                    'due_day'     => $extraService?->due_day,
                    'cost'        => $cost,
                    'quantity'    => $qty,
                    'total'       => $rowTotal,
                    'sort_order'  => $sortOrder++,
                ];

                if ($existing) {
                    $existing->update($attrs);
                    $parent = $existing;
                } else {
                    // Постоянная доп. услуга — такой же повторяющийся БП, как тарифный:
                    // задачи по ней идут со следующего месяца. Временную (разовую)
                    // добавляют, чтобы сделать сейчас, — её не откладываем.
                    $parent = $estimate->items()->create($attrs + ($extraType === 'recurring'
                        ? ['tasks_start_from' => EstimateItem::tasksStartForNew()->toDateString()]
                        : []));
                }

                $childTotal = 0;
                $childByKey = [];
                foreach (($existing->children ?? collect()) as $c) {
                    $childByKey[$this->itemKey($c->service_id, null, null, $c->name, $c->type)] = $c;
                }
                $keptChildIds = [];
                $childOrder   = 0;

                foreach ($extraData['children'] ?? [] as $childData) {
                    if (empty($childData['name'])) continue;
                    $cc = (float) ($childData['cost'] ?? 0);
                    $cq = (int) ($childData['quantity'] ?? 1);
                    $ct = round($cc * $cq, 2);
                    $childTotal += $ct;

                    $cAttrs = [
                        'parent_id'   => $parent->id,
                        'service_id'  => $childData['service_id'] ?? null,
                        'type'        => $extraType,
                        'name'        => $childData['name'],
                        'periodicity' => $childData['periodicity'] ?? null,
                        'cost'        => $cc,
                        'quantity'    => $cq,
                        'total'       => $ct,
                        'sort_order'  => $childOrder++,
                    ];
                    $ckey  = $this->itemKey($childData['service_id'] ?? null, null, null, $childData['name'], $extraType);
                    $child = $childByKey[$ckey] ?? null;
                    if ($child) {
                        $child->update($cAttrs);
                    } else {
                        $child = $estimate->items()->create($cAttrs);
                    }
                    $keptChildIds[] = $child->id;
                }

                if ($existing) {
                    $parent->children()->whereNotIn('id', $keptChildIds ?: [0])->delete();
                }

                if (!empty($extraData['children'])) {
                    $parent->update(['total' => $childTotal]);
                    $total += $childTotal;
                } else {
                    $total += $rowTotal;
                }
                $keptRootIds[] = $parent->id;
            }

            // Удаляем корневые позиции, которых больше нет в присланных, и их подпункты
            // (buh_task_logs этих позиций уходят каскадом — БП/подпункт убрали из сметы).
            $removedRootIds = $existingRoots->pluck('id')->diff($keptRootIds)->values();
            if ($removedRootIds->isNotEmpty()) {
                $estimate->items()->whereIn('parent_id', $removedRootIds)->delete();
                $estimate->items()->whereIn('id', $removedRootIds)->delete();
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
        });
    }

    /**
     * Стабильный ключ позиции сметы для reconcile в save():
     * по услуге+НО+филиалу, а для строк без услуги (extras) — по имени+типу.
     * Одинаково вычисляется и для присланной строки, и для существующей в БД.
     */
    private function itemKey(?int $serviceId, ?string $taxOffice, ?string $branch, ?string $name, ?string $type): string
    {
        return $serviceId
            ? 'svc:' . $serviceId . ':' . ($taxOffice ?? '') . ':' . ($branch ?? '')
            : 'name:' . mb_strtolower(trim((string) $name)) . ':' . ($type ?? '');
    }

    public function pdf(Request $request, Client $client)
    {
        $this->authorizeClient($client);

        $client->load(['taxSystem', 'tariff']);
        $estimate = Estimate::with(['rootItems.children'])
            ->where('client_id', $client->id)
            ->first();

        if (!$estimate) {
            abort(404, 'Смета не найдена');
        }

        $pdf = Pdf::loadView('pdf.estimate', compact('client', 'estimate'))
            ->setPaper('a4', 'portrait');

        // Имя файла из кириллицы: без транслитерации preg_replace оставлял одни подчёркивания
        $filename = 'smeta-' . (Str::slug($client->name) ?: 'client-' . $client->id) . '.pdf';

        // Обе ссылки на смету открываются в новой вкладке — показываем PDF там,
        // а не отдаём вложением: иначе вкладка остаётся пустой, а файл молча падает в загрузки.
        return $pdf->stream($filename);
    }
}
