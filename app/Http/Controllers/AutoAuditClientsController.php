<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Tenant;
use App\Services\AutoAudit\AutoAuditClientBoard;
use App\Support\Impersonation;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Вкладка автоаудита «По клиентам»: клиенты по месяцам, за кем ход, сводка по бухгалтерам.
 *
 * Только чтение. Видят вендор, зашедший в фирму, и руководитель, если фирме открыли и
 * страницу автоаудита, и эту вкладку (autoaudit:access --on --clients-on). Остальным 404.
 *
 * Страница не грузит всё сразу (требование Искендера 06.10.2026):
 *   - таблица получает только статусы клеток, подробности клиента приходят отдельным
 *     запросом по клику (card);
 *   - на экране не больше PER_PAGE строк, дальше «Показать ещё»;
 *   - месяцев семь, дальше назад листают стрелкой.
 * Фильтры в адресе страницы и считаются на сервере.
 */
class AutoAuditClientsController extends Controller
{
    public const PER_PAGE = 60;

    /** Цвета статусов: клетка с буквой и точка на плитке. Гамма как на «Все сверки». */
    public const STYLES = [
        AutoAuditClientBoard::OVERDUE     => ['cell' => 'bg-red-50 text-red-700',         'dot' => 'bg-red-600'],
        AutoAuditClientBoard::ACCOUNTANT  => ['cell' => 'bg-amber-50 text-amber-700',     'dot' => 'bg-amber-500'],
        AutoAuditClientBoard::CHIEF       => ['cell' => 'bg-violet-50 text-violet-700',   'dot' => 'bg-violet-500'],
        AutoAuditClientBoard::UNVERIFIED  => ['cell' => 'bg-orange-50 text-orange-700',   'dot' => 'bg-orange-500'],
        AutoAuditClientBoard::WAITING_RUN => ['cell' => 'bg-sky-50 text-sky-700',         'dot' => 'bg-sky-500'],
        AutoAuditClientBoard::IN_PROGRESS => ['cell' => 'bg-slate-100 text-slate-500',    'dot' => 'bg-slate-300'],
        AutoAuditClientBoard::OK          => ['cell' => 'bg-emerald-50 text-emerald-700', 'dot' => 'bg-emerald-600'],
        AutoAuditClientBoard::NONE        => ['cell' => 'text-slate-300',                 'dot' => 'bg-slate-200'],
    ];

    /** Короткие названия месяцев для шапки таблицы. */
    public const MONTHS_SHORT = [1 => 'Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'];

    public function index(Request $request): View
    {
        $this->allowedOnly();

        $today  = CarbonImmutable::now()->startOfDay();
        $focus  = $this->focus($request, $today);
        $months = AutoAuditClientBoard::window($focus, $today);
        $board  = new AutoAuditClientBoard($today);
        $data   = $board->build($months);
        $key    = $focus->format('Y-m');

        $status = $request->query('status');
        $status = is_string($status) && isset(AutoAuditClientBoard::STATUSES[$status]) ? $status : null;
        $all    = $request->boolean('all');
        $acc    = (int) $request->query('acc') ?: null;
        $q      = trim((string) $request->query('q'));
        $limit  = max(self::PER_PAGE, min(2000, (int) $request->query('limit')));

        $rows      = collect($data['rows']);
        $employees = $data['employees'];
        $name      = fn (?int $id) => $id ? ($employees[$id] ?? 'сотрудник удалён') : 'не назначен';

        // Плитки и сводка по бухгалтерам считаются до фильтров: числа не должны прыгать от
        // того, что выбрано ниже.
        $counts = $rows->countBy(fn (array $r) => $r['cells'][$key]['status']);
        $team   = $this->team($rows, $key, $name);

        $filtered = $rows
            ->filter(fn (array $r) => $status
                ? $r['cells'][$key]['status'] === $status
                : ($all || in_array($r['cells'][$key]['status'], AutoAuditClientBoard::ACTION, true)))
            ->filter(fn (array $r) => !$acc || $r['accountant'] === $acc)
            ->filter(fn (array $r) => $q === ''
                || mb_stripos($r['client']->name, $q) !== false
                || mb_stripos($name($r['accountant']), $q) !== false)
            ->sortBy([
                fn (array $a, array $b) => $this->rank($a['cells'][$key]['status']) <=> $this->rank($b['cells'][$key]['status']),
                fn (array $a, array $b) => ($b['cells'][$key]['days'] ?? -1) <=> ($a['cells'][$key]['days'] ?? -1),
                fn (array $a, array $b) => mb_strtolower($a['client']->name) <=> mb_strtolower($b['client']->name),
            ])
            ->values();

        return view('auto-audit.clients', [
            'months'       => $months,
            'focus'        => $focus,
            'key'          => $key,
            'prev'         => $focus->subMonth(),
            'next'         => $focus->addMonth()->lte($today->startOfMonth()) ? $focus->addMonth() : null,
            'rows'         => $filtered->take($limit),
            'total'        => $filtered->count(),
            'limit'        => $limit,
            'counts'       => $counts,
            'team'         => $team,
            'clientsCount' => $rows->count(),
            'notConnected' => $data['notConnected'],
            'status'       => $status,
            'all'          => $all,
            'acc'          => $acc,
            'q'            => $q,
            'name'         => $name,
            'checkedAt'    => $board->checkedAt()?->setTimezone(config('app.timezone')),
            'vendor'       => Impersonation::isActive(),
            'openToManager' => $this->openToManager(),
        ]);
    }

