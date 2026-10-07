<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Models\Check;
use App\Models\CheckResult;
use App\Models\Component;
use App\Models\Maintenance;
use App\Models\UptimeDay;
use App\Services\CheckRunner;
use App\Services\MonthlyUptimeReport;
use App\Services\OutgoingWebhook;
use App\Services\Probe;
use App\Services\ProbeResult;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MaintenanceUptimeAccountingTest extends TestCase
{
    use RefreshDatabase;

    private function runner(bool $ok): CheckRunner
    {
        $probe = new class($ok) extends Probe
        {
            public function __construct(private bool $healthy) {}

            public function run(Check $check): ProbeResult
            {
                return new ProbeResult($this->healthy, 12, $this->healthy ? 'Measured healthy' : 'Measured failed');
            }
        };

        return new CheckRunner($probe, app(OutgoingWebhook::class));
    }

    private function window(Component $component, string $start, string $end, array $extra = []): Maintenance
    {
        $window = Maintenance::create($extra + ['title' => 'Work', 'starts_at' => $start, 'ends_at' => $end, 'started_at' => $start]);
        $window->components()->attach($component->id);

        return $window;
    }

    public function test_maintenance_checks_preserve_evidence_without_changing_observed_uptime(): void
    {
        $component = Component::create(['name' => 'A']);
        $check = Check::create(['component_id' => $component->id, 'type' => 'http', 'target' => 'https://example.test', 'interval_seconds' => 60]);
        $this->travelTo(CarbonImmutable::parse('2026-09-01 12:00:00', 'UTC'));
        $this->runner(true)->runOne($check);
        $this->window($component, '2026-09-01 12:00:00', '2026-09-01 13:00:00');
        $component->update(['status' => ComponentStatus::UnderMaintenance]);
        $this->travelTo(CarbonImmutable::parse('2026-09-01 12:01:00', 'UTC'));
        $this->runner(false)->runOne($check->fresh());
        $this->assertSame(60, UptimeDay::sole()->up_seconds);
        $this->assertSame(0, UptimeDay::sole()->down_seconds);
        $this->assertSame(2, CheckResult::count());
        $this->assertFalse(CheckResult::latest('id')->first()->ok);
        $report = app(MonthlyUptimeReport::class)->build('2026-09');
        $this->assertSame(100.0, $report['rows'][0]['uptime']);
        $this->assertSame(60, $report['rows'][0]['excluded_seconds']);
        $this->assertSame(0.14, $report['rows'][0]['coverage']);
        $this->assertSame(43140, $report['rows'][0]['unobserved_seconds']);
    }

    public function test_interval_union_excludes_partial_overlap_and_stops_at_cancellation(): void
    {
        $component = Component::create(['name' => 'A']);
        $check = Check::create(['component_id' => $component->id, 'type' => 'http', 'target' => 'https://example.test', 'interval_seconds' => 60]);
        CheckResult::create(['component_id' => $component->id, 'ok' => true, 'checked_at' => '2026-09-01 12:00:00']);
        $this->window($component, '2026-09-01 12:00:10', '2026-09-01 12:00:40', ['completed_at' => '2026-09-01 12:00:40']);
        $this->window($component, '2026-09-01 12:00:20', '2026-09-01 13:00:00', ['cancelled_at' => '2026-09-01 12:00:50']);
        $this->window($component, '2026-09-01 11:00:00', '2026-09-01 13:00:00', ['started_at' => null]);
        $this->runner(true)->runOne($check, CarbonImmutable::parse('2026-09-01 12:01:00', 'UTC'));
        $this->assertSame(20, UptimeDay::sole()->up_seconds);
        $this->assertSame(0, UptimeDay::sole()->down_seconds);
    }

    public function test_credit_is_capped_and_uses_utc_midnight_for_zoned_now(): void
    {
        $component = Component::create(['name' => 'A']);
        $check = Check::create(['component_id' => $component->id, 'type' => 'http', 'target' => 'https://example.test', 'interval_seconds' => 60]);
        CheckResult::create(['component_id' => $component->id, 'ok' => true, 'checked_at' => '2026-08-31 23:00:00']);
        $this->window($component, '2026-08-31 23:00:00', '2026-08-31 23:55:00', ['completed_at' => '2026-08-31 23:55:00']);
        $this->runner(true)->runOne($check, CarbonImmutable::parse('2026-09-01 02:00:30', 'Europe/Amsterdam'));
        $this->assertSame('2026-09-01', UptimeDay::sole()->day->format('Y-m-d'));
        $this->assertSame(30, UptimeDay::sole()->up_seconds);
    }

    public function test_manual_maintenance_does_not_add_credit_or_rewrite_older_rows(): void
    {
        $component = Component::create(['name' => 'A', 'status' => 5]);
        $check = Check::create(['component_id' => $component->id, 'type' => 'http', 'target' => 'https://example.test']);
        $legacy = UptimeDay::create(['component_id' => $component->id, 'day' => '2026-08-01', 'up_seconds' => 120, 'down_seconds' => 60]);
        $this->runner(true)->runOne($check, CarbonImmutable::parse('2026-09-01 12:00:00', 'UTC'));
        $this->assertSame(1, UptimeDay::count());
        $this->assertSame(120, $legacy->fresh()->up_seconds);
        $this->assertSame(60, $legacy->fresh()->down_seconds);
        $this->assertSame(1, CheckResult::count());
    }
}
