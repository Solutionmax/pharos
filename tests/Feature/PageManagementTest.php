<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\StatusPage;
use App\Models\User;
use App\Services\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PageManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected string $secret;

    protected function setUp(): void
    {
        parent::setUp();

        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        config(['pharos.license_public_key' => sodium_bin2hex(sodium_crypto_sign_publickey($pair))]);

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'correct-horse-battery',
            'role' => UserRole::Admin,
        ]);
    }

    public function test_page_tags_are_saved_and_default_remains_the_main_page(): void
    {
        $this->license(limit: 3);
        $this->actingAs($this->admin)->post('/admin/pages', [
            'name' => 'Tagged customer', 'slug' => 'tagged-customer',
            'tag_label' => 'Customer', 'tag_color' => 'violet',
        ])->assertRedirect('/admin/pages');
        $page = StatusPage::where('slug', 'tagged-customer')->sole();
        $this->assertSame('Customer', $page->tagLabel());
        $this->assertSame('violet', $page->tagColor());
        $this->actingAs($this->admin)->get('/admin/pages')->assertOk()->assertSee('page-tag--violet', false);

        $default = StatusPage::default();
        $this->actingAs($this->admin)->put('/admin/pages/'.$default->id, [
            'name' => $default->name, 'slug' => $default->slug,
            'tag_label' => 'Other', 'tag_color' => 'rose',
        ])->assertRedirect('/admin/pages');
        $this->assertSame('Default', $default->fresh()->tagLabel());
        $this->assertSame('blue', $default->fresh()->tagColor());
    }

    public function test_page_tags_reject_unsupported_colours_and_reserved_default_label(): void
    {
        $this->license(limit: 3);
        $this->actingAs($this->admin)->post('/admin/pages', [
            'name' => 'Bad tag', 'slug' => 'bad-tag',
            'tag_label' => 'dEfAuLt', 'tag_color' => 'unknown',
        ])->assertSessionHasErrors(['tag_label', 'tag_color']);
        $this->assertSame(1, StatusPage::count());
    }

    public function test_page_mail_identifies_its_owner_and_explains_central_inheritance(): void
    {
        $page = StatusPage::create(['name' => 'Harbor test', 'slug' => 'harbor-test']);
        $this->actingAs($this->admin)->get('/admin/pages/'.$page->id.'/mail')
            ->assertOk()->assertSee('Email for Harbor test')
            ->assertSee('Account emails always use central mail')
            ->assertSee(route('admin.settings', ['tab' => 'mail']), false);
    }

    public function test_a_free_installation_cannot_create_a_second_page(): void
    {
        $this->actingAs($this->admin)->post('/admin/pages', [
            'name' => 'Customer B',
            'slug' => 'customer-b',
        ])->assertForbidden();

        $this->assertSame(1, StatusPage::count());
    }

    public function test_a_multi_page_licence_allows_an_unpublished_page_with_assignments(): void
    {
        $member = User::create([
            'name' => 'Operator',
            'email' => 'operator@example.com',
            'password' => 'correct-horse-battery',
            'role' => UserRole::User,
        ]);
        $this->license(limit: 3);

        $this->actingAs($this->admin)->post('/admin/pages', [
            'name' => 'Customer B',
            'slug' => 'customer-b',
            'user_ids' => [$member->id],
        ])->assertRedirect('/admin/pages');

        $page = StatusPage::where('slug', 'customer-b')->sole();
        $this->assertFalse($page->is_published);
        $this->assertNull($page->archived_at);
        $this->assertTrue($member->fresh()->statusPages->contains($page));
    }

    public function test_the_signed_active_page_limit_is_enforced_server_side(): void
    {
        $this->license(limit: 2);

        $this->createPage('Customer B', 'customer-b')->assertRedirect('/admin/pages');
        $this->createPage('Customer C', 'customer-c')->assertForbidden();

        $this->assertSame(2, StatusPage::count());
    }

    public function test_a_page_can_be_published_and_assignments_can_be_changed(): void
    {
        $first = User::create([
            'name' => 'First', 'email' => 'first@example.com',
            'password' => 'correct-horse-battery', 'role' => UserRole::User,
        ]);
        $second = User::create([
            'name' => 'Second', 'email' => 'second@example.com',
            'password' => 'correct-horse-battery', 'role' => UserRole::User,
        ]);
        $this->license();
        $this->createPage('Customer B', 'customer-b', ['user_ids' => [$first->id]]);
        $page = StatusPage::where('slug', 'customer-b')->sole();

        $this->actingAs($this->admin)->put("/admin/pages/{$page->id}", [
            'name' => 'Customer B',
            'slug' => 'customer-b',
            'is_published' => '1',
            'user_ids' => [$second->id],
        ])->assertRedirect('/admin/pages');

        $this->assertTrue($page->fresh()->is_published);
        $this->assertFalse($first->fresh()->statusPages->contains($page));
        $this->assertTrue($second->fresh()->statusPages->contains($page));
    }

    public function test_a_domain_requires_dns_and_tls_confirmation_and_is_stored_as_a_normalized_host(): void
    {
        $this->license();

        $this->createPage('Customer B', 'customer-b', [
            'domain' => ' Status.Example.com. ',
        ])->assertSessionHasErrors('domain_verified');

        $this->createPage('Customer B', 'customer-b', [
            'domain' => ' Status.Example.com. ',
            'domain_verified' => '1',
        ])->assertRedirect('/admin/pages');

        $this->assertSame('status.example.com', StatusPage::where('slug', 'customer-b')->sole()->domain);
    }

    public function test_a_normalized_domain_can_belong_to_only_one_page(): void
    {
        $this->license();
        $this->createPage('Customer B', 'customer-b', [
            'domain' => 'status.example.com',
            'domain_verified' => '1',
        ]);

        $this->createPage('Customer C', 'customer-c', [
            'domain' => 'STATUS.EXAMPLE.COM.',
            'domain_verified' => '1',
        ])->assertSessionHasErrors('domain');

        $this->assertSame(2, StatusPage::count());
    }

    public function test_the_default_page_cannot_be_archived(): void
    {
        $default = StatusPage::default();

        $this->actingAs($this->admin)
            ->post("/admin/pages/{$default->id}/archive")
            ->assertForbidden();

        $this->assertNull($default->fresh()->archived_at);
    }

    public function test_archiving_a_page_unpublishes_it_without_deleting_it(): void
    {
        $this->license();
        $this->createPage('Customer B', 'customer-b', ['is_published' => '1']);
        $page = StatusPage::where('slug', 'customer-b')->sole();

        $this->actingAs($this->admin)
            ->post("/admin/pages/{$page->id}/archive")
            ->assertRedirect('/admin/pages');

        $this->assertNotNull($page->fresh()->archived_at);
        $this->assertFalse($page->fresh()->is_published);
        $this->assertDatabaseHas('status_pages', ['id' => $page->id]);
    }

    public function test_reactivation_obeys_the_current_page_limit(): void
    {
        $this->license(limit: 3);
        $this->createPage('Customer B', 'customer-b');
        $this->createPage('Customer C', 'customer-c');
        $pageB = StatusPage::where('slug', 'customer-b')->sole();
        $this->actingAs($this->admin)->post("/admin/pages/{$pageB->id}/archive");
        $this->license(limit: 2);

        $this->actingAs($this->admin)->put("/admin/pages/{$pageB->id}", [
            'name' => 'Customer B',
            'slug' => 'customer-b',
            'reactivate' => '1',
        ])->assertForbidden();

        $this->assertNotNull($pageB->fresh()->archived_at);
    }

    public function test_an_expired_licence_does_not_block_edits_to_an_existing_page(): void
    {
        $this->license();
        $this->createPage('Customer B', 'customer-b');
        $page = StatusPage::where('slug', 'customer-b')->sole();
        $this->license(expiresAt: '2026-08-01');

        $this->actingAs($this->admin)->put("/admin/pages/{$page->id}", [
            'name' => 'Customer B renamed',
            'slug' => 'customer-b',
        ])->assertRedirect('/admin/pages');

        $this->assertSame('Customer B renamed', $page->fresh()->name);
    }

    public function test_page_management_is_restricted_to_installation_administrators(): void
    {
        $member = User::create([
            'name' => 'Operator',
            'email' => 'operator@example.com',
            'password' => 'correct-horse-battery',
            'role' => UserRole::User,
        ]);

        $this->actingAs($member)->get('/admin/pages')->assertForbidden();
        $this->actingAs($member)->post('/admin/pages', [
            'name' => 'Customer B', 'slug' => 'customer-b',
        ])->assertForbidden();
    }

    public function test_the_page_selector_only_links_pages_assigned_to_an_ordinary_user(): void
    {
        $member = User::create([
            'name' => 'Operator',
            'email' => 'operator@example.com',
            'password' => 'correct-horse-battery',
            'role' => UserRole::User,
        ]);
        $this->license();
        $this->createPage('Customer B', 'customer-b', ['user_ids' => [$member->id]]);
        $page = StatusPage::where('slug', 'customer-b')->sole();
        $default = StatusPage::default();

        $this->flushSession();
        $this->actingAs($member)->get("/admin/pages/{$page->id}/components")
            ->assertOk()
            ->assertSee('Customer B')
            ->assertSee('href="'.route('page.admin.components', ['statusPage' => $page->id]).'"', false)
            ->assertDontSee('href="'.route('page.admin.components', ['statusPage' => $default->id]).'"', false);
    }

    public function test_large_page_menus_offer_search_and_all_available_choices(): void
    {
        for ($number = 1; $number <= 9; $number++) {
            StatusPage::create(['name' => 'Customer '.$number, 'slug' => 'customer-'.$number, 'is_published' => true]);
        }
        $response = $this->actingAs($this->admin)->get('/admin/pages')->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        foreach (['Choose a page to manage', 'View a published status page'] as $label) {
            $menu = $xpath->query('//details[@aria-label="'.$label.'"]')->item(0);
            $this->assertNotNull($menu);
            $this->assertCount(10, $xpath->query('.//a', $menu));
            $this->assertCount(1, $xpath->query('.//input[@type="search"]', $menu));
        }
    }

    public function test_public_page_menu_only_offers_published_pages_the_user_can_manage(): void
    {
        $member = User::factory()->create(['role' => UserRole::User]);
        $public = StatusPage::create(['name' => 'Public customer', 'slug' => 'public-customer', 'is_published' => true]);
        $draft = StatusPage::create(['name' => 'Draft customer', 'slug' => 'draft-customer']);
        $archived = StatusPage::create(['name' => 'Archived customer', 'slug' => 'archived-customer', 'is_published' => true, 'archived_at' => now()]);
        $member->statusPages()->attach([$public->id, $draft->id, $archived->id]);

        $response = $this->actingAs($member)->get('/admin/pages/'.$public->id.'/components')->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $links = $xpath->query('//*[@aria-label="View a published status page"]//a');
        $this->assertCount(1, $links);
        $this->assertSame($public->publicUrl(), $links->item(0)->getAttribute('href'));
        $this->assertStringContainsString($public->name, $links->item(0)->textContent);
        $response->assertDontSee($archived->name)->assertSee($draft->name);
    }

    public function test_page_overview_has_manage_links_and_canonical_public_addresses(): void
    {
        $page = StatusPage::create(['name' => 'Customer site', 'slug' => 'customer-site', 'is_published' => true, 'domain' => 'status.customer.test']);
        $draft = StatusPage::create(['name' => 'Draft site', 'slug' => 'draft-site']);
        $response = $this->actingAs($this->admin)->get('/admin/pages')->assertOk();
        $dom = new \DOMDocument;
        @$dom->loadHTML($response->getContent());
        $xpath = new \DOMXPath($dom);
        $rows = $xpath->query('//table/tbody/tr');
        foreach ($rows as $row) {
            $html = $dom->saveHTML($row);
            if (str_contains($row->textContent, $page->name)) {
                $this->assertStringContainsString('href="'.$page->publicUrl().'"', $html);
                $this->assertStringContainsString('href="'.route('page.admin.components', ['statusPage' => $page->id]).'"', $html);
                $this->assertStringContainsString('Manage', $row->textContent);
            }
            if (str_contains($row->textContent, $draft->name)) {
                $this->assertStringContainsString($draft->publicUrl(), $row->textContent);
                $this->assertStringNotContainsString('href="'.$draft->publicUrl().'"', $html);
            }
        }
        $this->assertCount(3, $rows);
        $response->assertSee(StatusPage::default()->publicUrl());
    }

    public function test_the_page_selector_has_an_empty_state_for_an_unassigned_user(): void
    {
        $member = User::create([
            'name' => 'Operator',
            'email' => 'operator@example.com',
            'password' => 'correct-horse-battery',
            'role' => UserRole::User,
        ]);

        $this->actingAs($member)->get('/admin/no-pages')
            ->assertOk()
            ->assertSee('No pages assigned');
    }

    public function test_the_page_list_and_form_offer_management_without_deletion(): void
    {
        $this->actingAs($this->admin)->get('/admin/pages')
            ->assertOk()
            ->assertSee('Status pages')
            ->assertSee('Create page')
            ->assertDontSee('Delete page');

        $this->actingAs($this->admin)->get('/admin/pages/create')
            ->assertOk()
            ->assertSee('name="name"', false)
            ->assertSee('name="slug"', false)
            ->assertSee('name="domain"', false)
            ->assertSee('DNS and TLS');
    }

    protected function createPage(string $name, string $slug, array $extra = [])
    {
        return $this->actingAs($this->admin)->post('/admin/pages', [
            'name' => $name,
            'slug' => $slug,
            ...$extra,
        ]);
    }

    protected function license(?int $limit = null, ?string $expiresAt = null): void
    {
        $payload = [
            'product' => 'pharos',
            'features' => [License::FEATURE_BRAND_PACK, License::FEATURE_MULTI_PAGES],
        ];

        if ($limit !== null) {
            $payload['limits'] = ['status_pages' => $limit];
        }

        if ($expiresAt !== null) {
            $payload['expires_at'] = $expiresAt;
        }

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = sodium_crypto_sign_detached($json, $this->secret);
        $b64 = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        Setting::put('license.key', $b64($json).'.'.$b64($signature));
        Cache::forget('license.payload');
    }
}
