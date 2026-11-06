{{-- Ячейка «Долг сейчас» окупаемости: долг на сегодня, под ним просрочка больше 30 дней --}}
@if($r['debt'] > 0)
    <td class="px-4 py-3 text-right tabular-nums font-medium whitespace-nowrap {{ $r['overdue'] > 0 ? 'text-red-600' : 'text-slate-700' }}">
        {{ $money($r['debt']) }}
        @if($r['overdue'] > 0)
            <span class="block text-[11px] font-normal">{{ $money($r['overdue']) }} больше 30 дн.</span>
        @endif
    </td>
@else
    <td class="px-4 py-3 text-right tabular-nums text-slate-300">0</td>
@endif
