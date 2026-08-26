<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Enums\IncidentStatus;
use App\Models\Check;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
            'name' => 's1121',
            'description' => 'Availability of s1121',
            'component_group_id' => $group->id,
            'status' => ComponentStatus::Operational->value,
            'source' => 'check',
            'check_type' => 'http',
            'check_target' => 'https://s1121.example.net/',
            'check_interval' => 60,
            'enabled' => 1,
            'show_uptime' => 1,
            'tags' => 'shared, cpanel',
        ])->assertRedirect('/admin/components');

        $component = Component::where('name', 's1121')->first();
        $this->assertNotNull($component);
        $this->assertSame(['shared', 'cpanel'], $component->tagList());
        $this->assertSame('https://s1121.example.net/', $component->check->target);
    }

    public function test_switching_a_component_to_manual_removes_its_check(): void
    {
        $component = Component::create(['name' => 's1121', 'source' => 'check']);
        Check::create(['component_id' => $component->id, 'type' => 'http', 'target' => 'https://x.example/']);

        $this->actingAs($this->user)->put("/admin/components/{$component->id}", [
            'name' => 's1121',
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
}
