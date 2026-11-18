<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Component;
use App\Models\Setting;
use App\Models\StatusPage;
use App\Models\User;
use App\Services\Branding;
use App\Services\PageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportsModuleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    /** Without an owner the root URL is the setup screen. */
    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    }

    public function test_monthly_reports_are_public_by_default(): void
    {
        Component::create(['name' => 'Web']);

        $this->assertTrue(app(Branding::class)->module('page.show_reports'));
        $this->get('/')->assertOk()->assertSee('/reports"', false);
        $this->get('/reports?month=2026-09')->assertOk();
    }

    public function test_switched_off_the_link_is_gone_and_every_public_format_is_not_found(): void
    {
        Component::create(['name' => 'Web']);
        Setting::put('page.show_reports', '0');

        $this->get('/')->assertOk()->assertDontSee('/reports"', false);
        foreach (['/reports', '/reports.csv', '/reports.pdf'] as $path) {
            $this->get($path.'?month=2026-09')->assertNotFound();
        }
    }

    public function test_switched_off_the_admin_still_reads_and_downloads_the_reports(): void
    {
        Component::create(['name' => 'Web']);
        Setting::put('page.show_reports', '0');

        $this->actingAs($this->admin)->get('/admin/reports?month=2026-09')->assertOk();
        $this->actingAs($this->admin)->get('/admin/reports.csv?month=2026-09')->assertOk();
    }

    public function test_the_switch_belongs_to_one_status_page(): void
    {
        Component::create(['name' => 'Web']);
        $other = StatusPage::create(['name' => 'Other', 'slug' => 'other', 'is_published' => true]);
        app(PageContext::class)->run($other->id, fn () => Setting::put('page.show_reports', '0'));

        $this->get('/status/other/reports?month=2026-09')->assertNotFound();
        $this->get('/status/other/reports.csv?month=2026-09')->assertNotFound();
        $this->get('/reports?month=2026-09')->assertOk();
    }

    public function test_the_switch_is_saved_from_the_status_page_settings(): void
    {
        $this->actingAs($this->admin)->get('/admin/status-page')->assertOk()
            ->assertSee('name="modules[page.show_reports]"', false)->assertSee('Off also closes the report addresses.');

        $modules = array_fill_keys(array_keys(Branding::MODULES), '1');
        unset($modules['page.show_reports']);

        $this->actingAs($this->admin)->put('/admin/status-page', [
            'theme' => 'system', 'incident_days' => 5, 'modules' => $modules,
        ])->assertSessionHasNoErrors();

        $this->assertFalse(app(Branding::class)->module('page.show_reports'));
    }
}
