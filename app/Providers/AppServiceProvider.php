<?php

namespace App\Providers;

use App\Models\ErrorReport;
use App\Support\TenantContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Счётчик неразобранных сбоев рядом со ссылкой в панели владельца.
        // Ради него журнал и заведён: поломку видно с любой страницы панели,
        // не открывая сам журнал и не дожидаясь звонка от клиента.
        View::composer('layouts.vendor', function ($view) {
            $view->with('unresolvedErrorCount', ErrorReport::unresolved()->count());
        });

        View::composer('layouts.app', function ($view) {
            $urgentCount = 0;

            if (auth('employee')->check()) {
                $employee = auth('employee')->user();
                $today    = now()->day;
                $year     = now()->year;
                $month    = now()->month;
                $warnDay  = $today + 3;

                // Единственное место в приложении, где запросы идут мимо моделей,
                // а значит и мимо автоматического фильтра по фирме. Здесь его
                // приходится дописывать руками — иначе счётчик считал бы по всем
                // аккаунтам сразу и молча показывал бы неверное число.
                $tenantId = TenantContext::id() ?? $employee->tenant_id;

                $clientIds = DB::table('clients')
                    ->where('tenant_id', $tenantId)
                    ->where('responsible_employee_id', $employee->id)
                    ->pluck('id');

                $completedIds = DB::table('buh_task_logs')
                    ->where('tenant_id', $tenantId)
                    ->where('employee_id', $employee->id)
                    ->where('year', $year)
                    ->where('month', $month)
                    ->whereIn('status', ['completed', 'review'])
                    ->pluck('estimate_item_id');

                $plannedUrgent = DB::table('estimate_items')
                    ->join('estimates', 'estimate_items.estimate_id', '=', 'estimates.id')
                    ->where('estimate_items.tenant_id', $tenantId)
                    ->whereIn('estimates.client_id', $clientIds)
                    ->whereNull('estimate_items.parent_id')
                    ->whereNotNull('estimate_items.due_day')
                    ->where('estimate_items.due_day', '<=', $warnDay)
                    ->whereNotIn('estimate_items.id', $completedIds)
                    ->count();

                $adhocUrgent = DB::table('buh_adhoc_tasks')
                    ->where('tenant_id', $tenantId)
                    ->where('employee_id', $employee->id)
                    ->where('year', $year)
                    ->where('month', $month)
                    ->whereNotNull('due_day')
                    ->where('due_day', '<=', $warnDay)
                    ->where('status', '!=', 'completed')
                    ->count();

                $urgentCount = $plannedUrgent + $adhocUrgent;
            }

            $view->with('sidebarUrgentCount', $urgentCount);
        });
    }
}
