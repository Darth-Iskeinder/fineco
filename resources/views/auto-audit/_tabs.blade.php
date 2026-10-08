{{-- Вкладки автоаудита. «По клиентам» видна не всем (AutoAuditClientsController::visible),
     без неё вкладок нет вовсе: одна вкладка ни к чему. --}}
@if (\App\Http\Controllers\AutoAuditClientsController::visible())
    <nav class="inline-flex gap-1 rounded-lg bg-slate-100 p-[3px] {{ $class ?? '' }}" aria-label="Автоаудит">
        @foreach (['auto-audit.clients' => 'По клиентам', 'auto-audit.index' => 'Все сверки'] as $route => $label)
            <a href="{{ route($route) }}"
               @if ($active === $route) aria-current="page" @endif
               @class(['rounded-md px-3.5 py-1.5 text-sm font-medium transition-colors',
                       'bg-white text-slate-900 shadow-sm' => $active === $route,
                       'text-slate-500 hover:text-slate-900' => $active !== $route])>{{ $label }}</a>
        @endforeach
    </nav>
@endif
