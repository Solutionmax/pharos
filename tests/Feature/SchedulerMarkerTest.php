<?php

namespace Tests\Feature;

use App\Console\Commands\RunChecks;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * The web installer turns its "waiting for the first run" light green by
 * reading this file, because it cannot boot the app on every poll.
 */
class SchedulerMarkerTest extends TestCase
{
    use RefreshDatabase;

    private string $marker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->marker = storage_path(RunChecks::SCHEDULER_MARKER);
        @unlink($this->marker);
    }

    protected function tearDown(): void
    {
        @unlink($this->marker);
        parent::tearDown();
    }

    public function test_every_check_run_stamps_the_scheduler_marker(): void
    {
        Carbon::setTestNow('2026-09-23 14:02:00');

        $this->artisan('pharos:check')->assertSuccessful();

        $this->assertFileExists($this->marker);
        $this->assertSame('2026-09-23T14:02:00+00:00', file_get_contents($this->marker));
        Carbon::setTestNow();
    }

    public function test_the_marker_path_is_the_one_the_installer_reads(): void
    {
        $this->assertSame(storage_path('framework/pharos-scheduler-last-run'), $this->marker);
    }

    public function test_the_schedule_runs_the_check_that_stamps_it(): void
    {
        $this->artisan('schedule:list')->expectsOutputToContain('pharos:check')->assertSuccessful();
    }
}