    /** Карточка клиента за месяц: отдельный запрос по клику, отдаёт кусок страницы. */
    public function card(Request $request, Client $client): View
    {
        $this->allowedOnly();

        $today  = CarbonImmutable::now()->startOfDay();
        $focus  = $this->focus($request, $today);
        $months = AutoAuditClientBoard::window($focus, $today);
        $board  = new AutoAuditClientBoard($today);
        $data   = $board->build($months, $client->id);
        $row    = $data['rows'][0] ?? null;

        abort_unless($row, 404);

        $employees = $data['employees'];

        return view('auto-audit.clients-card', [
            'row'       => $row,
            'months'    => $months,
            'focus'     => $focus,
            'cell'      => $row['cells'][$focus->format('Y-m')],
            'name'      => fn (?int $id) => $id ? ($employees[$id] ?? 'сотрудник удалён') : 'не назначен',
            'checkedAt' => $board->checkedAt()?->setTimezone(config('app.timezone')),
            'today'     => $today,
        ]);
    }

    /**
     * Сводка по бухгалтерам за выбранный месяц: сколько у кого клиентов, сколько ждут его,
     * главбуха и просрочено, и сколько дней висит самый старый вопрос.
     */
    private function team($rows, string $key, callable $name): array
    {
        return $rows
            ->groupBy(fn (array $r) => $r['accountant'] ?? 0)
            ->map(function ($group, $id) use ($key, $name) {
                $cells = $group->map(fn (array $r) => $r['cells'][$key]);
                $count = fn (string $s) => $cells->where('status', $s)->count();

                return [
                    'id'         => (int) $id ?: null,
                    'name'       => $name((int) $id ?: null),
                    'clients'    => $group->count(),
                    'accountant' => $count(AutoAuditClientBoard::ACCOUNTANT),
                    'chief'      => $count(AutoAuditClientBoard::CHIEF),
                    'overdue'    => $count(AutoAuditClientBoard::OVERDUE),
                    'oldest'     => (int) $cells
                        ->whereIn('status', [AutoAuditClientBoard::ACCOUNTANT, AutoAuditClientBoard::CHIEF])
                        ->max('days'),
                ];
            })
            ->sortBy([
                fn ($a, $b) => ($b['accountant'] + $b['chief'] + $b['overdue']) <=> ($a['accountant'] + $a['chief'] + $a['overdue']),
                fn ($a, $b) => $b['oldest'] <=> $a['oldest'],
                fn ($a, $b) => $a['name'] <=> $b['name'],
            ])
            ->values()
            ->all();
    }

    private function rank(string $status): int
    {
        return array_search($status, array_keys(AutoAuditClientBoard::STATUSES), true);
    }

    /** Выбранный месяц из адреса, «2026-09». Кривой или будущий даёт месяц по умолчанию. */
    private function focus(Request $request, CarbonImmutable $today): CarbonImmutable
    {
        $month = $request->query('month');

        if (is_string($month) && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $m)) {
            $focus = CarbonImmutable::create((int) $m[1], (int) $m[2], 1);

            if ($focus->lte($today->startOfMonth()) && $focus->year >= 2020) {
                return $focus;
            }
        }

        return AutoAuditClientBoard::defaultFocus($today);
    }

    private function allowedOnly(): void
    {
        abort_unless(self::visible(), 404);
    }

    /** Видна ли вкладка: вендору всегда, руководителю по двум флагам фирмы. */
    public static function visible(): bool
    {
        if (Impersonation::isActive()) {
            return true;
        }

        return (bool) auth('employee')->user()?->canSeeAutoAudit()
            && (bool) Tenant::find(TenantContext::id())?->autoAuditClientsEnabled();
    }

    private function openToManager(): bool
    {
        $tenant = Tenant::find(TenantContext::id());

        return (bool) $tenant?->autoAuditEnabled() && (bool) $tenant?->autoAuditClientsEnabled();
    }
}
