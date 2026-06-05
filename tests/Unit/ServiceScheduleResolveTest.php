<?php

namespace Tests\Unit;

use App\Models\Service;
use PHPUnit\Framework\TestCase;

class ServiceScheduleResolveTest extends TestCase
{
    public function test_without_override_uses_service_defaults(): void
    {
        $r = Service::resolveScheduleRaw(null, 'Ежеквартально', [3, 6, 9, 12], [20]);

        $this->assertSame('Ежеквартально', $r['periodicity']);
        $this->assertSame([3, 6, 9, 12], $r['months']);
        $this->assertSame([20], $r['days']);
    }

    public function test_override_fully_replaces_service_schedule(): void
    {
        // У БП — ежеквартально 20-го; у клиента — ежемесячно 5-го.
        $r = Service::resolveScheduleRaw(
            ['periodicity' => 'Ежемесячно', 'start_month' => [], 'start_day' => [5]],
            'Ежеквартально', [3, 6, 9, 12], [20],
        );

        $this->assertSame('Ежемесячно', $r['periodicity']);
        $this->assertSame([], $r['months']);
        $this->assertSame([5], $r['days']);
    }

    public function test_override_is_taken_whole_no_field_mixing(): void
    {
        // Override без месяцев НЕ подмешивает месяцы БП — строка берётся целиком.
        $r = Service::resolveScheduleRaw(
            ['periodicity' => 'Ежеквартально', 'start_month' => null, 'start_day' => [15]],
            'Ежеквартально', [3, 6, 9, 12], [20],
        );

        $this->assertSame([], $r['months']);
        $this->assertSame([15], $r['days']);
    }

    public function test_values_are_normalized_to_ints(): void
    {
        $r = Service::resolveScheduleRaw(
            ['periodicity' => 'Ежегодно', 'start_month' => ['4', '10'], 'start_day' => ['1']],
            null, null, null,
        );

        $this->assertSame([4, 10], $r['months']);
        $this->assertSame([1], $r['days']);
    }

    public function test_null_arrays_become_empty(): void
    {
        $r = Service::resolveScheduleRaw(null, 'Ежемесячно', null, null);

        $this->assertSame([], $r['months']);
        $this->assertSame([], $r['days']);
    }
}
