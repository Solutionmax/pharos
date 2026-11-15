<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Enums\UserRole;
use App\Models\ApiToken;
use App\Models\Check;
use App\Models\Component;
use App\Models\StatusPage;
use App\Models\User;
use App\Services\PageContext;
use App\Services\RequestBodyLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;
use Tests\TestCase;

class AlertReceiverTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private ApiToken $token;

    private string $plain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => UserRole::Admin]);
        [$this->token, $this->plain] = ApiToken::issue('Prometheus', $this->owner, StatusPage::defaultId(), 'write');
    }

    public function test_alertmanager_v4_payload_maps_severity_and_reports_what_it_ignored(): void
    {
        $api = Component::create(['name' => 'API']);
        $website = Component::create(['name' => 'Website']);
        $billing = Component::create(['name' => 'Billing']);
        Check::create(['component_id' => $billing->id, 'type' => 'http', 'target' => 'https://billing.example.net/', 'enabled' => true]);

        $response = $this->withToken($this->plain)->postJson('/api/v1/alertmanager', $this->fixture('alertmanager-v4'))->assertOk();

        $response->assertJson(['ok' => true])
            ->assertJsonPath('applied.0', ['component' => $api->id, 'name' => 'API', 'status' => 4, 'changed' => true])
            ->assertJsonPath('applied.1', ['component' => $website->id, 'name' => 'Website', 'status' => 3, 'changed' => true])
            ->assertJsonPath('ignored.0', ['alert' => 'HighErrorRate', 'component' => 'Billing', 'reason' => 'checked_component'])
            ->assertJsonPath('ignored.1', ['alert' => 'HighErrorRate', 'component' => null, 'reason' => 'no_component_label']);
        $this->assertSame(ComponentStatus::MajorOutage, $api->fresh()->status);
        $this->assertSame(ComponentStatus::PartialOutage, $website->fresh()->status);
        $this->assertSame(ComponentStatus::Operational, $billing->fresh()->status);

        // Marked like the Kuma receiver marks it: reported from outside, by this token.
        $this->assertSame('webhook', $api->fresh()->source);
        $this->assertSame($this->token->id, $api->fresh()->reported_by_token_id);
        $this->assertNotNull($api->fresh()->reported_at);
        $this->assertSame('manual', $billing->fresh()->source);
        $this->assertNull($billing->fresh()->reported_at);
    }

    public function test_resolved_alerts_set_operational_and_the_worst_alert_per_component_wins(): void
    {
        $api = Component::create(['name' => 'API', 'status' => ComponentStatus::MajorOutage]);
        $payload = $this->fixture('alertmanager-v4');
        $payload['status'] = 'resolved';
        $payload['alerts'] = [array_replace($payload['alerts'][0], ['status' => 'resolved', 'endsAt' => '2026-11-15T16:45:00Z'])];

        $this->withToken($this->plain)->postJson('/api/v1/alertmanager', $payload)->assertOk()
            ->assertJsonPath('applied.0.status', 1);
        $this->assertSame(ComponentStatus::Operational, $api->fresh()->status);

        // By id this time; info alone is slow, a warning next to it makes it partly down.
        $info = array_replace_recursive($payload['alerts'][0], ['status' => 'firing', 'labels' => ['severity' => 'info', 'pharos_component' => (string) $api->id]]);
        $warning = array_replace_recursive($info, ['labels' => ['severity' => 'Warning']]);
        $this->withToken($this->plain)->postJson('/api/v1/alertmanager', ['alerts' => [$info]])->assertOk();
        $this->assertSame(ComponentStatus::PerformanceIssues, $api->fresh()->status);
        $this->withToken($this->plain)->postJson('/api/v1/alertmanager', ['alerts' => [$info, $warning]])->assertOk()
            ->assertJsonCount(1, 'applied')->assertJsonPath('applied.0.status', 3);
        $this->assertSame(ComponentStatus::PartialOutage, $api->fresh()->status);

        // The same state again is accepted and changes nothing.
        $this->withToken($this->plain)->postJson('/api/v1/alertmanager', ['alerts' => [$warning]])->assertOk()
            ->assertJsonPath('applied.0.changed', false);
    }

    public function test_grafana_payload_is_understood_and_unknown_components_are_reported(): void
    {
        $website = Component::create(['name' => 'Website']);

        $this->withToken($this->plain)->postJson('/api/v1/grafana', $this->fixture('grafana'))->assertOk()
            ->assertJsonPath('applied.0', ['component' => $website->id, 'name' => 'Website', 'status' => 2, 'changed' => true])
            ->assertJsonPath('ignored', []);
        $this->assertSame(ComponentStatus::PerformanceIssues, $website->fresh()->status);

        $payload = $this->fixture('grafana');
        $payload['alerts'][0]['labels']['pharos_component'] = 'website'; // exact name only
        $payload['alerts'][1] = array_replace_recursive($payload['alerts'][0], ['status' => 'resolved', 'labels' => ['pharos_component' => '999999']]);
        $this->withToken($this->plain)->postJson('/api/v1/grafana', $payload)->assertOk()
            ->assertJsonPath('applied', [])
            ->assertJsonPath('ignored.0', ['alert' => 'High memory usage', 'component' => 'website', 'reason' => 'unknown_component'])
            ->assertJsonPath('ignored.1.reason', 'unknown_component');

        $payload['alerts'] = [array_replace($payload['alerts'][0], ['status' => 'resolved', 'labels' => ['pharos_component' => 'Website']])];
        $this->withToken($this->plain)->postJson('/api/v1/grafana', $payload)->assertOk();
        $this->assertSame(ComponentStatus::Operational, $website->fresh()->status);
    }

    public function test_tokens_and_input_are_checked_like_the_kuma_receiver(): void
    {
        $api = Component::create(['name' => 'API']);
        [, $read] = ApiToken::issue('Reader', $this->owner, StatusPage::defaultId(), 'read');

        foreach (['/api/v1/alertmanager', '/api/v1/grafana'] as $url) {
            $this->withoutToken()->postJson($url, $this->fixture('alertmanager-v4'))->assertUnauthorized();
            $this->withToken($read)->postJson($url, $this->fixture('alertmanager-v4'))->assertForbidden();
            foreach ([[], ['alerts' => []], ['alerts' => 'x'], ['alerts' => [['status' => 'pending', 'labels' => []]]],
                ['alerts' => [['status' => 'firing']]], ['alerts' => [['status' => 'firing', 'labels' => ['pharos_component' => ['API']]]]],
                ['alerts' => array_fill(0, 501, ['status' => 'firing', 'labels' => []])]] as $body) {
                $this->withToken($this->plain)->postJson($url, $body)->assertUnprocessable();
            }
        }
        $this->assertSame(ComponentStatus::Operational, $api->fresh()->status);
        $this->assertNull($api->fresh()->reported_at);
    }

    public function test_components_are_looked_up_on_the_page_of_the_token_only(): void
    {
        $other = StatusPage::create(['name' => 'Other', 'slug' => 'other', 'is_published' => true]);
        $foreign = app(PageContext::class)->run($other->id, fn () => Component::create(['name' => 'API']));
        $own = Component::create(['name' => 'Website']);
        [, $otherToken] = ApiToken::issue('Other', $this->owner, $other->id, 'write');
        $byForeignId = ['alerts' => [['status' => 'firing', 'labels' => ['pharos_component' => (string) $foreign->id]]]];

        // The default page has no API, and the foreign id is not one of its components.
        $this->withToken($this->plain)->postJson('/api/v1/alertmanager', $this->fixture('alertmanager-v4'))->assertOk()
            ->assertJsonPath('ignored.0.reason', 'unknown_component');
        $this->withToken($this->plain)->postJson('/api/v1/alertmanager', $byForeignId)->assertOk()
            ->assertJsonPath('ignored.0.reason', 'unknown_component');
        $this->withToken($otherToken)->postJson('/api/v1/alertmanager', $byForeignId)->assertForbidden();

        $this->withToken($otherToken)->postJson('/api/v1/pages/other/alertmanager', $this->fixture('alertmanager-v4'))->assertOk()
            ->assertJsonPath('applied.0.component', $foreign->id);
        $this->assertSame(ComponentStatus::MajorOutage, app(PageContext::class)->run($other->id, fn () => $foreign->fresh()->status));
        $this->assertSame(ComponentStatus::PartialOutage, $own->fresh()->status);
    }

    public function test_alert_bodies_are_bounded_before_they_are_parsed(): void
    {
        foreach (['/api/v1/alertmanager', '/api/v1/grafana', '/api/v1/pages/other/grafana'] as $path) {
            $this->assertSame(262144, RequestBodyLimits::limit(SymfonyRequest::create($path, 'POST')), $path);
        }
        $this->assertNull(RequestBodyLimits::limit(SymfonyRequest::create('/api/v1/alertmanager', 'GET')));
    }

    /** @return array<string, mixed> */
    private function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(base_path('tests/Fixtures/alerts/'.$name.'.json')), true, flags: JSON_THROW_ON_ERROR);
    }
}
