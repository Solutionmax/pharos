<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Enums\IncidentStatus;
use App\Enums\UserRole;
use App\Models\Component;
use App\Models\Incident;
use App\Models\StatusPage;
use App\Models\Subscriber;
use App\Models\UptimeDay;
use App\Models\User;
use App\Services\PageContext;
use App\Services\PageHealth;
use App\Services\PageOverview;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class OverviewTest extends TestCase
{
    use RefreshDatabase;

    protected StatusPage $other;

    protected function setUp(): void
    {
        parent::setUp();
        $this->other = StatusPage::create(['name' => 'Harbor Logistics', 'slug' => 'harbor', 'is_published' => true]);
    }

    protected function onPage(StatusPage $page, callable $callback): mixed
    {
        return app(PageContext::class)->run($page->id, $callback);
    }

    public function test_overview_is_the_landing_screen_after_sign_in(): void
    {
        User::factory()->create(['email' => 'a@example.com', 'password' => 'correct-horse-battery', 'role' => UserRole::Admin]);

        $this->post('/admin/login', ['email' => 'a@example.com', 'password' => 'correct-horse-battery'])
            ->assertRedirect('/admin/overview');
        $this->get('/admin')->assertRedirect(route('admin.overview'));
    }

    public function test_a_page_without_uptime_still_draws_a_valid_sparkline(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $html = $this->actingAs($admin)->get('/admin/pages/'.$this->other->id.'/overview')->assertOk()->getContent();

        // Every SVG path must open with a moveto, or the browser rejects it.
        preg_match_all('/<path d="([^"]*)"/', $html, $paths);
        foreach ($paths[1] as $d) {
            $this->assertMatchesRegularExpression('/^\s*[Mm]/', $d);
        }
    }

    public function test_a_user_of_another_page_lands_on_that_pages_overview(): void
    {
        $user = User::factory()->create(['role' => UserRole::User]);
        $user->statusPages()->attach($this->other->id, ['role' => 'viewer']);

        $this->actingAs($user)->get('/admin')->assertRedirect(route('page.admin.overview', ['statusPage' => $this->other->id]));
        $this->get('/admin/overview')->assertNotFound();
        $this->get('/admin/pages/'.$this->other->id.'/overview')->assertOk()->assertSee('Overview');
    }

    public function test_overview_shows_only_the_selected_pages_data(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Component::create(['name' => 'default-web', 'status' => ComponentStatus::MajorOutage]);
        Incident::create(['name' => 'Default page outage', 'status' => IncidentStatus::Investigating, 'occurred_at' => now()]);
        $this->onPage($this->other, function () {
            Component::create(['name' => 'harbor-api', 'status' => ComponentStatus::Operational]);
        });

        $this->actingAs($admin)->get('/admin/pages/'.$this->other->id.'/overview')
            ->assertOk()
            ->assertSee('harbor-api')
            ->assertSee('All systems operational')
            ->assertDontSee('default-web')
            ->assertDontSee('Default page outage');

        $this->get('/admin/overview')->assertOk()
            ->assertSee('default-web')->assertSee('Major outage')
            ->assertSee('Default page outage')
            ->assertDontSee('harbor-api');
    }

    public function test_overview_numbers(): void
    {
        Carbon::setTestNow('2026-09-23 12:00:00');
        $web = Component::create(['name' => 'web', 'status' => ComponentStatus::PartialOutage]);
        Component::create(['name' => 'db', 'status' => ComponentStatus::Operational]);
        Component::create(['name' => 'hidden', 'status' => ComponentStatus::MajorOutage, 'enabled' => false]);
        UptimeDay::create(['component_id' => $web->id, 'day' => '2026-09-22', 'up_seconds' => 43200, 'down_seconds' => 43200, 'worst_status' => 3]);
        Incident::create(['name' => 'Old', 'status' => IncidentStatus::Resolved, 'occurred_at' => now()->subDays(3), 'resolved_at' => now()->subDays(3)->addMinutes(30)]);
        Incident::create(['name' => 'Older', 'status' => IncidentStatus::Resolved, 'occurred_at' => now()->subDays(5), 'resolved_at' => now()->subDays(5)->addMinutes(90)]);
        Incident::create(['name' => 'Ancient', 'status' => IncidentStatus::Resolved, 'occurred_at' => now()->subDays(40), 'resolved_at' => now()->subDays(39)]);
        Incident::create(['name' => 'Now', 'status' => IncidentStatus::Identified, 'occurred_at' => now()->subHour()]);
        Subscriber::create(['email' => 'a@example.net', 'token' => Subscriber::freshToken(), 'verified_at' => now()]);
        Subscriber::create(['email' => 'b@example.net', 'token' => Subscriber::freshToken()]);
        $this->onPage($this->other, fn () => Incident::create(['name' => 'Elsewhere', 'status' => IncidentStatus::Investigating, 'occurred_at' => now()]));

        $data = app(PageOverview::class)->build();

        $this->assertSame(ComponentStatus::PartialOutage, $data['worst']);
        $this->assertCount(2, $data['components']);
        $this->assertSame(1, $data['operational']);
        $this->assertSame(['Now'], $data['open']->pluck('name')->all());
        $this->assertSame('Old', $data['lastResolved']->name);
        $this->assertSame(3, $data['incidents30']);
        $this->assertSame(60, $data['mttr']);
        $this->assertSame('1h', PageOverview::duration($data['mttr']));
        $this->assertSame(1, $data['subscribers']);
        $this->assertSame(50.0, $data['uptime']);
        $this->assertCount(90, $data['days']);
        $this->assertSame('b', $data['days'][88]['tone']);
        $this->assertCount(30, $data['services'][0]['rows'][0]['days']);
    }

    public function test_duration_is_short(): void
    {
        $this->assertSame('No data', PageOverview::duration(null));
        $this->assertSame('34m', PageOverview::duration(34));
        $this->assertSame('5h 10m', PageOverview::duration(310));
        $this->assertSame('2d 3h', PageOverview::duration(2 * 1440 + 180));
    }

    public function test_health_links_follow_the_users_rights(): void
    {
        $viewer = User::factory()->create(['role' => UserRole::User]);
        $viewer->statusPages()->attach($this->other->id, ['role' => 'viewer']);
        $pageAdmin = User::factory()->create(['role' => UserRole::User]);
        $pageAdmin->statusPages()->attach($this->other->id, ['role' => 'admin']);

        $viewerChecks = collect($this->onPage($this->other, fn () => app(PageHealth::class)->checks($viewer, $this->other)))->keyBy('key');
        $adminChecks = collect($this->onPage($this->other, fn () => app(PageHealth::class)->checks($pageAdmin, $this->other)))->keyBy('key');

        $this->assertNull($viewerChecks['delivery']['url']);
        $this->assertNull($viewerChecks['brand']['url']);
        $this->assertNull($viewerChecks['domain']['url']);
        $this->assertStringContainsString('/admin/pages/'.$this->other->id.'/mail', (string) $adminChecks['delivery']['url']);
        // Publishing and domains stay with installation administrators.
        $this->assertNull($adminChecks['domain']['url']);
        $this->assertSame('good', $adminChecks['published']['state']);
    }

    public function test_checks_coverage_counts_components_without_automation(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Component::create(['name' => 'a', 'source' => 'check']);
        Component::create(['name' => 'b', 'source' => 'manual']);
        Component::create(['name' => 'c', 'source' => 'manual']);

        $checks = collect(app(PageHealth::class)->checks($admin))->keyBy('key');

        $this->assertSame('warn', $checks['checks']['state']);
        $this->assertSame('1 of 3 components; 2 rely on someone noticing', $checks['checks']['detail']);
    }

    public function test_a_new_page_shows_the_ready_steps_and_a_ready_page_does_not(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $draft = StatusPage::create(['name' => 'Draft page', 'slug' => 'draft']);

        $this->actingAs($admin)->get('/admin/pages/'.$draft->id.'/overview')
            ->assertOk()->assertSee('Get this page ready')->assertSee('Publish the page')
            ->assertSee('No components yet');

        config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => 'smtp.example.net', 'mail.from.address' => 'status@example.net']);
        $this->onPage($this->other, fn () => Component::create(['name' => 'harbor-api']));

        $this->get('/admin/pages/'.$this->other->id.'/overview')->assertOk()->assertDontSee('Get this page ready');
        $this->assertSame([], $this->onPage($this->other, fn () => app(PageHealth::class)->readiness($admin, $this->other)));
    }

    public function test_status_pages_manage_opens_the_overview(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->get('/admin/pages')->assertOk()
            ->assertSee('href="'.route('page.admin.overview', ['statusPage' => $this->other->id]).'"', false);
    }
}
