<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ApiToken;
use App\Models\Component;
use App\Models\Incident;
use App\Models\Maintenance;
use App\Models\StatusPage;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\PageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExtendedApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_crud_enforces_read_write_owner_rights_and_page_boundaries(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Admin]);
        [, $write] = ApiToken::issue('write', $owner);
        [, $read] = ApiToken::issue('read', $owner, null, 'read');
        $this->getJson('/api/v1/ping')->assertOk()->assertJsonPath('data', 'Pong!');
        $this->getJson('/api/v1/groups')->assertUnauthorized();
        $group = $this->withToken($write)->postJson('/api/v1/groups', ['name' => 'Group'])->assertCreated()->json('data.id');
        $this->withToken($read)->getJson('/api/v1/groups')->assertOk()->assertJsonFragment(['name' => 'Group']);
        $this->withToken($read)->postJson('/api/v1/groups', ['name' => 'Forbidden'])->assertForbidden();
        $component = $this->withToken($write)->postJson('/api/v1/components', ['name' => 'C', 'group_id' => $group])->assertCreated()->json('data.id');
        $this->deleteJson('/api/v1/components/'.$component)->assertNoContent();
        $this->deleteJson('/api/v1/groups/'.$group)->assertNoContent();
        $page = StatusPage::create(['name' => 'Other', 'slug' => 'other', 'is_published' => false]);
        $this->getJson('/api/v1/pages/other/groups')->assertForbidden();
        [, $other] = ApiToken::issue('other', $owner, $page->id, 'read');
        $this->withToken($other)->getJson('/api/v1/pages/other/groups')->assertOk();
        $owner->update(['role' => UserRole::User]);
        $this->getJson('/api/v1/pages/other/groups')->assertForbidden();
    }

    public function test_subscriber_privacy_and_maintenance_crud_validation(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Admin]);
        [, $plain] = ApiToken::issue('write', $owner);
        $subscriber = Subscriber::create(['email' => 'private@example.com', 'token' => 'TOP-SECRET', 'created_ip' => '192.0.2.1']);
        $this->getJson('/api/v1/subscribers')->assertUnauthorized();
        $this->withToken($plain)->postJson('/api/v1/subscribers', ['email' => 'Private@Example.com'])->assertUnprocessable();
        $this->withToken($plain)->getJson('/api/v1/subscribers')->assertOk()->assertJsonFragment(['email' => 'private@example.com'])->assertDontSee('TOP-SECRET')->assertDontSee('192.0.2.1');
        $maintenance = $this->postJson('/api/v1/maintenance', ['title' => 'Work', 'starts_at' => now()->addHour()->toIso8601String(), 'ends_at' => now()->addHours(2)->toIso8601String(), 'components' => []])->assertCreated()->json('data.id');
        $this->getJson('/api/v1/maintenance/'.$maintenance)->assertOk()->assertJsonPath('data.title', 'Work');
        $this->putJson('/api/v1/maintenance/'.$maintenance, ['title' => 'Better'])->assertOk();
        $this->deleteJson('/api/v1/maintenance/'.$maintenance)->assertNoContent();
        $viewer = User::factory()->create(['role' => UserRole::User]);
        $viewer->statusPages()->attach(StatusPage::defaultId(), ['role' => 'viewer']);
        [, $reader] = ApiToken::issue('viewer', $viewer, null, 'read');
        $this->withToken($reader)->getJson('/api/v1/subscribers')->assertForbidden();
        $this->getJson('/api/v1/subscribers/'.$subscriber->id)->assertForbidden();
        $this->getJson('/api/v1/subscribers/999999')->assertForbidden();
        $this->withToken($plain)->deleteJson('/api/v1/subscribers/'.$subscriber->id)->assertNoContent();
    }

    public function test_malformed_bounded_inputs_and_cross_page_ids_cannot_partially_mutate(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Admin]);
        [, $plain] = ApiToken::issue('write', $owner);
        $page = StatusPage::create(['name' => 'Other', 'slug' => 'other', 'is_published' => true]);
        $foreign = PageContext::class;
        $foreign = app($foreign)->run($page->id, fn () => Component::create(['name' => 'Foreign']));
        $this->withToken($plain)->postJson('/api/v1/incidents', ['name' => 'Malformed', 'status' => ['bad'], 'message' => 'x'])->assertStatus(422);
        $this->postJson('/api/v1/incidents', ['name' => 'Foreign', 'status' => 1, 'message' => 'x', 'component_id' => $foreign->id])->assertStatus(422);
        $this->postJson('/api/v1/incidents', ['name' => 'Bad enum', 'status' => 1, 'message' => 'x', 'components' => [$foreign->id => 99]])->assertStatus(422);
        $this->assertDatabaseCount('incidents', 0);
        $this->assertSame(1, $foreign->fresh()->status->value);
        $this->postJson('/api/v1/groups', ['name' => str_repeat('x', 300000)])->assertStatus(413);
        $this->getJson('/api/v1/subscribers?per_page=100000')->assertStatus(422);
        $this->deleteJson('/api/v1/components/'.$foreign->id)->assertNotFound();
        $this->withHeader('Authorization', '')->deleteJson('/api/v1/components/'.$foreign->id)->assertUnauthorized();
    }

    public function test_api_deletion_closes_auto_incidents_and_restores_overdue_maintenance(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Admin]);
        [, $plain] = ApiToken::issue('write', $owner);
        $component = Component::create(['name' => 'A', 'status' => 5]);
        $window = Maintenance::create(['title' => 'Overdue', 'starts_at' => now()->subHours(2), 'ends_at' => now()->subHour(), 'started_at' => now()->subHours(2)]);
        $window->components()->attach($component->id, ['previous_status' => 1, 'applied_status' => 5]);
        $this->withToken($plain)->deleteJson('/api/v1/maintenance/'.$window->id)->assertNoContent();
        $this->assertSame(1, $component->fresh()->status->value);
        $incident = Incident::create(['name' => 'Automatic', 'status' => 1, 'occurred_at' => now(), 'auto_resolve' => true, 'grouping_key' => 'check:'.$component->id]);
        $incident->components()->attach($component->id, ['status' => 4]);
        $this->deleteJson('/api/v1/components/'.$component->id)->assertNoContent();
        $this->assertSame(4, $incident->fresh()->status->value);
        $this->assertNotNull($incident->fresh()->resolved_at);
    }

    public function test_metrics_require_read_token_and_escape_names_without_targets_or_cross_page_leaks(): void
    {
        $owner = User::factory()->create(['role' => UserRole::Admin]);
        [, $plain] = ApiToken::issue('read', $owner, null, 'read');
        Component::create(['name' => "quote\"slash\\\nname", 'status' => 3]);
        $this->get('/metrics')->assertUnauthorized();
        $response = $this->withToken($plain)->get('/metrics')->assertOk()->assertSee('pharos_component_status', false)->assertSee('quote\\"slash\\\\\\nname', false);
        $response->assertDontSee('token_hash');
        StatusPage::default()->update(['is_published' => false]);
        $this->get('/metrics')->assertOk();
        $this->get('/api/v1/metrics')->assertOk();
    }
}
