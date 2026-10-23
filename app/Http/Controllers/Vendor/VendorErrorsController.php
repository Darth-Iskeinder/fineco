<?php

namespace App\Http\Controllers\Vendor;

use App\Http\Controllers\Controller;
use App\Models\ErrorReport;
use App\Models\Tenant;
use Illuminate\Http\Request;

/**
 * Журнал сбоев в панели владельца: всё, что сломалось у всех фирм.
 *
 * Смысл экрана — узнавать о поломке раньше, чем о ней сообщит клиент. Поэтому
 * по умолчанию показываем незакрытые и свежие сверху, а разобранное убираем
 * с глаз (оно вернётся само, если ошибка повторится).
 */
class VendorErrorsController extends Controller
{
    private const PER_PAGE = 50;

    public function index(Request $request)
    {
        $showResolved = $request->boolean('resolved');

        // Фильтр по фирме: из панели «Аккаунты» сюда ведёт ссылка со счётчиком сбоев фирмы.
        $tenant = $request->filled('tenant') ? Tenant::find($request->integer('tenant')) : null;

        $reports = ErrorReport::with(['tenant:id,name', 'employee:id,full_name'])
            ->when(!$showResolved, fn ($q) => $q->unresolved())
            ->when($tenant, fn ($q) => $q->where('tenant_id', $tenant->id))
            ->orderByDesc('last_seen_at')
            ->paginate(self::PER_PAGE)
            ->withQueryString();

        return view('vendor-panel.errors', [
            'reports'         => $reports,
            'showResolved'    => $showResolved,
            'tenant'          => $tenant,
            'unresolvedCount' => ErrorReport::unresolved()->count(),
        ]);
    }

    public function resolve(ErrorReport $report)
    {
        $report->update(['resolved_at' => now()]);

        return back()->with('status', 'Ошибка отмечена разобранной. Повторится — вернётся в список.');
    }

    public function reopen(ErrorReport $report)
    {
        $report->update(['resolved_at' => null]);

        return back()->with('status', 'Ошибка возвращена в работу.');
    }
}
