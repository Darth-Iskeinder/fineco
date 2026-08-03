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
 * Заход устроен просто: вендор входит как администратор этой фирмы. Пароль
 * администратора при этом не нужен и не становится известен — система логинит
 * его сама. Права полные, чтобы можно было не только посмотреть на проблему,
 * но и починить.
 */
class VendorPanelController extends Controller
{
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

    /** Войти в аккаунт фирмы её администратором. */
    public function enter(Tenant $tenant)
    {
        if ($tenant->isTemplate()) {
            return back()->withErrors(['tenant' => 'В аккаунт-образец входить нельзя: рабочих данных в нём нет.']);
        }

        $admin = Employee::acrossTenants()
            ->where('tenant_id', $tenant->id)
            ->where('status', Employee::STATUS_ACTIVE)
            ->whereHas('role', fn ($q) => $q->where('name', Role::ADMIN))
            ->orderBy('id')
            ->first();

        if (!$admin) {
            return back()->withErrors([
                'tenant' => "В аккаунте «{$tenant->name}» нет активного администратора — входить не от кого.",
            ]);
        }

        Auth::guard('employee')->login($admin);
        Impersonation::start($tenant);

        return redirect('/');
    }

    /** Выйти из фирмы обратно в панель. Вендором при этом остаёмся. */
    public function leave()
    {
        Impersonation::stop();

        return redirect()->route('vendor.index');
    }
}
