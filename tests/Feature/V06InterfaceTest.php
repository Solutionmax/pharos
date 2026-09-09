<?php

namespace Tests\Feature;

use App\Enums\IncidentStatus;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\UptimeDay;
use App\Models\User;
use App\Services\Uptime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class V06InterfaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_measurements_are_unknown_instead_of_perfect(): void
    {
        $component = Component::create(['name' => 'Unmeasured API']);
        $this->assertNull(app(Uptime::class)->percentage($component));
        $this->assertSame('No data', Uptime::format(null));
        $this->assertSame(90.0, Uptime::average([null, 80.0, 100.0]));
        $this->assertNull(Uptime::average([]));
    }

    public function test_service_history_excludes_disabled_components(): void
    {
        $user = User::factory()->create();
        $group = ComponentGroup::create(['name' => 'Email']);
        Component::create(['name' => 'SMTP', 'component_group_id' => $group->id]);
        $disabled = Component::create(['name' => 'Disabled', 'component_group_id' => $group->id, 'enabled' => false]);
        UptimeDay::create(['component_id' => $disabled->id, 'day' => today(), 'up_seconds' => 86400, 'down_seconds' => 0]);
        $this->actingAs($user)->get(route('admin.groups'))
            ->assertOk()->assertSee('No data')->assertSee('history-bar')->assertDontSee('100.00%');
    }

    public function test_status_choices_preserve_api_values_and_editor_has_a_fallback(): void
    {
        $user = User::factory()->create();
        $incident = Incident::create(['name' => 'Delivery delayed', 'status' => IncidentStatus::Watching, 'occurred_at' => now()]);
        $this->actingAs($user)->get(route('admin.incidents.update-form', $incident))
            ->assertOk()->assertSee('type="radio"', false)->assertSee('Monitoring')
            ->assertSee('value="3" checked', false)->assertSee('data-pharos-editor="message"', false)
            ->assertSee('<textarea id="message"', false);
        $this->assertSame(IncidentStatus::Watching, IncidentStatus::fromName('Monitoring'));
        $this->assertSame(IncidentStatus::Watching, IncidentStatus::fromName('Watching'));
    }

    public function test_public_refresh_contains_only_public_incidents(): void
    {
        User::factory()->create();
        foreach (['public', 'internal', 'authenticated'] as $visibility) {
            Incident::create(['name' => $visibility.' incident', 'visibility' => $visibility, 'occurred_at' => now()]);
        }
        $this->get('/')->assertOk()->assertSee('id="pharos-live"', false)
            ->assertSee('public incident')->assertDontSee('internal incident')->assertDontSee('authenticated incident');
    }

    public function test_empty_rollup_is_not_a_successful_measurement(): void
    {
        $component = Component::create(['name' => 'No samples']);
        UptimeDay::create(['component_id' => $component->id, 'day' => today(), 'up_seconds' => 0, 'down_seconds' => 0]);
        $this->assertNull(app(Uptime::class)->percentage($component));
        $bar = app(Uptime::class)->bar($component);
        $this->assertSame('unknown', end($bar)['tone']);
    }

    public function test_reopening_an_incident_clears_its_resolution_timestamp(): void
    {
        $user = User::factory()->create();
        $incident = Incident::create(['name' => 'Delivery', 'status' => IncidentStatus::Resolved, 'occurred_at' => now()->subHour(), 'resolved_at' => now()]);
        $this->actingAs($user)->post(route('admin.incidents.update', $incident), ['status' => 1, 'message' => 'Delivery is delayed again.'])
            ->assertRedirect(route('admin.incidents'));
        $this->assertNull($incident->fresh()->resolved_at);
    }
}
