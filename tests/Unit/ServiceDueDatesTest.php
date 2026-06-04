<?php

namespace Tests\Unit;

use App\Models\Service;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

class ServiceDueDatesTest extends TestCase
{
    private function compute(?string $kind, array $months, array $days, string $from, string $to): array
    {
        return array_map(
            fn ($d) => $d->toDateString(),
            Service::computeDueDates($kind, $months, $days, CarbonImmutable::parse($from), CarbonImmutable::parse($to)),
        );
    }

    public function test_monthly_produces_one_date_per_month(): void
    {
        $dates = $this->compute('monthly', [], [15], '2026-01-01', '2026-03-31');
        $this->assertSame(['2026-01-15', '2026-02-15', '2026-03-15'], $dates);
    }

    public function test_quarterly_produces_date_in_each_selected_month(): void
    {
        $dates = $this->compute('quarterly', [3, 7, 9, 12], [20], '2026-01-01', '2026-12-31');
        $this->assertSame(['2026-03-20', '2026-07-20', '2026-09-20', '2026-12-20'], $dates);
    }

    public function test_yearly_produces_single_date(): void
    {
        $dates = $this->compute('yearly', [4], [10], '2026-01-01', '2026-12-31');
        $this->assertSame(['2026-04-10'], $dates);
    }

    public function test_weekly_produces_each_weekday_in_range(): void
    {
        // Вт(2) и Пт(5) в первую неделю января 2026 (1 янв = Чт)
        $dates = $this->compute('weekly', [], [2, 5], '2026-01-01', '2026-01-11');
        $this->assertSame(['2026-01-02', '2026-01-06', '2026-01-09'], $dates);
    }

    public function test_day_is_clamped_to_month_length(): void
    {
        // 31-е в феврале невисокосного 2026 → 28-е
        $dates = $this->compute('monthly', [], [31], '2026-02-01', '2026-02-28');
        $this->assertSame(['2026-02-28'], $dates);
    }

    public function test_quarterly_spans_multiple_years(): void
    {
        $dates = $this->compute('quarterly', [12, 3], [1], '2025-12-01', '2026-03-31');
        $this->assertSame(['2025-12-01', '2026-03-01'], $dates);
    }

    public function test_empty_when_kind_null_or_missing_inputs(): void
    {
        $this->assertSame([], $this->compute(null, [3], [10], '2026-01-01', '2026-12-31'));
        $this->assertSame([], $this->compute('monthly', [], [], '2026-01-01', '2026-12-31'));
        $this->assertSame([], $this->compute('quarterly', [], [10], '2026-01-01', '2026-12-31'));
        $this->assertSame([], $this->compute('weekly', [], [], '2026-01-01', '2026-12-31'));
    }
}
