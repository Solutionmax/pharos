<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ApiToken;
use App\Models\StatusPage;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Review 15 nov 2026, D1: reading subscribers hands out email addresses, so it needs a write token. */
class SubscriberApiScopeTest extends TestCase
{
    use RefreshDatabase;

    protected Subscriber $subscriber;

    protected function setUp(): void
    {
        parent::setUp();

        $this->subscriber = Subscriber::create(['email' => 'private@example.com', 'token' => Subscriber::freshToken()]);
    }

    public function test_a_read_token_of_a_page_admin_gets_no_subscriber_data(): void
    {
        $owner = User::factory()->create(['role' => UserRole::User]);
        $owner->statusPages()->attach(StatusPage::defaultId(), ['role' => 'admin']);
        [, $read] = ApiToken::issue('read', $owner, null, 'read');

        $this->withToken($read)->getJson('/api/v1/subscribers')->assertForbidden()
            ->assertJson(['error' => 'Subscribers need a token with write access'])
            ->assertDontSee('private@example.com');
        $this->withToken($read)->getJson('/api/v1/subscribers/'.$this->subscriber->id)->assertForbidden()->assertDontSee('private@example.com');
        $this->withToken($read)->getJson('/api/v1/subscribers/999999')->assertForbidden();
        $this->withToken($read)->postJson('/api/v1/subscribers', ['email' => 'new@example.com'])->assertForbidden();
        $this->withToken($read)->deleteJson('/api/v1/subscribers/'.$this->subscriber->id)->assertForbidden();

        $this->assertNotNull($this->subscriber->fresh());
    }

    public function test_a_read_token_of_an_installation_admin_gets_none_either(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        [, $read] = ApiToken::issue('read', $admin, null, 'read');

        $this->withToken($read)->getJson('/api/v1/subscribers')->assertForbidden();
        // The other API routes still take the read token.
        $this->withToken($read)->getJson('/api/v1/groups')->assertOk();
    }

    public function test_a_write_token_of_a_page_admin_reads_and_writes_subscribers(): void
    {
        $owner = User::factory()->create(['role' => UserRole::User]);
        $owner->statusPages()->attach(StatusPage::defaultId(), ['role' => 'admin']);
        [, $write] = ApiToken::issue('write', $owner);

        $this->withToken($write)->getJson('/api/v1/subscribers')->assertOk()->assertJsonFragment(['email' => 'private@example.com']);
        $this->withToken($write)->getJson('/api/v1/subscribers/'.$this->subscriber->id)->assertOk();
        $this->withToken($write)->deleteJson('/api/v1/subscribers/'.$this->subscriber->id)->assertNoContent();
    }

    public function test_a_write_token_of_an_editor_is_still_refused(): void
    {
        $editor = User::factory()->create(['role' => UserRole::User]);
        $editor->statusPages()->attach(StatusPage::defaultId(), ['role' => 'editor']);
        [, $write] = ApiToken::issue('write', $editor);

        $this->withToken($write)->getJson('/api/v1/subscribers')->assertForbidden();
        $this->withToken($write)->getJson('/api/v1/groups')->assertOk();
    }

    public function test_the_page_routes_ask_the_same(): void
    {
        $page = StatusPage::create(['name' => 'Shop', 'slug' => 'shop', 'is_published' => true]);
        $owner = User::factory()->create(['role' => UserRole::User]);
        $owner->statusPages()->attach($page->id, ['role' => 'admin']);
        [, $read] = ApiToken::issue('read', $owner, $page->id, 'read');
        [, $write] = ApiToken::issue('write', $owner, $page->id);

        $this->withToken($read)->getJson('/api/v1/pages/shop/subscribers')->assertForbidden();
        $this->withToken($write)->getJson('/api/v1/pages/shop/subscribers')->assertOk();
    }
}
