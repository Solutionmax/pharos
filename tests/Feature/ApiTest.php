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
            ->assertJsonPath('error', 'Unknown status. Use investigating, identified, watching or resolved.');
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
}
