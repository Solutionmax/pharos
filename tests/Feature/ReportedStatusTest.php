<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Enums\UserRole;
use App\Models\ApiToken;
use App\Models\Check;
use App\Models\Component;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReportedStatusTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected ApiToken $token;

    protected string $plain;

    protected function setUp(): void
    {
        parent::setUp();
        $this->owner = User::factory()->create(['role' => UserRole::Admin]);
        [$this->token, $this->plain] = ApiToken::issue('zabbix-intern', $this->owner, StatusPage::defaultId(), 'write');
    }

    public function test_an_api_write_marks_a_manual_component_as_set_from_outside(): void
    {
        $component = Component::create(['name' => 'Mail']);

        $this->withToken($this->plain)->putJson('/api/v1/components/'.$component->id, ['status' => 1])->assertOk();

        $fresh = $component->fresh();
        $this->assertSame('webhook', $fresh->source);
        $this->assertSame($this->token->id, $fresh->reported_by_token_id);
        $this->assertNotNull($fresh->reported_at);
    }

    public function test_a_component_with_its_own_enabled_check_is_left_alone(): void
    {
        $component = Component::create(['name' => 'Web', 'source' => 'check']);
        Check::create(['component_id' => $component->id, 'type' => 'http', 'target' => 'https://example.test/', 'enabled' => true]);

        $this->withToken($this->plain)->putJson('/api/v1/components/'.$component->id, ['status' => 2])->assertOk();

        $fresh = $component->fresh();
        $this->assertSame('check', $fresh->source);
        $this->assertNull($fresh->reported_at);
        $this->assertNull($fresh->reported_by_token_id);
    }

    public function test_the_kuma_endpoint_marks_kuma(): void
    {
        $component = Component::create(['name' => 'Mail']);

        $this->withToken($this->plain)->postJson('/api/v1/integrations/kuma/'.$component->id, ['heartbeat' => ['status' => 0]])->assertOk();

        $fresh = $component->fresh();
        $this->assertSame('kuma', $fresh->source);
        $this->assertSame($this->token->id, $fresh->reported_by_token_id);
        $this->assertNotNull($fresh->reported_at);
    }

    public function test_a_pending_kuma_state_writes_nothing_so_it_marks_nothing(): void
    {
        $component = Component::create(['name' => 'Mail']);

        $this->withToken($this->plain)->postJson('/api/v1/integrations/kuma/'.$component->id, ['heartbeat' => ['status' => 2]])->assertOk();

        $this->assertSame('manual', $component->fresh()->source);
    }

    public function test_a_status_set_by_hand_in_the_admin_changes_neither(): void
    {
        $component = Component::create(['name' => 'Mail', 'source' => 'kuma']);
        $manual = Component::create(['name' => 'Phone']);

        $this->actingAs($this->owner)->put('/admin/components/'.$component->id.'/status', ['status' => 3])->assertRedirect();
        $this->actingAs($this->owner)->put('/admin/components/'.$manual->id.'/status', ['status' => 3])->assertRedirect();

        $this->assertSame('kuma', $component->fresh()->source);
        $this->assertSame('manual', $manual->fresh()->source);
        $this->assertNull($manual->fresh()->reported_at);
    }

    public function test_deleting_the_token_keeps_the_label_and_drops_the_reference(): void
    {
        $component = Component::create(['name' => 'Mail']);
        $this->withToken($this->plain)->putJson('/api/v1/components/'.$component->id, ['status' => 1])->assertOk();

        $this->token->delete();

        $fresh = $component->fresh();
        $this->assertSame('webhook', $fresh->source);
        $this->assertNull($fresh->reported_by_token_id);
        $this->assertNotNull($fresh->reported_at);
    }

    public function test_the_migration_turns_the_legacy_api_source_into_webhook(): void
    {
        $legacy = Component::create(['name' => 'Old']);
        $kept = Component::create(['name' => 'Kept', 'source' => 'kuma']);
        DB::table('components')->where('id', $legacy->id)->update(['source' => 'api']);

        (require database_path('migrations/2026_10_07_000000_add_reporting_to_components.php'))->up();

        $this->assertSame('webhook', $legacy->fresh()->source);
        $this->assertSame('kuma', $kept->fresh()->source);
    }
}
