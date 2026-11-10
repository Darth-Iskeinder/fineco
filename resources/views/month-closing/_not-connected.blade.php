{{-- Условие, которое к закрытию месяца пока не подключено: серый блок без галочки. --}}
<section class="rounded-xl border border-dashed border-slate-200 px-4 py-3" data-section="{{ $section }}">
    <div class="flex gap-3">
        <span class="mt-0.5 inline-grid h-5 w-5 flex-shrink-0 place-items-center rounded-full bg-slate-100 text-[11px] font-bold text-slate-400">·</span>
        <div class="min-w-0 flex-1">
            <div class="font-semibold text-slate-500">{{ $title }}</div>
            <div class="text-[13px] text-slate-500">{{ $text }}</div>
        </div>
    </div>
</section>
