{{-- Квартальная сверка складывает три месячные ОСВ: показываем, сколько дал каждый месяц.
     Ошибка июля всплывает в клетке сентября, и без раскладки её не найти. --}}
@php
    $sheets = collect($result->sources ?? [])
        ->where('side', 'osv')
        ->filter(fn (array $s) => !empty($s['task_month']))
        ->map(function (array $s) {
            [$m, $y] = array_map('intval', explode('.', $s['task_month']));
            $month = \Carbon\CarbonImmutable::create($y, $m, 1)->subMonth();

            return ['month' => $month, 'value' => $s['value'] ?? null];
        })
        ->sortBy(fn (array $s) => $s['month']->timestamp);
@endphp
@if ($sheets->isNotEmpty())
    <div class="text-[13px] text-slate-500">
        Квартал складывает три ОСВ:
        @foreach ($sheets as $sheet)
            {{ \App\Services\AutoAudit\DocumentPeriod::of($sheet['month']->year, $sheet['month']->month)->title() }}
            <b class="font-semibold tabular-nums whitespace-nowrap text-slate-900">{{ $money($sheet['value']) }}</b>@if (!$loop->last), @endif
        @endforeach
    </div>
@endif
