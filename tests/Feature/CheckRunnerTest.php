<?php

namespace Tests\Feature;

use App\Enums\CheckType;
use App\Enums\ComponentStatus;
use App\Enums\IncidentStatus;
use App\Models\Check;
use App\Models\CheckResult;
use App\Models\Component;
use App\Models\Incident;
use App\Models\UptimeDay;
use App\Services\CheckRunner;
use App\Services\OutgoingWebhook;
use App\Services\Probe;
use App\Services\ProbeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/** Probe stub: the runner is what we are testing, not the network. */
class FakeProbe extends Probe
{
    public function __construct(public bool $ok = true, public ?string $message = 'stub') {}

    public function run(Check $check): ProbeResult
    {
        return new ProbeResult($this->ok, 12, $this->message);
    }
}

class CheckRunnerTest extends TestCase
{
    use RefreshDatabase;

    protected function makeCheck(array $attributes = []): Check
    {
        $component = Component::create(['name' => 'web-06', 'status' => ComponentStatus::Operational]);

        return Check::create(array_merge([
            'component_id' => $component->id,
            'type' => CheckType::Http,
            'target' => 'https://example.net/',
            'interval_seconds' => 60,
            'retries' => 2,
        ], $attributes));
    }

    protected function runner(bool $ok): CheckRunner
    {
        return new CheckRunner(new FakeProbe($ok, $ok ? 'HTTP 200' : 'No response'), app(OutgoingWebhook::class));
    }

    public function test_one_failure_is_not_enough_to_declare_an_outage(): void
    {
        $check = $this->makeCheck();
        $this->runner(false)->runOne($check);

        $this->assertSame(ComponentStatus::Operational, $check->component->fresh()->status);
        $this->assertSame(0, Incident::count());
    }

    public function test_it_opens_an_incident_once_retries_are_exhausted(): void
    {
        $check = $this->makeCheck(['retries' => 2]);
        $runner = $this->runner(false);

        $runner->runOne($check);
        $runner->runOne($check->fresh());

        $this->assertSame(ComponentStatus::MajorOutage, $check->component->fresh()->status);

        $incident = Incident::first();
        $this->assertNotNull($incident);
        $this->assertSame('web-06 unreachable', $incident->name);
        $this->assertSame('check', $incident->source);
        $this->assertTrue($incident->auto_resolve);
        $this->assertTrue($incident->updates->first()->automatic);
        $this->assertStringContainsString('No response', $incident->updates->first()->message);
    }

    public function test_it_does_not_open_a_second_incident_while_one_is_open(): void
    {
        $check = $this->makeCheck(['retries' => 1]);
        $runner = $this->runner(false);

        $runner->runOne($check);
        $runner->runOne($check->fresh());
        $runner->runOne($check->fresh());

        $this->assertSame(1, Incident::count());
    }

    public function test_it_resolves_itself_after_a_recovery_streak(): void
    {
        $check = $this->makeCheck(['retries' => 1]);
        $this->runner(false)->runOne($check);
        $this->assertSame(1, Incident::count());

        $up = $this->runner(true);
        for ($i = 0; $i < CheckRunner::RECOVERY_STREAK; $i++) {
            $up->runOne($check->fresh());
        }

        $incident = Incident::first()->fresh('updates');
        $this->assertSame(IncidentStatus::Resolved, $incident->status);
        $this->assertNotNull($incident->resolved_at);
        $this->assertSame(ComponentStatus::Operational, $check->component->fresh()->status);
        $this->assertTrue($incident->updates->first()->automatic);
    }

    /**
     * The form promises "a built-in check overwrites this on its next run".
     * A hand-set Degraded must therefore not survive a passing check; only
     * Under maintenance is deliberate and stays until someone clears it.
     */
    public function test_a_passing_check_clears_a_hand_set_degraded_status(): void
    {
        $check = $this->makeCheck();
        $check->component->update(['status' => ComponentStatus::PerformanceIssues]);

        $this->runner(true)->runOne($check->fresh());

        $this->assertSame(ComponentStatus::Operational, $check->component->fresh()->status);
    }

    public function test_a_passing_check_leaves_maintenance_alone(): void
    {
        $check = $this->makeCheck();
        $check->component->update(['status' => ComponentStatus::UnderMaintenance]);

        $this->runner(true)->runOne($check->fresh());

        $this->assertSame(ComponentStatus::UnderMaintenance, $check->component->fresh()->status);
    }

