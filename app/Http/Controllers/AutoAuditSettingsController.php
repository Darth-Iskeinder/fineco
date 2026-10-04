<?php

namespace App\Http\Controllers;

use App\Models\AutoAuditResult;
use App\Models\Service;
use App\Models\Tenant;
use App\Services\AutoAudit\AccountList;
use App\Services\AutoAudit\AutoAuditRunner;
use App\Services\AutoAudit\AutoAuditSources;
use App\Services\AutoAudit\DocumentPeriod;
use App\Support\Impersonation;
use App\Support\TenantContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * «Настройки → Автоаудит»: какие проверки идут в фирме и какие счета ведомости они берут.
 *
 * Счета у проверки меняет руководитель прямо здесь (или вендор, зашедший в фирму, или
 * вендор командой autoaudit:accounts). Новые счета действуют на все месяцы: прогон и так
 * каждый раз пересчитывает всё со старта.
 *
 * Здесь же проверку выключают и включают. Срабатывает со следующего прогона: страница
 * автоаудита и вопросы в БухЗадачнике показывают то, что записал прогон, а не настройку.
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

    /**
     * Задать проверке свои счета или вернуть общий (reset).
     *
     * Здесь проверяем только, что это похоже на счёт. Есть ли счёт в ведомостях, смотрит
     * команда: на странице это пока не делаем (Искендер, 04.10.2026).
     */
    public function update(Request $request, int $rule): RedirectResponse
    {
        abort_unless(self::allowed(), 404);
        abort_unless(isset(AutoAuditRunner::RULES[$rule]), 404);

        $tenant = Tenant::findOrFail(TenantContext::id());

        try {
            $accounts = $request->boolean('reset')
                ? []
                : AccountList::parse((string) $request->input('accounts'), AutoAuditRunner::RULES[$rule]['account']);
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['accounts' => $e->getMessage()])->withInput(['rule' => $rule] + $request->only('accounts'));
        }

        $by = Impersonation::isActive() ? 'вендор' : auth('employee')->user()?->full_name;

        $tenant->setAutoAuditAccounts($rule, $accounts ?: null, $by);

        return redirect()->route('settings.auto-audit')->with('success', $accounts
            ? "Проверка №{$rule}: счета " . implode(', ', $accounts) . '. Следующий прогон пересчитает её за все месяцы.'
            : "Проверка №{$rule}: вернули общий счёт. Следующий прогон пересчитает её за все месяцы.");
    }

    /**
     * Включить или выключить проверку в фирме. Последнюю включённую выключить нельзя:
     * выключить автоаудит фирме целиком можно командой autoaudit:access.
     */
    public function toggle(Request $request, int $rule): RedirectResponse
    {
        abort_unless(self::allowed(), 404);
        abort_unless(isset(AutoAuditRunner::RULES[$rule]), 404);

        $tenant  = Tenant::findOrFail(TenantContext::id());
        $enabled = $request->boolean('enabled');
        $off     = $tenant->autoAuditOff();

        if (!$enabled && !array_diff_key(AutoAuditRunner::RULES, $off + [$rule => true])) {
            return back()->with('error', 'Нельзя выключить последнюю проверку. Если автоаудит в фирме не нужен совсем, напишите нам.');
        }

        $tenant->setAutoAuditRuleEnabled($rule, $enabled, Impersonation::isActive() ? 'вендор' : auth('employee')->user()?->full_name);

        return redirect()->route('settings.auto-audit')->with('success', $enabled
            ? "Проверка №{$rule} включена. Следующий прогон пересчитает её за все месяцы."
            : "Проверка №{$rule} выключена. Её строки и вопросы уйдут после следующего прогона.");
    }

    /**
     * Сколько вопросов бухгалтерам закроется, если выключить проверку: проблемные строки,
     * где кроме неё не останется ни одной включённой проверки.
     *
     * @param array<int, mixed> $off уже выключенные
     * @return array<int, int> номер проверки => вопросов
     */
    private function questionsByRule(array $off): array
    {
        $counts = array_fill_keys(array_keys(AutoAuditRunner::RULES), 0);

        $rows = AutoAuditResult::current()
            ->whereIn('outcome', AutoAuditResult::FINDING_OUTCOMES)
            ->pluck('rule');

        foreach ($rows as $rule) {
            $numbers = array_diff(array_map('intval', explode(',', (string) $rule)), array_keys($off));

            if (count($numbers) === 1 && isset($counts[reset($numbers)])) {
                $counts[reset($numbers)]++;
            }
        }

        return $counts;
    }

    /** @return array<int, array> строка на каждую проверку */
    private function rows(?Tenant $tenant, ?string $start): array
    {
        $own       = $tenant?->autoAuditAccounts() ?? [];
        $off       = $tenant?->autoAuditOff() ?? [];
        $questions = $this->questionsByRule($off);
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
                'off'      => $off[$number] ?? null,
                'questions' => $questions[$number],
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
