<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Setting;
use App\Models\User;
use App\Services\Branding;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);

        $group = ComponentGroup::create(['name' => 'Shared hosting']);
        Component::create(['component_group_id' => $group->id, 'name' => 'web-01']);

        $incident = Incident::create([
            'name' => 'Mail queue backed up',
            'status' => IncidentStatus::Investigating,
            'occurred_at' => now(),
        ]);
        IncidentUpdate::create([
            'incident_id' => $incident->id,
            'status' => IncidentStatus::Investigating,
            'message' => 'Looking into it.',
        ]);
    }

    public function test_everything_is_shown_on_a_fresh_install(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('All systems operational')
            ->assertSee('Services')
            ->assertSee('web-01')
            ->assertSee('Mail queue backed up');
    }

    public function test_each_section_can_be_switched_off_on_its_own(): void
    {
        $cases = [
            'page.show_overall' => 'All systems operational',
            'page.show_services' => 'web-01',
            'page.show_incidents' => 'Mail queue backed up',
        ];

        foreach ($cases as $key => $text) {
            Setting::put($key, '1');
            $this->get('/')->assertSee($text);

            Setting::put($key, '0');
            $this->get('/')->assertDontSee($text);

            Setting::put($key, '1');
        }
    }

    /**
     * "Get notified" linked to #subscribe, an anchor that was never on the page,
     * backed by a feature that does not exist. It sat in the corner of every
     * customer's status page, switched on by default, doing nothing.
     */
    public function test_the_public_page_offers_nothing_it_cannot_do(): void
    {
        $this->get('/')->assertOk()
            ->assertDontSee('Get notified')
            ->assertDontSee('#subscribe', false);
    }

    /** Every in-page link has to land on something that is actually rendered. */
    public function test_no_anchor_on_the_public_page_points_at_nothing(): void
    {
        $html = $this->get('/')->assertOk()->getContent();

        preg_match_all('/href="#([^"]+)"/', $html, $links);

        foreach (array_unique($links[1]) as $anchor) {
            $this->assertMatchesRegularExpression(
                '/(id|name)="'.preg_quote($anchor, '/').'"/',
                $html,
                "The page links to #{$anchor}, which it never renders.",
            );
        }
    }

    public function test_the_uptime_bar_can_go_while_the_headline_stays(): void
    {
        Setting::put('page.show_uptime', '0');

        $this->get('/')->assertOk()
            ->assertSee('All systems operational')
            ->assertDontSee('last 90 days');
    }

    public function test_empty_days_can_be_collapsed(): void
    {
        Setting::put('page.show_empty_days', '0');

        $this->get('/')->assertOk()
            ->assertSee('Mail queue backed up')
            ->assertDontSee('No incidents');
    }

    public function test_saving_settings_switches_modules_off(): void
    {
        // An unchecked box is simply absent from the request, so the controller
        // must iterate over the known modules rather than over what was posted.
        $this->actingAs($this->user)->put('/admin/settings', [
            'theme' => 'dark',
            'incident_days' => 7,
            'modules' => ['page.show_services' => '1'],
        ])->assertRedirect();

        $branding = app(Branding::class);
        $this->assertTrue($branding->module('page.show_services'));
        $this->assertFalse($branding->module('page.show_overall'));
        $this->assertFalse($branding->module('page.show_incidents'));
        $this->assertSame('dark', $branding->theme());
    }

    public function test_the_default_theme_is_stamped_on_the_page(): void
    {
        // Only the <html> tag matters here: the stylesheet legitimately contains
        // [data-theme="dark"] selectors in every case.
        $htmlTag = fn (string $body) => substr($body, 0, strpos($body, '>', strpos($body, '<html')) + 1);

        Setting::put('brand.theme', 'dark');
        $this->assertStringContainsString('data-theme="dark"', $htmlTag($this->get('/')->getContent()));

        Setting::put('brand.theme', 'light');
        $this->assertStringContainsString('data-theme="light"', $htmlTag($this->get('/')->getContent()));

        // "system" must stamp nothing, so prefers-color-scheme decides.
        Setting::put('brand.theme', 'system');
        $this->assertStringNotContainsString('data-theme', $htmlTag($this->get('/')->getContent()));
    }

    public function test_an_unknown_theme_falls_back_to_system(): void
    {
        Setting::put('brand.theme', 'neon');

        $this->assertSame('system', app(Branding::class)->theme());
    }

    public function test_the_incident_window_is_configurable(): void
    {
        Setting::put('page.incident_days', '2');
        $this->get('/')->assertOk()->assertDontSee(now()->subDays(4)->format('j F'));

        Setting::put('page.incident_days', '10');
        $this->get('/')->assertOk()->assertSee(now()->subDays(4)->format('j F'));
    }

    public function test_a_signed_in_visitor_gets_a_way_back_to_the_admin(): void
    {
        $this->get('/')->assertDontSee('Back to admin');

        $this->actingAs($this->user)->get('/')
            ->assertOk()
            ->assertSee('Back to admin')
            ->assertSee(route('admin.components'));
    }

    public function test_the_built_in_mark_is_used_until_a_logo_is_uploaded(): void
    {
        $this->get('/')->assertOk()->assertSee('pharos-favicon.svg', false);
    }

    public function test_a_logo_setting_pointing_at_a_missing_file_is_ignored(): void
    {
        // Otherwise a deleted upload renders as a broken image on every visit.
        Setting::put('brand.logo_path', 'brand/gone.png');

        $this->assertNull(app(Branding::class)->logoUrl());
        $this->get('/')->assertOk()->assertDontSee('brand/gone.png');
    }
}
