<?php

namespace Tests\Feature;

use App\Enums\CheckType;
use App\Enums\ComponentStatus;
use App\Models\ApiToken;
use App\Models\Check;
use App\Models\Component;
use App\Models\Incident;
use App\Models\Setting;
use App\Models\WebhookEndpoint;
use App\Models\User;
use App\Services\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TeamAndIntegrationsTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Raymon',
            'email' => 'raymon@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);
    }

    // ---------- users ----------

    public function test_a_colleague_can_be_added_and_can_sign_in(): void
    {
        $this->actingAs($this->user)->post('/admin/users', [
            'name' => 'Tom',
            'email' => 'tom@example.com',
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-long-enough-password',
        ])->assertRedirect('/admin/users');

        $this->post('/admin/logout');
        $this->post('/admin/login', ['email' => 'tom@example.com', 'password' => 'a-long-enough-password'])
            ->assertRedirect('/admin/components');
    }

    public function test_a_short_password_is_refused(): void
    {
        $this->actingAs($this->user)->post('/admin/users', [
            'name' => 'Tom',
            'email' => 'tom@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ])->assertSessionHasErrors('password');

        $this->assertSame(1, User::count());
    }

    public function test_a_mistyped_repeat_is_refused(): void
    {
        $this->actingAs($this->user)->post('/admin/users', [
            'name' => 'Tom',
            'email' => 'tom@example.com',
            'password' => 'a-long-enough-password',
            'password_confirmation' => 'a-different-password',
        ])->assertSessionHasErrors('password');
    }

    public function test_you_cannot_delete_yourself(): void
    {
        User::create(['name' => 'Tom', 'email' => 'tom@example.com', 'password' => Hash::make('x-long-password')]);

        $this->actingAs($this->user)->delete("/admin/users/{$this->user->id}")
            ->assertSessionHasErrors('user');

        $this->assertSame(2, User::count());
    }

    public function test_changing_your_own_password_asks_for_the_current_one(): void
    {
        $this->actingAs($this->user)->put('/admin/profile/password', [
            'current_password' => 'not-the-right-one',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertSessionHasErrors('current_password');

        $this->assertTrue(Hash::check('correct-horse-battery', $this->user->fresh()->password));
    }

    public function test_the_last_account_cannot_be_removed(): void
    {
        // Locking everyone out of a self-hosted install is not recoverable
        // through the interface.
        $other = User::create(['name' => 'Tom', 'email' => 'tom@example.com', 'password' => Hash::make('x-long-password')]);

        $this->actingAs($this->user)->delete("/admin/users/{$other->id}")->assertRedirect();
        $this->assertSame(1, User::count());

        $this->actingAs($this->user)->delete("/admin/users/{$this->user->id}")
            ->assertSessionHasErrors('user');
        $this->assertSame(1, User::count());
    }

    public function test_changing_your_own_password_works(): void
    {
        $this->actingAs($this->user)->put('/admin/profile/password', [
            'current_password' => 'correct-horse-battery',
            'password' => 'a-brand-new-password',
            'password_confirmation' => 'a-brand-new-password',
        ])->assertRedirect();

        $this->post('/admin/logout');
        $this->post('/admin/login', ['email' => 'raymon@example.com', 'password' => 'a-brand-new-password'])
            ->assertRedirect('/admin/components');
    }

    // ---------- api tokens ----------

    public function test_a_token_is_shown_once_and_stored_hashed(): void
    {
        $response = $this->actingAs($this->user)
            ->post('/admin/integrations/tokens', ['name' => 'n8n'])
            ->assertRedirect('/admin/integrations');

        $plain = session('new_token');
        $this->assertNotNull($plain);
        $this->assertSame(40, strlen($plain));

        $token = ApiToken::first();
        $this->assertSame('n8n', $token->name);
        $this->assertNotSame($plain, $token->token_hash);
        $this->assertSame(hash('sha256', $plain), $token->token_hash);

        // Flash data survives exactly one request: the redirect target shows it,
        // and a reload no longer does.
        $this->actingAs($this->user)->get('/admin/integrations')->assertSee($plain);
        $this->actingAs($this->user)->get('/admin/integrations')->assertDontSee($plain);
    }

    public function test_revoking_a_token_stops_it_working(): void
    {
        [$token, $plain] = ApiToken::issue('n8n');
        $component = Component::create(['name' => 'web-01']);

        $this->withHeader('Authorization', "Bearer {$plain}")
            ->putJson("/api/v1/components/{$component->id}", ['status' => 4])->assertOk();

        $this->actingAs($this->user)->delete("/admin/integrations/tokens/{$token->id}")->assertRedirect();

        $this->withHeader('Authorization', "Bearer {$plain}")
            ->putJson("/api/v1/components/{$component->id}", ['status' => 1])->assertStatus(401);
    }

    // ---------- outgoing webhook ----------

    public function test_adding_a_notification_generates_a_signing_secret(): void
    {
        $this->actingAs($this->user)->post('/admin/integrations/notifications', [
            'label' => 'n8n',
            'url' => 'https://hooks.example.net/webhook/pharos',
            'format' => 'generic',
        ])->assertRedirect();

        $endpoint = WebhookEndpoint::sole();
        $this->assertSame('https://hooks.example.net/webhook/pharos', $endpoint->url);
        $this->assertTrue($endpoint->enabled);
        $this->assertSame(32, strlen(Setting::get('integrations.webhook_secret')));
    }

    public function test_a_notification_url_must_be_http_or_https(): void
    {
        $this->actingAs($this->user)->post('/admin/integrations/notifications', [
            'label' => 'bad', 'url' => 'javascript://x%0aalert(1)', 'format' => 'generic',
        ])->assertSessionHasErrors('url');

        $this->assertSame(0, WebhookEndpoint::count());
    }

    public function test_publishing_an_incident_fires_a_signed_webhook(): void
    {
        Http::fake();
        WebhookEndpoint::create(['label' => 'n8n', 'url' => 'https://hooks.example.net/webhook/pharos', 'format' => 'generic']);
        Setting::put('integrations.webhook_secret', 'a-secret');

        $component = Component::create(['name' => 'web-01']);

        $this->actingAs($this->user)->post('/admin/incidents', [
            'name' => 'Mail queue backed up',
            'message' => 'Looking into it.',
            'status' => 1,
            'impact' => 'major',
            'visibility' => 'public',
            'components' => [$component->id => 3],
        ])->assertRedirect();

        Http::assertSent(function ($request) {
            $body = $request->body();
            $payload = json_decode($body, true);

            return $request->url() === 'https://hooks.example.net/webhook/pharos'
                && $payload['event'] === 'incident.created'
                && $payload['incident']['name'] === 'Mail queue backed up'
                && $payload['incident']['components'] === ['web-01']
                && $request->header('X-Pharos-Signature')[0] === hash_hmac('sha256', $body, 'a-secret');
        });
    }

    /**
     * The whole reason formats exist: Slack answers 400 to anything that is not
     * its own shape, so a raw payload reaches nobody and fails silently.
     */
    public function test_slack_gets_slack_shaped_json_and_no_signature(): void
    {
        Http::fake();
        WebhookEndpoint::create(['label' => '#ops', 'url' => 'https://hooks.slack.com/services/T/B/x', 'format' => 'slack']);
        Setting::put('integrations.webhook_secret', 'a-secret');

        $component = Component::create(['name' => 'web-01']);

        $this->actingAs($this->user)->post('/admin/incidents', [
            'name' => 'Mail queue backed up',
            'message' => 'Looking into it.',
            'status' => 1,
            'impact' => 'major',
            'visibility' => 'public',
            'components' => [$component->id => 3],
        ])->assertRedirect();

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);

            return $request->url() === 'https://hooks.slack.com/services/T/B/x'
                && str_contains($payload['text'], 'Mail queue backed up')
                && $payload['blocks'][0]['type'] === 'section'
                // Slack drops unknown headers; signing it would only be theatre.
                && $request->header('X-Pharos-Signature') === [];
        });
    }

    public function test_teams_gets_an_adaptive_card_envelope(): void
    {
        Http::fake();
        WebhookEndpoint::create(['label' => 'Ops channel', 'url' => 'https://prod.westeurope.logic.azure.com/workflows/x', 'format' => 'teams']);

        $this->actingAs($this->user)->post('/admin/incidents', [
            'name' => 'Mail queue backed up',
            'message' => 'Looking into it.',
            'status' => 1,
            'impact' => 'major',
            'visibility' => 'public',
        ])->assertRedirect();

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);

            return $payload['type'] === 'message'
                && $payload['attachments'][0]['contentType'] === 'application/vnd.microsoft.card.adaptive'
                && $payload['attachments'][0]['content']['type'] === 'AdaptiveCard'
                && str_contains($payload['attachments'][0]['content']['body'][0]['text'], 'Mail queue backed up');
        });
    }

    public function test_every_enabled_destination_is_notified(): void
    {
        Http::fake();
        WebhookEndpoint::create(['label' => 'n8n', 'url' => 'https://hooks.example.net/a', 'format' => 'generic']);
        WebhookEndpoint::create(['label' => '#ops', 'url' => 'https://hooks.slack.com/b', 'format' => 'slack']);
        WebhookEndpoint::create(['label' => 'old', 'url' => 'https://hooks.example.net/c', 'format' => 'generic', 'enabled' => false]);

        $this->actingAs($this->user)->post('/admin/incidents', [
            'name' => 'Mail queue backed up', 'message' => 'Looking into it.',
            'status' => 1, 'impact' => 'minor', 'visibility' => 'public',
        ])->assertRedirect();

        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request) => $request->url() === 'https://hooks.example.net/c');
    }

    public function test_a_test_send_records_what_came_back(): void
    {
        Http::fake(['*' => Http::response('ok', 200)]);
        $endpoint = WebhookEndpoint::create(['label' => '#ops', 'url' => 'https://hooks.slack.com/b', 'format' => 'slack']);

        $this->actingAs($this->user)
            ->post("/admin/integrations/notifications/{$endpoint->id}/test")
            ->assertRedirect('/admin/integrations');

        $endpoint->refresh();
        $this->assertSame(200, $endpoint->last_status);
        $this->assertNull($endpoint->last_error);
        $this->assertNotNull($endpoint->last_attempt_at);
    }

    public function test_a_refused_delivery_is_recorded_on_the_endpoint(): void
    {
        Http::fake(['*' => Http::response('invalid_payload', 400)]);
        $endpoint = WebhookEndpoint::create(['label' => '#ops', 'url' => 'https://hooks.slack.com/b', 'format' => 'slack']);

        $this->actingAs($this->user)->post("/admin/integrations/notifications/{$endpoint->id}/test")->assertRedirect();

        $endpoint->refresh();
        $this->assertSame(400, $endpoint->last_status);
        // The status is what diagnoses it; the body is the receiver's, could be
        // anything, and would be stored and shown in the admin.
        $this->assertSame('HTTP 400', $endpoint->last_error);
        $this->assertStringNotContainsString('invalid_payload', $endpoint->last_error);
    }

    public function test_no_webhook_is_sent_when_none_is_configured(): void
    {
        Http::fake();

        $this->actingAs($this->user)->post('/admin/incidents', [
            'name' => 'Mail queue backed up',
            'message' => 'Looking into it.',
            'status' => 1,
            'impact' => 'minor',
            'visibility' => 'public',
        ])->assertRedirect();

        Http::assertNothingSent();
        $this->assertSame(1, Incident::count());
    }

    public function test_a_failing_webhook_does_not_block_publishing(): void
    {
        // Mid-outage, a broken receiver must not stop you telling customers.
        Http::fake(fn () => throw new \RuntimeException('connection refused'));
        WebhookEndpoint::create(['label' => 'down', 'url' => 'https://hooks.example.net/down', 'format' => 'generic']);

        $this->actingAs($this->user)->post('/admin/incidents', [
            'name' => 'Mail queue backed up',
            'message' => 'Looking into it.',
            'status' => 1,
            'impact' => 'minor',
            'visibility' => 'public',
        ])->assertRedirect('/admin/incidents');

        $this->assertSame(1, Incident::count());
    }

    public function test_an_incident_opened_by_a_check_fires_the_webhook(): void
    {
        // This is the headline feature. It used to reach nobody.
        Http::fake();
        WebhookEndpoint::create(['label' => 'n8n', 'url' => 'https://hooks.example.net/webhook/pharos', 'format' => 'generic']);
        Setting::put('integrations.webhook_secret', 'a-secret');

        $component = Component::create(['name' => 'web-06']);
        $check = Check::create([
            'component_id' => $component->id,
            'type' => CheckType::Http,
            'target' => 'https://example.net/',
            'retries' => 1,
        ]);

        $runner = new \App\Services\CheckRunner(
            new class extends \App\Services\Probe
            {
                public function run(Check $check): \App\Services\ProbeResult
                {
                    return new \App\Services\ProbeResult(false, 12, 'No response');
                }
            },
            app(\App\Services\OutgoingWebhook::class),
        );
        $runner->runOne($check);

        Http::assertSent(function ($request) {
            $payload = json_decode($request->body(), true);

            return $payload['event'] === 'incident.created'
                && $payload['incident']['name'] === 'web-06 unreachable'
                && $payload['incident']['components'] === ['web-06'];
        });
    }

    public function test_creating_an_incident_over_the_api_fires_the_webhook(): void
    {
        Http::fake();
        WebhookEndpoint::create(['label' => 'n8n', 'url' => 'https://hooks.example.net/webhook/pharos', 'format' => 'generic']);
        [, $plain] = ApiToken::issue('n8n');

        $this->withHeader('Authorization', "Bearer {$plain}")
            ->postJson('/api/v1/incidents', [
                'name' => 'Mail queue backed up',
                'status' => 'investigating',
                'message' => 'Looking into it.',
            ])->assertCreated();

        Http::assertSent(fn ($request) => json_decode($request->body(), true)['event'] === 'incident.created');
    }

    public function test_resolving_over_the_api_puts_components_back(): void
    {
        // The admin already did this; the API leaving them red made the page lie.
        $component = Component::create(['name' => 'web-06', 'status' => ComponentStatus::MajorOutage]);
        [, $plain] = ApiToken::issue('n8n');

        $incident = \App\Models\Incident::create([
            'name' => 'web-06 unreachable',
            'status' => \App\Enums\IncidentStatus::Investigating,
            'occurred_at' => now(),
        ]);
        $incident->components()->attach($component->id, ['status' => ComponentStatus::MajorOutage->value]);

        $this->withHeader('Authorization', "Bearer {$plain}")
            ->postJson("/api/v1/incidents/{$incident->id}/updates", [
                'status' => 'resolved',
                'message' => 'Back up.',
            ])->assertOk();

        $this->assertSame(ComponentStatus::Operational, $component->fresh()->status);
    }

    public function test_an_explicit_component_status_still_wins_on_resolve(): void
    {
        $component = Component::create(['name' => 'web-06', 'status' => ComponentStatus::MajorOutage]);
        [, $plain] = ApiToken::issue('n8n');

        $incident = \App\Models\Incident::create([
            'name' => 'web-06 unreachable',
            'status' => \App\Enums\IncidentStatus::Investigating,
            'occurred_at' => now(),
        ]);
        $incident->components()->attach($component->id, ['status' => ComponentStatus::MajorOutage->value]);

        $this->withHeader('Authorization', "Bearer {$plain}")
            ->postJson("/api/v1/incidents/{$incident->id}/updates", [
                'status' => 'resolved',
                'message' => 'Closed, but this one is still degraded.',
                'components' => [$component->id => 'degraded'],
            ])->assertOk();

        $this->assertSame(ComponentStatus::PerformanceIssues, $component->fresh()->status);
    }

    public function test_heartbeat_push_urls_are_listed(): void
    {
        $component = Component::create(['name' => 'Backups', 'source' => 'heartbeat']);
        Check::create([
            'component_id' => $component->id,
            'type' => CheckType::Heartbeat,
            'target' => 'hb_abcdefghijklmnop',
        ]);

        $this->actingAs($this->user)->get('/admin/integrations')
            ->assertOk()
            ->assertSee('Backups')
            ->assertSee('/api/v1/heartbeat/hb_abcdefghijklmnop');
    }

    // ---------- branding uploads ----------

    public function test_a_logo_cannot_be_uploaded_without_a_licence(): void
    {
        Storage::fake('public');

        $this->actingAs($this->user)->put('/admin/branding', [
            'name' => 'Northwind',
            'accent' => '#b8532f',
            'logo' => UploadedFile::fake()->image('logo.png', 400, 120),
        ])->assertRedirect();

        $this->assertNull(Setting::get('brand.logo_path'));
        // The free half still applied.
        $this->assertSame('Northwind', Setting::get('brand.name'));
    }

    public function test_a_licensed_install_can_upload_a_logo_and_favicon(): void
    {
        Storage::fake('public');
        $this->licence();

        $this->actingAs($this->user)->put('/admin/branding', [
            'name' => 'Northwind',
            'accent' => '#b8532f',
            'logo' => UploadedFile::fake()->image('logo.png', 400, 120),
            'favicon' => UploadedFile::fake()->image('icon.png', 64, 64),
        ])->assertRedirect();

        $logo = Setting::get('brand.logo_path');
        $this->assertNotNull($logo);
        Storage::disk('public')->assertExists($logo);
        Storage::disk('public')->assertExists(Setting::get('brand.favicon_path'));

        $this->get('/')->assertOk()->assertSee($logo);
    }

    /**
     * A navy logo on a transparent PNG vanishes on the dark theme. The paid half
     * therefore takes an optional second logo for dark mode; the light one stays
     * the fallback so nobody is forced to upload two.
     */
    public function test_a_licensed_install_can_add_a_logo_for_dark_mode(): void
    {
        Storage::fake('public');
        $this->licence();

        $this->actingAs($this->user)->put('/admin/branding', [
            'name' => 'Northwind',
            'accent' => '#b8532f',
            'logo' => UploadedFile::fake()->image('logo.png', 400, 120),
            'logo_dark' => UploadedFile::fake()->image('logo-dark.png', 400, 120),
        ])->assertRedirect();

        $dark = Setting::get('brand.logo_dark_path');
        $this->assertNotNull($dark);
        Storage::disk('public')->assertExists($dark);

        // Both are in the page: CSS picks one per theme.
        $this->get('/')->assertOk()
            ->assertSee(Setting::get('brand.logo_path'))
            ->assertSee($dark)
            ->assertSee('logo-dark', false);

        $this->actingAs($this->user)->put('/admin/branding', [
            'name' => 'Northwind', 'accent' => '#b8532f', 'remove_logo_dark' => '1',
        ])->assertRedirect();

        $this->assertNull(Setting::get('brand.logo_dark_path'));
        Storage::disk('public')->assertMissing($dark);
        $this->get('/')->assertOk()->assertDontSee('logo-dark', false);
    }

    public function test_replacing_a_logo_deletes_the_old_file(): void
    {
        Storage::fake('public');
        $this->licence();

        $this->actingAs($this->user)->put('/admin/branding', [
            'name' => 'Northwind', 'accent' => '#b8532f',
            'logo' => UploadedFile::fake()->image('one.png', 400, 120),
        ]);
        $first = Setting::get('brand.logo_path');

        $this->actingAs($this->user)->put('/admin/branding', [
            'name' => 'Northwind', 'accent' => '#b8532f',
            'logo' => UploadedFile::fake()->image('two.png', 400, 120),
        ]);

        Storage::disk('public')->assertMissing($first);
        Storage::disk('public')->assertExists(Setting::get('brand.logo_path'));
    }

    public function test_an_svg_logo_is_refused(): void
    {
        // SVG can carry script and this file is served to every visitor.
        Storage::fake('public');
        $this->licence();

        $this->actingAs($this->user)->put('/admin/branding', [
            'name' => 'Northwind',
            'accent' => '#b8532f',
            'logo' => UploadedFile::fake()->create('logo.svg', 4, 'image/svg+xml'),
        ])->assertSessionHasErrors('logo');

        $this->assertNull(Setting::get('brand.logo_path'));
    }

    public function test_an_oversized_logo_is_refused(): void
    {
        Storage::fake('public');
        $this->licence();

        $this->actingAs($this->user)->put('/admin/branding', [
            'name' => 'Northwind',
            'accent' => '#b8532f',
            'logo' => UploadedFile::fake()->image('huge.png', 400, 120)->size(900),
        ])->assertSessionHasErrors('logo');
    }

    protected function licence(): void
    {
        $pair = sodium_crypto_sign_keypair();
        config(['pharos.license_public_key' => sodium_bin2hex(sodium_crypto_sign_publickey($pair))]);

        $payload = json_encode([
            'product' => 'pharos',
            'issued_to' => 'raymon@example.com',
            'features' => [License::FEATURE_BRAND_PACK],
        ], JSON_UNESCAPED_SLASHES);

        $b64 = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
        $key = $b64($payload).'.'.$b64(sodium_crypto_sign_detached($payload, sodium_crypto_sign_secretkey($pair)));

        app(License::class)->store($key);
    }

    public function test_a_notification_may_not_point_at_this_machine_or_link_local(): void
    {
        // 169.254.169.254 hands out cloud credentials to whoever asks; 127.0.0.1
        // is this very box. The rest of the LAN stays open — an n8n next door is
        // the common case.
        foreach ([
            'http://169.254.169.254/latest/meta-data',
            'http://127.0.0.1:8080/x',
            'http://[::ffff:169.254.169.254]/x',
            'http://[::1]/x',
        ] as $url) {
            $this->actingAs($this->user)->post('/admin/integrations/notifications', [
                'label' => 'bad', 'url' => $url, 'format' => 'generic',
            ])->assertSessionHasErrors('url');
        }

        $this->assertSame(0, WebhookEndpoint::count());

        foreach (['http://192.168.18.161:5678/webhook/pharos', 'https://1.1.1.1/hook'] as $url) {
            $this->actingAs($this->user)->post('/admin/integrations/notifications', [
                'label' => 'fine', 'url' => $url, 'format' => 'generic',
            ])->assertSessionHasNoErrors();
        }

        $this->assertSame(2, WebhookEndpoint::count());
    }

    public function test_delivery_to_link_local_is_refused_without_a_request(): void
    {
        // Checked again at send time, not only when saved: a name can be
        // re-pointed at the metadata service in between (DNS rebinding).
        Http::fake();
        $endpoint = WebhookEndpoint::create([
            'label' => 'rebound', 'url' => 'http://169.254.169.254/latest/meta-data', 'format' => 'generic',
        ]);

        $this->assertFalse(app(\App\Services\OutgoingWebhook::class)->test($endpoint));

        Http::assertNothingSent();
        $endpoint->refresh();
        $this->assertStringContainsString('never', $endpoint->last_error);
    }

    public function test_a_masked_url_keeps_its_port(): void
    {
        $e = new \App\Models\WebhookEndpoint(['url' => 'http://192.168.18.162:8799/hook/very-long-path']);
        $this->assertSame('http://192.168.18.162:8799/hook/ve…', $e->maskedUrl());
    }
}
