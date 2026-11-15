<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Enums\IncidentStatus;
use App\Enums\UserRole;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\Maintenance;
use App\Models\Setting;
use App\Models\StatusPage;
use App\Models\UptimeDay;
use App\Models\User;
use App\Services\PageContext;
use App\Services\SignInStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\Support\GrantsBrandPack;
use Tests\TestCase;

/**
 * The two sign in layouts: "simple" (one column, one status line) and "status"
 * (a "Right now" panel beside the form). Whatever either shows is a subset of
 * what the public default page shows to anyone, and nothing while it is not public.
 */
class SignInLayoutTest extends TestCase
{
    use GrantsBrandPack, RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(Carbon::parse('2026-11-15 12:00:00'));
        $this->admin = User::create([
            'name' => 'Raymon', 'email' => 'raymon@example.com',
            'password' => Hash::make('correct-horse-battery'), 'role' => UserRole::Admin,
        ]);
    }

    /** Visible text only: what a person reads, without scripts, styles and attributes. */
    protected function visibleText(TestResponse $response): string
    {
        return strip_tags(preg_replace('~<(script|style)\b[^>]*>.*?</\1>~si', '', $response->getContent()));
    }

    /** 90 days of measurements, with the given days ago at the given percentage. */
    protected function measure(Component $component, array $losses = []): void
    {
        for ($ago = 0; $ago < 90; $ago++) {
            $down = isset($losses[$ago]) ? (int) round(86400 * (100 - $losses[$ago]) / 100) : 0;
            UptimeDay::create([
                'component_id' => $component->id,
                'day' => Carbon::today()->subDays($ago),
                'up_seconds' => 86400 - $down,
                'down_seconds' => $down,
            ]);
        }
    }

    protected function useStatusLayout(): void
    {
        Setting::put(SignInStatus::SETTING, SignInStatus::STATUS);
    }

    protected function save(array $fields): TestResponse
    {
        return $this->put('/admin/status-page', ['theme' => 'system', 'incident_days' => 5, ...$fields]);
    }

    // ---------- the setting ----------

    public function test_the_layout_is_saved_and_bad_values_are_refused(): void
    {
        $this->assertSame(SignInStatus::SIMPLE, SignInStatus::layout());

        $this->actingAs($this->admin)->get('/admin/status-page')->assertOk()
            ->assertSee('name="login_layout"', false)
            ->assertSee('<option value="simple" selected>', false);

        $this->actingAs($this->admin)->save(['login_layout' => 'status'])
            ->assertRedirect('/admin/status-page')->assertSessionHasNoErrors();
        $this->assertSame('status', Setting::get(SignInStatus::SETTING));
        $this->assertSame(SignInStatus::STATUS, SignInStatus::layout());

        foreach (['fancy', '', 'STATUS'] as $bad) {
            $this->actingAs($this->admin)->from('/admin/status-page')->save(['login_layout' => $bad])
                ->assertRedirect('/admin/status-page')->assertSessionHasErrors('login_layout');
        }
        $this->assertSame('status', Setting::get(SignInStatus::SETTING));

        // A form without the field (an older tab) leaves the choice alone.
        $this->actingAs($this->admin)->save([])->assertSessionHasNoErrors();
        $this->assertSame('status', Setting::get(SignInStatus::SETTING));
    }

    public function test_the_layout_belongs_to_the_installation_not_to_a_page_user(): void
    {
        $member = User::create([
            'name' => 'Tom', 'email' => 'tom@example.com',
            'password' => Hash::make('correct-horse-battery'), 'role' => UserRole::User,
        ]);
        $member->statusPages()->attach(StatusPage::default());

        $this->actingAs($member)->get('/admin/status-page')->assertOk()
            ->assertDontSee('name="login_layout"', false);
        $this->actingAs($member)->save(['login_layout' => 'status'])->assertRedirect('/admin/status-page');

        $this->assertNull(Setting::get(SignInStatus::SETTING));
        $this->assertSame(SignInStatus::SIMPLE, SignInStatus::layout());
    }

    public function test_an_unknown_stored_value_reads_as_the_simple_layout(): void
    {
        Setting::put(SignInStatus::SETTING, 'fancy');

        $this->assertSame(SignInStatus::SIMPLE, SignInStatus::layout());
        $this->get('/admin/login')->assertOk()->assertDontSee('pa-split', false);
    }

    // ---------- A: simple ----------

    public function test_the_simple_layout_shows_one_status_line_to_the_status_page(): void
    {
        $web = Component::create(['name' => 'Web', 'status' => ComponentStatus::Operational]);
        $this->measure($web);

        $page = $this->get('/admin/login')->assertOk()
            ->assertSee('<h1 class="pa-h1">Sign in</h1>', false)
            ->assertSee('class="pa-line is-ok" href="'.StatusPage::default()->publicUrl().'"', false)
            ->assertSee('All systems operational')
            ->assertDontSee('pa-split', false)
            ->assertDontSee('Right now');

        // Only the headline: no strip, no incident, no planned work.
        $this->assertStringNotContainsString('pa-strip', $page->getContent());
        // The line carries the link, so the footer does not repeat it.
        $this->assertSame(1, substr_count($this->visibleText($page), 'Status page'));
    }

    public function test_the_simple_layout_counts_services_with_trouble(): void
    {
        Component::create(['name' => 'Mail', 'status' => ComponentStatus::MajorOutage]);
        Component::create(['name' => 'Backups', 'status' => ComponentStatus::PartialOutage]);
        Component::create(['name' => 'Web', 'status' => ComponentStatus::Operational]);

        $this->get('/admin/login')->assertOk()
            ->assertSee('class="pa-line is-b"', false)
            ->assertSee('2 services are down');
    }

    // ---------- B: status ----------

    public function test_the_status_layout_shows_the_panel_with_real_data(): void
    {
        $this->useStatusLayout();
        $web = Component::create(['name' => 'Web', 'status' => ComponentStatus::Operational, 'position' => 1]);
        $mail = Component::create(['name' => 'Mail', 'status' => ComponentStatus::MajorOutage, 'position' => 2]);
        $this->measure($web, [3 => 50.0]);
        $this->measure($mail);
        $incident = Incident::create(['name' => 'Mail delivery delayed', 'status' => IncidentStatus::Investigating, 'occurred_at' => '2026-11-15 10:00:00']);
        $incident->components()->attach($mail->id, ['status' => ComponentStatus::MajorOutage->value]);
        Maintenance::create(['title' => 'Kernel update', 'starts_at' => '2026-11-18 22:00:00', 'ends_at' => '2026-11-18 23:00:00']);

        $page = $this->get('/admin/login')->assertOk()
            ->assertSee('class="pa pa-split"', false)
            ->assertSee('Right now')
            ->assertSee('<div class="pa-state is-b">', false)
            ->assertSee('1 service is down')
            ->assertSee('Mail. 1 service is fine.')
            // Web lost half of one day; Mail none: (89 × 100 + 50) / 90, then the mean of both.
            ->assertSee('<b>99.72%</b> uptime', false)
            ->assertSee('Latest incident')
            ->assertSee('Mail delivery delayed')
            ->assertSee('Today 10:00 · Investigating')
            ->assertSee('Next maintenance')
            ->assertSee('Kernel update')
            ->assertSee('18 Nov, 22:00')
            // On a phone the panel gives way to the same line as the simple layout.
            ->assertSee('class="pa-line is-b"', false);

        $html = $page->getContent();
        preg_match('~<div class="pa-strip"[^>]*>(.*?)</div>~s', $html, $strip);
        $this->assertSame(90, substr_count($strip[1], '<i'));
        // Three days ago, oldest first: the worst day of the overall row, as tall as the public chart draws it.
        $this->assertSame(1, substr_count($strip[1], 'class="b"'));
        $this->assertStringContainsString('<i class="b" style="--h:100%"></i>', $strip[1]);
        // On wide screens the footer takes the link back from the hidden line.
        $this->assertStringContainsString('class="pa-foot-status"', $html);
    }

    public function test_the_status_layout_shows_the_latest_solved_incident_and_planned_work_under_way(): void
    {
        $this->useStatusLayout();
        Component::create(['name' => 'Web', 'status' => ComponentStatus::Operational]);
        Incident::create(['name' => 'Umami unreachable', 'status' => IncidentStatus::Resolved, 'occurred_at' => '2026-11-13 09:00:00', 'resolved_at' => '2026-11-13 09:42:00']);
        Maintenance::create(['title' => 'Proxmox update', 'starts_at' => '2026-11-15 11:00:00', 'ends_at' => '2026-11-15 13:30:00']);

        $this->get('/admin/login')->assertOk()
            ->assertSee('Umami unreachable')
            ->assertSee('13 Nov · Solved in 42 min')
            ->assertSee('Proxmox update')
            ->assertSee('Under way, until 13:30');
    }

    public function test_the_status_layout_leaks_nothing_the_public_page_hides(): void
    {
        $this->useStatusLayout();
        $web = Component::create(['name' => 'Web', 'status' => ComponentStatus::Operational]);
        $this->measure($web);

        $vault = ComponentGroup::create(['name' => 'Vault service', 'visible' => false]);
        $secret = Component::create(['name' => 'Secret vault', 'status' => ComponentStatus::MajorOutage, 'component_group_id' => $vault->id]);
        $this->measure($secret, [1 => 0.0, 2 => 10.0]);
        $off = Component::create(['name' => 'Switched off box', 'status' => ComponentStatus::MajorOutage, 'enabled' => false]);
        $this->measure($off, [4 => 0.0]);

        Incident::create(['name' => 'Private breach', 'status' => IncidentStatus::Investigating, 'visibility' => 'internal', 'occurred_at' => now()]);
        Incident::create(['name' => 'Staff only notice', 'status' => IncidentStatus::Resolved, 'visibility' => 'authenticated', 'occurred_at' => now()->subHour(), 'resolved_at' => now()]);
        Maintenance::create(['title' => 'Cancelled work', 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'cancelled_at' => now()]);

        $other = StatusPage::create(['name' => 'Other', 'slug' => 'other', 'is_published' => true]);
        app(PageContext::class)->run($other->id, function () {
            Component::create(['name' => 'Other page box', 'status' => ComponentStatus::MajorOutage]);
            Incident::create(['name' => 'Other page outage', 'status' => IncidentStatus::Investigating, 'occurred_at' => now()]);
            Maintenance::create(['title' => 'Other page work', 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2)]);
        });

        $page = $this->get('/admin/login')->assertOk()
            ->assertSee('Right now')
            ->assertSee('<div class="pa-state is-ok">', false)
            ->assertSee('All systems operational')
            ->assertSee('1 service, checked at 12:00.')
            ->assertSee('<b>100.00%</b> uptime', false)
            ->assertDontSee('Latest incident')
            ->assertDontSee('Next maintenance');

        $text = $this->visibleText($page);
        foreach (['Vault service', 'Secret vault', 'Switched off box', 'Private breach', 'Staff only notice', 'Cancelled work', 'Other page box', 'Other page outage', 'Other page work', 'down'] as $leak) {
            $this->assertStringNotContainsStringIgnoringCase($leak, $text, "the sign in page must not show \"$leak\"");
        }
        // The hidden and the switched off components lost whole days; none of that reaches the strip.
        preg_match('~<div class="pa-strip"[^>]*>(.*?)</div>~s', $page->getContent(), $strip);
        $this->assertSame(90, substr_count($strip[1], '<i></i>'));
    }

    public function test_the_status_layout_hides_what_the_page_modules_hide(): void
    {
        $this->useStatusLayout();
        $mail = Component::create(['name' => 'Mail', 'status' => ComponentStatus::MajorOutage]);
        $this->measure($mail, [2 => 50.0]);
        Incident::create(['name' => 'Mail delivery delayed', 'status' => IncidentStatus::Investigating, 'occurred_at' => now()]);
        Setting::put('page.show_uptime', '0');
        Setting::put('page.show_incidents', '0');
        Setting::put('page.show_services', '0');

        $page = $this->get('/admin/login')->assertOk()
            ->assertSee('Right now')
            // Without the services list the public headline names no service, so neither does this.
            ->assertSee('Major outage')
            ->assertDontSee('pa-strip', false)
            ->assertDontSee('Latest incident')
            ->assertDontSee('Mail delivery delayed');
        $text = $this->visibleText($page);
        $this->assertStringNotContainsString('Mail', $text);
        $this->assertStringNotContainsString('uptime', $text);

        // Without the headline on the public page there is no status at all.
        Setting::put('page.show_overall', '0');
        $this->get('/admin/login')->assertOk()
            ->assertDontSee('Right now')
            ->assertDontSee('pa-line', false)
            ->assertDontSee('pa-split', false);
    }

    public function test_the_status_layout_falls_back_to_the_simple_one_while_the_page_is_not_public(): void
    {
        $this->useStatusLayout();
        $box = Component::create(['name' => 'Postbox', 'status' => ComponentStatus::MajorOutage]);
        $this->measure($box, [1 => 0.0]);
        Incident::create(['name' => 'Delivery delayed', 'status' => IncidentStatus::Investigating, 'occurred_at' => now()]);

        foreach ([['is_published' => false], ['is_published' => true, 'archived_at' => now()]] as $state) {
            StatusPage::default()->update($state);

            $page = $this->get('/admin/login')->assertOk()
                ->assertSee('<h1 class="pa-h1">Sign in</h1>', false)
                ->assertSee('name="password"', false)
                ->assertDontSee('pa-split', false)
                ->assertDontSee('pa-now', false)
                ->assertDontSee('pa-line', false)
                ->assertDontSee('Right now');

            $text = $this->visibleText($page);
            foreach (['Postbox', 'Delivery delayed', 'down', 'operational', 'uptime'] as $leak) {
                $this->assertStringNotContainsStringIgnoringCase($leak, $text);
            }
            // The footer keeps its plain link, as before.
            $this->assertStringContainsString('Status page', $text);
        }
    }

    public function test_the_other_signed_out_screens_stay_one_plain_column(): void
    {
        $this->useStatusLayout();
        Component::create(['name' => 'Mail', 'status' => ComponentStatus::MajorOutage]);

        $this->get('/admin/forgot-password')->assertOk()
            ->assertSee('Forgot your password?')
            ->assertDontSee('pa-split', false)
            ->assertDontSee('pa-line', false)
            ->assertDontSee('Right now');
    }

    public function test_the_status_layout_stays_white_label_under_a_brand_pack(): void
    {
        $this->useStatusLayout();
        $this->grantBrandPack();
        Setting::put('brand.name', 'Acme Hosting');
        Setting::put('brand.credit_hidden', '1');
        $web = Component::create(['name' => 'Web', 'status' => ComponentStatus::Operational]);
        $this->measure($web);

        $page = $this->get('/admin/login')->assertOk()
            ->assertSee('Right now')
            ->assertSee('<b>Acme Hosting</b>', false)
            ->assertDontSee('brand/pharos-logo.svg', false)
            ->assertDontSee('brand/pharos-mark.svg', false);
        $this->assertStringNotContainsStringIgnoringCase('pharos', $this->visibleText($page));

        // Free, the same page still names Pharos.
        Setting::put('license.key', null);
        Cache::forget('license.payload');
        $this->get('/admin/login')->assertOk()->assertSee('Powered by Pharos')->assertSee('brand/pharos-logo.svg', false);
    }
}
