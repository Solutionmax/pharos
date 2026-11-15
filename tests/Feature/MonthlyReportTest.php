<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\Maintenance;
use App\Models\StatusPage;
use App\Models\UptimeDay;
use App\Models\User;
use App\Services\MonthlyUptimeReport;
use App\Services\PageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MonthlyReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_uses_weighted_observations_utc_boundaries_coverage_and_safe_csv(): void
    {
        $component = Component::create(['name' => '=1+1']);
        UptimeDay::create(['component_id' => $component->id, 'day' => '2026-09-01', 'up_seconds' => 60, 'down_seconds' => 60]);
        UptimeDay::create(['component_id' => $component->id, 'day' => '2026-09-02', 'up_seconds' => 1080, 'down_seconds' => 0]);
        UptimeDay::create(['component_id' => $component->id, 'day' => '2026-10-01', 'up_seconds' => 0, 'down_seconds' => 10000]);
        $hidden = ComponentGroup::create(['name' => 'Hidden', 'visible' => false]);
        Component::create(['name' => 'SECRET', 'component_group_id' => $hidden->id]);
        // Visitors read uptime and downtime; the seconds and the accounting note are in the CSV.
        $this->get('/reports?month=2026-09')->assertOk()->assertSee('95.00%')->assertSee('1 min')->assertDontSee('SECRET')->assertDontSee('Coverage');
        $csv = $this->get('/reports.csv?month=2026-09')->assertOk();
        $csv->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $rows = array_map(fn (string $line) => str_getcsv($line, ',', '"', ''), explode("\n", trim($csv->getContent())));
        $this->assertSame("'=1+1", $rows[1][0]);
        $this->assertSame('1140', $rows[1][1]);
        $this->assertSame('60', $rows[1][2]);
        $this->assertSame('95.00', $rows[1][3]);
        $this->get('/reports?month=2026-13')->assertSessionHasErrors('month');
        $this->get('/reports?month=2099-01')->assertSessionHasErrors('month');
    }

    public function test_overlapping_maintenance_is_excluded_once_and_foreign_rows_never_count(): void
    {
        $component = Component::create(['name' => 'A']);
        foreach ([['01:00:00', '03:00:00'], ['02:00:00', '04:00:00']] as [$from, $to]) {
            $window = Maintenance::create(['title' => 'Work', 'starts_at' => '2026-09-01 '.$from, 'ends_at' => '2026-09-01 '.$to, 'started_at' => '2026-09-01 '.$from, 'completed_at' => '2026-09-01 '.$to]);
            $window->components()->attach($component->id);
        }
        $report = app(MonthlyUptimeReport::class)->build('2026-09');
        $this->assertSame(10800, $report['rows'][0]['excluded_seconds']);
        $this->assertSame(30 * 86400 - 10800, $report['rows'][0]['unobserved_seconds']);
        $this->assertNull($report['rows'][0]['uptime']);
        $page = StatusPage::create(['name' => 'Other', 'slug' => 'other', 'is_published' => true]);
        app(PageContext::class)->run($page->id, fn () => Component::create(['name' => 'FOREIGN']));
        $this->get('/reports.csv?month=2026-09')->assertOk()->assertDontSee('FOREIGN');
        $this->get('/status/other/reports.csv?month=2026-09')->assertOk()->assertSee('FOREIGN')->assertDontSee('Work');
    }

    public function test_pdf_is_actual_safe_unicode_pdf_and_page_scoped(): void
    {
        Component::create(['name' => 'Üptime <img src="file:///etc/passwd">']);
        $pdf = $this->get('/reports.pdf?month=2026-09')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $pdf->getContent());
        $this->assertStringContainsString('%%EOF', $pdf->getContent());
        $this->assertGreaterThan(1000, strlen($pdf->getContent()));
        $this->assertStringNotContainsString('root:x:', $pdf->getContent());
        $path = tempnam(sys_get_temp_dir(), 'pharos-report-');
        try {
            file_put_contents($path, $pdf->getContent());
            $process = new Process(['pdftotext', $path, '-']);
            $process->mustRun();
            $this->assertStringContainsString('Üptime', $process->getOutput());
            $this->assertStringContainsString('2026-09', $process->getOutput());
        } finally {
            unlink($path);
        }
        StatusPage::default()->update(['is_published' => false]);
        $this->get('/reports.pdf?month=2026-09')->assertNotFound();
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))->get('/admin/reports?month=2026-09')->assertOk();
    }

    public function test_visitors_read_downtime_incidents_and_how_the_month_was_measured(): void
    {
        Carbon::setTestNow('2026-11-15 12:00:00');
        $website = Component::create(['name' => 'Website']);
        $mail = Component::create(['name' => 'Mail']);
        UptimeDay::create(['component_id' => $website->id, 'day' => '2026-11-03', 'up_seconds' => 86400 - 4320, 'down_seconds' => 4320]);
        UptimeDay::create(['component_id' => $mail->id, 'day' => '2026-11-04', 'up_seconds' => 86400, 'down_seconds' => 0]);
        $work = Maintenance::create(['title' => 'Work', 'starts_at' => '2026-11-06 01:00:00', 'ends_at' => '2026-11-06 02:00:00', 'started_at' => '2026-11-06 01:00:00', 'completed_at' => '2026-11-06 02:00:00']);
        $work->components()->attach($mail->id);
        foreach ([['2026-11-05 10:00:00', 'public'], ['2026-11-07 10:00:00', 'internal'], ['2026-10-30 10:00:00', 'public']] as [$at, $visibility]) {
            Incident::create(['name' => 'Down', 'status' => 4, 'occurred_at' => $at, 'resolved_at' => $at, 'visibility' => $visibility])
                ->components()->attach($website->id, ['status' => 4]);
        }

        $this->get('/reports?month=2026-11')->assertOk()
            ->assertSee('Measured since 3 Nov. Planned maintenance does not count as downtime.')
            ->assertSeeInOrder(['Website', '95.00%', '1 h 12 min', '1', 'Mail', '100.00%', 'none', '0'])
            ->assertDontSee('Unobserved seconds');
        $this->get('/reports.csv?month=2026-11')->assertOk()->assertSee('Unobserved seconds');
        $this->get('/reports?month=2026-10')->assertOk()->assertSee('Nothing was measured this month.');

        $this->assertSame('none', MonthlyUptimeReport::downtime(0));
        $this->assertSame('< 1 min', MonthlyUptimeReport::downtime(20));
        $this->assertSame('6 min', MonthlyUptimeReport::downtime(360));
        $this->assertSame('2 d 3 h', MonthlyUptimeReport::downtime(2 * 86400 + 3 * 3600));
    }
}
