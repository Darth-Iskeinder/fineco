<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\ClientResponsibleChange;
use App\Models\Tenant;
use App\Services\AutoAudit\AutoAuditSources;
use App\Services\MonthClosing\MonthClosingBoard;
use App\Support\Impersonation;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «Закрытие месяца»: закрыт ли месяц каждого клиента, по фирме и по главбухам.
 *
 * Первый шаг (10.11.2026): только чтение, из того, что уже есть. Видят вендор, зашедший в
 * фирму, и руководитель, если фирме открыли страницу (month-closing:access --on).
 * Остальным 404. Карточка клиента приходит отдельным запросом по клику.
 */
class MonthClosingController extends Controller
{
    /** Цвета клеток: как на макете, сплошная заливка с белым знаком. */
    public const STYLES = [
        MonthClosingBoard::CLOSED  => ['cell' => 'bg-emerald-600 text-white',                           'dot' => 'bg-emerald-600', 'badge' => 'bg-emerald-50 text-emerald-700'],
        MonthClosingBoard::OVERDUE => ['cell' => 'bg-red-600 text-white',                               'dot' => 'bg-red-600',     'badge' => 'bg-red-50 text-red-700'],
        MonthClosingBoard::OPEN    => ['cell' => 'border border-dashed border-slate-300 text-slate-400', 'dot' => 'border border-dashed border-slate-400', 'badge' => 'bg-slate-100 text-slate-600'],
        MonthClosingBoard::NONE    => ['cell' => 'text-slate-200',                                       'dot' => 'bg-slate-200',   'badge' => 'bg-slate-50 text-slate-400'],
    ];

