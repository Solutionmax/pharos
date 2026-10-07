<?php

namespace Tests\Feature;

use App\Models\Component;
use App\Models\StatusPage;
use App\Models\UptimeDay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class MonthlyReportPdfLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_embeds_brand_fonts_and_preserves_unicode_rows_across_pages(): void
    {
        StatusPage::default()->update(['name' => 'North Üptime']);
        for ($i = 1; $i <= 80; $i++) {
            Component::create(['name' => sprintf('Service %03d — Réseau Ж', $i), 'position' => $i]);
        }
        UptimeDay::create(['component_id' => Component::first()->id, 'day' => '2026-09-01', 'up_seconds' => 1140, 'down_seconds' => 60]);

        $response = $this->get('/reports.pdf?month=2026-09')->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $path = tempnam(sys_get_temp_dir(), 'pharos-pdf-layout-');
        try {
            file_put_contents($path, $response->getContent());
            $text = new Process(['pdftotext', '-layout', $path, '-']);
            $text->mustRun();
            $output = $text->getOutput();
            $this->assertStringContainsString('North Üptime', $output);
            $this->assertStringContainsString('2026-09', $output);
            $this->assertStringContainsString('95.00%', $output);
            $this->assertStringContainsString('1,140', $output);
            $this->assertStringContainsString('2,590,800', $output);
            for ($i = 1; $i <= 80; $i++) {
                $this->assertStringContainsString(sprintf('Service %03d — Réseau Ж', $i), $output);
            }
            $pages = array_values(array_filter(explode("\f", $output), fn ($page) => trim($page) !== ''));
            $this->assertGreaterThan(1, count($pages));
            foreach ($pages as $page) {
                $this->assertStringContainsString('Monthly uptime report', $page);
                $this->assertStringContainsString('UTC', $page);
                $this->assertStringContainsString('North Üptime', $page);
                $this->assertMatchesRegularExpression('/\d+\s*\/\s*'.count($pages).'\b/', $page);
                if (preg_match('/Service \d{3}/', $page)) {
                    $this->assertStringContainsString('Coverage', $page);
                    $this->assertStringContainsString('Uptime', $page);
                }
            }

            $fonts = new Process(['pdffonts', $path]);
            $fonts->mustRun();
            $this->assertMatchesRegularExpression('/PlusJakartaSans-Regular\s+.*yes\s+yes\s+yes/', $fonts->getOutput());
            $this->assertMatchesRegularExpression('/PlusJakartaSans-Bold\s+.*yes\s+yes\s+yes/', $fonts->getOutput());
        } finally {
            unlink($path);
        }
    }
}
