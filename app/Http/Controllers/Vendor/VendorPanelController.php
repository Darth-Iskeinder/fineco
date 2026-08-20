<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\Role;
use App\Models\Tenant;
use App\Support\Impersonation;
use Illuminate\Support\Facades\Auth;

/**
 * Панель владельца системы: все фирмы списком и заход внутрь любой из них.
 *
 * Заход устроен просто: вендор входит сотрудником этой фирмы. Пароль сотрудника
 * при этом не нужен и не становится известен — система логинит его сама.
 *
 * Кем именно — решает порядок ниже: берём самого старшего из активных. Раньше
 * искали только администратора, и фирма, раздавшая роли по-своему (руководитель,
 * главбухи, бухгалтеры — а админа нет), становилась недоступной для поддержки.
 * Права нужны максимально возможные: чтобы не только увидеть проблему, но и
 * починить, — однако выше того, что есть в фирме, всё равно не прыгнуть.
 */
class VendorPanelController extends Controller
{
    /** Кем входим в чужую фирму: сверху вниз, пока кто-то не найдётся. */
    private const ENTER_ROLE_ORDER = [
        Role::ADMIN,
        Role::MANAGER,
        Role::HEAD_ACCOUNTANT,
        Role::AUDITOR,
        Role::ACCOUNTANT,
    ];


    public function index()
    {
        // Считаем в обход фильтра по фирме: увидеть все аккаунты — это и есть
        // задача панели, поэтому выход за пределы фирмы здесь явный.
        $tenants = Tenant::real()
            ->withCount([
                'clients'   => fn ($q) => $q->acrossTenants(),
                'employees' => fn ($q) => $q->acrossTenants(),
            ])
            ->orderBy('name')
            ->get();

        return view('vendor-panel.index', [
            'tenants'      => $tenants,
            'insideTenant' => Impersonation::tenantName(),
        ]);
    }

    /** Войти в аккаунт фирмы её сотрудником — самым старшим из активных. */
    public function enter(Tenant $tenant)
    {
        if ($tenant->isTemplate()) {
            return back()->withErrors(['tenant' => 'В аккаунт-образец входить нельзя: рабочих данных в нём нет.']);
        }

        $employee = $this->whoToEnterAs($tenant);

        if (!$employee) {
            return back()->withErrors([
                'tenant' => "В аккаунте «{$tenant->name}» нет активных сотрудников — входить не от кого.",
            ]);
        }

        Auth::guard('employee')->login($employee);
        Impersonation::start($tenant, $employee);

        return redirect('/');
    }

    /**
     * Сотрудник, под которым вендор войдёт в фирму.
     *
     * Роли перебираем по старшинству, а в конце берём кого угодно активного:
     * появится новая роль вне списка — вход всё равно не сломается.
     */
    private function whoToEnterAs(Tenant $tenant): ?Employee
    {
        $active = fn () => Employee::acrossTenants()
            ->where('tenant_id', $tenant->id)
            ->where('status', Employee::STATUS_ACTIVE)
            ->orderBy('id');

        foreach (self::ENTER_ROLE_ORDER as $role) {
            $employee = $active()
                ->whereHas('role', fn ($q) => $q->where('name', $role))
                ->first();

            if ($employee) {
                return $employee;
            }
        }

        return $active()->first();
    }

    /** Выйти из фирмы обратно в панель. Вендором при этом остаёмся. */
    public function leave()
    {
        Impersonation::stop();

        return redirect()->route('vendor.index');
    }
}
