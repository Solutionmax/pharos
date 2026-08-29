<?php

namespace Tests\Feature;

use App\Enums\CheckType;
use App\Enums\ComponentStatus;
use App\Enums\IncidentStatus;
use App\Models\Check;
use App\Models\CheckResult;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminTest extends TestCase
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
    }

    public function test_the_window_note_only_appears_when_there_is_a_table(): void
    {
        $this->actingAs($this->user)->get('/admin/components')
            ->assertOk()
            ->assertDontSee('Bars cover 30 days')
            ->assertSee('Nothing on the status page yet');

        Component::create(['name' => 'web-01', 'status' => ComponentStatus::Operational]);

        $this->actingAs($this->user)->get('/admin/components')
            ->assertOk()
            ->assertSee('Bars cover 30 days')
            ->assertSee('uptime is measured over 90', false);
    }

    /** The 30-day strip names its day the same way the public bar does, through the shared tip. */
    public function test_the_thirty_day_strip_names_its_day(): void
    {
        Component::create(['name' => 'web-01', 'status' => ComponentStatus::Operational]);

        $this->actingAs($this->user)->get('/admin/components')
            ->assertOk()
            ->assertSee('data-tip="'.now()->format('j M'), false)
            ->assertDontSee('title="'.now()->format('j M'), false)
            ->assertSee('class="strip" role="img" tabindex="0" aria-label="web-01, last 30 days:', false)
            ->assertSee('class="daytip" role="tooltip"', false);
    }

    public function test_the_admin_is_closed_to_strangers(): void
    {
        foreach (['/admin/components', '/admin/incidents', '/admin/branding'] as $url) {
            $this->get($url)->assertRedirect('/admin/login');
        }
    }

    public function test_signing_in_works(): void
    {
        $this->post('/admin/login', [
            'email' => 'admin@example.com',
            'password' => 'correct-horse-battery',
        ])->assertRedirect('/admin/components');

        $this->assertAuthenticatedAs($this->user);
    }

    public function test_a_wrong_password_is_refused_without_saying_which_half_was_wrong(): void
    {
        $this->post('/admin/login', ['email' => 'admin@example.com', 'password' => 'nope'])
            ->assertSessionHasErrors(['email' => 'Those details do not match an account.']);

        $this->assertGuest();
    }

    public function test_repeated_failures_are_throttled(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/admin/login', ['email' => 'admin@example.com', 'password' => 'nope']);
        }

        $response = $this->post('/admin/login', [
            'email' => 'admin@example.com',
            'password' => 'correct-horse-battery',
        ]);

        // Even the correct password is refused once the limiter trips.
        $this->assertGuest();
        $response->assertSessionHasErrors('email');
    }

    public function test_a_component_can_be_created_with_a_check(): void
    {
        $group = ComponentGroup::create(['name' => 'Shared hosting']);

        $this->actingAs($this->user)->post('/admin/components', [
            'name' => 'web-01',
            'description' => 'Availability of web-01',
            'component_group_id' => $group->id,
            'status' => ComponentStatus::Operational->value,
            'source' => 'check',
            'check_type' => 'http',
            'check_target' => 'https://web-01.example.net/',
            'check_interval' => 60,
            'enabled' => 1,
            'show_uptime' => 1,
            'tags' => 'shared, cpanel',
        ])->assertRedirect('/admin/components');

        $component = Component::where('name', 'web-01')->first();
        $this->assertNotNull($component);
        $this->assertSame(['shared', 'cpanel'], $component->tagList());
        $this->assertSame('https://web-01.example.net/', $component->check->target);
    }

    public function test_switching_a_component_to_manual_removes_its_check(): void
    {
        $component = Component::create(['name' => 'web-01', 'source' => 'check']);
        Check::create(['component_id' => $component->id, 'type' => 'http', 'target' => 'https://x.example/']);

        $this->actingAs($this->user)->put("/admin/components/{$component->id}", [
            'name' => 'web-01',
            'status' => 1,
            'source' => 'manual',
        ])->assertRedirect();

        $this->assertNull($component->fresh()->check);
    }

    public function test_a_heartbeat_component_gets_an_unguessable_token(): void
    {
        $this->actingAs($this->user)->post('/admin/components', [
            'name' => 'Backups',
            'status' => 1,
            'source' => 'heartbeat',
        ])->assertRedirect();

        $check = Component::where('name', 'Backups')->first()->check;
        $this->assertNotNull($check);
        $this->assertStringStartsWith('hb_', $check->target);
        $this->assertGreaterThanOrEqual(24, strlen($check->target));
    }

    public function test_the_form_offers_tags_that_are_already_in_use(): void
    {
        Component::create(['name' => 'web-01', 'tags' => 'shared, cpanel']);
        Component::create(['name' => 'mail-01', 'tags' => 'mail, shared']);

        $this->actingAs($this->user)->get('/admin/components/create')
            ->assertOk()
            ->assertSee('data-drop-tag="cpanel"', false)
            ->assertSee('data-drop-tag="mail"', false)
            // Listed once, however many components carry it.
            ->assertSeeInOrder(['data-drop-tag="cpanel"', 'data-drop-tag="mail"', 'data-drop-tag="shared"'], false);
    }

    /**
     * host:port as an HTTP target is the mistake that costs an afternoon: the
     * probe cannot fetch it, so a service that is up is published as down.
     */
    public function test_an_http_check_refuses_a_host_port_target(): void
    {
        $this->actingAs($this->user)->post('/admin/components', [
            'name' => 'Synology',
            'status' => 1,
            'source' => 'check',
            'check_type' => 'http',
            'check_target' => '192.168.18.8:5000',
        ])->assertSessionHasErrors('check_target');

        $this->assertSame(0, Component::count());
    }

    public function test_a_tcp_check_refuses_a_url_target(): void
    {
        $this->actingAs($this->user)->post('/admin/components', [
            'name' => 'IMAP',
            'status' => 1,
            'source' => 'check',
            'check_type' => 'tcp',
            'check_target' => 'https://mail.example.net',
        ])->assertSessionHasErrors('check_target');

        $this->assertSame(0, Component::count());
    }

    public function test_a_built_in_check_without_a_target_is_refused_rather_than_dropped(): void
    {
        $this->actingAs($this->user)->post('/admin/components', [
            'name' => 'Website',
            'status' => 1,
            'source' => 'check',
            'check_type' => 'http',
            'check_target' => '',
        ])->assertSessionHasErrors('check_target');

        $this->assertSame(0, Component::count());
    }

    public function test_the_matching_shapes_are_accepted(): void
    {
        $this->actingAs($this->user)->post('/admin/components', [
            'name' => 'Website', 'status' => 1, 'source' => 'check',
            'check_type' => 'http', 'check_target' => 'https://example.net/',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->user)->post('/admin/components', [
            'name' => 'IMAP', 'status' => 1, 'source' => 'check',
            'check_type' => 'tcp', 'check_target' => 'mail.example.net:993',
        ])->assertSessionHasNoErrors();

        $this->assertSame(2, Component::count());
    }

    public function test_a_tag_can_be_removed_from_every_component(): void
    {
        $a = Component::create(['name' => 'web-01', 'tags' => 'shared, cpanel']);
        $b = Component::create(['name' => 'web-02', 'tags' => 'Shared']);
        $c = Component::create(['name' => 'mail-01', 'tags' => 'mail']);

        $this->actingAs($this->user)
            ->deleteJson('/admin/components/tags/shared')
            ->assertOk()
            ->assertJson(['components' => 2]);

        $this->assertSame(['cpanel'], $a->fresh()->tagList());
        // Matched case-insensitively, or "Shared" and "shared" live on forever.
        $this->assertNull($b->fresh()->tags);
        $this->assertSame(['mail'], $c->fresh()->tagList());
    }

    public function test_removing_an_unused_tag_touches_nothing(): void
    {
        $a = Component::create(['name' => 'web-01', 'tags' => 'shared']);

        $this->actingAs($this->user)
            ->deleteJson('/admin/components/tags/nosuchtag')
            ->assertOk()
            ->assertJson(['components' => 0]);

        $this->assertSame(['shared'], $a->fresh()->tagList());
    }

    public function test_removing_a_tag_is_closed_to_strangers(): void
    {
        $a = Component::create(['name' => 'web-01', 'tags' => 'shared']);

        $this->delete('/admin/components/tags/shared')->assertRedirect('/admin/login');

        $this->assertSame(['shared'], $a->fresh()->tagList());
    }

    public function test_an_incident_can_be_deleted(): void
    {
        $incident = Incident::create([
            'name' => 'Synology unreachable',
            'status' => IncidentStatus::Investigating,
            'occurred_at' => now(),
        ]);
        IncidentUpdate::create([
            'incident_id' => $incident->id,
            'status' => IncidentStatus::Investigating,
            'message' => 'Automatic check failed.',
        ]);

        $this->actingAs($this->user)->delete("/admin/incidents/{$incident->id}")
            ->assertRedirect('/admin/incidents');

        $this->assertSame(0, Incident::count());
        $this->assertSame(0, IncidentUpdate::count());
        $this->get('/')->assertDontSee('Synology unreachable');
    }

    /**
     * A check-opened incident resolves itself when the check recovers. Delete
     * the component and there is nothing left to recover, so without this it
     * stays "Investigating" on the public page for good.
     */
    public function test_deleting_a_component_closes_the_incident_its_check_opened(): void
    {
        $component = Component::create(['name' => 'Synology', 'source' => 'check']);
        $incident = Incident::create([
            'name' => 'Synology unreachable',
            'status' => IncidentStatus::Investigating,
            'source' => 'check',
            'auto_resolve' => true,
            'grouping_key' => 'check:'.$component->id,
            'occurred_at' => now(),
        ]);

        $this->actingAs($this->user)->delete("/admin/components/{$component->id}")->assertRedirect();

        $incident->refresh();
        $this->assertSame(IncidentStatus::Resolved, $incident->status);
        $this->assertNotNull($incident->resolved_at);
        $this->assertStringContainsString('component was removed', $incident->updates()->latest('id')->first()->message);
    }

    /**
     * A forgotten cron line looks exactly like a healthy install, which is the
     * failure Pharos exists to stop. These three cover the whole signal.
     */
    public function test_a_never_run_scheduler_is_called_out(): void
    {
        $component = Component::create(['name' => 'web-01', 'source' => 'check']);
        Check::create(['component_id' => $component->id, 'type' => 'http', 'target' => 'https://example.net/']);

        $this->actingAs($this->user)->get('/admin/components')
            ->assertOk()
            ->assertSee('Nothing is being checked')
            ->assertSee('php artisan schedule:run');
    }

    public function test_a_stalled_scheduler_is_called_out(): void
    {
        $component = Component::create(['name' => 'web-01', 'source' => 'check']);
        Check::create(['component_id' => $component->id, 'type' => 'http', 'target' => 'https://example.net/']);
        Setting::put('checks.last_run_at', now()->subHour()->toIso8601String());

        $this->actingAs($this->user)->get('/admin/components')
            ->assertOk()
            ->assertSee('Nothing is being checked');
    }

    public function test_a_running_scheduler_says_nothing_and_neither_does_an_install_without_checks(): void
    {
        // Nothing to check yet: the warning would be noise on a fresh install.
        $this->actingAs($this->user)->get('/admin/components')
            ->assertOk()->assertDontSee('Nothing is being checked');

        $component = Component::create(['name' => 'web-01', 'source' => 'check']);
        Check::create(['component_id' => $component->id, 'type' => 'http', 'target' => 'https://example.net/']);
        Setting::put('checks.last_run_at', now()->toIso8601String());

        $this->actingAs($this->user)->get('/admin/components')
            ->assertOk()->assertDontSee('Nothing is being checked');
    }

    public function test_running_the_checks_stamps_that_the_scheduler_was_here(): void
    {
        $this->assertNull(Setting::get('checks.last_run_at'));

        $this->artisan('pharos:check')->assertSuccessful();

        $this->assertNotNull(Setting::get('checks.last_run_at'));
    }

    public function test_an_invalid_link_is_refused(): void
    {
        $this->actingAs($this->user)->post('/admin/components', [
            'name' => 'x',
            'status' => 1,
            'source' => 'manual',
            'link' => 'not-a-url',
        ])->assertSessionHasErrors('link');

        $this->assertSame(0, Component::count());
    }

    /** The link is rendered as an href on the public page, so the scheme matters. */
    public function test_a_script_url_is_refused_as_a_link(): void
    {
        foreach (['javascript://comment%0aalert(1)', 'data:text/html,<script>alert(1)</script>'] as $hostile) {
            $this->actingAs($this->user)->post('/admin/components', [
                'name' => 'x',
                'status' => 1,
                'source' => 'manual',
                'link' => $hostile,
            ])->assertSessionHasErrors('link');
        }

        $this->assertSame(0, Component::count());
    }

    public function test_publishing_an_incident_sets_every_component_it_names(): void
    {
        $a = Component::create(['name' => 'web-06']);
        $b = Component::create(['name' => 'web-08']);

        $this->actingAs($this->user)->post('/admin/incidents', [
            'name' => 'Hypervisor downtime',
            'message' => 'One of our hypervisors stopped responding.',
            'status' => IncidentStatus::Investigating->value,
            'impact' => 'critical',
            'visibility' => 'public',
            'components' => [$a->id => ComponentStatus::MajorOutage->value, $b->id => ComponentStatus::PerformanceIssues->value],
        ])->assertRedirect('/admin/incidents');

        $this->assertSame(ComponentStatus::MajorOutage, $a->fresh()->status);
        $this->assertSame(ComponentStatus::PerformanceIssues, $b->fresh()->status);
        $this->assertSame(1, Incident::count());
    }

    public function test_resolving_an_incident_puts_its_components_back(): void
    {
        $component = Component::create(['name' => 'web-06', 'status' => ComponentStatus::MajorOutage]);

        $incident = Incident::create([
            'name' => 'web-06 unreachable',
            'status' => IncidentStatus::Investigating,
            'occurred_at' => now(),
        ]);
        $incident->components()->attach($component->id, ['status' => ComponentStatus::MajorOutage->value]);
        IncidentUpdate::create([
            'incident_id' => $incident->id,
            'status' => IncidentStatus::Investigating,
            'message' => 'Looking into it.',
        ]);

        $this->actingAs($this->user)->post("/admin/incidents/{$incident->id}/update", [
            'status' => IncidentStatus::Resolved->value,
            'message' => 'Back up.',
        ])->assertRedirect();

        // A status page that leaves components red after the incident closes is lying.
        $this->assertSame(ComponentStatus::Operational, $component->fresh()->status);
        $this->assertNotNull($incident->fresh()->resolved_at);
    }

    public function test_incidents_can_be_searched_and_filtered(): void
    {
        Incident::create(['name' => 'web-06 unreachable', 'status' => IncidentStatus::Resolved, 'occurred_at' => now(), 'resolved_at' => now()]);
        Incident::create(['name' => 'Mail queue backed up', 'status' => IncidentStatus::Investigating, 'occurred_at' => now()]);

        $this->actingAs($this->user)->get('/admin/incidents?q=web-06')
            ->assertOk()->assertSee('web-06 unreachable')->assertDontSee('Mail queue backed up');

        $this->actingAs($this->user)->get('/admin/incidents?state=open')
            ->assertOk()->assertSee('Mail queue backed up')->assertDontSee('web-06 unreachable');
    }

    public function test_repeat_outages_on_one_target_are_counted(): void
    {
        foreach ([1, 8, 15] as $daysAgo) {
            Incident::create([
                'name' => 'web-08 unreachable',
                'status' => IncidentStatus::Resolved,
                'grouping_key' => 'check:9',
                'occurred_at' => now()->subDays($daysAgo),
                'resolved_at' => now()->subDays($daysAgo),
            ]);
        }

        $this->actingAs($this->user)->get('/admin/incidents')
            ->assertOk()
            ->assertSee('3× in 30 days');
    }

    public function test_branding_basics_are_free(): void
    {
        $this->actingAs($this->user)->put('/admin/branding', [
            'name' => 'Northwind',
            'accent' => '#B8532F',
        ])->assertRedirect();

        $this->assertSame('Northwind', Setting::get('brand.name'));
        $this->assertSame('#b8532f', Setting::get('brand.accent'));
    }

    public function test_a_malformed_accent_colour_is_refused(): void
    {
        $this->actingAs($this->user)->put('/admin/branding', ['name' => 'x', 'accent' => 'red'])
            ->assertSessionHasErrors('accent');
    }

    // ---------- recent checks ----------

    protected function checkedComponent(string $source = 'check'): Component
    {
        $component = Component::create(['name' => 'web-01', 'source' => $source]);

        if ($source !== 'manual') {
            Check::create(['component_id' => $component->id, 'type' => CheckType::Http, 'target' => 'https://example.net/']);
        }

        return $component;
    }

    /** @return list<string> the data-tip of every sliver that has one, in page order */
    protected function beatTips(string $html): array
    {
        preg_match_all('/<span class="beat[^"]*" data-tip="([^"]*)"/', $html, $m);

        return $m[1];
    }

    public function test_the_component_screen_shows_the_last_forty_runs_newest_last(): void
    {
        $component = $this->checkedComponent();
        $base = now()->startOfMinute()->subMinutes(60);
        $error = 'Connection refused by the far end after a very long and detailed explanation';

        // 45 runs a minute apart: the five oldest (777 ms) must fall off the strip.
        for ($i = 0; $i < 45; $i++) {
            $failed = in_array($i, [10, 20, 30], true);
            CheckResult::create([
                'component_id' => $component->id,
                'ok' => ! $failed,
                'latency_ms' => $i < 5 ? 777 : ($i === 40 ? 5000 : ($failed ? null : 90)),
                'message' => $failed ? $error : null,
                'checked_at' => $base->copy()->addMinutes($i),
            ]);
        }

        $html = $this->actingAs($this->user)->get("/admin/components/{$component->id}/edit")
            ->assertOk()
            ->assertSee('Recent checks')
            ->assertDontSee('777 ms')
            ->assertDontSee('No runs yet')
            ->getContent();

        $tips = $this->beatTips($html);
        $this->assertCount(40, $tips);
        $this->assertSame($base->copy()->addMinutes(5)->format('H:i:s').' · 90 ms', $tips[0]);
        $this->assertSame($base->copy()->addMinutes(44)->format('H:i:s').' · 90 ms', $tips[39]);

        // A failed run is red and names its error, cut short; the slow one is amber.
        $failedTip = $base->copy()->addMinutes(10)->format('H:i:s').' · failed · '.Str::limit($error, 60);
        $this->assertStringContainsString('<span class="beat b" data-tip="'.$failedTip.'"', $html);
        $this->assertStringContainsString('<span class="beat w" data-tip="'.$base->copy()->addMinutes(40)->format('H:i:s').' · 5,000 ms"', $html);
        $this->assertStringNotContainsString('class="beat unknown"', $html);

        $this->assertStringContainsString('Last run 16 minutes ago · 3 failed · median 90 ms', $html);
    }

    public function test_a_short_history_is_padded_with_placeholders(): void
    {
        $component = $this->checkedComponent();
        foreach ([3, 2, 1] as $ago) {
            CheckResult::create(['component_id' => $component->id, 'ok' => true, 'latency_ms' => 88, 'checked_at' => now()->subMinutes($ago)]);
        }

        $html = $this->actingAs($this->user)->get("/admin/components/{$component->id}/edit")->assertOk()->getContent();

        $this->assertCount(3, $this->beatTips($html));
        $this->assertSame(37, substr_count($html, 'class="beat unknown"'));
        $this->assertStringContainsString('Last run 1 minute ago · 3/3 ok · median 88 ms', $html);
    }

    public function test_a_check_that_has_never_run_says_so(): void
    {
        $component = $this->checkedComponent();

        $this->actingAs($this->user)->get("/admin/components/{$component->id}/edit")->assertOk()
            ->assertSee('Recent checks')
            ->assertSee('No runs yet — the first one lands within a minute once the cron line is in place.');
    }

    public function test_a_manual_component_has_no_recent_checks_panel(): void
    {
        $component = $this->checkedComponent('manual');
        CheckResult::create(['component_id' => $component->id, 'ok' => true, 'latency_ms' => 88, 'checked_at' => now()]);

        $this->actingAs($this->user)->get("/admin/components/{$component->id}/edit")->assertOk()
            ->assertDontSee('Recent checks')
            ->assertDontSee('class="beat', false);
    }
}