    public function test_a_check_is_only_due_once_its_interval_has_passed(): void
    {
        $check = $this->makeCheck(['interval_seconds' => 300, 'last_run_at' => now()]);

        $this->assertFalse($check->isDue(now()->addMinutes(4)));
        $this->assertTrue($check->isDue(now()->addMinutes(6)));
    }

    public function test_disabled_checks_never_run(): void
    {
        $this->makeCheck(['enabled' => false]);

        $this->assertSame(0, $this->runner(false)->runDue());
    }

    public function test_it_records_uptime_per_day(): void
    {
        $this->travelTo(Carbon::parse('2026-08-27 12:00:00'));
        $check = $this->makeCheck();
        $this->runner(true)->runOne($check);

        $day = UptimeDay::first();
        $this->assertSame(60, $day->up_seconds);
        $this->assertSame(0, $day->down_seconds);
        $this->assertSame(100.0, $day->percentage());
    }

    public function test_uptime_credits_the_wall_time_between_runs_not_the_interval(): void
    {
        $this->travelTo(Carbon::parse('2026-08-27 12:00:00'));
        $check = $this->makeCheck(['interval_seconds' => 60]);
        $runner = $this->runner(true);

        $runner->runOne($check);
        $this->travel(60)->seconds();
        $runner->runOne($check->fresh());
        $this->travel(60)->seconds();
        $runner->runOne($check->fresh());

        $this->assertSame(180, UptimeDay::first()->up_seconds);
    }

    public function test_a_stalled_scheduler_does_not_back_fill_more_than_one_interval(): void
    {
        $this->travelTo(Carbon::parse('2026-08-27 12:00:00'));
        $check = $this->makeCheck(['interval_seconds' => 60]);
        $runner = $this->runner(true);

        $runner->runOne($check);
        $this->travel(3)->hours();
        $runner->runOne($check->fresh());

        $this->assertSame(120, UptimeDay::first()->up_seconds);
    }

    public function test_a_run_just_after_midnight_does_not_credit_yesterday_to_today(): void
    {
        $this->travelTo(Carbon::parse('2026-08-27 23:59:40'));
        $check = $this->makeCheck(['interval_seconds' => 60]);
        $runner = $this->runner(true);

        $runner->runOne($check);
        $this->travel(40)->seconds();
        $runner->runOne($check->fresh());

        $today = UptimeDay::where('day', Carbon::parse('2026-08-28'))->first();
        $this->assertSame(20, $today->up_seconds);
    }

    /**
     * Regression: a due heartbeat is evaluated on every scheduler tick, and each
     * tick credited the full 24 h interval. One day of "Backups" reached
     * up_seconds = 101,952,000.
     */
    public function test_a_heartbeat_evaluated_every_tick_never_exceeds_the_day(): void
    {
        $this->travelTo(Carbon::parse('2026-08-27 03:30:00'));
        $check = $this->makeCheck([
            'type' => CheckType::Heartbeat,
            'target' => 'hb_backups',
            'interval_seconds' => 86400,
            'last_run_at' => now(),
        ]);
        $runner = new CheckRunner(new Probe, app(OutgoingWebhook::class));

        // Next day, the ping is late but within grace: due on every tick from here on.
        $this->travel(1)->day();
        $this->assertTrue($check->fresh()->isDue(now()));
        $runner->runOne($check->fresh(), now());
        $afterFirst = UptimeDay::first()->up_seconds;

        $this->travel(1)->minute();
        $runner->runOne($check->fresh(), now());
        $this->travel(1)->minute();
        $runner->runOne($check->fresh(), now());

        $day = UptimeDay::first();
        $total = $day->up_seconds + $day->down_seconds;

        $this->assertSame($afterFirst + 120, $day->up_seconds, 'each tick credits the elapsed minute');
        $this->assertLessThanOrEqual(now()->secondsSinceMidnight(), $total);
        $this->assertLessThanOrEqual(86400, $total);
        $this->assertSame(100.0, $day->percentage());
    }