    public const MONTHS = [1 => 'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];

    public function index(Request $request): View
    {
        $this->allowedOnly();

        $today  = CarbonImmutable::now()->startOfDay();
        $focus  = $this->focus($request, $today);
        $months = MonthClosingBoard::window($focus, $today);
        $data   = (new MonthClosingBoard($today))->build($months);
        $key    = $focus->format('Y-m');

        $employees = $data['employees'];
        $name      = fn (?int $id) => $id ? ($employees[$id] ?? 'сотрудник удалён') : 'без главбуха';
        $rows      = collect($data['rows']);

        $chief = (string) $request->query('chief') ?: null;
        $chief = in_array($chief, ['none', 'unknown'], true) || ctype_digit((string) $chief) ? $chief : null;

        // Главбух строки за выбранный месяц: кто вёл клиента в срок закрытия, а не нынешний
        // ответственный (см. MonthClosingBoard::monthChief). 'unknown' = пришёл позже срока.
        $keyOf = fn (array $r) => $r['cells'][$key]['chiefUnknown'] ? 'unknown' : (string) ($r['cells'][$key]['chief'] ?? 'none');
        $label = fn (string $k) => match ($k) {
            'none'    => 'Без главбуха',
            'unknown' => 'Не определён',
            default   => AutoAuditSources::shortName($name((int) $k)),
        };

        // Плитки считаются до фильтра: чип выбирает строки, а не меняет итоги.
        // Главбух без клиентов с задачами в выбранном месяце не показывается: «0 из 0» только
        // мешает. Так уходят уволенные, на которых остались остановленные клиенты (Жусупова,
        // 10.11.2026). Выбранный чип остаётся, иначе его не снять.
        $firm   = $this->tally($rows, $key);
        $chiefs = $rows
            ->groupBy($keyOf)
            ->map(fn ($group, $k) => ['key' => (string) $k, 'id' => ctype_digit((string) $k) ? (int) $k : null, 'name' => $label((string) $k)] + $this->tally($group, $key))
            ->filter(fn (array $c) => $c['total'] > 0 || $c['key'] === $chief)
            ->sortBy(fn (array $c) => [$c['id'] === null ? 1 : 0, mb_strtolower($c['name'])])
            ->values();

        $filtered = $rows
            ->filter(fn (array $r) => $chief === null || $keyOf($r) === $chief)
            ->sortBy(fn (array $r) => [ctype_digit($keyOf($r)) ? 0 : 1, mb_strtolower($label($keyOf($r))), mb_strtolower($r['client']->name)])
            ->values();

        // С какого дня записываются смены ответственных: раньше передачи не известны.
        $historySince = ClientResponsibleChange::min('changed_at');

        return view('month-closing.index', [
            'months'    => $months,
            'focus'     => $focus,
            'key'       => $key,
            'prev'      => $focus->subMonth(),
            'next'      => $focus->addMonth()->lte(MonthClosingBoard::latest($today)) ? $focus->addMonth() : null,
            'rows'      => $filtered,
            'firm'      => $firm,
            'chiefs'    => $chiefs,
            'chief'     => $chief,
            'name'      => $name,
            'keyOf'     => $keyOf,
            'label'     => $label,
            'historySince' => $historySince ? CarbonImmutable::parse($historySince)->setTimezone(config('app.display_timezone')) : null,
            'autoAudit' => $data['autoAudit'],
            'vendor'    => Impersonation::isActive(),
            'openToManager' => (bool) Tenant::find(TenantContext::id())?->monthClosingEnabled(),
            'firmName'  => Tenant::find(TenantContext::id())?->name,
        ]);
    }

    /** Карточка клиента за месяц: отдельный запрос по клику, отдаёт кусок страницы. */
    public function card(Request $request, Client $client): View
    {
        $this->allowedOnly();

        $today  = CarbonImmutable::now()->startOfDay();
        $focus  = $this->focus($request, $today);
        $months = MonthClosingBoard::window($focus, $today);
        $data   = (new MonthClosingBoard($today))->build($months, $client->id);
        $row    = $data['rows'][0] ?? null;

        abort_unless($row, 404);

        $employees = $data['employees'];

        return view('month-closing.card', [
            'row'       => $row,
            'months'    => $months,
            'focus'     => $focus,
            'cell'      => $row['cells'][$focus->format('Y-m')],
            'name'      => fn (?int $id) => $id ? ($employees[$id] ?? 'сотрудник удалён') : 'не назначен',
            'today'     => $today,
            'autoAudit' => $data['autoAudit'],
            'taxes'     => $data['taxes'],
            'auditLink' => AutoAuditClientsController::visible(),
        ]);
    }

    /** Сколько клиентов закрыли месяц из тех, у кого в нём есть задачи. */
    private function tally($rows, string $key): array
    {
        $statuses = $rows->map(fn (array $r) => $r['cells'][$key]['status'])->reject(fn (string $s) => $s === MonthClosingBoard::NONE);
        $total    = $statuses->count();
        $closed   = $statuses->filter(fn (string $s) => $s === MonthClosingBoard::CLOSED)->count();

        return [
            'clients' => $rows->count(),
            'total'   => $total,
            'closed'  => $closed,
            'overdue' => $statuses->filter(fn (string $s) => $s === MonthClosingBoard::OVERDUE)->count(),
            'open'    => $statuses->filter(fn (string $s) => $s === MonthClosingBoard::OPEN)->count(),
            'pct'     => $total ? (int) round($closed * 100 / $total) : null,
        ];
    }

    /** Выбранный месяц из адреса, «2026-09». Кривой или слишком поздний даёт месяц по умолчанию. */
    private function focus(Request $request, CarbonImmutable $today): CarbonImmutable
    {
        $month = $request->query('month');

        if (is_string($month) && preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $month, $m)) {
            $focus = CarbonImmutable::create((int) $m[1], (int) $m[2], 1);

            if ($focus->lte(MonthClosingBoard::latest($today)) && $focus->year >= 2020) {
                return $focus;
            }
        }

        return MonthClosingBoard::defaultFocus($today);
    }

    private function allowedOnly(): void
    {
        abort_unless(self::visible(), 404);
    }

    /** Видна ли страница: вендору всегда, руководителю по флагу фирмы. */
    public static function visible(): bool
    {
        if (Impersonation::isActive()) {
            return true;
        }

        $employee = auth('employee')->user();

        return (bool) $employee?->isManager()
            && (bool) Tenant::find(TenantContext::id())?->monthClosingEnabled();
    }
}
