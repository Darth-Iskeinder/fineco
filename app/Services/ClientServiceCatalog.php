<?php

namespace App\Services;

use App\Models\Client;
use App\Models\Service;
use Illuminate\Support\Collection;

/**
 * Какие бизнес-процессы подтягиваются клиенту.
 *
 * Раньше это жило прямо в EstimateController@edit. Вынесено отдельно, чтобы тот
 * же ответ можно было получить, не собирая смету целиком: отчёт «что изменится»
 * должен спрашивать у настоящего кода, а не повторять его логику своими словами.
 * Расходящиеся копии одного правила — верный способ показать красивый отчёт и
 * получить в смете другое.
 *
 * Порядок БП в ответе — это порядок, в котором они встают в смету, и он значим:
 * первым идёт совпадение по режиму налогообложения, затем особые условия,
 * затем рекомендательные.
 */
class ClientServiceCatalog
{
    /**
     * БП клиента, в порядке подтягивания.
     *
     * Три прохода, как и было:
     *  1) совпал РН клиента с РН у БП, ИЛИ категория «Обязательная» (тянется и без
     *     совпадения РН). Клиенту-нулёвке обязательные не тянем вообще, даже при
     *     совпадении РН;
     *  2) особые условия: для каждого признака, включённого у клиента, берём
     *     помеченные им БП, которых ещё нет. Без фильтра по РН;
     *  3) рекомендательные/контрольные: тянутся независимо от РН и условий
     *     (тумблер у них выключен, это решается уже в смете).
     *
     * Каталог читаем один раз и дальше фильтруем в памяти: три прохода идут по
     * одному и тому же списку активных корневых БП.
     */
    public function rootsFor(Client $client): Collection
    {
        $catalog = Service::with(['taxSystems', 'children.rate', 'rate'])
            ->roots()->active()->ordered()->get();

        $clientIsZero      = (bool) $client->is_zero_movement;
        $clientTaxSystemId = $client->tax_system_id;
        $matchesTaxSystem  = fn ($s) => $clientTaxSystemId
            && $s->taxSystems->contains('id', $clientTaxSystemId);

        // Ключ — id БП, поэтому один и тот же БП не попадёт в список дважды,
        // а порядок вставки сохраняется.
        $picked = [];

        // --- Проход 1: режим налогообложения и обязательные ---
        foreach ($catalog as $s) {
            if ($clientIsZero && Service::isMandatoryCategory($s->category)) {
                continue;
            }

            if ($matchesTaxSystem($s) || Service::isMandatoryCategory($s->category)) {
                $picked[$s->id] = $s;
            }
        }

        // --- Проход 2: особые условия клиента ---
        foreach (Service::SPECIAL_FLAGS as $col => $cfg) {
            if (!$client->{$cfg['client']}) {
                continue;
            }

            foreach ($catalog as $s) {
                if ($s->$col && !isset($picked[$s->id])) {
                    $picked[$s->id] = $s;
                }
            }
        }

        // --- Проход 3: рекомендательные / контрольные ---
        foreach ($catalog as $s) {
            if (Service::isRecommendedCategory($s->category) && !isset($picked[$s->id])) {
                $picked[$s->id] = $s;
            }
        }

        return collect(array_values($picked));
    }

    /**
     * БП, которые клиенту сейчас подтягиваются, но не проходят по типу обслуживания.
     *
     * Это и есть будущее сужение: у БП проставлен тип, а у клиента такой тип не
     * отмечен. БП без типа не отсекается никогда, клиент на полном обслуживании
     * не теряет ничего.
     *
     * Пока сужение не включено, метод ни на что не влияет и служит отчёту.
     */
    public function narrowedAwayFor(Client $client): Collection
    {
        if ($client->servesEverything()) {
            return collect();
        }

        $allowed = $client->serviceTypeKeys();

        return $this->rootsFor($client)
            ->filter(fn ($s) => $s->service_type && !in_array($s->service_type, $allowed, true))
            ->values();
    }
}
