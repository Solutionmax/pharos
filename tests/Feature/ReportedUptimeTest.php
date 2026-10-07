<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Models\Check;
use App\Models\Component;
use App\Models\Setting;
use App\Models\StatusPage;
use App\Models\UptimeDay;
use App\Services\PageContext;
use App\Services\ReportedUptime;
use App\Services\Uptime;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class ReportedUptimeTest extends TestCase
{
    use RefreshDatabase;

    protected function tickAt(string $last, string $now): void
    {
        Setting::put(ReportedUptime::LAST_TICK, Carbon::parse($last)->toIso8601String());
        app(ReportedUptime::class)->tick(Carbon::parse($now));
    }

    protected function day(Component $component, string $day = '2026-10-06'): ?UptimeDay
    {
        return UptimeDay::where('component_id', $component->id)->where('day', Carbon::parse($day)->startOfDay())->first();
    }

    protected function reported(ComponentStatus $status, string $source = 'webhook'): Component
    {
        return Component::create(['name' => 'Reported', 'source' => $source, 'status' => $status]);
    }

    public function test_operational_and_degraded_credit_up_seconds(): void
    {
        $ok = $this->reported(ComponentStatus::Operational);
        $slow = $this->reported(ComponentStatus::PerformanceIssues, 'kuma');
        $upstream = $this->reported(ComponentStatus::Operational, 'upstream');

        $this->tickAt('2026-10-06 12:00:00', '2026-10-06 12:01:00');

        foreach ([$ok, $slow, $upstream] as $component) {
            $this->assertSame([60, 0], [$this->day($component)->up_seconds, $this->day($component)->down_seconds]);
            $this->assertSame(ComponentStatus::Operational, $this->day($component)->worst_status);
        }
    }

    public function test_partial_and_major_outage_credit_down_seconds_and_set_the_worst_status(): void
    {
        $partial = $this->reported(ComponentStatus::PartialOutage);
        $major = $this->reported(ComponentStatus::MajorOutage);

        $this->tickAt('2026-10-06 12:00:00', '2026-10-06 12:01:00');

        $this->assertSame([0, 60, ComponentStatus::PartialOutage], [$this->day($partial)->up_seconds, $this->day($partial)->down_seconds, $this->day($partial)->worst_status]);
        $this->assertSame([0, 60, ComponentStatus::MajorOutage], [$this->day($major)->up_seconds, $this->day($major)->down_seconds, $this->day($major)->worst_status]);

        $partial->update(['status' => ComponentStatus::Operational]);
        $this->tickAt('2026-10-06 12:01:00', '2026-10-06 12:02:00');
        $this->assertSame(ComponentStatus::PartialOutage, $this->day($partial)->worst_status);
    }

    public function test_maintenance_is_never_an_outage_and_credits_nothing(): void
    {
        $component = $this->reported(ComponentStatus::UnderMaintenance);

        $this->tickAt('2026-10-06 12:00:00', '2026-10-06 12:01:00');

        $this->assertNull($this->day($component));
    }

    public function test_a_component_set_by_hand_gets_nothing(): void
    {
        $component = Component::create(['name' => 'Phone', 'source' => 'manual']);
        $disabled = Component::create(['name' => 'Off', 'source' => 'webhook', 'enabled' => false]);

        $this->tickAt('2026-10-06 12:00:00', '2026-10-06 12:01:00');

        $this->assertNull($this->day($component));
        $this->assertNull($this->day($disabled));
    }

    public function test_a_component_with_its_own_enabled_check_gets_nothing_from_the_tick(): void
    {
        $checked = $this->reported(ComponentStatus::Operational);
        Check::create(['component_id' => $checked->id, 'type' => 'http', 'target' => 'https://example.test/', 'enabled' => true]);
        $paused = $this->reported(ComponentStatus::Operational, 'kuma');
        Check::create(['component_id' => $paused->id, 'type' => 'http', 'target' => 'https://example.test/', 'enabled' => false]);

        $this->tickAt('2026-10-06 12:00:00', '2026-10-06 12:01:00');

        $this->assertNull($this->day($checked));
        $this->assertSame(60, $this->day($paused)->up_seconds);
    }

    public function test_the_credit_per_tick_is_capped_after_a_stalled_scheduler(): void
    {
        $component = $this->reported(ComponentStatus::Operational);

        $this->tickAt('2026-10-06 08:00:00', '2026-10-06 12:00:00');

        $this->assertSame(ReportedUptime::MAX_CREDIT_SECONDS, $this->day($component)->up_seconds);
    }

    public function test_time_is_cut_at_midnight_utc(): void
    {
        $component = $this->reported(ComponentStatus::Operational);

        $this->tickAt('2026-10-06 23:59:30', '2026-10-07 00:00:30');

        $this->assertNull($this->day($component, '2026-10-06'));
        $this->assertSame(30, $this->day($component, '2026-10-07')->up_seconds);
    }

    public function test_the_first_tick_only_starts_the_clock(): void
    {
        $component = $this->reported(ComponentStatus::Operational);

        app(ReportedUptime::class)->tick(Carbon::parse('2026-10-06 12:00:00'));
        $this->assertNull($this->day($component));

        app(ReportedUptime::class)->tick(Carbon::parse('2026-10-06 12:01:00'));
        $this->assertSame(60, $this->day($component)->up_seconds);
    }

    public function test_time_is_never_credited_twice_for_the_same_tick(): void
    {
        $component = $this->reported(ComponentStatus::Operational);

        $this->tickAt('2026-10-06 12:00:00', '2026-10-06 12:01:00');
        app(ReportedUptime::class)->tick(Carbon::parse('2026-10-06 12:01:00'));

        $this->assertSame(60, $this->day($component)->up_seconds);
    }

    public function test_a_component_on_an_archived_page_gets_nothing_but_another_page_does(): void
    {
        $archived = StatusPage::create(['name' => 'Old', 'slug' => 'old', 'is_published' => true, 'archived_at' => now()]);
        $live = StatusPage::create(['name' => 'Live', 'slug' => 'live', 'is_published' => true]);
        $onArchived = app(PageContext::class)->run($archived->id, fn () => $this->reported(ComponentStatus::Operational));
        $onLive = app(PageContext::class)->run($live->id, fn () => $this->reported(ComponentStatus::Operational));

        $this->tickAt('2026-10-06 12:00:00', '2026-10-06 12:01:00');

        $this->assertNull($this->day($onArchived));
        $this->assertSame(60, $this->day($onLive)->up_seconds);
    }

    public function test_the_uptime_bar_shows_reported_days_as_known(): void
    {
        $component = $this->reported(ComponentStatus::Operational);
        $this->tickAt('2026-10-06 12:00:00', '2026-10-06 12:01:00');

        $bar = app(Uptime::class)->bar($component, Carbon::parse('2026-10-06'));

        $last = end($bar);
        $this->assertTrue($last['known']);
        $this->assertSame(100.0, $last['pct']);
        $this->assertFalse($bar[0]['known']);
    }

    public function test_the_tick_is_scheduled_every_minute(): void
    {
        $events = collect(app(Schedule::class)->events())->filter(fn ($e) => $e->description === 'credit-reported-uptime');

        $this->assertCount(1, $events);
        $this->assertSame('* * * * *', $events->first()->expression);
    }
}
