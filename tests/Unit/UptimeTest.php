<?php

namespace Tests\Unit;

use App\Enums\ComponentStatus;
use App\Enums\IncidentStatus;
use App\Models\Component;
use App\Models\Incident;
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
        $this->day(daysAgo: 1, downSeconds: 900);   // 15 min lost
        $this->assertSame('p', $this->uptime->bar($this->component)[Uptime::WINDOW_DAYS - 2]['tone']);

        // One missed check is a blip, not a slow day.
        UptimeDay::query()->delete();
        $this->day(daysAgo: 1, downSeconds: 60);    // 1 min lost, 99.93%
        $this->assertSame('t', $this->uptime->bar($this->component)[Uptime::WINDOW_DAYS - 2]['tone']);
        $this->assertEqualsWithDelta(1.0, $this->uptime->bar($this->component)[Uptime::WINDOW_DAYS - 2]['lost'], 0.001);

        // Exactly five minutes is no longer a blip, whatever the rounding of the percentage.
        UptimeDay::query()->delete();
        $this->day(daysAgo: 1, downSeconds: 300);
        $this->assertSame('p', $this->uptime->bar($this->component)[Uptime::WINDOW_DAYS - 2]['tone']);

        UptimeDay::query()->delete();
        $this->day(daysAgo: 1, downSeconds: 7200);  // 2 h lost
        $this->assertSame('b', $this->uptime->bar($this->component)[Uptime::WINDOW_DAYS - 2]['tone']);
    }

    public function test_a_public_incident_colours_every_day_it_ran_for_the_components_it_set(): void
    {
        $this->day(daysAgo: 1, downSeconds: 60);    // a blip on its own
        $this->day(daysAgo: 2, downSeconds: 0);
        $this->day(daysAgo: 3, downSeconds: 0);
        $other = Component::create(['name' => 'web-07']);
        $incident = Incident::create(['name' => 'Slow disks', 'status' => IncidentStatus::Resolved, 'visibility' => 'public',
            'occurred_at' => Carbon::today()->subDays(2)->setTime(22, 0), 'resolved_at' => Carbon::today()->subDay()->setTime(2, 0)]);
        $incident->components()->attach($this->component->id, ['status' => ComponentStatus::PerformanceIssues->value]);
        $private = Incident::create(['name' => 'Internal', 'status' => IncidentStatus::Resolved, 'visibility' => 'private',
            'occurred_at' => Carbon::today()->subDays(3)->setTime(9, 0), 'resolved_at' => Carbon::today()->subDays(3)->setTime(10, 0)]);
        $private->components()->attach($this->component->id, ['status' => ComponentStatus::MajorOutage->value]);

        $bars = $this->uptime->barsFor(Component::whereIn('id', [$this->component->id, $other->id])->get());
        $bar = $bars[$this->component->id];

        // The blip day and the day before both ran the incident: slow, not a blip and not green.
        $this->assertSame('w', $bar[Uptime::WINDOW_DAYS - 2]['tone']);
        $this->assertSame('w', $bar[Uptime::WINDOW_DAYS - 3]['tone']);
        // A private incident says nothing on the public strip.
        $this->assertSame('ok', $bar[Uptime::WINDOW_DAYS - 4]['tone']);
        // A component the incident did not set keeps its own measurement.
        $this->assertSame('unknown', $bars[$other->id][Uptime::WINDOW_DAYS - 2]['tone']);
    }

    public function test_an_incident_on_a_day_without_measurements_still_shows(): void
    {
        $incident = Incident::create(['name' => 'Down before monitoring', 'status' => IncidentStatus::Resolved, 'visibility' => 'public',
            'occurred_at' => Carbon::today()->subDays(5)->setTime(9, 0), 'resolved_at' => Carbon::today()->subDays(5)->setTime(11, 0)]);
        $incident->components()->attach($this->component->id, ['status' => ComponentStatus::MajorOutage->value]);

        $day = $this->uptime->bar($this->component)[Uptime::WINDOW_DAYS - 6];

        $this->assertFalse($day['known']);
        $this->assertSame('b', $day['tone']);
        $this->assertSame('b', $this->uptime->aggregate([$this->component->id => $this->uptime->bar($this->component)])[Uptime::WINDOW_DAYS - 6]['tone']);
    }

    public function test_a_component_with_no_history_reports_unknown_uptime(): void
    {
        $this->assertNull($this->uptime->percentage($this->component));
    }

    public function test_many_components_cost_no_more_queries_than_one_and_match_the_single_bar(): void
    {
        $this->day(1, 3600);
        $other = Component::create(['name' => 'web-07']);
        $components = Component::whereIn('id', [$this->component->id, $other->id])->get();
        $this->uptime->barsFor($components->take(1));

        DB::enableQueryLog();
        $this->uptime->barsFor($components->take(1));
        $one = count(DB::getQueryLog());
        DB::flushQueryLog();
        $bars = $this->uptime->barsFor($components);
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        // The days in one query, the incidents that colour them in one more.
        $this->assertSame($one, $queries);
        $this->assertSame($this->uptime->bar($this->component), $bars[$this->component->id]);
        $this->assertSame($this->uptime->bar($other), $bars[$other->id]);
        $this->assertSame($this->uptime->percentage($this->component), $this->uptime->percentageOf($bars[$this->component->id]));
    }
}
