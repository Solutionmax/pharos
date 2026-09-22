<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditEntry;
use App\Models\Check;
use App\Models\Component;
use App\Models\Setting;
use App\Models\StatusPage;
use App\Models\User;
use App\Services\MailConfig;
use App\Services\PageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageRolesTest extends TestCase
{
    use RefreshDatabase;

    public function test_viewer_cannot_open_edit_forms_or_mutate_on_legacy_and_explicit_routes(): void
    {
        $user = User::factory()->create(['role' => UserRole::User]);
        $page = StatusPage::default();
        $user->statusPages()->attach($page->id);
        foreach (['/admin', '/admin/pages/'.$page->id] as $prefix) {
            $this->actingAs($user)->get($prefix.'/components')->assertOk();
            // The migration defaults old memberships to editor; downgrade through the assignment UI.
            $admin = User::factory()->create(['role' => UserRole::Admin]);
            $this->actingAs($admin)->put('/admin/users/'.$user->id.'/pages', [
                'status_page_ids' => [$page->id], 'page_roles' => [$page->id => 'viewer'],
            ])->assertRedirect();
            $this->actingAs($user)->get($prefix.'/components/create')->assertForbidden();
            $this->actingAs($user)->post($prefix.'/components', [])->assertForbidden();
            $this->actingAs($user)->get($prefix.'/subscribers/export')->assertForbidden();
            $this->actingAs($user)->get($prefix.'/status-page')->assertForbidden();
        }
    }

    public function test_page_admin_has_page_settings_but_not_installation_authority(): void
    {
        $user = User::factory()->create(['role' => UserRole::User]);
        $page = StatusPage::default();
        $user->statusPages()->attach($page->id, ['role' => 'admin']);
        foreach (['/admin', '/admin/pages/'.$page->id] as $prefix) {
            foreach (['components/create', 'branding', 'mail', 'mail-templates'] as $path) {
                $this->actingAs($user)->get($prefix.'/'.$path)->assertOk();
            }
        }
        foreach (['users', 'settings', 'pages'] as $path) {
            $this->get('/admin/'.$path)->assertForbidden();
        }
        $this->post('/admin/branding/activate')->assertForbidden();
        $this->get('/admin/branding')->assertDontSee('name="key"', false)->assertDontSee('admin/branding/deactivate');
    }

    public function test_capabilities_and_role_revocation_use_fresh_membership_queries(): void
    {
        $user = User::factory()->create(['role' => UserRole::User]);
        $page = StatusPage::default();
        $user->statusPages()->attach($page->id);
        $this->assertTrue($user->canEditPage($page->id));
        $this->assertFalse($user->canAdministerPage($page->id));
        $user->load('statusPages');
        $user->statusPages()->updateExistingPivot($page->id, ['role' => 'admin']);
        $this->assertTrue($user->canAdministerPage($page->id));
        $user->statusPages()->updateExistingPivot($page->id, ['role' => 'viewer']);
        $this->assertFalse($user->canEditPage($page->id));
        $this->assertFalse($user->canAdministerPage($page->id));
        $user->statusPages()->detach();
        $this->assertFalse($user->canAccessPage($page->id));
    }

    public function test_editor_keeps_operations_but_cannot_manage_page_administration(): void
    {
        $user = User::factory()->create(['role' => UserRole::User]);
        $page = StatusPage::default();
        $user->statusPages()->attach($page->id);
        foreach (['/admin', '/admin/pages/'.$page->id] as $prefix) {
            $this->actingAs($user)->get($prefix.'/components/create')->assertOk();
            $this->get($prefix.'/status-page')->assertOk();
            foreach (['branding', 'mail', 'mail-templates'] as $path) {
                $this->get($prefix.'/'.$path)->assertForbidden();
            }
            foreach (['integrations/tokens', 'integrations/webhook/rotate', 'mail-test'] as $path) {
                $this->post($prefix.'/'.$path)->assertForbidden();
            }
        }
    }

    public function test_id_only_assignment_and_page_edits_preserve_roles_and_audit_changes(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create(['role' => UserRole::User]);
        $page = StatusPage::default();
        $this->actingAs($admin)->put('/admin/users/'.$user->id.'/pages', [
            'status_page_ids' => [$page->id], 'page_roles' => [$page->id => 'viewer'],
        ])->assertRedirect();
        $this->put('/admin/users/'.$user->id.'/pages', ['status_page_ids' => [$page->id]])->assertRedirect();
        $this->put('/admin/pages/'.$page->id, [
            'name' => $page->name, 'slug' => $page->slug, 'user_ids' => [$user->id],
        ])->assertRedirect();
        $this->assertFalse($user->canEditPage($page->id));
        $audit = AuditEntry::where('action', 'user.page_access_changed')->latest('id')->firstOrFail();
        $this->assertSame('viewer', $audit->changes['pages']['to'][$page->id]);
        $this->put('/admin/users/'.$user->id.'/pages', [
            'status_page_ids' => [$page->id], 'page_roles' => [$page->id => 'owner'],
        ])->assertSessionHasErrors('page_roles.'.$page->id);
        $this->assertFalse($user->canEditPage($page->id));
    }

    public function test_role_migration_preserves_existing_memberships_as_editors(): void
    {
        $migration = require database_path('migrations/2026_09_23_000000_add_role_to_status_page_user.php');
        $migration->down();
        $user = User::factory()->create(['role' => UserRole::User]);
        $page = StatusPage::default();
        $user->statusPages()->attach($page->id);
        $migration->up();
        $this->assertTrue($user->canAccessPage($page->id));
        $this->assertTrue($user->canEditPage($page->id));
        $this->assertFalse($user->canAdministerPage($page->id));
    }

    public function test_page_admin_mail_has_no_installation_management_links(): void
    {
        $user = User::factory()->create(['role' => UserRole::User]);
        $user->statusPages()->attach(StatusPage::defaultId(), ['role' => 'admin']);
        $this->actingAs($user)->get('/admin/mail')->assertOk()
            ->assertDontSee('href="'.route('admin.settings', ['tab' => 'mail']).'"', false)
            ->assertDontSee('href="'.route('admin.pages.index').'"', false);
    }

    public function test_page_admin_changes_only_the_assigned_nondefault_page(): void
    {
        $user = User::factory()->create(['role' => UserRole::User]);
        $page = StatusPage::create(['name' => 'Customer', 'slug' => 'customer']);
        $user->statusPages()->attach($page->id, ['role' => 'admin']);
        $prefix = '/admin/pages/'.$page->id;
        $this->actingAs($user)->put($prefix.'/branding', ['name' => 'Customer brand', 'accent' => '#123456'])->assertRedirect();
        $this->assertSame('Customer brand', app(PageContext::class)->run($page->id, fn () => Setting::get('brand.name')));
        $this->assertNotSame('Customer brand', Setting::get('brand.name'));
        $this->put($prefix.'/mail', ['mode' => 'central', 'encryption' => 'tls', 'from_name' => 'Customer sender'])->assertRedirect();
        $this->assertSame('Customer sender', app(PageContext::class)->run($page->id, fn () => app(MailConfig::class)->storedPage()['from_name']));
        $this->assertNotSame('Customer sender', app(MailConfig::class)->storedPage()['from_name']);
        $this->get('/admin/branding')->assertNotFound();
        $this->put('/admin/branding', ['name' => 'Cross page', 'accent' => '#123456'])->assertNotFound();
        foreach (['users', 'settings', 'pages', 'audit', 'audit/export', 'updates'] as $path) {
            $this->get('/admin/'.$path)->assertForbidden();
        }
        $this->post('/admin/branding/deactivate')->assertForbidden();
        $this->post('/admin/pages')->assertForbidden();
    }

    public function test_viewer_lists_do_not_offer_mutation_forms_or_edit_links(): void
    {
        $user = User::factory()->create(['role' => UserRole::User]);
        $user->statusPages()->attach(StatusPage::defaultId(), ['role' => 'viewer']);
        foreach (['components', 'services', 'incidents', 'subscribers'] as $path) {
            $response = $this->actingAs($user)->get('/admin/'.$path)->assertOk();
            $response->assertDontSee('/components/create')->assertDontSee('/services/create')
                ->assertDontSee('/incidents/create')->assertDontSee('/subscribers/enabled');
        }
    }

    public function test_new_account_page_roles_are_saved_and_audited(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $page = StatusPage::default();
        $this->actingAs($admin)->post('/admin/users', [
            'name' => 'Reader', 'email' => 'reader@example.test',
            'password' => 'long-enough-password', 'password_confirmation' => 'long-enough-password',
            'role' => 'user', 'status_page_ids' => [$page->id], 'page_roles' => [$page->id => 'viewer'],
        ])->assertRedirect();
        $user = User::where('email', 'reader@example.test')->sole();
        $this->assertTrue($user->canAccessPage($page->id));
        $this->assertFalse($user->canEditPage($page->id));
        $audit = AuditEntry::where('action', 'user.page_access_changed')->where('subject_id', $user->id)->first();
        $this->assertNotNull($audit);
        $this->assertSame('viewer', $audit->changes['pages']['to'][$page->id]);
    }

    public function test_viewer_component_list_hides_heartbeat_tokens_and_monitor_credentials(): void
    {
        $user = User::factory()->create(['role' => UserRole::User]);
        $user->statusPages()->attach(StatusPage::defaultId(), ['role' => 'viewer']);
        foreach (['heartbeat' => 'sensitive-heartbeat-token-xx', 'http' => 'https://user:password@example.test'] as $type => $target) {
            $component = Component::create(['name' => $type, 'source' => 'check']);
            Check::create(['component_id' => $component->id, 'type' => $type, 'target' => $target]);
        }
        foreach (['/admin', '/admin/pages/'.StatusPage::defaultId()] as $prefix) {
            $this->actingAs($user)->get($prefix.'/components')->assertOk()
                ->assertDontSee('sensitive-heartbeat')->assertDontSee('https://user:password');
        }
    }
}
