<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Setting;
use App\Models\StatusPage;
use App\Models\User;
use App\Services\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * The licence matrix end to end: every combination a customer can hold, driven
 * through the same HTTP routes the admin uses. One scenario per test so a
 * failure names the plan that broke.
 */
class LicenceMatrixTest extends TestCase
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
        Cache::flush();
        Storage::fake('public');

        $this->admin = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'correct-horse-battery',
            'role' => UserRole::Admin,
        ]);
    }

    // ---------- no key ----------

    public function test_free_allows_one_page_and_none_of_the_paid_branding(): void
    {
        $license = app(License::class);
        $this->assertFalse($license->has(License::FEATURE_BRAND_PACK));
        $this->assertFalse($license->has(License::FEATURE_MULTI_PAGES));
        $this->assertSame(1, $license->statusPageLimit());

        $this->assertBrandPackRefused();
        $this->assertCreateRefused('second');
        $this->assertSame(1, StatusPage::query()->count());
    }

    public function test_free_ignores_a_logo_left_behind_by_an_earlier_key(): void
    {
        Storage::disk('public')->put('brand/left.png', 'png');
        Setting::put('brand.logo_path', 'brand/left.png');
        Setting::put('brand.credit_hidden', '1');

        $this->get('/')->assertOk()->assertDontSee('brand/left.png')->assertSee('Powered by Pharos');
    }

    // ---------- Brand pack only ----------

    public function test_brand_pack_only_unlocks_branding_but_not_a_second_page(): void
    {
        $this->activate([License::FEATURE_BRAND_PACK]);

        $this->assertBrandPackWorks();
        $this->assertCreateRefused('second');
    }

    // ---------- Multi page only ----------

    public function test_multi_page_only_without_a_limit_allows_many_pages_and_no_branding(): void
    {
        $this->activate([License::FEATURE_MULTI_PAGES]);

        foreach (['two', 'three', 'four', 'five'] as $slug) {
            $this->createPage($slug)->assertRedirect('/admin/pages');
        }

        $this->assertSame(5, StatusPage::query()->whereNull('archived_at')->count());
        $this->assertNull(app(License::class)->statusPageLimit());
        $this->assertBrandPackRefused();
    }

    public function test_multi_page_with_a_limit_refuses_one_page_more(): void
    {
        $this->activate([License::FEATURE_MULTI_PAGES], limit: 3);

        $this->createPage('two')->assertRedirect('/admin/pages');
        $this->createPage('three')->assertRedirect('/admin/pages');
        $this->assertCreateRefused('four');
        $this->assertSame(3, StatusPage::query()->count());
    }

    public function test_archiving_frees_a_place_and_reactivation_respects_the_limit(): void
    {
        $this->activate([License::FEATURE_MULTI_PAGES], limit: 3);
        $this->createPage('two');
        $this->createPage('three');

        $two = StatusPage::query()->where('slug', 'two')->sole();
        $this->actingAs($this->admin)->post("/admin/pages/{$two->id}/archive")->assertRedirect();

        // The archived page no longer counts, so a new one fits.
        $this->createPage('four')->assertRedirect('/admin/pages');

        // Three active again: bringing the archived page back would make four.
        $this->reactivate($two)->assertForbidden();
        $this->assertNotNull($two->fresh()->archived_at);

        // Make room and it comes back.
        $four = StatusPage::query()->where('slug', 'four')->sole();
        $this->actingAs($this->admin)->post("/admin/pages/{$four->id}/archive");
        $this->reactivate($two)->assertRedirect('/admin/pages');
        $this->assertNull($two->fresh()->archived_at);
    }

    // ---------- both ----------

    public function test_both_features_unlock_branding_and_pages(): void
    {
        $this->activate([License::FEATURE_BRAND_PACK, License::FEATURE_MULTI_PAGES], limit: 2);

        $this->assertBrandPackWorks();
        $this->createPage('two')->assertRedirect('/admin/pages');
        $this->assertCreateRefused('three');
    }

    // ---------- expired key carrying both ----------

    public function test_an_expired_key_keeps_the_brand_pack_and_existing_pages_but_blocks_growth(): void
    {
        $this->activate([License::FEATURE_BRAND_PACK, License::FEATURE_MULTI_PAGES], limit: 5);
        $this->createPage('two', published: true);
        $this->createPage('three', published: true);
        $two = StatusPage::query()->where('slug', 'two')->sole();
        $three = StatusPage::query()->where('slug', 'three')->sole();
        $this->actingAs($this->admin)->post("/admin/pages/{$three->id}/archive");

        // The year runs out. A lapsed key carrying the Brand pack is still accepted on paste.
        $this->activate([License::FEATURE_BRAND_PACK, License::FEATURE_MULTI_PAGES], limit: 5, expiresAt: '2026-08-01');
        $license = app(License::class);
        $this->assertTrue($license->expired());
        $this->assertTrue($license->has(License::FEATURE_BRAND_PACK));
        $this->assertFalse($license->has(License::FEATURE_MULTI_PAGES));
        $this->assertSame(1, $license->statusPageLimit());

        $this->assertBrandPackWorks();

        // The extra page that already exists keeps working and publishing.
        $this->get('/status/two')->assertOk();
        $this->actingAs($this->admin)->put("/admin/pages/{$two->id}", ['name' => 'Two', 'slug' => 'two'])
            ->assertRedirect('/admin/pages');
        $this->get('/status/two')->assertNotFound();
        $this->actingAs($this->admin)->put("/admin/pages/{$two->id}", ['name' => 'Two', 'slug' => 'two', 'is_published' => '1'])
            ->assertRedirect('/admin/pages');
        $this->get('/status/two')->assertOk();
        $this->actingAs($this->admin)->get("/admin/pages/{$two->id}/branding")->assertOk();

        // Growth is refused: no new page, no reactivation.
        $this->assertCreateRefused('four');
        $this->reactivate($three)->assertForbidden();
        $this->assertNotNull($three->fresh()->archived_at);
    }

    public function test_an_expired_multi_page_only_key_is_refused_on_paste(): void
    {
        $key = $this->key([License::FEATURE_MULTI_PAGES], expiresAt: '2026-08-01');

        $this->actingAs($this->admin)->post('/admin/branding/activate', ['key' => $key])
            ->assertSessionHasErrors(['key' => 'That key ran out on 2026-08-01. Renew to get a new one.']);
        $this->assertNull(Setting::get('license.key'));
    }

    // ---------- refused keys ----------

    public function test_a_key_bound_to_another_host_is_refused_with_the_reason(): void
    {
        $key = $this->key([License::FEATURE_BRAND_PACK, License::FEATURE_MULTI_PAGES], issuedFor: 'status.other.example');

        $this->actingAs($this->admin)->post('/admin/branding/activate', ['key' => $key])
            ->assertSessionHasErrors(['key' => 'This key was issued for status.other.example, and this status page runs on '.License::thisHost().'. Keys are tied to the domain given at checkout.']);

        $this->assertNull(Setting::get('license.key'));
        $this->assertBrandPackRefused();
        $this->assertCreateRefused('second');
    }

    public function test_a_key_bound_elsewhere_that_is_already_stored_grants_nothing(): void
    {
        // The installation moved to a new host after activation.
        Setting::put('license.key', $this->key([License::FEATURE_BRAND_PACK, License::FEATURE_MULTI_PAGES], issuedFor: 'status.other.example'));
        Cache::forget('license.payload');

        $this->assertFalse(app(License::class)->has(License::FEATURE_BRAND_PACK));
        $this->assertSame(1, app(License::class)->statusPageLimit());
    }

    public function test_a_tampered_signature_is_refused(): void
    {
        [$payload] = explode('.', $this->key([License::FEATURE_BRAND_PACK]));
        $forged = $payload.'.'.rtrim(strtr(base64_encode(str_repeat("\0", SODIUM_CRYPTO_SIGN_BYTES)), '+/', '-_'), '=');

        $this->actingAs($this->admin)->post('/admin/branding/activate', ['key' => $forged])
            ->assertSessionHasErrors(['key' => 'That key is not valid for this product.']);

        // Written straight into the database it still counts for nothing.
        Setting::put('license.key', $forged);
        Cache::forget('license.payload');
        $this->assertFalse(app(License::class)->has(License::FEATURE_BRAND_PACK));
        $this->assertBrandPackRefused();
    }

    // ---------- helpers ----------

    protected function assertBrandPackRefused(): void
    {
        $this->actingAs($this->admin)->put('/admin/branding', [
            'name' => 'Northwind',
            'accent' => '#b8532f',
            'credit_hidden' => '1',
            'logo' => UploadedFile::fake()->image('logo.png', 400, 120),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame('Northwind', Setting::get('brand.name'), 'the free half still saves');
        $this->assertNull(Setting::get('brand.logo_path'));
        $this->assertSame('0', Setting::get('brand.credit_hidden', '0'));
        $this->get('/')->assertOk()->assertSee('Powered by Pharos');

        $this->actingAs($this->admin)
            ->put('/admin/mail-templates', ['template' => 'incident_opened', 'subject' => 'Custom', 'body' => 'Body'])
            ->assertSessionHasErrors(['template' => 'Editing the mail templates is part of the brand pack.']);
        $this->assertNull(Setting::get('mail.template.incident_opened.subject'));
    }

    protected function assertBrandPackWorks(): void
    {
        $this->actingAs($this->admin)->put('/admin/branding', [
            'name' => 'Northwind',
            'accent' => '#b8532f',
            'credit_hidden' => '1',
            'logo' => UploadedFile::fake()->image('logo.png', 400, 120),
        ])->assertRedirect()->assertSessionHasNoErrors();

        $logo = Setting::get('brand.logo_path');
        $this->assertNotNull($logo);
        $this->get('/')->assertOk()->assertSee($logo)->assertDontSee('Powered by Pharos');

        $this->actingAs($this->admin)
            ->put('/admin/mail-templates', ['template' => 'incident_opened', 'subject' => 'Custom {incident}', 'body' => 'Body'])
            ->assertSessionHasNoErrors();
        $this->assertSame('Custom {incident}', Setting::get('mail.template.incident_opened.subject'));
    }

    protected function assertCreateRefused(string $slug): void
    {
        $this->createPage($slug)->assertForbidden();
        $this->assertFalse(StatusPage::query()->where('slug', $slug)->exists());
    }

    protected function createPage(string $slug, bool $published = false): TestResponse
    {
        return $this->actingAs($this->admin)->post('/admin/pages', array_filter([
            'name' => ucfirst($slug),
            'slug' => $slug,
            'is_published' => $published ? '1' : null,
        ]));
    }

    protected function reactivate(StatusPage $page): TestResponse
    {
        return $this->actingAs($this->admin)->put("/admin/pages/{$page->id}", [
            'name' => $page->name,
            'slug' => $page->slug,
            'reactivate' => '1',
        ]);
    }

    /** Pastes the key on the Branding screen, the way a customer does. */
    protected function activate(array $features, ?int $limit = null, ?string $expiresAt = null): void
    {
        $this->actingAs($this->admin)
            ->post('/admin/branding/activate', ['key' => $this->key($features, $limit, $expiresAt)])
            ->assertSessionHasNoErrors();
        $this->assertNotNull(Setting::get('license.key'));
    }

    /** @param list<string> $features */
    protected function key(array $features, ?int $limit = null, ?string $expiresAt = null, ?string $issuedFor = null): string
    {
        $payload = array_filter([
            'product' => 'pharos',
            'issued_to' => 'customer@example.com',
            'features' => $features,
            'limits' => $limit === null ? null : ['status_pages' => $limit],
            'expires_at' => $expiresAt,
            'issued_for' => $issuedFor,
        ], fn ($value) => $value !== null);

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $b64 = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        return $b64($json).'.'.$b64(sodium_crypto_sign_detached($json, $this->secret));
    }
}
