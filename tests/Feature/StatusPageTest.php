<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Enums\IncidentStatus;
use App\Models\UptimeDay;
use App\Models\User;
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
        \App\Models\Setting::put('page.incident_days', 2);
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
        $this->get('/')->assertSee('Powered by Pharos');

        \App\Models\Setting::put('brand.credit_hidden', '1');

        $this->get('/')->assertDontSee('Powered by Pharos');
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
            ->assertSee('Powered by Pharos');
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
        $this->get('/')->assertOk()
            ->assertSee('animation:pulse', false)
            ->assertSee('prefers-reduced-motion:reduce', false);
    }

    /** Ninety slivers are unreadable without a hover that names the day. */
    public function test_every_uptime_sliver_names_its_day(): void
    {
        \App\Models\Component::create(['name' => 'Website']);

        $this->get('/')->assertOk()
            ->assertSee('data-tip="'.now()->format('j M'), false)
            ->assertDontSee('title="'.now()->format('j M'), false)
            ->assertSee(' · ', false)
            // One styled tip for all of them, drawn by the page rather than the browser.
            ->assertSee('class="daytip" role="tooltip"', false);
    }

    /** Ninety tab stops would be absurd: the bar is the stop, and it speaks the summary. */
    public function test_the_uptime_bar_is_one_tab_stop_with_a_summary(): void
    {
        \App\Models\Component::create(['name' => 'Website']);

        $body = $this->get('/')->assertOk()
            ->assertSee('class="bar" role="img" tabindex="0"', false)
            ->assertSee('% uptime, no disruptions', false)
            ->assertSee('class="bar mini" role="img" tabindex="0" aria-label="Website:', false)
            ->getContent();

        $this->assertStringNotContainsString('<span class="" tabindex', $body);
        $this->assertStringNotContainsString('data-tip="'.now()->format('j M').' · all operational" tabindex', $body);
    }
}
