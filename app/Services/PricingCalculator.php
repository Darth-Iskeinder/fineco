<?php

namespace App\Services;

use App\Models\Service;
use App\Models\Tariff;

class PricingCalculator
{
    /**
     * Рассчитывает итоговую стоимость услуги для заданного количества и тарифа.
     *
     * Порядок приоритетов:
     *   1. Ступенчатые цены (pricing_rules): unit_price × quantity
     *   2. Поштучно с учётом тарифа: max(0, quantity - free_limit) × (price_override ?? cost)
     *
     * @return numeric-string  Итоговая сумма (не цена за единицу).
     */
    public function calculate(Service $service, ?Tariff $tariff, int $quantity): string
    {
        // 1. Ступенчатые цены
        $tieredUnitPrice = $this->applyPricingRules($service->pricing_rules, $quantity);
        if ($tieredUnitPrice !== null) {
            return (string) ($tieredUnitPrice * $quantity);
        }

        // 2. Поштучно с учётом тарифного лимита и переопределённой цены
        [$freeLimit, $unitPrice] = $this->resolvePivotPricing($service, $tariff);

        $payableQty = max(0, $quantity - $freeLimit);

        return (string) ($payableQty * $unitPrice);
    }

    // ---------------------------------------------------------------

    /**
     * Ищет первый тир в pricing_rules, где $quantity <= max_qty.
     * Тиры должны быть отсортированы по max_qty по возрастанию.
     *
     * Структура: [['max_qty' => 10, 'price' => 2000], ['max_qty' => 50, 'price' => 1500], ...]
     */
    private function applyPricingRules(?array $rules, int $quantity): ?float
    {
        if (empty($rules)) {
            return null;
        }

        foreach ($rules as $tier) {
            if ($quantity <= (int) $tier['max_qty']) {
                return (float) $tier['price'];
            }
        }

        return null;
    }

    /**
     * Возвращает [free_limit, unit_price] из pivot-связи услуги с тарифом.
     * Если тариф не передан или связи нет — лимит 0, цена = service->cost.
     *
     * @return array{int, float}
     */
    private function resolvePivotPricing(Service $service, ?Tariff $tariff): array
    {
        if ($tariff === null) {
            return [0, (float) $service->cost];
        }

        $pivot = $service->tariffs->firstWhere('id', $tariff->id)?->pivot;

        if ($pivot === null) {
            return [0, (float) $service->cost];
        }

        $freeLimit = (int) $pivot->free_limit;
        $unitPrice = $pivot->price_override !== null
            ? (float) $pivot->price_override
            : (float) $service->cost;

        return [$freeLimit, $unitPrice];
    }
}
