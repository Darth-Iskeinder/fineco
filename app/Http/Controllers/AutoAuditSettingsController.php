<?php

namespace App\Http\Controllers;

use App\Models\Service;
use App\Models\Tenant;
use App\Services\AutoAudit\AutoAuditRunner;
use App\Services\AutoAudit\AutoAuditSources;
use App\Services\AutoAudit\DocumentPeriod;
use App\Support\Impersonation;
use App\Support\TenantContext;
use Illuminate\View\View;

/**
 * «Настройки → Автоаудит»: какие проверки идут в фирме и какие счета ведомости они берут.
 *
 * Пока только просмотр. Счета фирме задаёт вендор командой autoaudit:accounts, правка
 * на странице и выключатель проверки будут следующими шагами.
 *
 * Видят те же, кто видит страницу автоаудита: вендор, зашедший в фирму, и руководитель
 * фирмы, которой автоаудит открыт. Остальным 404, и пункта в меню у них нет.
 */
class AutoAuditSettingsController extends Controller
{
    public static function allowed(): bool
    {
        return Impersonation::isActive() || (bool) auth('employee')->user()?->canSeeAutoAudit();
    }

    public function show(): View
    {
        abort_unless(self::allowed(), 404);

        $tenant = Tenant::find(TenantContext::id());
        $start  = $tenant?->autoAuditFrom();

        return view('settings.auto-audit', [
            'rows'    => $this->rows($tenant, $start),
            'start'   => $start ? $this->monthTitle($start) : null,
            'nightly' => (bool) $tenant?->autoAuditNightly(),
        ]);
    }

    /** @return array<int, array> строка на каждую проверку */
    private function rows(?Tenant $tenant, ?string $start): array
    {
        $own    = $tenant?->autoAuditAccounts() ?? [];
        $marked = Service::whereIn('reference_id', array_column(AutoAuditSources::SIDES, 'ref'))
            ->pluck('reference_id')
            ->all();

        $rows = [];

        foreach (AutoAuditRunner::RULES as $number => $rule) {
            // Проверка идёт, только если в фирме отмечены оба её БП: ведомость и второй документ.
            $missing = array_values(array_filter(
                ['osv', $rule['document']],
                fn (string $side) => !in_array(AutoAuditSources::SIDES[$side]['ref'], $marked),
            ));

            // Своя дата старта у проверки, если она позже старта фирмы: новая проверка
            // в прошлое не смотрит.
            $from = $rule['from'] && $rule['from'] > ($start ?? '') ? $this->monthTitle($rule['from']) : null;

            $rows[] = [
                'number'   => $number,
                'name'     => $rule['name'],
                'hint'     => AutoAuditRunner::hintFor($number, $tenant),
                'accounts' => AutoAuditRunner::accountsFor($number, $tenant),
                'default'  => $rule['account'],
                'changed'  => $own[$number] ?? null,
                'missing'  => array_map(fn (string $side) => AutoAuditSources::SIDES[$side]['label'], $missing),
                'from'     => $from,
            ];
        }

        return $rows;
    }

    /** '2026-09' => «сентябрь 2026». */
    private function monthTitle(string $month): string
    {
        [$year, $number] = array_map('intval', explode('-', $month));

        return DocumentPeriod::of($year, $number)->title();
    }
}
