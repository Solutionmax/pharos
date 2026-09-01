<?php

namespace Tests\Unit;

use App\Models\Component;
use App\Models\UptimeDay;
use App\Services\Uptime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class UptimeTest extends TestCase
{
    use RefreshDatabase;

    protected Uptime $uptime;

    protected Component $component;

    protected function setUp(): void
    {
        parent::setUp();
        $this->uptime = new Uptime;
        $this->component = Component::create(['name' => 'web-06']);
    }

    protected function day(int $daysAgo, int $downSeconds): void
    {
        UptimeDay::create([
            'component_id' => $this->component->id,
            'day' => Carbon::today()->subDays($daysAgo),
            'up_seconds' => 86400 - $downSeconds,
            'down_seconds' => $downSeconds,
        ]);
    }

    public function test_the_bar_always_has_one_entry_per_day_in_the_window(): void
    {
        $this->assertCount(Uptime::WINDOW_DAYS, $this->uptime->bar($this->component));
    }

    public function test_the_bar_runs_oldest_to_newest_and_ends_today(): void
    {
        $bar = $this->uptime->bar($this->component);

        $this->assertSame(
            Carbon::today()->subDays(Uptime::WINDOW_DAYS - 1)->format('Y-m-d'),
            $bar[0]['day'],
        );
        $this->assertSame(Carbon::today()->format('Y-m-d'), end($bar)['day']);
    }

    public function test_days_without_data_are_marked_unknown_not_green(): void
    {
        $bar = $this->uptime->bar($this->component);

        $this->assertFalse($bar[0]['known']);
        $this->assertSame('unknown', $bar[0]['tone']);
    }

    public function test_today_is_included_in_the_window(): void
    {
        // Regression: the date cast stores "Y-m-d 00:00:00", which sorted after a
        // bare "Y-m-d" string and pushed today out of the range.
        $this->day(daysAgo: 0, downSeconds: 0);

        $bar = $this->uptime->bar($this->component);
        $this->assertTrue(end($bar)['known']);
    }

    public function test_a_perfect_day_is_a_hundred_percent(): void
    {
        $this->day(daysAgo: 1, downSeconds: 0);

        $this->assertSame(100.0, $this->uptime->percentage($this->component));
    }

    public function test_half_a_day_down_is_fifty_percent_for_that_day(): void
    {
        $this->day(daysAgo: 1, downSeconds: 43200);

        $this->assertSame(50.0, $this->uptime->percentage($this->component));
    }

    public function test_days_without_data_do_not_drag_the_average_down(): void
    {
        // Two known days, 100% and 50%, and 88 unknown ones: the answer is 75%,
        // not 99.7%. A new component must not look better than it is.
        $this->day(daysAgo: 1, downSeconds: 0);
        $this->day(daysAgo: 2, downSeconds: 43200);

        $this->assertSame(75.0, $this->uptime->percentage($this->component));
    }

    public function test_tone_thresholds(): void
    {
        $this->day(daysAgo: 1, downSeconds: 0);
        $this->assertSame('ok', $this->uptime->bar($this->component)[Uptime::WINDOW_DAYS - 2]['tone']);

        UptimeDay::query()->delete();
        $this->day(daysAgo: 1, downSeconds: 900);   // 98.96%
        $this->assertSame('p', $this->uptime->bar($this->component)[Uptime::WINDOW_DAYS - 2]['tone']);

        UptimeDay::query()->delete();
        $this->day(daysAgo: 1, downSeconds: 60);    // 99.93%
        $this->assertSame('w', $this->uptime->bar($this->component)[Uptime::WINDOW_DAYS - 2]['tone']);

        UptimeDay::query()->delete();
        $this->day(daysAgo: 1, downSeconds: 7200);  // 91.67%
        $this->assertSame('b', $this->uptime->bar($this->component)[Uptime::WINDOW_DAYS - 2]['tone']);
    }

    public function test_a_component_with_no_history_reports_full_uptime(): void
    {
        $this->assertSame(100.0, $this->uptime->percentage($this->component));
    }

    public function test_many_components_cost_one_query_and_match_the_single_bar(): void
    {
        $this->day(1, 3600);
        $other = Component::create(['name' => 'web-07']);
        $components = Component::whereIn('id', [$this->component->id, $other->id])->get();

        DB::enableQueryLog();
        $bars = $this->uptime->barsFor($components);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertSame(1, $queries);
        $this->assertSame($this->uptime->bar($this->component), $bars[$this->component->id]);
        $this->assertSame($this->uptime->bar($other), $bars[$other->id]);
        $this->assertSame($this->uptime->percentage($this->component), $this->uptime->percentageOf($bars[$this->component->id]));
    }
}
