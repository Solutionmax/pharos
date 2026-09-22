<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ApiToken;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\StatusPage;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\PageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageRoutesTest extends TestCase
{
    use RefreshDatabase;

    public function test_page_administration_is_available_to_an_administrator(): void
    {
        $this->actingAs(User::factory()->create(['role' => UserRole::Admin]))
            ->get('/admin/pages')->assertOk();
    }

    public function test_public_and_legacy_api_only_show_the_selected_page(): void
    {
        User::factory()->create();
        Component::create(['name' => 'Default exclusive']);
        $b = StatusPage::create(['name' => 'Second', 'slug' => 'second', 'is_published' => true]);
        $component = app(PageContext::class)->run($b->id, fn () => Component::create(['name' => 'Second exclusive']));
        $this->get('/status/second')->assertOk()->assertSee('Second exclusive')->assertDontSee('Default exclusive');
        $this->get('/')->assertOk()->assertSee('Default exclusive')->assertDontSee('Second exclusive');
        $this->getJson('/api/v1/pages/second/components')->assertOk()->assertJsonFragment(['name' => 'Second exclusive'])->assertJsonMissing(['name' => 'Default exclusive']);
        $this->getJson('/api/v1/components/'.$component->id)->assertNotFound();
        $this->get('/status/unknown')->assertNotFound();
    }

    public function test_admin_links_keep_page_context_and_foreign_records_are_not_bound(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $a = Component::create(['name' => 'Default exclusive']);
        $b = StatusPage::create(['name' => 'Second', 'slug' => 'second']);
        $this->actingAs($admin)->get('/admin/pages/'.$b->id.'/components')->assertOk()
            ->assertSee('/admin/pages/'.$b->id.'/components/create', false)->assertDontSee('Default exclusive');
        $this->get('/admin/pages/'.$b->id.'/components/'.$a->id.'/edit')->assertNotFound();
        $this->delete('/admin/pages/'.$b->id.'/components/'.$a->id)->assertNotFound();
        $this->assertDatabaseHas('components', ['id' => $a->id]);
    }

    public function test_page_assignments_apply_to_legacy_and_explicit_routes(): void
    {
        User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create(['role' => UserRole::User]);
        $b = StatusPage::create(['name' => 'Second', 'slug' => 'second']);
        $this->actingAs($user)->get('/admin/pages/'.$b->id.'/components')->assertNotFound();
        $this->get('/admin/components')->assertNotFound();
        $user->statusPages()->attach($b);
        $this->get('/admin/pages/'.$b->id.'/components')->assertOk();
        $this->get('/admin/pages/'.$b->id.'/branding')->assertForbidden();
    }

    public function test_foreign_group_cannot_be_assigned_through_a_form(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $foreign = ComponentGroup::create(['name' => 'Default group']);
        $b = StatusPage::create(['name' => 'Second', 'slug' => 'second']);
        $this->actingAs($admin)->post('/admin/pages/'.$b->id.'/components', [
            'name' => 'Attempt', 'status' => 1, 'source' => 'manual', 'component_group_id' => $foreign->id,
        ])->assertSessionHasErrors('component_group_id');
        $this->assertDatabaseMissing('components', ['name' => 'Attempt']);
    }

    public function test_api_token_never_exceeds_page_or_owner_rights(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $a = Component::create(['name' => 'A']);
        $b = StatusPage::create(['name' => 'Second', 'slug' => 'second', 'is_published' => true]);
        $component = app(PageContext::class)->run($b->id, fn () => Component::create(['name' => 'B']));
        [$token, $plain] = ApiToken::issue('B token');
        $token->forceFill(['status_page_id' => $b->id, 'user_id' => $admin->id])->save();
        $this->withToken($plain)->putJson('/api/v1/components/'.$a->id, ['status' => 3])->assertForbidden();
        $this->withToken($plain)->putJson('/api/v1/pages/second/components/'.$component->id, ['status' => 3])->assertOk();
        $admin->forceFill(['role' => UserRole::User])->save();
        $this->withToken($plain)->putJson('/api/v1/pages/second/components/'.$component->id, ['status' => 1])->assertForbidden();
    }

    public function test_foreign_valid_token_cannot_read_internal_incidents(): void
    {
        User::factory()->create();
        [$token, $plain] = ApiToken::issue('Default token');
        $b = StatusPage::create(['name' => 'Second', 'slug' => 'second', 'is_published' => true]);
        app(PageContext::class)->run($b->id, fn () => Incident::create(['name' => 'Private B incident', 'status' => 1, 'visibility' => 'internal', 'occurred_at' => now()]));
        $this->withToken($plain)->getJson('/api/v1/pages/second/incidents')->assertOk()->assertJsonMissing(['name' => 'Private B incident']);
    }

    public function test_custom_domain_resolves_only_its_page_and_admin_links_stay_central(): void
    {
        User::factory()->create();
        config(['app.url' => 'http://localhost']);
        $b = StatusPage::create(['name' => 'Second', 'slug' => 'second', 'is_published' => true, 'domain' => 'status.second.test']);
        app(PageContext::class)->run($b->id, fn () => Component::create(['name' => 'Domain exclusive']));
        $this->get('https://status.second.test/')->assertOk()->assertSee('Domain exclusive');
        $this->get('https://unknown.test/')->assertNotFound();
        $this->get('https://status.second.test/admin/login')->assertRedirect('http://localhost/admin/login');
    }

    public function test_drafts_are_hidden_and_unassigned_login_has_a_useful_landing(): void
    {
        User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create(['role' => UserRole::User]);
        StatusPage::create(['name' => 'Draft', 'slug' => 'draft']);
        $this->get('/status/draft')->assertNotFound();
        $this->getJson('/api/v1/pages/draft/components')->assertNotFound();
        $this->actingAs($user)->get('/admin')->assertRedirect('/admin/no-pages');
        $this->get('/admin/no-pages')->assertOk()->assertSee('No status pages assigned');
    }

    public function test_streamed_export_retains_page_context_until_the_body_is_sent(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Subscriber::create(['email' => 'a@example.test', 'token' => 'a', 'verified_at' => now()]);
        $b = StatusPage::create(['name' => 'Second', 'slug' => 'second']);
        app(PageContext::class)->run($b->id, fn () => Subscriber::create(['email' => 'b@example.test', 'token' => 'b', 'verified_at' => now()]));
        $body = $this->actingAs($admin)->get('/admin/pages/'.$b->id.'/subscribers/export')->assertOk()->streamedContent();
        $this->assertStringContainsString('b@example.test', $body);
        $this->assertStringNotContainsString('a@example.test', $body);
        $this->assertSame(StatusPage::default()->id, app(PageContext::class)->id());
    }

    public function test_cli_tokens_require_a_current_owner_and_explicit_page(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $b = StatusPage::create(['name' => 'Second', 'slug' => 'second']);
        $this->artisan('pharos:token', ['name' => 'no owner'])->assertFailed();
        $this->assertDatabaseCount('api_tokens', 0);
        $this->artisan('pharos:token', ['name' => 'Scoped CLI', '--user' => $admin->email, '--page' => $b->id])->assertSuccessful();
        $this->assertDatabaseHas('api_tokens', ['name' => 'Scoped CLI', 'user_id' => $admin->id, 'status_page_id' => $b->id]);
        $admin->forceFill(['role' => UserRole::User])->save();
        $this->artisan('pharos:token', ['name' => 'Denied CLI', '--user' => $admin->email, '--page' => $b->id])->assertFailed();
        $this->assertDatabaseMissing('api_tokens', ['name' => 'Denied CLI']);
    }

    public function test_unknown_api_hosts_are_rejected_and_domain_pages_use_their_canonical_form_origin(): void
    {
        User::factory()->create();
        config(['app.url' => 'http://localhost']);
        StatusPage::create(['name' => 'Domain', 'slug' => 'domain', 'domain' => 'status.other.test', 'is_published' => true]);
        $this->getJson('https://unknown.test/api/v1/components')->assertNotFound();
        $this->get('http://localhost/status/domain?page=2')->assertRedirect('https://status.other.test/?page=2');
    }

    public function test_a_custom_domain_cannot_claim_the_central_installation_host(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        config(['app.url' => 'http://localhost']);
        $page = StatusPage::create(['name' => 'Second', 'slug' => 'second']);
        $this->actingAs($admin)->put('http://localhost/admin/pages/'.$page->id, ['name' => 'Second', 'slug' => 'second', 'domain' => 'localhost', 'domain_verified' => '1'])->assertSessionHasErrors('domain');
        $this->assertNull($page->fresh()->domain);
    }

    public function test_subscription_links_survive_domain_changes_and_slug_changes_are_refused(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $b = StatusPage::create(['name' => 'Second', 'slug' => 'second', 'domain' => 'status.old.test', 'is_published' => true]);
        $subscriber = app(PageContext::class)->run($b->id, fn () => Subscriber::create(['email' => 'subscriber@example.test', 'token' => 'stable', 'verified_at' => now()]));
        $link = $subscriber->unsubscribeUrl();
        $this->assertStringStartsWith(rtrim(config('app.url'), '/').'/status/second/unsubscribe/', $link);
        $b->update(['domain' => 'status.new.test']);
        $this->get($link)->assertOk();
        $this->assertNotNull($subscriber->fresh()->unsubscribed_at);
        $this->actingAs($admin)->put('/admin/pages/'.$b->id, ['name' => 'Second renamed', 'slug' => 'new-slug'])->assertSessionHasErrors('slug');
    }

    public function test_new_admin_routes_reject_sessions_from_before_password_rotation(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $old = $admin->password;
        $admin->update(['password' => 'changed-password-for-test']);
        foreach (['/admin/pages', '/admin/mail', '/admin/pages/1/mail', '/admin/no-pages'] as $path) {
            $this->flushSession();
            $this->actingAs($admin->fresh())->withSession(['password_hash_web' => $old])->get($path)->assertRedirect(route('admin.login'));
            $this->assertGuest();
        }
    }
}
