<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Enums\IncidentStatus;
use App\Enums\UserRole;
use App\Models\ApiToken;
use App\Models\Check;
use App\Models\Component;
use App\Models\Incident;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportedIncidentTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected ApiToken $token;

    protected string $plain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        [$this->token, $this->plain] = ApiToken::issue('zabbix-intern', $this->admin, StatusPage::defaultId(), 'write');
    }

    protected function checked(): Component
    {
        $component = Component::create(['name' => 'Checked', 'source' => 'check']);
        Check::create(['component_id' => $component->id, 'type' => 'http', 'target' => 'https://example.test/', 'enabled' => true]);

        return $component;
    }

    protected function assertReported(Component $component): void
    {
        $fresh = $component->fresh();
        $this->assertSame('webhook', $fresh->source);
        $this->assertSame($this->token->id, $fresh->reported_by_token_id);
        $this->assertNotNull($fresh->reported_at);
    }

    protected function assertUntouched(Component $component, string $source = 'manual'): void
    {
        $fresh = $component->fresh();
        $this->assertSame($source, $fresh->source);
        $this->assertNull($fresh->reported_at);
        $this->assertNull($fresh->reported_by_token_id);
    }

    protected function api(string $method, string $url, array $body)
    {
        return $this->withToken($this->plain)->json($method, $url, $body);
    }

    protected function incident(): Incident
    {
        return Incident::create(['name' => 'Outage', 'status' => IncidentStatus::Investigating, 'occurred_at' => now()]);
    }

    public function test_an_incident_with_a_components_map_marks_the_component(): void
    {
        $component = Component::create(['name' => 'Mail']);
        $checked = $this->checked();

        $this->api('POST', '/api/v1/incidents', [
            'name' => 'Down', 'status' => 'investigating', 'message' => 'x',
            'components' => [$component->id => 4, $checked->id => 4],
        ])->assertCreated();

        $this->assertReported($component);
        $this->assertSame(ComponentStatus::MajorOutage, $component->fresh()->status);
        $this->assertUntouched($checked, 'check');
    }

    public function test_the_cachet_component_pair_marks_the_component(): void
    {
        $component = Component::create(['name' => 'Mail']);

        $this->api('POST', '/api/v1/incidents', [
            'name' => 'Down', 'status' => 'investigating', 'message' => 'x',
            'component_id' => $component->id, 'component_status' => 3,
        ])->assertCreated();

        $this->assertReported($component);
    }

    public function test_an_update_with_components_marks_the_component(): void
    {
        $component = Component::create(['name' => 'Mail']);
        $checked = $this->checked();
        $incident = $this->incident();

        $this->api('POST', "/api/v1/incidents/{$incident->id}/updates", [
            'status' => 'identified', 'message' => 'x', 'components' => [$component->id => 3, $checked->id => 3],
        ])->assertOk();

        $this->assertReported($component);
        $this->assertUntouched($checked, 'check');
    }

    public function test_a_resolve_that_resets_components_marks_them(): void
    {
        $component = Component::create(['name' => 'Mail', 'status' => ComponentStatus::MajorOutage]);
        $checked = $this->checked();
        $incident = $this->incident();
        $incident->components()->attach([$component->id => ['status' => 4], $checked->id => ['status' => 4]]);

        $this->api('POST', "/api/v1/incidents/{$incident->id}/updates", ['status' => 'resolved', 'message' => 'Fixed.'])->assertOk();

        $this->assertSame(ComponentStatus::Operational, $component->fresh()->status);
        $this->assertReported($component);
        $this->assertUntouched($checked, 'check');
    }

    public function test_an_existing_outside_source_is_kept_but_the_report_is_recorded(): void
    {
        $component = Component::create(['name' => 'Mail', 'source' => 'kuma']);

        $this->api('POST', '/api/v1/incidents', [
            'name' => 'Down', 'status' => 'investigating', 'message' => 'x', 'components' => [$component->id => 4],
        ])->assertCreated();

        $fresh = $component->fresh();
        $this->assertSame('kuma', $fresh->source);
        $this->assertSame($this->token->id, $fresh->reported_by_token_id);
    }

    public function test_the_same_actions_in_the_admin_leave_a_manual_component_manual(): void
    {
        $component = Component::create(['name' => 'Mail']);
        $incident = $this->incident();

        $this->actingAs($this->admin)->post('/admin/incidents', [
            'name' => 'Down', 'message' => 'x', 'status' => 1, 'impact' => 'major', 'visibility' => 'public',
            'components' => [$component->id => 4],
        ])->assertRedirect();
        $this->assertSame(ComponentStatus::MajorOutage, $component->fresh()->status);

        $incident->components()->attach($component->id, ['status' => 4]);
        $this->actingAs($this->admin)->post("/admin/incidents/{$incident->id}/update", ['status' => 4, 'message' => 'Fixed.'])->assertRedirect();
        $this->actingAs($this->admin)->post("/admin/incidents/{$incident->id}/resolve", ['message' => 'Fixed.'])->assertRedirect();

        $this->assertUntouched($component);
    }
}
