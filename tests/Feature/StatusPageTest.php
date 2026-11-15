<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Enums\IncidentStatus;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Setting;
use App\Models\UptimeDay;
use App\Models\User;
use App\Services\Branding;
use App\Services\Clock;
use App\Services\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class StatusPageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * An installed status page has an owner. Without one the root URL is the
     * setup screen, so every test here starts from a finished install.
     */
    protected function setUp(): void
    {
        parent::setUp();

        User::create([
            'name' => 'Admin',
            'email' => 'admin@example.net',
            'password' => Hash::make('correct-horse-battery-staple'),
        ]);
    }

    protected function makeComponent(array $attributes = []): Component
    {
        $group = ComponentGroup::create(['name' => 'Shared hosting', 'position' => 1]);

        return Component::create(array_merge([
            'component_group_id' => $group->id,
            'name' => 'web-01',
            'status' => ComponentStatus::Operational,
        ], $attributes));
    }

    /**
     * "Ungrouped" is the default on the component form, and deleting a service
     * drops its components there. If the page skips them, both of those are a
     * silent way to publish nothing.
     */
    public function test_a_component_in_no_service_is_still_published(): void
    {
        Component::create([
            'name' => 'Website',
            'description' => 'Availability of your website',
            'status' => ComponentStatus::Operational,
        ]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Website')
            ->assertSee('Availability of your website');
    }

    public function test_a_component_link_becomes_a_link_on_the_page(): void
    {
        Component::create([
            'name' => 'Website',
            'link' => 'https://example.net/shop',
            'status' => ComponentStatus::Operational,
        ]);

        $this->get('/')->assertOk()->assertSee('href="https://example.net/shop"', false);
    }

    public function test_the_headline_follows_the_worst_component_not_the_first(): void
    {
        $this->makeComponent(['name' => 'web-01', 'status' => ComponentStatus::Operational]);
        $this->makeComponent(['name' => 'nas-01', 'status' => ComponentStatus::MajorOutage]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Major outage')
            ->assertDontSee('All systems operational');
    }

    public function test_a_service_is_as_red_as_its_worst_component_not_its_first(): void
    {
        $group = ComponentGroup::create(['name' => 'Servers', 'position' => 1]);
        Component::create(['component_group_id' => $group->id, 'name' => 'web-01', 'status' => ComponentStatus::Operational]);
        Component::create(['component_group_id' => $group->id, 'name' => 'nas-01', 'status' => ComponentStatus::MajorOutage]);

        $this->assertSame(ComponentStatus::MajorOutage, $group->fresh()->status());
    }

    public function test_an_open_incident_stays_on_the_page_after_the_history_window(): void
    {
        Setting::put('page.incident_days', 2);
        $component = $this->makeComponent(['status' => ComponentStatus::MajorOutage]);

        $old = Incident::create([
            'name' => 'Storage array degraded',
            'status' => IncidentStatus::Investigating,
            'occurred_at' => now()->subDays(10),
        ]);
        $old->components()->attach($component->id, ['status' => ComponentStatus::MajorOutage->value]);
        IncidentUpdate::create(['incident_id' => $old->id, 'status' => IncidentStatus::Investigating, 'message' => 'Still degraded.']);

        $closed = Incident::create([
            'name' => 'Old and resolved',
            'status' => IncidentStatus::Resolved,
            'occurred_at' => now()->subDays(10),
            'resolved_at' => now()->subDays(9),
        ]);
        IncidentUpdate::create(['incident_id' => $closed->id, 'status' => IncidentStatus::Resolved, 'message' => 'Fixed.']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Ongoing')
            ->assertSee('Storage array degraded')
            ->assertDontSee('Old and resolved');
    }

    public function test_a_hidden_service_does_not_drive_the_public_headline(): void
    {
        $this->makeComponent(['name' => 'web-01', 'status' => ComponentStatus::Operational]);
        $hidden = ComponentGroup::create(['name' => 'Internal', 'position' => 2, 'visible' => false]);
        Component::create(['component_group_id' => $hidden->id, 'name' => 'vault', 'status' => ComponentStatus::MajorOutage]);

        $this->get('/')
            ->assertOk()
            ->assertSee('All systems operational')
            ->assertDontSee('Major outage');
    }

    public function test_an_ungrouped_outage_still_drives_the_headline(): void
    {
        Component::create(['name' => 'Website', 'status' => ComponentStatus::MajorOutage]);

        $this->get('/')->assertOk()->assertSee(ComponentStatus::MajorOutage->label());
    }

    public function test_a_disabled_ungrouped_component_stays_off_the_page(): void
    {
        Component::create([
            'name' => 'Retired box',
            'status' => ComponentStatus::Operational,
            'enabled' => false,
        ]);

        $this->get('/')->assertOk()->assertDontSee('Retired box');
    }

    public function test_it_renders_groups_and_components(): void
    {
        $this->makeComponent(['description' => 'Availability of web-01']);

        $this->get('/')
            ->assertOk()
            ->assertSee('Shared hosting')
            ->assertSee('web-01')
            ->assertSee('All systems operational');
    }

    public function test_headline_reflects_the_worst_component(): void
    {
        $this->makeComponent(['status' => ComponentStatus::MajorOutage]);

        $this->get('/')
            ->assertOk()
            ->assertSee('Major outage')
            ->assertDontSee('All systems operational');
    }

    public function test_a_day_without_incidents_says_so(): void
    {
        $this->makeComponent();

        $this->get('/')->assertOk()->assertSee('No incidents');
    }

    public function test_it_shows_the_incident_timeline_newest_first(): void
    {
        $component = $this->makeComponent();

        $incident = Incident::create([
            'name' => 'Outbound email delayed',
            'status' => IncidentStatus::Watching,
            'occurred_at' => now(),
        ]);
        $incident->components()->attach($component->id, ['status' => ComponentStatus::PartialOutage->value]);

        IncidentUpdate::create([
            'incident_id' => $incident->id,
            'status' => IncidentStatus::Investigating,
            'message' => 'Queue length above threshold.',
            'automatic' => true,
            'created_at' => now()->subHour(),
        ]);
        IncidentUpdate::create([
            'incident_id' => $incident->id,
            'status' => IncidentStatus::Watching,
            'message' => 'The queue is draining.',
            'created_at' => now(),
        ]);

        $response = $this->get('/')->assertOk()
            ->assertSee('Outbound email delayed')
            ->assertSee('The queue is draining.')
            ->assertSee('automatic');

        $html = $response->getContent();
        $this->assertLessThan(
            strpos($html, 'Queue length above threshold.'),
            strpos($html, 'The queue is draining.'),
            'The newest update must come first in the timeline.',
        );
    }

    public function test_older_days_are_reached_one_page_at_a_time(): void
    {
        Setting::put('page.incident_days', 2);
        $this->makeComponent();

        Incident::create(['name' => 'Fresh outage', 'status' => IncidentStatus::Resolved, 'occurred_at' => now(), 'resolved_at' => now()]);
        Incident::create(['name' => 'Three days ago', 'status' => IncidentStatus::Resolved, 'occurred_at' => now()->subDays(3), 'resolved_at' => now()->subDays(3)]);

        $this->get('/')->assertOk()
            ->assertSee('Fresh outage')
            ->assertDontSee('Three days ago')
            ->assertSee('Older incidents')
            ->assertDontSee('Newer incidents');

        $this->get('/?page=2')->assertOk()
            ->assertSee('Three days ago')
            ->assertDontSee('Fresh outage')
            ->assertSee('Newer incidents')
            ->assertDontSee('Older incidents');
    }

    public function test_the_older_link_only_appears_when_there_is_something_older(): void
    {
        Setting::put('page.incident_days', 2);
        $this->makeComponent();

        Incident::create(['name' => 'Only today', 'status' => IncidentStatus::Resolved, 'occurred_at' => now(), 'resolved_at' => now()]);
        Incident::create(['name' => 'Internal, old', 'status' => IncidentStatus::Resolved, 'occurred_at' => now()->subDays(5), 'resolved_at' => now()->subDays(5), 'visibility' => 'internal']);

        $this->get('/')->assertOk()->assertDontSee('Older incidents');
    }

    public function test_a_page_number_that_is_not_a_page_is_not_found(): void
    {
        $this->makeComponent();

        $this->get('/?page=abc')->assertNotFound();
        $this->get('/?page=0')->assertNotFound();
        $this->get('/?page=99999999999999999999')->assertNotFound();
    }

    /**
     * The pinned list and the day list must meet at midnight, not at "now minus
     * N days": an open incident from the evening just before the first page
     * used to fall between the two and vanish.
     */
    public function test_an_open_incident_just_before_the_first_page_is_pinned(): void
    {
        Setting::put('page.incident_days', 2);
        $this->makeComponent();

        $justBefore = Carbon::today(Clock::timezone())->subDay()->subHour();
        Incident::create(['name' => 'Evening before the window', 'status' => IncidentStatus::Investigating, 'occurred_at' => $justBefore]);

        $this->get('/')->assertOk()->assertSee('Evening before the window')->assertSee('Ongoing');
    }

    public function test_internal_incidents_are_never_shown(): void
    {
        $this->makeComponent();

        $incident = Incident::create([
            'name' => 'Internal note about a customer',
            'status' => IncidentStatus::Investigating,
            'visibility' => 'internal',
            'occurred_at' => now(),
        ]);
        IncidentUpdate::create([
            'incident_id' => $incident->id,
            'status' => IncidentStatus::Investigating,
            'message' => 'Do not publish this.',
        ]);

        $this->get('/')->assertOk()
            ->assertDontSee('Internal note about a customer')
            ->assertDontSee('Do not publish this.');
    }

    public function test_uptime_percentage_is_rendered_from_daily_rollups(): void
    {
        $component = $this->makeComponent();

        for ($i = 0; $i < 90; $i++) {
            UptimeDay::create([
                'component_id' => $component->id,
                'day' => Carbon::today()->subDays($i)->format('Y-m-d'),
                'up_seconds' => $i === 0 ? 43200 : 86400,
                'down_seconds' => $i === 0 ? 43200 : 0,
            ]);
        }

        // 89 perfect days plus one at 50% = 99.44%
        $this->get('/')->assertOk()->assertSee('99.44');
    }

    public function test_the_footer_credit_can_be_hidden(): void
    {
        $this->makeComponent();
        $this->get('/')->assertSee('This page runs on Pharos.');

        // Hiding the credit is part of the Brand pack, checked when the page renders:
        // a real signed key from a throwaway keypair, no mocks.
        $pair = sodium_crypto_sign_keypair();
        config(['pharos.license_public_key' => sodium_bin2hex(sodium_crypto_sign_publickey($pair))]);
        $payload = json_encode(['product' => 'pharos', 'issued_to' => 'k@example.com', 'features' => ['brand_pack'], 'issued_at' => '2026-08-01'], JSON_UNESCAPED_SLASHES);
        $b64 = fn (string $bin) => rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
        $key = $b64($payload).'.'.$b64(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($pair)));
        $this->assertTrue(app(License::class)->store($key));
        Setting::put('brand.credit_hidden', '1');

        $this->get('/')->assertDontSee('This page runs on Pharos.')->assertDontSee('href="https://pharos.solutionmax.net"', false);
    }

    /**
     * The credit is the only thing the free version asks for, so it has to be a
     * link — a plain <span> would be worth nothing and would break silently.
     */
    public function test_the_footer_credit_links_back(): void
    {
        $this->makeComponent();

        $this->get('/')
            ->assertOk()
            ->assertSee('href="https://pharos.solutionmax.net"', escape: false)
            ->assertSee('This page runs on Pharos.');
    }

    public function test_incident_messages_render_markdown_but_never_raw_html(): void
    {
        $incident = Incident::create([
            'name' => 'Mail delayed',
            'status' => IncidentStatus::Investigating,
            'impact' => 'minor',
            'visibility' => 'public',
            'occurred_at' => now(),
        ]);

        IncidentUpdate::create([
            'incident_id' => $incident->id,
            'status' => IncidentStatus::Investigating,
            'message' => "We are **on it**.\nNext update at 14:00.\n\n- queue drained\n\n<script>alert(1)</script>",
            'automatic' => false,
        ]);

        $response = $this->get('/');

        $response->assertSee('<strong>on it</strong>', false);
        $response->assertSee('<li>queue drained</li>', false);
        // A single Enter is a line break, not a space: two typed lines stay two lines.
        $response->assertSee('<br>', false);
        // The tag is shown as text, not run. That is html_input=escape doing its job.
        $response->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_status_dots_pulse_unless_motion_is_reduced(): void
    {
        $this->get('/')->assertOk()->assertSee('assets/pharos-status.css', false);

        $css = file_get_contents(public_path('assets/pharos-status.css'));
        $this->assertStringContainsString('animation:pulse', $css);
        $this->assertStringContainsString('prefers-reduced-motion:reduce', $css);
    }

    /** Ninety slivers are unreadable without a hover that names the day. */
    public function test_every_day_of_the_chart_can_be_read(): void
    {
        Component::create(['name' => 'Website']);

        // One readout for the whole chart: the day under the pointer (or the arrow
        // keys) is spoken there, from notes the page carries for every day.
        $this->get('/')->assertOk()
            ->assertSee('class="readout" aria-live="polite"', false)
            ->assertSee('"label":"'.now()->translatedFormat('D j M').'"', false)
            ->assertDontSee('title="'.now()->format('j M'), false);
    }

    /** Ninety tab stops would be absurd: the bar is the stop, and it speaks the summary. */
    public function test_the_uptime_bar_is_one_tab_stop_with_a_summary(): void
    {
        Component::create(['name' => 'Website']);

        $body = $this->get('/')->assertOk()
            ->assertSee('tabindex="0" role="group"', false)
            ->assertSee('Use the left and right arrow keys to read a day.', false)
            ->assertSee('class="track" aria-hidden="true"', false)
            ->assertSee('No measurements this day.', false)
            ->getContent();

        // The chart is the single tab stop; no day is one of its own.
        $this->assertSame(1, substr_count($body, 'tabindex="0"'));
    }

    /** Review U8: a shared link says what the page is and how it is doing. */
    public function test_the_title_leads_with_the_headline_and_link_previews_have_their_tags(): void
    {
        $this->makeComponent();
        Component::create(['name' => 'Docs']);

        $this->get('/')->assertOk()
            ->assertSee('<title>All systems operational · Pharos Status</title>', false)
            ->assertSee('<meta name="description" content="Live status of Shared hosting and Docs.">', false)
            ->assertSee('<link rel="canonical" href="'.url('/').'">', false)
            ->assertSee('<meta property="og:type" content="website">', false)
            ->assertSee('<meta property="og:site_name" content="Pharos Status">', false)
            ->assertSee('<meta property="og:title" content="All systems operational · Pharos Status">', false)
            ->assertSee('<meta property="og:url" content="'.url('/').'">', false)
            // Pharos sends no picture of its own; only an operator's logo is one.
            ->assertDontSee('og:image', false);

        Component::where('name', 'Docs')->update(['status' => ComponentStatus::PartialOutage]);
        $this->get('/')->assertOk()->assertSee('<title>Partial outage · Pharos Status</title>', false);

        // A page that hides its headline does not put it in the title either.
        Setting::put('page.show_overall', '0');
        $this->get('/')->assertOk()->assertSee('<title>Pharos Status</title>', false);
    }

    public function test_a_long_list_of_services_is_cut_for_the_description(): void
    {
        foreach (range(1, 30) as $n) {
            Component::create(['name' => 'Service number '.$n]);
        }

        preg_match('/<meta name="description" content="([^"]*)">/', $this->get('/')->assertOk()->getContent(), $match);
        $this->assertLessThanOrEqual(156, mb_strlen(html_entity_decode($match[1])));
        $this->assertStringStartsWith('Live status of Service number 1, Service number 2', $match[1]);
    }

    public function test_an_incident_page_carries_its_name_and_state_for_link_previews(): void
    {
        $component = $this->makeComponent();
        $incident = Incident::create(['name' => 'Mail queue backed up', 'status' => IncidentStatus::Resolved, 'occurred_at' => now()->subHour(), 'resolved_at' => now()]);
        $incident->components()->attach($component->id, ['status' => ComponentStatus::PartialOutage->value]);

        $this->get('/incidents/'.$incident->id)->assertOk()
            ->assertSee('<meta property="og:title" content="Mail queue backed up · Resolved">', false)
            ->assertSee('<meta property="og:url" content="'.url('/incidents/'.$incident->id).'">', false)
            ->assertSee('Affected services: web-01', false)
            ->assertSee('<link rel="canonical" href="'.url('/incidents/'.$incident->id).'">', false);
    }

    /** Review U5: a quiet span says so once, and shows the last incident instead of a row per day. */
    public function test_a_quiet_span_says_so_once_and_shows_the_last_incident(): void
    {
        Setting::put('page.incident_days', 5);
        Setting::put('page.show_empty_days', '1');
        $component = $this->makeComponent();
        $incident = Incident::create(['name' => 'Router swap', 'status' => IncidentStatus::Resolved,
            'occurred_at' => now()->subDays(20)->setTime(10, 0), 'resolved_at' => now()->subDays(20)->setTime(10, 4)]);
        $incident->components()->attach($component->id, ['status' => ComponentStatus::MajorOutage->value]);

        $this->get('/')->assertOk()
            ->assertSee('No incidents in the past 20 days.')
            ->assertDontSee('class="entry quiet"', false)
            ->assertDontSee('Nothing in the past')
            ->assertSee('Last incident')
            ->assertSee('Router swap')
            ->assertSee('Resolved after 4 min')
            ->assertSee('Affected web-01')
            ->assertSee('href="'.url('/incidents/'.$incident->id).'"', false)
            // "All incidents" opens the span of the log that holds it.
            ->assertSee('href="'.url('/').'?page=5">All incidents →', false);

        $this->get('/?page=5')->assertOk()->assertSee('Router swap');
    }

    public function test_a_page_that_never_had_an_incident_says_so_without_a_card(): void
    {
        Setting::put('page.incident_days', 7);
        $this->makeComponent();

        $this->get('/')->assertOk()
            ->assertSee('No incidents in the past 7 days.')
            ->assertDontSee('Last incident')
            ->assertDontSee('All incidents →');
    }

    /** Review U9: the credit and the links are one landmark, and the credit is a line, not a banner. */
    public function test_the_footer_holds_the_links_and_one_quiet_credit(): void
    {
        $this->makeComponent();

        $body = $this->get('/')->assertOk()->getContent();

        $this->assertMatchesRegularExpression('#<footer>.*RSS feed.*This page runs on Pharos\. Status pages you host yourself\. <a href="https://pharos\.solutionmax\.net" rel="noopener">pharos\.solutionmax\.net</a>.*</footer>#s', $body);
        $this->assertStringNotContainsString('<div class="sign">', $body);
    }

    public function test_the_chart_hint_speaks_to_a_finger_too(): void
    {
        Component::create(['name' => 'Website']);

        $this->get('/')->assertOk()
            ->assertSee('data-hint-touch="Tap a day to read what happened."', false)
            ->assertSee('<span class="hint-tap">Tap a day to read what happened.</span>', false);
        $this->assertStringContainsString('@media (hover:none)', (string) file_get_contents(public_path('assets/pharos-status.css')));
    }

    /** Review U1: the legend names what the colours mean now. */
    public function test_the_legend_names_blips_slow_disruptions_and_outages(): void
    {
        Component::create(['name' => 'Website']);

        $this->get('/')->assertOk()
            ->assertSeeInOrder(['Brief blip (under 5 min)', 'Slow', 'Disruption', 'Outage', 'Taller means longer'])
            ->assertDontSee('Partly down');
    }

    /** Review U9: link text in the accent is darkened until it reads at 4.5:1; fills keep the accent. */
    public function test_links_get_a_readable_shade_of_the_accent(): void
    {
        $this->makeComponent();
        $links = app(Branding::class)->linkColours('#0079d2');

        $this->get('/')->assertOk()
            ->assertSee('--brand:#0079d2;', false)
            ->assertSee('--link:'.$links['light'].';', false)
            ->assertSee('--link:'.$links['dark'].';', false);
        $this->assertNotSame('#0079d2', $links['light']);
    }
}
