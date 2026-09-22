<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ApiToken;
use App\Models\Check;
use App\Models\Component;
use App\Models\Incident;
use App\Models\Setting;
use App\Models\StatusPage;
use App\Models\User;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\PageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TokenScopesAndDeliveryFiltersTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_token_reads_private_incidents_but_cannot_write(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Admin]);
        [$token, $plain] = ApiToken::issue('Reader', $owner, StatusPage::defaultId(), 'read');
        $component = Component::create(['name' => 'API']);
        Incident::create(['name' => 'Private outage', 'visibility' => 'internal', 'status' => 1, 'occurred_at' => now()]);
        $this->withToken($plain)->getJson('/api/v1/incidents')->assertJsonFragment(['name' => 'Private outage']);
        $this->withToken($plain)->putJson('/api/v1/components/'.$component->id, ['status' => 4])->assertForbidden();
    }

    public function test_ui_tokens_default_to_read(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->post('/admin/integrations/tokens', ['name' => 'Reader'])->assertRedirect();
        $this->assertSame('read', ApiToken::sole()->scope);
    }

    public function test_delivery_filters_preserve_pagination_and_page_isolation(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $page = StatusPage::defaultId();
        $endpoint = WebhookEndpoint::create(['label' => 'Local Slack', 'url' => 'https://example.com/hook', 'format' => 'slack']);
        foreach (range(1, 7) as $i) {
            WebhookDelivery::create(['status_page_id' => $page, 'webhook_endpoint_id' => $endpoint->id, 'event_key' => uniqid(), 'payload' => [], 'attempts' => 6]);
        }
        WebhookDelivery::create(['status_page_id' => $page, 'webhook_endpoint_id' => $endpoint->id, 'event_key' => uniqid(), 'payload' => [], 'attempts' => 1, 'sent_at' => now()]);
        $other = StatusPage::create(['name' => 'Other', 'slug' => 'other']);
        app(PageContext::class)->run($other->id, fn () => WebhookEndpoint::create(['label' => 'Foreign secret destination', 'url' => 'https://example.com/foreign', 'format' => 'slack']));
        $url = '/admin/integrations?delivery_endpoint='.$endpoint->id.'&delivery_channel=slack&delivery_status=failed';
        $response = $this->actingAs($admin)->get($url)->assertOk()->assertDontSee('Foreign secret destination');
        $response->assertViewHas('deliveries', fn ($items) => $items->total() === 7 && $items->count() === 5);
        $response->assertSee('delivery_status=failed', false)->assertSee('deliveries_page=2', false);
        $this->get($url.'&deliveries_page=2')->assertViewHas('deliveries', fn ($items) => $items->count() === 2);
        $this->get('/admin/integrations?delivery_status=delivered')->assertViewHas('deliveries', fn ($items) => $items->total() === 1);
        $this->get('/admin/integrations?delivery_status=pending')->assertViewHas('deliveries', fn ($items) => $items->total() === 0);
        $this->get('/admin/integrations?delivery_channel=generic')->assertViewHas('deliveries', fn ($items) => $items->total() === 0);
        $foreign = app(PageContext::class)->run($other->id, fn () => WebhookEndpoint::first());
        $this->get('/admin/integrations?delivery_endpoint='.$foreign->id)->assertViewHas('deliveries', fn ($items) => $items->total() === 0)->assertDontSee('Foreign secret destination');
    }

    public function test_write_scope_tracks_owner_role_and_page_membership(): void
    {
        User::factory()->create(['role' => UserRole::Admin]);
        $owner = User::factory()->create(['role' => UserRole::User]);
        $page = StatusPage::default();
        $owner->statusPages()->attach($page, ['role' => 'editor']);
        $component = Component::create(['name' => 'API']);
        [, $plain] = ApiToken::issue('Writer', $owner, $page->id, 'write');
        $this->withToken($plain)->putJson('/api/v1/components/'.$component->id, ['status' => 4])->assertOk();
        $owner->statusPages()->updateExistingPivot($page->id, ['role' => 'viewer']);
        $this->withToken($plain)->putJson('/api/v1/components/'.$component->id, ['status' => 1])->assertForbidden();
        Incident::create(['name' => 'Private outage', 'visibility' => 'internal', 'status' => 1, 'occurred_at' => now()]);
        $this->getJson('/api/v1/incidents')->assertJsonFragment(['name' => 'Private outage']);
        $other = StatusPage::create(['name' => 'Other', 'slug' => 'other', 'is_published' => true]);
        app(PageContext::class)->run($other->id, fn () => Incident::create(['name' => 'Other private', 'visibility' => 'internal', 'status' => 1, 'occurred_at' => now()]));
        $this->getJson('/api/v1/pages/other/incidents')->assertJsonMissing(['name' => 'Other private']);
        $owner->statusPages()->detach($page);
        $this->getJson('/api/v1/incidents')->assertJsonMissing(['name' => 'Private outage']);
    }

    public function test_viewer_sees_destination_and_history_without_credentials_or_forms(): void
    {
        User::factory()->create(['role' => UserRole::Admin]);
        $viewer = User::factory()->create(['role' => UserRole::User]);
        $viewer->statusPages()->attach(StatusPage::defaultId(), ['role' => 'viewer']);
        WebhookEndpoint::create(['label' => 'Operations', 'url' => 'https://example.com/secret-hook', 'format' => 'generic', 'last_error' => 'secret-error']);
        $component = Component::create(['name' => 'Backups']);
        Check::create(['component_id' => $component->id, 'type' => 'heartbeat', 'target' => 'secret-heartbeat']);
        Setting::put('integrations.webhook_secret', 'secret-signing');
        $this->actingAs($viewer)->get('/admin/integrations')->assertOk()->assertSee('Operations')->assertSee('Delivery history')
            ->assertDontSee('secret-hook')->assertDontSee('secret-error')->assertDontSee('secret-heartbeat')->assertDontSee('secret-signing')
            ->assertDontSee('Create token')->assertDontSee('>Send test<', false)->assertDontSee('id="add-notification"', false);
    }

    public function test_cli_checks_scope_and_current_owner_permissions(): void
    {
        User::factory()->create(['role' => UserRole::Admin]);
        $owner = User::factory()->create(['role' => UserRole::User]);
        $owner->statusPages()->attach(StatusPage::defaultId(), ['role' => 'viewer']);
        $options = ['name' => 'CLI reader', '--user' => $owner->email, '--page' => StatusPage::defaultId()];
        $this->artisan('pharos:token', $options + ['--scope' => 'invalid'])->assertFailed();
        $this->artisan('pharos:token', $options + ['--scope' => 'write'])->assertFailed();
        $this->artisan('pharos:token', $options + ['--scope' => 'read'])->assertSuccessful();
        $this->assertSame('read', ApiToken::sole()->scope);
    }

    public function test_page_admin_issues_scoped_tokens_only_for_their_page(): void
    {
        User::factory()->create(['role' => UserRole::Admin]);
        $admin = User::factory()->create(['role' => UserRole::User]);
        $page = StatusPage::create(['name' => 'Managed', 'slug' => 'managed']);
        $admin->statusPages()->attach($page->id, ['role' => 'admin']);
        $this->actingAs($admin)->post('/admin/pages/'.$page->id.'/integrations/tokens', ['name' => 'Writer', 'scope' => 'write'])->assertRedirect();
        $token = ApiToken::sole();
        $this->assertSame('write', $token->scope);
        $this->assertSame($page->id, $token->status_page_id);
        $this->assertSame($admin->id, $token->user_id);
        $this->post('/admin/pages/'.$page->id.'/integrations/tokens', ['name' => 'Invalid', 'scope' => 'root'])->assertSessionHasErrors('scope');
        $this->post('/admin/integrations/tokens', ['name' => 'Foreign'])->assertNotFound();
        $this->delete('/admin/pages/'.$page->id.'/integrations/tokens/'.$token->id)->assertRedirect();
        $this->assertDatabaseMissing('api_tokens', ['id' => $token->id]);
    }

    public function test_flashed_plaintext_token_is_only_shown_on_its_page(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $other = StatusPage::create(['name' => 'Other', 'slug' => 'other']);
        $this->actingAs($admin)->post('/admin/integrations/tokens', ['name' => 'Reader']);
        $plain = session('new_token');
        $this->get('/admin/pages/'.$other->id.'/integrations')->assertOk()->assertDontSee($plain);
    }
}
