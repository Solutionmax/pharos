<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Enums\IncidentStatus;
use App\Models\ApiToken;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\IncidentTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiTest extends TestCase
{
    use RefreshDatabase;

    protected string $token;

    protected Component $component;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->token] = ApiToken::issue('tests');

        $group = ComponentGroup::create(['name' => 'Shared hosting']);
        $this->component = Component::create([
            'component_group_id' => $group->id,
            'name' => 'web-06',
            'status' => ComponentStatus::Operational,
        ]);
    }

    public function test_components_are_public_and_use_the_cachet_envelope(): void
    {
        $this->getJson('/api/v1/components')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.name', 'web-06')
            ->assertJsonPath('data.0.status', 1)
            ->assertJsonPath('data.0.status_name', 'Operational');
    }

    public function test_the_public_feed_hides_what_the_page_hides(): void
    {
        $hiddenGroup = ComponentGroup::create(['name' => 'Internal', 'visible' => false]);
        Component::create(['component_group_id' => $hiddenGroup->id, 'name' => 'backup-host', 'status' => ComponentStatus::Operational]);
        $disabled = Component::create(['name' => 'retired-box', 'status' => ComponentStatus::Operational, 'enabled' => false]);

        $this->getJson('/api/v1/components')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonMissing(['name' => 'backup-host'])
            ->assertJsonMissing(['name' => 'retired-box']);

        $this->getJson('/api/v1/components/'.$disabled->id)->assertNotFound();

        // Writes by id still reach a hidden component: a Cachet script may keep
        // updating something the page no longer shows.
        $this->withToken($this->token)
            ->postJson('/api/v1/components/'.$disabled->id, ['status' => 4])
            ->assertOk();
    }

    public function test_writing_requires_a_token(): void
    {
        $this->putJson("/api/v1/components/{$this->component->id}", ['status' => 4])
            ->assertStatus(401);
    }

    public function test_an_invalid_token_is_rejected(): void
    {
        $this->withHeader('Authorization', 'Bearer not-a-real-token')
            ->putJson("/api/v1/components/{$this->component->id}", ['status' => 4])
            ->assertStatus(401);
    }

    public function test_a_bearer_token_can_set_component_status(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/v1/components/{$this->component->id}", ['status' => 4])
            ->assertOk()
            ->assertJsonPath('data.status_name', 'Major outage');

        $this->assertSame(ComponentStatus::MajorOutage, $this->component->fresh()->status);
    }

    public function test_the_cachet_token_header_still_works(): void
    {
        // Existing n8n workflows and scripts must not need changing.
        $this->withHeader('X-Cachet-Token', $this->token)
            ->postJson("/api/v1/components/{$this->component->id}", ['status' => 3])
            ->assertOk()
            ->assertJsonPath('data.status_name', 'Partial outage');
    }

    public function test_an_incident_can_touch_several_components_at_once(): void
    {
        $second = Component::create(['name' => 'web-08', 'status' => ComponentStatus::Operational]);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/v1/incidents', [
                'name' => 'Hypervisor downtime',
                'status' => 'investigating',
                'impact' => 'critical',
                'message' => 'One of our hypervisors stopped responding.',
                'components' => [
                    $this->component->id => 'major_outage',
                    $second->id => 'degraded',
                ],
            ])
            ->assertCreated()
            ->assertJsonPath('data.impact', 'critical')
            ->assertJsonCount(2, 'data.components');

        $this->assertSame(ComponentStatus::MajorOutage, $this->component->fresh()->status);
        $this->assertSame(ComponentStatus::PerformanceIssues, $second->fresh()->status);
    }

    public function test_a_template_fills_in_title_and_message(): void
    {
        IncidentTemplate::create([
            'name' => 'Server unreachable',
            'slug' => 'server-unreachable',
            'title_template' => '{{server}} unreachable',
            'body_template' => 'We identified an outage on {{server}}, starting at {{started_at}}.',
        ]);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/v1/incidents', [
                'template' => 'server-unreachable',
                'vars' => ['server' => 'web-06.example.net', 'started_at' => '16:50'],
                'status' => 'investigating',
            ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'web-06.example.net unreachable')
            ->assertJsonPath('data.updates.0.message', 'We identified an outage on web-06.example.net, starting at 16:50.');
    }

    public function test_an_unknown_status_is_refused_with_a_helpful_message(): void
    {
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson('/api/v1/incidents', [
                'name' => 'Something',
                'status' => 'exploded',
                'message' => 'x',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error', 'Unknown status. Use investigating, identified, watching or resolved, or 1-4.');
    }

    public function test_adding_an_update_moves_the_incident_forward(): void
    {
        $incident = Incident::create([
            'name' => 'Outbound email delayed',
            'status' => IncidentStatus::Investigating,
            'occurred_at' => now(),
        ]);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->postJson("/api/v1/incidents/{$incident->id}/updates", [
                'status' => 'resolved',
                'message' => 'Queue is empty again.',
            ])
            ->assertOk()
            ->assertJsonPath('data.status_name', 'Resolved');

        $this->assertNotNull($incident->fresh()->resolved_at);
    }

    public function test_internal_incidents_stay_out_of_the_public_feed(): void
    {
        Incident::create([
            'name' => 'Internal note',
            'status' => IncidentStatus::Investigating,
            'visibility' => 'internal',
            'occurred_at' => now(),
        ]);

        $this->getJson('/api/v1/incidents')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_using_a_token_records_when_it_was_last_used(): void
    {
        $token = ApiToken::findByPlaintext($this->token);
        $this->assertNull($token->last_used_at);

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/v1/components/{$this->component->id}", ['status' => 1]);

        $this->assertNotNull($token->fresh()->last_used_at);
    }

    public function test_a_cachet_script_may_send_the_numeric_status(): void
    {
        // Cachet 2.x posts status as 1-4. Our enum uses those very values, so
        // refusing them breaks the compatibility the routes promise.
        $this->withToken($this->token)->postJson('/api/v1/incidents', [
            'name' => 'Packet loss',
            'message' => 'Looking into it',
            'status' => 1,
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 1);
    }

    public function test_a_cachet_script_may_send_the_numeric_status_on_an_update(): void
    {
        $incident = Incident::create([
            'name' => 'Packet loss',
            'status' => IncidentStatus::Investigating,
            'occurred_at' => now(),
        ]);

        $this->withToken($this->token)
            ->postJson("/api/v1/incidents/{$incident->id}/updates", [
                'status' => 2,
                'message' => 'Found it',
            ])
            ->assertOk();

        $this->assertSame(IncidentStatus::Identified, $incident->fresh()->status);
    }

    public function test_a_cachet_script_may_attach_a_component_by_the_flat_fields(): void
    {
        // Cachet 2.x has no components map; it takes component_id + component_status.
        $this->withToken($this->token)->postJson('/api/v1/incidents', [
            'name' => 'Disk pressure',
            'message' => 'Cleaning up',
            'status' => 'investigating',
            'component_id' => $this->component->id,
            'component_status' => 3,
        ])->assertCreated();

        $this->assertSame(ComponentStatus::PartialOutage, $this->component->fresh()->status);
    }

    public function test_incidents_use_the_same_envelope_as_components(): void
    {
        Incident::create([
            'name' => 'Packet loss',
            'status' => IncidentStatus::Investigating,
            'occurred_at' => now(),
        ]);

        $this->getJson('/api/v1/incidents')
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1);
    }

    // ---------- limits and ordering ----------

    public function test_writes_are_rate_limited_per_minute(): void
    {
        // 60 a minute is far above any sane integration and far below a brute force.
        for ($i = 0; $i < 60; $i++) {
            $this->withHeader('Authorization', "Bearer {$this->token}")
                ->putJson("/api/v1/components/{$this->component->id}", ['status' => 1])
                ->assertOk();
        }

        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/v1/components/{$this->component->id}", ['status' => 1])
            ->assertStatus(429);
    }

    public function test_heartbeats_have_their_own_looser_limit(): void
    {
        // A job pings once a minute at most; many jobs behind one NAT add up, so
        // the bucket is separate from the writes and twice the size.
        for ($i = 0; $i < 120; $i++) {
            $this->postJson('/api/v1/heartbeat/no-such-token')->assertNotFound();
        }

        $this->postJson('/api/v1/heartbeat/no-such-token')->assertStatus(429);

        // The writes still have their own full budget.
        $this->withHeader('Authorization', "Bearer {$this->token}")
            ->putJson("/api/v1/components/{$this->component->id}", ['status' => 1])
            ->assertOk();
    }

    public function test_a_missing_token_is_refused_before_the_incident_is_looked_up(): void
    {
        // Binding ran before the token check, so a 404 told a stranger which ids exist.
        $this->postJson('/api/v1/incidents/999999/updates', ['status' => 2, 'message' => 'x'])
            ->assertStatus(401);
    }
}
