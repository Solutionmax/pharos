<?php

namespace Tests\Unit;

use App\Casts\LocalTime;
use App\Models\Incident;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class LocalTimeTest extends TestCase
{
    use RefreshDatabase;

    private function cast(): LocalTime
    {
        return new LocalTime;
    }

    public function test_a_string_without_offset_is_read_in_the_customers_zone(): void
    {
        Setting::put('app.timezone', 'Europe/Amsterdam');

        $this->assertSame('2026-08-28 08:24:00', $this->cast()->set(new Incident, 'occurred_at', '2026-08-28T10:24', []));
        $this->assertSame('2026-08-28 08:24:00', $this->cast()->set(new Incident, 'occurred_at', '2026-08-28 10:24:00', []));
    }

    public function test_a_string_with_an_offset_keeps_that_offset(): void
    {
        Setting::put('app.timezone', 'Europe/Amsterdam');

        $this->assertSame('2026-08-28 15:24:00', $this->cast()->set(new Incident, 'occurred_at', '2026-08-28T10:24:00-05:00', []));
        $this->assertSame('2026-08-28 10:24:00', $this->cast()->set(new Incident, 'occurred_at', '2026-08-28T10:24:00Z', []));
    }

    public function test_a_carbon_in_any_zone_is_stored_as_utc(): void
    {
        Setting::put('app.timezone', 'Europe/Amsterdam');

        $utc = Carbon::parse('2026-08-28 08:24:00', 'UTC');
        $local = Carbon::parse('2026-08-28 10:24:00', 'Europe/Amsterdam');
        $immutable = new \DateTimeImmutable('2026-08-28 04:24:00', new \DateTimeZone('America/New_York'));

        foreach ([$utc, $local, $immutable] as $value) {
            $this->assertSame('2026-08-28 08:24:00', $this->cast()->set(new Incident, 'occurred_at', $value, []));
        }
    }

    public function test_get_returns_the_stored_utc_value_in_the_customers_zone(): void
    {
        Setting::put('app.timezone', 'Europe/Amsterdam');

        $value = $this->cast()->get(new Incident, 'occurred_at', '2026-08-28 08:24:00', []);

        $this->assertSame('Europe/Amsterdam', $value->timezone->getName());
        $this->assertSame('2026-08-28T10:24:00+02:00', $value->toIso8601String());
    }

    public function test_null_passes_through_both_ways(): void
    {
        $this->assertNull($this->cast()->get(new Incident, 'resolved_at', null, []));
        $this->assertNull($this->cast()->set(new Incident, 'resolved_at', null, []));
    }

    public function test_the_default_zone_is_utc(): void
    {
        $value = $this->cast()->get(new Incident, 'occurred_at', '2026-08-28 08:24:00', []);

        $this->assertSame('2026-08-28T08:24:00+00:00', $value->toIso8601String());
    }
}