    public function test_a_stale_heartbeat_accrues_down_time_per_tick(): void
    {
        $this->travelTo(Carbon::parse('2026-08-27 12:00:00'));
        $check = $this->makeCheck([
            'type' => CheckType::Heartbeat,
            'target' => 'hb_backups',
            'interval_seconds' => 86400,
            'last_run_at' => now()->subDays(3),
            'retries' => 1,
        ]);
        $runner = new CheckRunner(new Probe, app(OutgoingWebhook::class));

        $runner->runOne($check->fresh(), now());
        $afterFirst = UptimeDay::first()->down_seconds;
        $this->travel(1)->minute();
        $runner->runOne($check->fresh(), now());

        $day = UptimeDay::first();
        $this->assertSame($afterFirst + 60, $day->down_seconds);
        $this->assertSame(0, $day->up_seconds);
        $this->assertLessThanOrEqual(86400, $day->down_seconds);
        $this->assertSame(0.0, $day->percentage());
    }

    public function test_a_failure_marks_the_day_as_degraded(): void
    {
        $this->travelTo(Carbon::parse('2026-08-27 12:00:00'));
        $check = $this->makeCheck();
        $this->runner(false)->runOne($check);

        $day = UptimeDay::first();
        $this->assertSame(60, $day->down_seconds);
        $this->assertSame(ComponentStatus::MajorOutage, $day->worst_status);
    }

    public function test_a_heartbeat_that_stops_calling_in_is_an_outage(): void
    {
        $check = $this->makeCheck([
            'type' => CheckType::Heartbeat,
            'target' => 'hb_test',
            'interval_seconds' => 3600,
            'last_run_at' => now()->subHours(5),
            'retries' => 1,
        ]);

        // The real probe, because staleness is exactly what we are testing.
        (new CheckRunner(new Probe, app(OutgoingWebhook::class)))->runOne($check);

        $this->assertSame(ComponentStatus::MajorOutage, $check->component->fresh()->status);
    }

    public function test_a_fresh_heartbeat_is_healthy(): void
    {
        $check = $this->makeCheck([
            'type' => CheckType::Heartbeat,
            'target' => 'hb_test',
            'interval_seconds' => 3600,
            'last_run_at' => now()->subMinutes(5),
        ]);

        (new CheckRunner(new Probe, app(OutgoingWebhook::class)))->runOne($check);

        $this->assertSame(ComponentStatus::Operational, $check->component->fresh()->status);
    }

    public function test_pinging_a_heartbeat_endpoint_keeps_it_alive(): void
    {
        $check = $this->makeCheck([
            'type' => CheckType::Heartbeat,
            'target' => 'hb_secret',
            'last_run_at' => now()->subDay(),
        ]);

        $this->postJson('/api/v1/heartbeat/hb_secret')->assertOk()->assertJson(['ok' => true]);

        $this->assertTrue($check->fresh()->last_run_at->isAfter(now()->subMinute()));
    }

    public function test_a_single_ping_clears_a_heartbeat_outage(): void
    {
        $check = $this->makeCheck([
            'type' => CheckType::Heartbeat,
            'target' => 'hb_secret',
            'interval_seconds' => 86400,
            'last_run_at' => now()->subDays(3),
            'retries' => 1,
        ]);

        // Silence first, the way a missed nightly backup gets there.
        (new CheckRunner(new Probe, app(OutgoingWebhook::class)))->runOne($check);
        $this->assertSame(ComponentStatus::MajorOutage, $check->component->fresh()->status);
        $this->assertSame(1, Incident::whereNull('resolved_at')->count());

        $this->postJson('/api/v1/heartbeat/hb_secret')->assertOk();

        // The job reporting in IS the recovery. Waiting for two more daily cycles
        // would keep the page red for days after the backup demonstrably ran.
        $this->assertSame(ComponentStatus::Operational, $check->component->fresh()->status);
        $this->assertSame(0, Incident::whereNull('resolved_at')->count());
    }

    public function test_an_unknown_heartbeat_token_is_a_404(): void
    {
        $this->postJson('/api/v1/heartbeat/nope')->assertStatus(404);
    }

    public function test_a_second_run_is_skipped_while_one_is_still_going(): void
    {
        $check = $this->makeCheck(['retries' => 0]);

        $lock = Cache::lock('pharos:checks', 300);
        $this->assertTrue($lock->get());

        $this->artisan('pharos:check --force')->assertSuccessful();

        $this->assertSame(0, CheckResult::count());

        $lock->release();
    }
}
