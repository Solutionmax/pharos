<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Maintenance;
use App\Models\StatusPage;
use App\Models\UptimeDay;
use App\Models\User;
use App\Services\MonthlyUptimeReport;
use App\Services\PageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->get('/reports?month=2026-09')->assertOk()->assertSee('95.00%')->assertSee('UTC')->assertDontSee('SECRET');
        $csv = $this->get('/reports.csv?month=2026-09')->assertOk();
        $csv->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $rows = array_map('str_getcsv', explode("\n", trim($csv->getContent())));
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
}
