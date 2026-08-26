<?php

namespace Tests\Feature;

use App\Enums\CheckType;
use App\Enums\ComponentStatus;
use App\Models\ApiToken;
use App\Models\Check;
use App\Models\Component;
use App\Models\Incident;
use App\Models\Setting;
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
        $this->actingAs($this->user)->put("/admin/users/{$this->user->id}/password", [
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

    public function test_saving_a_webhook_url_generates_a_signing_secret(): void
    {
        $this->actingAs($this->user)->put('/admin/integrations/webhook', [
            'webhook_url' => 'https://hooks.example.net/webhook/pharos',
        ])->assertRedirect();

        $this->assertSame('https://hooks.example.net/webhook/pharos', Setting::get('integrations.webhook_url'));
        $this->assertSame(32, strlen(Setting::get('integrations.webhook_secret')));
    }

    public function test_publishing_an_incident_fires_a_signed_webhook(): void
    {
        Http::fake();
        Setting::put('integrations.webhook_url', 'https://hooks.example.net/webhook/pharos');
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
        Setting::put('integrations.webhook_url', 'https://hooks.example.net/down');

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
        Setting::put('integrations.webhook_url', 'https://hooks.example.net/webhook/pharos');
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
        Setting::put('integrations.webhook_url', 'https://hooks.example.net/webhook/pharos');
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
}
