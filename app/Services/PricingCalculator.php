<?php

namespace App\Services;

use App\Models\Billing;
use App\Models\Service;

/**
 * Единый источник цены БП. Режим биллинга — переключатель:
 *   - included / none      → 0 (входит в абонентку / не тарифицируется);
 *   - by_quantity / addon  → цена из привязанной ставки (rate);
 *   - режим не задан        → откат на собственный cost БП (обратная совместимость).
 *
 * Единица измерения берётся из ставки и служит только подписью — на расчёт
 * не влияет (сумма всегда «цена за единицу × количество»).
 */
class PricingCalculator
{
    /** Эффективная цена за единицу для БП по режиму биллинга. */
    public function unitPrice(Service $service): float
    {
        $code = $service->billingCode();

        if (in_array($code, Billing::FREE_CODES, true)) {
            return 0.0;
        }

        if (in_array($code, Billing::PAID_CODES, true)) {
            return $service->rate ? (float) $service->rate->price : 0.0;
        }

        return (float) $service->cost;
    }

    /** Сумма строки сметы: цена за единицу × количество (>= 0), округление до копеек. */
    public function lineTotal(Service $service, int $quantity): float
    {
        return round($this->unitPrice($service) * max(0, $quantity), 2);
    }
}
