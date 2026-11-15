<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Http\Controllers\PublicFeatures\ReportController;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Setting;
use App\Models\StatusPage;
use App\Models\UptimeDay;
use App\Models\User;
use App\Services\PageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Security review E5: a public PDF or CSV is built once per month, page,
 * language and visible set, not on every request.
 */
class MonthlyReportCacheTest extends TestCase
{
    use RefreshDatabase;

    private Component $api;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now('UTC')->setDate(2026, 11, 15)->setTime(12, 0));
        User::factory()->create(['role' => UserRole::Admin]);
        $this->api = Component::create(['name' => 'API']);
    }

    private function day(string $date, int $up, int $down): void
    {
        UptimeDay::where('component_id', $this->api->id)->delete();
        UptimeDay::create(['component_id' => $this->api->id, 'day' => $date, 'up_seconds' => $up, 'down_seconds' => $down]);
    }

    public function test_a_finished_month_is_built_once_and_served_from_the_cache(): void
    {
        $this->day('2026-09-01', 100, 0);
        $first = $this->get('/reports.csv?month=2026-09')->assertOk()->assertSee('100.00')->getContent();

        // History changing later (an import, say) shows after the cache expires, not before.
        $this->day('2026-09-01', 50, 50);
        $this->assertSame($first, $this->get('/reports.csv?month=2026-09')->assertOk()->getContent());

        $this->travel(ReportController::FINISHED_MONTH_SECONDS + 60)->seconds();
        $this->get('/reports.csv?month=2026-09')->assertOk()->assertSee('50.00');
    }

    public function test_the_pdf_is_cached_byte_for_byte(): void
    {
        $this->day('2026-09-01', 100, 0);
        $first = $this->get('/reports.pdf?month=2026-09')->assertOk()->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader('Content-Disposition', 'attachment; filename="uptime-2026-09.pdf"')->getContent();
        $this->assertStringStartsWith('%PDF-', $first);

        // Other numbers would draw another file; the same bytes mean it was not drawn again.
        $this->day('2026-09-01', 50, 50);
        $this->assertSame($first, $this->get('/reports.pdf?month=2026-09')->getContent());
        $this->travel(ReportController::FINISHED_MONTH_SECONDS + 60)->seconds();
        $this->assertNotSame($first, $this->get('/reports.pdf?month=2026-09')->getContent());
    }

    public function test_the_current_month_is_cached_for_minutes_only(): void
    {
        $this->day('2026-11-15', 100, 0);
        $this->get('/reports.csv')->assertOk()->assertSee('100.00');

        $this->day('2026-11-15', 50, 50);
        $this->get('/reports.csv')->assertOk()->assertSee('100.00');

        $this->travel(ReportController::CURRENT_MONTH_SECONDS + 60)->seconds();
        $this->get('/reports.csv')->assertOk()->assertSee('50.00');
    }

    public function test_a_change_in_what_is_visible_or_the_language_is_a_new_file(): void
    {
        $this->day('2026-09-01', 100, 0);
        $secret = Component::create(['name' => 'Payroll']);
        $this->get('/reports.csv?month=2026-09')->assertOk()->assertSee('Payroll');

        // Hiding a service takes it out at once, not after a day.
        $secret->update(['component_group_id' => ComponentGroup::create(['name' => 'Internal', 'visible' => false])->id]);
        $this->get('/reports.csv?month=2026-09')->assertOk()->assertDontSee('Payroll');

        Setting::put('page.locale', 'nl');
        $this->get('/reports.csv?month=2026-09')->assertOk()
            ->assertSee(__('Historical daily totals may include maintenance observations. They are preserved and are not reconstructed.', [], 'nl'));
    }

    public function test_pages_do_not_share_a_file(): void
    {
        $this->day('2026-09-01', 100, 0);
        $other = StatusPage::create(['name' => 'Other', 'slug' => 'other', 'is_published' => true]);
        app(PageContext::class)->run($other->id, fn () => Component::create(['name' => 'Elsewhere']));

        $this->get('/reports.csv?month=2026-09')->assertOk()->assertSee('API')->assertDontSee('Elsewhere');
        $this->get('/status/other/reports.csv?month=2026-09')->assertOk()->assertSee('Elsewhere')->assertDontSee('API');
    }

    public function test_the_admin_copy_is_always_fresh_and_never_fills_the_public_cache(): void
    {
        $admin = User::where('role', UserRole::Admin)->first();
        $this->day('2026-09-01', 100, 0);
        $this->actingAs($admin)->get('/admin/reports.csv?month=2026-09')->assertOk()->assertSee('100.00');

        $this->day('2026-09-01', 50, 50);
        $this->actingAs($admin)->get('/admin/reports.csv?month=2026-09')->assertOk()->assertSee('50.00');
    }

    public function test_an_invalid_month_is_refused_before_anything_is_built_or_cached(): void
    {
        $this->get('/reports.pdf?month=2099-01')->assertRedirect();
        $this->get('/reports.csv?month=nonsense')->assertRedirect();
    }
}
