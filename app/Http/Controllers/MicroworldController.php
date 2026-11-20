<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Employee;
use App\Models\EstimateItem;
use App\Models\Role;
use App\Models\Tenant;
use App\Services\Microworld\MyResults;
use App\Support\Impersonation;
use App\Support\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * «Микромир»: сотрудник сам видит свою успеваемость по сметным задачам.
 *
 * Первая версия (17.11.2026) без денег, открыта всем фирмам 20.11.2026: видят сотрудники фирмы, если
 * её открыли (microworld:access --on), и вендор, зашедший в фирму. Остальным 404.
 * Вендор выбирает, чьими глазами смотреть (?employee=), иначе ему показать нечего.
 *
 * У того, кто ведёт клиентов (главбух, руководитель с клиентами), две вкладки: «Команда на
 * моих клиентах» (по умолчанию) и «Мои задачи» (?view=me). У бухгалтера только вторая.
 */
class MicroworldController extends Controller
{
    public const MONTHS = [1 => 'Январь', 'Февраль', 'Март', 'Апрель', 'Май', 'Июнь', 'Июль', 'Август', 'Сентябрь', 'Октябрь', 'Ноябрь', 'Декабрь'];

    public const MONTHS_SHORT = [1 => 'Янв', 'Фев', 'Мар', 'Апр', 'Май', 'Июн', 'Июл', 'Авг', 'Сен', 'Окт', 'Ноя', 'Дек'];

    public function index(Request $request): View
    {
        abort_unless(self::visible(), 404);

        $today = CarbonImmutable::now()->startOfDay();
        $focus = $this->focus($request, $today);

        $vendor   = Impersonation::isActive();
        $me       = auth('employee')->user();
        $employee = $me;
        $choices  = collect();

        if ($vendor) {
            $choices  = Employee::orderBy('full_name')->get(['id', 'full_name', 'role_id'])->load('role:id,display_name');
            $employee = $choices->firstWhere('id', (int) $request->query('employee')) ?? $me;
        }

        $data    = (new MyResults($today))->build($employee, $focus);

        // Главбуху сначала команда на его клиентах, свои задачи по второй вкладке
        $view = $data['team'] && $request->query('view') !== 'me' ? 'team' : 'me';
        $cutoff  = CarbonImmutable::parse(MyResults::CUTOFF)->startOfMonth();
        $prev    = $focus->subMonth();
        $next    = $focus->addMonth();

        return view('microworld.index', $data + [
            'employee'  => $employee,
            'view'      => $view,
            'vendor'    => $vendor,
            'choices'   => $choices,
            'focus'     => $focus,
            'isCurrent' => $focus->isSameMonth($today),
            'prev'      => $prev->gte($cutoff) ? $prev : null,
            'next'      => $next->lte($today->startOfMonth()) ? $next : null,
        ]);
    }

    /**
     * Цвета процента как в «Закрытии месяца». Порога в продукте нет, это только подсказка глазу.
     *
     * @return array{text: string, bar: string}
     */
    public static function tone(?int $pct): array
    {
        return match (true) {
            $pct === null => ['text' => 'text-slate-300',   'bar' => 'bg-slate-200'],
            $pct >= 80    => ['text' => 'text-emerald-600', 'bar' => 'bg-emerald-600'],
            $pct >= 50    => ['text' => 'text-amber-600',   'bar' => 'bg-amber-500'],
            default       => ['text' => 'text-red-600',     'bar' => 'bg-red-600'],
        };
    }

    /** «1 задача», «3 задачи», «5 задач». */
    public static function plural(int $n, array $forms): string
    {
        $n10  = $n % 10;
        $n100 = $n % 100;
        $form = $n10 === 1 && $n100 !== 11 ? 0 : ($n10 >= 2 && $n10 <= 4 && ($n100 < 10 || $n100 >= 20) ? 1 : 2);

        return $n . ' ' . $forms[$form];
    }

    /**
     * Видна ли страница (решено 17.11.2026). Вендору всегда. Остальным только при включённом
     * флаге фирмы, и дальше по роли:
     *  - бухгалтер, главбух, юрист всегда: это их рабочий экран (юрист бывает исполнителем БП);
     *  - руководитель и админ, только если ведут клиентов или на них назначены позиции сметы.
     *    Пустой экран им ни к чему, а пункт не мигает от месяца к месяцу;
     *  - аудитор и прочие никогда.
     * Зовётся и из меню на каждой странице, поэтому ответ запоминается на запрос.
     */
    public static function visible(): bool
    {
        if (Impersonation::isActive()) {
            return true;
        }

        $employee = auth('employee')->user();
        if (!$employee) {
            return false;
        }

        $attributes = request()->attributes;
        $key        = 'microworld.visible.' . $employee->id;

        if (!$attributes->has($key)) {
            $attributes->set($key, self::allowedFor($employee));
        }

        return $attributes->get($key);
    }

    private static function allowedFor(Employee $employee): bool
    {
        if (!Tenant::find(TenantContext::id())?->microworldEnabled()) {
            return false;
        }

        $role = $employee->role;

        if ($role && ($role->isAccountant() || $role->isHeadAccountant() || $role->name === Role::LEGAL_COUNSEL)) {
            return true;
        }

        if ($role && ($role->isManager() || $role->isAdmin())) {
            return Client::where('responsible_employee_id', $employee->id)->exists()
                || EstimateItem::where('assignee_id', $employee->id)->exists();
        }

        return false;
    }

    /** Выбранный месяц (?month=2026-10): не раньше начала задач и не в будущем. */
    private function focus(Request $request, CarbonImmutable $today): CarbonImmutable
    {
        $current = $today->startOfMonth();
        $raw     = (string) $request->query('month');

        if (!preg_match('/^\d{4}-\d{2}$/', $raw)) {
            return $current;
        }

        $month = CarbonImmutable::createFromFormat('!Y-m', $raw)->startOfMonth();
        $min   = CarbonImmutable::parse(MyResults::CUTOFF)->startOfMonth();

        return $month->lt($min) || $month->gt($current) ? $current : $month;
    }
}
