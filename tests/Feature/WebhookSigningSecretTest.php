<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditEntry;
use App\Models\Setting;
use App\Models\StatusPage;
use App\Models\StatusPageSetting;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\OutgoingWebhook;
use App\Services\PageContext;
use App\Services\WebhookSecret;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * F4: the webhook signing secret is stored encrypted, shown masked until an
 * administrator asks for it, and deliveries carry a timestamped signature
 * next to the body-only one existing receivers check.
 */
class WebhookSigningSecretTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
    }

    protected function rawSecret(?int $pageId = null): ?string
    {
        return StatusPageSetting::query()->where('status_page_id', $pageId ?? StatusPage::defaultId())
            ->where('key', WebhookSecret::KEY)->value('value');
    }

    protected function generic(): WebhookEndpoint
    {
        return WebhookEndpoint::create(['label' => 'n8n', 'url' => 'https://203.0.113.10/hook', 'format' => 'generic', 'enabled' => true]);
    }

    public function test_the_secret_is_stored_encrypted(): void
    {
        $secret = app(WebhookSecret::class)->rotate();

        $this->assertSame(32, strlen($secret));
        $this->assertStringNotContainsString($secret, (string) $this->rawSecret());
        $this->assertSame($secret, Crypt::decryptString((string) $this->rawSecret()));
        $this->assertSame($secret, app(WebhookSecret::class)->get());
    }

    public function test_a_delivery_carries_the_old_signature_and_a_timestamped_one(): void
    {
        Http::fake(['*' => Http::response('', 204)]);
        $this->travelTo(now()->setTimestamp(1_800_000_000));
        $secret = app(WebhookSecret::class)->rotate();

        $this->assertTrue(app(OutgoingWebhook::class)->test($this->generic()));

        Http::assertSent(function (Request $request) use ($secret) {
            $body = $request->body();

            return $request->header('X-Pharos-Signature')[0] === hash_hmac('sha256', $body, $secret)
                && $request->header('X-Pharos-Timestamp')[0] === '1800000000'
                && $request->header('X-Pharos-Signature-V2')[0] === hash_hmac('sha256', '1800000000.'.$body, $secret);
        });
    }

    public function test_chat_destinations_stay_unsigned(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        app(WebhookSecret::class)->rotate();

        app(OutgoingWebhook::class)->test(WebhookEndpoint::create(['label' => 'Slack', 'url' => 'https://203.0.113.11/services/x', 'format' => 'slack', 'enabled' => true]));

        Http::assertSent(fn (Request $request) => $request->header('X-Pharos-Signature') === [] && $request->header('X-Pharos-Timestamp') === []);
    }

    public function test_a_plain_secret_from_before_the_update_is_read_once_and_then_stored_encrypted(): void
    {
        Http::fake(['*' => Http::response('', 204)]);
        // As 1.1 stored it; the cache may still hold this after the update.
        Setting::put(WebhookSecret::KEY, 'legacyPlainSecret0123456789abcdef');

        app(OutgoingWebhook::class)->test($this->generic());

        Http::assertSent(fn (Request $request) => $request->header('X-Pharos-Signature')[0] === hash_hmac('sha256', $request->body(), 'legacyPlainSecret0123456789abcdef'));
        $this->assertSame('legacyPlainSecret0123456789abcdef', Crypt::decryptString((string) $this->rawSecret()));
        $this->assertSame('legacyPlainSecret0123456789abcdef', app(WebhookSecret::class)->get());
    }

    public function test_a_secret_encrypted_with_another_app_key_is_replaced_not_used(): void
    {
        $other = new Encrypter(random_bytes(32), 'aes-256-cbc');
        Setting::put(WebhookSecret::KEY, $other->encryptString('from-another-install'));

        $this->assertNull(app(WebhookSecret::class)->get());
        $new = app(WebhookSecret::class)->ensure();

        $this->assertNotSame('from-another-install', $new);
        $this->assertSame($new, app(WebhookSecret::class)->get());
    }

    public function test_the_update_encrypts_every_pages_secret_and_can_be_undone(): void
    {
        $beta = StatusPage::create(['name' => 'Beta', 'slug' => 'beta']);
        foreach ([StatusPage::defaultId() => 'alphaSecret0123456789abcdefghijkl', $beta->id => 'betaSecret0123456789abcdefghijklm'] as $pageId => $plain) {
            StatusPageSetting::create(['status_page_id' => $pageId, 'key' => WebhookSecret::KEY, 'value' => $plain]);
            // A cache filled by 1.1 must not outlive the migration.
            Cache::forever('status_page.'.$pageId.'.setting.'.WebhookSecret::KEY, $plain);
        }
        $migration = require database_path('migrations/2026_11_15_140000_encrypt_webhook_signing_secrets.php');

        $migration->up();
        $first = $this->rawSecret($beta->id);
        $this->assertSame('betaSecret0123456789abcdefghijklm', Crypt::decryptString((string) $first));
        $this->assertSame('alphaSecret0123456789abcdefghijkl', Crypt::decryptString((string) $this->rawSecret()));
        $this->assertNull(Cache::get('status_page.'.$beta->id.'.setting.'.WebhookSecret::KEY));
        $this->assertSame('betaSecret0123456789abcdefghijklm', app(PageContext::class)->run($beta->id, fn () => app(WebhookSecret::class)->get()));

        // Twice is the same as once.
        $migration->up();
        $this->assertSame($first, $this->rawSecret($beta->id));

        $migration->down();
        $this->assertSame('betaSecret0123456789abcdefghijklm', $this->rawSecret($beta->id));
        $this->assertSame('alphaSecret0123456789abcdefghijkl', $this->rawSecret());
    }

    public function test_the_admin_shows_the_secret_masked_until_it_is_revealed(): void
    {
        $secret = app(WebhookSecret::class)->rotate();

        $this->actingAs($this->admin)->get('/admin/integrations/send-out')->assertOk()
            ->assertDontSee($secret)
            ->assertSee(WebhookSecret::mask($secret))
            ->assertSee('Reveal secret')
            ->assertSee('X-Pharos-Signature-V2');

        $revealed = $this->actingAs($this->admin)->postJson('/admin/integrations/webhook/reveal')->assertOk()
            ->assertExactJson(['secret' => $secret]);
        $this->assertStringContainsString('no-store', (string) $revealed->headers->get('Cache-Control'));

        // Without JavaScript the form post shows the screen with the secret in it.
        $this->actingAs($this->admin)->post('/admin/integrations/webhook/reveal')->assertOk()
            ->assertSee($secret)->assertDontSee('Reveal secret');

        $this->assertSame(2, AuditEntry::where('action', 'webhook_secret.revealed')->count());
    }

    public function test_only_page_administrators_can_reveal_it(): void
    {
        $secret = app(WebhookSecret::class)->rotate();
        $page = StatusPage::default();
        $editor = User::factory()->create(['role' => UserRole::User]);
        $editor->statusPages()->attach($page->id, ['role' => 'editor']);

        $this->actingAs($editor)->postJson('/admin/pages/'.$page->id.'/integrations/webhook/reveal')->assertForbidden();
        $this->actingAs($editor)->get('/admin/pages/'.$page->id.'/integrations/send-out')->assertOk()
            ->assertDontSee($secret)->assertDontSee(WebhookSecret::mask($secret));

        $pageAdmin = User::factory()->create(['role' => UserRole::User]);
        $pageAdmin->statusPages()->attach($page->id, ['role' => 'admin']);
        $this->actingAs($pageAdmin)->postJson('/admin/pages/'.$page->id.'/integrations/webhook/reveal')->assertOk()
            ->assertJsonPath('secret', $secret);
    }

    public function test_reveal_without_a_secret_is_not_found(): void
    {
        $this->actingAs($this->admin)->postJson('/admin/integrations/webhook/reveal')->assertNotFound();
        $this->assertSame(0, DB::table('audit_log')->where('action', 'webhook_secret.revealed')->count());
    }

    public function test_rotating_stores_a_new_encrypted_secret(): void
    {
        $old = app(WebhookSecret::class)->rotate();

        $this->actingAs($this->admin)->post('/admin/integrations/webhook/rotate')->assertRedirect();

        $new = app(WebhookSecret::class)->get();
        $this->assertNotSame($old, $new);
        $this->assertStringNotContainsString((string) $new, (string) $this->rawSecret());
    }
}
