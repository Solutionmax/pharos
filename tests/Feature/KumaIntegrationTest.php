<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Enums\UserRole;
use App\Models\ApiToken;
use App\Models\AuditEntry;
use App\Models\Check;
use App\Models\Component;
use App\Models\StatusPage;
use App\Models\User;
use App\Services\PageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KumaIntegrationTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => UserRole::Admin, 'name' => 'Owner']);
    }

    public function test_kuma_heartbeat_states_map_to_component_status(): void
    {
        $component = Component::create(['name' => 'Mail']);
        [, $token] = ApiToken::issue('Kuma', $this->owner, StatusPage::defaultId(), 'write');
        $url = '/api/v1/integrations/kuma/'.$component->id;

        $this->withToken($token)->postJson($url, $this->kuma(0))->assertOk()->assertJson(['ok' => true, 'status' => 4]);
        $this->assertSame(ComponentStatus::MajorOutage, $component->fresh()->status);

        $this->withToken($token)->postJson($url, $this->kuma(2))->assertOk()->assertJson(['ok' => true, 'changed' => false]);
        $this->assertSame(ComponentStatus::MajorOutage, $component->fresh()->status);

        $this->withToken($token)->postJson($url, $this->kuma(3))->assertOk();
        $this->assertSame(ComponentStatus::UnderMaintenance, $component->fresh()->status);

        $this->withToken($token)->postJson($url, $this->kuma(1))->assertOk();
        $this->assertSame(ComponentStatus::Operational, $component->fresh()->status);
    }

    public function test_input_is_validated_strictly(): void
    {
        $component = Component::create(['name' => 'Mail']);
        [, $token] = ApiToken::issue('Kuma', $this->owner, StatusPage::defaultId(), 'write');
        $url = '/api/v1/integrations/kuma/'.$component->id;

        foreach ([[], ['heartbeat' => []], ['heartbeat' => ['status' => 4]], ['heartbeat' => ['status' => 'down']], ['heartbeat' => ['status' => '1a']], ['heartbeat' => 'x'], ['msg' => 'Kuma test']] as $body) {
            $this->withToken($token)->postJson($url, $body)->assertUnprocessable();
        }
        $this->assertSame(ComponentStatus::Operational, $component->fresh()->status);
        $this->withToken($token)->postJson('/api/v1/integrations/kuma/999999', $this->kuma(0))->assertNotFound();
    }

    public function test_read_tokens_missing_tokens_and_checked_components_are_refused(): void
    {
        $component = Component::create(['name' => 'Mail']);
        [, $read] = ApiToken::issue('Reader', $this->owner, StatusPage::defaultId(), 'read');
        $url = '/api/v1/integrations/kuma/'.$component->id;

        $this->postJson($url, $this->kuma(0))->assertUnauthorized();
        $this->withToken($read)->postJson($url, $this->kuma(0))->assertForbidden();
        $this->assertSame(ComponentStatus::Operational, $component->fresh()->status);

        $checked = Component::create(['name' => 'Web']);
        Check::create(['component_id' => $checked->id, 'type' => 'http', 'target' => 'https://example.com', 'enabled' => true]);
        [, $write] = ApiToken::issue('Writer', $this->owner, StatusPage::defaultId(), 'write');
        $this->withToken($write)->postJson('/api/v1/integrations/kuma/'.$checked->id, $this->kuma(0))->assertUnprocessable();
    }

    public function test_page_scoping_refuses_tokens_and_components_of_another_page(): void
    {
        $other = StatusPage::create(['name' => 'Other', 'slug' => 'other', 'is_published' => true]);
        $foreign = app(PageContext::class)->run($other->id, fn () => Component::create(['name' => 'Foreign']));
        $own = Component::create(['name' => 'Own']);
        [, $defaultToken] = ApiToken::issue('Default', $this->owner, StatusPage::defaultId(), 'write');
        [, $otherToken] = ApiToken::issue('Other', $this->owner, $other->id, 'write');

        // A default page token cannot reach the other page, through either address.
        $this->withToken($defaultToken)->postJson('/api/v1/pages/other/integrations/kuma/'.$foreign->id, $this->kuma(0))->assertForbidden();
        $this->withToken($defaultToken)->postJson('/api/v1/integrations/kuma/'.$foreign->id, $this->kuma(0))->assertNotFound();
        // The other page's token is refused on the default page.
        $this->withToken($otherToken)->postJson('/api/v1/integrations/kuma/'.$own->id, $this->kuma(0))->assertForbidden();

        $this->withToken($otherToken)->postJson('/api/v1/pages/other/integrations/kuma/'.$foreign->id, $this->kuma(0))->assertOk();
        $this->assertSame(ComponentStatus::MajorOutage, app(PageContext::class)->run($other->id, fn () => $foreign->fresh()->status));
        $this->assertSame(ComponentStatus::Operational, $own->fresh()->status);
    }

    public function test_a_change_is_audited_as_the_token(): void
    {
        $component = Component::create(['name' => 'Mail']);
        [, $token] = ApiToken::issue('Kuma monitor', $this->owner, StatusPage::defaultId(), 'write');
        $this->withToken($token)->postJson('/api/v1/integrations/kuma/'.$component->id, $this->kuma(0))->assertOk();

        $entry = AuditEntry::where('action', 'component.updated')->sole();
        $this->assertSame('API token: Kuma monitor', $entry->actor);
        $this->assertSame(['from' => 'Operational', 'to' => 'Major outage'], $entry->changes['status']);
    }

    public function test_the_legacy_kuma_address_keeps_working(): void
    {
        $component = Component::create(['name' => 'Mail']);
        [, $token] = ApiToken::issue('Kuma', $this->owner, StatusPage::defaultId(), 'write');
        $this->withToken($token)->postJson('/api/v1/kuma/components/'.$component->id, $this->kuma(3))->assertOk();
        $this->assertSame(ComponentStatus::UnderMaintenance, $component->fresh()->status);
    }

    /** @return array<string, mixed> */
    private function kuma(int $status): array
    {
        return [
            'heartbeat' => ['status' => $status, 'msg' => 'Kuma says so', 'time' => '2026-09-23 10:00:00'],
            'monitor' => ['name' => 'Mail', 'url' => 'https://mail.example.test'],
            'msg' => '[Mail] ['.($status === 1 ? 'Up' : 'Down').']',
        ];
    }
}
