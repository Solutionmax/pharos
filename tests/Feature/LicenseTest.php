<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\License;
use App\Services\MailTemplates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LicenseTest extends TestCase
{
    use RefreshDatabase;

    protected string $secret;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // A throwaway keypair per test run: the real private key never goes near CI.
        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);
        config(['pharos.license_public_key' => sodium_bin2hex(sodium_crypto_sign_publickey($pair))]);

        Cache::flush();

        $this->user = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('secret-secret'),
        ]);
    }

    public function test_the_shipped_config_carries_our_public_key(): void
    {
        // A customer pastes a key they paid for; if the public half only lives in
        // .env, verification fails on every fresh install and reads as a bad key.
        $shipped = require config_path('pharos.php');

        $this->assertNotSame('', $shipped['license_public_key'],
            'A release must ship the public key, not an empty string.');
        $this->assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES,
            strlen(sodium_hex2bin($shipped['license_public_key'])));
    }

    protected function sign(array $payload, ?string $secret = null): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $signature = sodium_crypto_sign_detached($json, $secret ?? $this->secret);

        $b64 = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        return $b64($json).'.'.$b64($signature);
    }

    protected function validKey(): string
    {
        return $this->sign([
            'product' => 'pharos',
            'issued_to' => 'klant@example.com',
            'features' => [License::FEATURE_BRAND_PACK],
            'issued_at' => '2026-08-25',
        ]);
    }

    public function test_a_signed_key_is_accepted(): void
    {
        $license = app(License::class);

        $this->assertTrue($license->store($this->validKey()));
        $this->assertTrue($license->has(License::FEATURE_BRAND_PACK));
        $this->assertSame('klant@example.com', $license->issuedTo());
    }

    public function test_a_key_signed_with_the_wrong_private_key_is_refused(): void
    {
        $other = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());

        $key = $this->sign([
            'product' => 'pharos',
            'issued_to' => 'pirate@example.com',
            'features' => [License::FEATURE_BRAND_PACK],
        ], $other);

        $this->assertNull(app(License::class)->verify($key));
    }

    public function test_editing_the_payload_breaks_the_signature(): void
    {
        // The whole point: a customer cannot add features by editing the key.
        $key = $this->sign([
            'product' => 'pharos',
            'issued_to' => 'klant@example.com',
            'features' => [],
        ]);

        [$payload, $signature] = explode('.', $key);
        $tampered = rtrim(strtr(base64_encode(json_encode([
            'product' => 'pharos',
            'issued_to' => 'klant@example.com',
            'features' => [License::FEATURE_BRAND_PACK],
        ], JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');

        $this->assertNull(app(License::class)->verify($tampered.'.'.$signature));
    }

    public function test_rubbish_is_refused_without_throwing(): void
    {
        $license = app(License::class);

        foreach (['', 'nonsense', 'a.b', str_repeat('x', 500), 'eyJ9.####'] as $key) {
            $this->assertNull($license->verify($key));
        }
    }

    public function test_a_key_for_another_product_is_refused(): void
    {
        $key = $this->sign(['product' => 'portalis', 'features' => [License::FEATURE_BRAND_PACK]]);

        $this->assertNull(app(License::class)->verify($key));
    }

    public function test_without_a_licence_the_footer_credit_cannot_be_hidden(): void
    {
        // Gated on the server: hiding the checkbox alone would be decoration.
        $this->actingAs($this->user)->put('/admin/branding', [
            'name' => 'Northwind',
            'accent' => '#b8532f',
            'credit_hidden' => 1,
        ])->assertRedirect();

        $this->assertSame('0', Setting::get('brand.credit_hidden', '0'));
        $this->get('/')->assertSee('Powered by Pharos');
    }

    public function test_with_a_licence_the_footer_credit_can_be_hidden(): void
    {
        $this->actingAs($this->user)
            ->post('/admin/branding/activate', ['key' => $this->validKey()])
            ->assertRedirect();

        $this->actingAs($this->user)->put('/admin/branding', [
            'name' => 'Northwind',
            'accent' => '#b8532f',
            'credit_hidden' => 1,
        ])->assertRedirect();

        $this->assertSame('1', Setting::get('brand.credit_hidden'));
        $this->get('/')->assertDontSee('Powered by Pharos');
    }

    public function test_paid_branding_falls_back_the_moment_the_key_is_gone(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('brand/logo.png', 'png');
        $license = app(License::class);
        $this->assertTrue($license->store($this->validKey()));

        Setting::put('brand.credit_hidden', '1');
        Setting::put('brand.logo_path', 'brand/logo.png');
        Setting::put('mail.template.incident_opened.subject', 'Custom subject');

        $this->get('/')->assertDontSee('Powered by Pharos')->assertSee('brand/logo.png');
        $this->assertSame('Custom subject', app(MailTemplates::class)->subject('incident_opened'));

        // Key removed: the paid parts stop showing, the uploads are kept for when it comes back.
        $license->forget();

        $this->get('/')->assertSee('Powered by Pharos')->assertDontSee('brand/logo.png');
        $this->assertNotSame('Custom subject', app(MailTemplates::class)->subject('incident_opened'));
        $this->assertSame('brand/logo.png', Setting::get('brand.logo_path'));

        $license->store($this->validKey());
        $this->get('/')->assertDontSee('Powered by Pharos')->assertSee('brand/logo.png');
    }

    public function test_a_key_bound_to_another_domain_is_refused_here(): void
    {
        config(['app.url' => 'https://status.other.example']);
        $key = $this->sign(['product' => 'pharos', 'issued_to' => 'k@example.com', 'features' => [License::FEATURE_BRAND_PACK], 'issued_at' => '2026-08-01', 'issued_for' => 'status.example.com']);

        $license = new License;
        $this->assertNull($license->verify($key));
        $this->assertFalse($license->store($key));
        $this->assertStringContainsString('status.example.com', (string) $license->whyNot($key));
        $this->assertStringContainsString('status.other.example', (string) $license->whyNot($key));
    }

    public function test_a_key_bound_to_this_domain_is_accepted_with_or_without_www(): void
    {
        $key = $this->sign(['product' => 'pharos', 'issued_to' => 'k@example.com', 'features' => [License::FEATURE_BRAND_PACK], 'issued_at' => '2026-08-01', 'issued_for' => 'Status.Example.com']);

        config(['app.url' => 'https://status.example.com']);
        $this->assertNotNull((new License)->verify($key));

        config(['app.url' => 'http://www.status.example.com/']);
        $this->assertNotNull((new License)->verify($key));
    }

    public function test_a_key_without_a_domain_works_anywhere(): void
    {
        config(['app.url' => 'https://anything.example']);
        $this->assertNotNull((new License)->verify($this->validKey()));
        $this->assertNull((new License)->whyNot($this->validKey()));
    }

    public function test_the_key_can_be_removed_from_the_branding_screen(): void
    {
        app(License::class)->store($this->validKey());
        $this->assertTrue(app(License::class)->has(License::FEATURE_BRAND_PACK));

        $this->actingAs($this->user)->post('/admin/branding/deactivate')->assertRedirect('/admin/branding');

        $this->assertNull(Setting::get('license.key'));
        $this->assertFalse(app(License::class)->has(License::FEATURE_BRAND_PACK));
    }

    public function test_the_sign_in_page_is_white_label_under_a_brand_pack(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('brand/acme.png', 'png');
        Setting::put('brand.name', 'Acme Hosting');
        Setting::put('brand.logo_path', 'brand/acme.png');
        Setting::put('brand.credit_hidden', '1');

        // Free: the page carries Pharos and shows the Pharos mark.
        $this->get('/admin/login')->assertOk()->assertSee('Powered by Pharos')->assertDontSee('brand/acme.png');

        // Brand pack with the credit hidden: their logo, their name, no Pharos anywhere.
        app(License::class)->store($this->validKey());
        $page = $this->get('/admin/login')->assertOk()->assertSee('brand/acme.png')->assertSee('Acme Hosting');
        // Visible text only: scripts and styles may keep their internal names.
        $visible = strip_tags(preg_replace('~<(script|style)\b[^>]*>.*?</\1>~si', '', $page->getContent()));
        $this->assertStringNotContainsStringIgnoringCase('pharos', $visible, 'a white-label page must not name Pharos');
    }

    public function test_activating_an_invalid_key_shows_an_error(): void
    {
        $this->actingAs($this->user)
            ->post('/admin/branding/activate', ['key' => 'not-a-key'])
            ->assertSessionHasErrors('key');
    }

    public function test_the_buy_button_is_shown_until_a_licence_is_active(): void
    {
        $this->actingAs($this->user)->get('/admin/branding')
            ->assertOk()->assertSee('Buy the brand pack');

        app(License::class)->store($this->validKey());

        $this->actingAs($this->user)->get('/admin/branding')
            ->assertOk()->assertDontSee('Buy the brand pack')->assertSee('klant@example.com');
    }

    // ---------- expiry ----------

    public function test_a_key_without_an_expiry_never_runs_out(): void
    {
        // Everything already handed out was signed without the claim; those keys
        // have to keep working.
        $this->assertNotNull((new License)->verify($this->validKey()));
    }

    public function test_an_expired_key_is_refused(): void
    {
        $key = $this->sign([
            'product' => 'pharos',
            'issued_to' => 'klant@example.com',
            'features' => [License::FEATURE_BRAND_PACK],
            'issued_at' => '2025-08-01',
            'expires_at' => '2026-08-01',
        ]);

        $this->assertNull((new License)->verify($key));
    }

    public function test_a_key_that_still_runs_is_accepted(): void
    {
        $key = $this->sign([
            'product' => 'pharos',
            'issued_to' => 'klant@example.com',
            'features' => [License::FEATURE_BRAND_PACK],
            'issued_at' => '2026-08-01',
            'expires_at' => now()->addMonths(6)->toDateString(),
        ]);

        $license = new License;
        $this->assertNotNull($license->verify($key));
        $this->assertTrue($license->store($key));
    }

    public function test_an_expired_key_keeps_the_brand_pack_and_loses_support(): void
    {
        // Supported is a yearly key that carries the Brand pack. When the year is
        // over the customer loses support, not the logo they paid for.
        Setting::put('license.key', $this->sign([
            'product' => 'pharos',
            'issued_to' => 'klant@example.com',
            'features' => [License::FEATURE_BRAND_PACK, 'supported'],
            'issued_at' => '2025-08-01',
            'expires_at' => '2026-08-01',
        ]));
        Cache::forget('license.payload');

        $license = new License;
        $this->assertTrue($license->expired());
        $this->assertTrue($license->has(License::FEATURE_BRAND_PACK));
        $this->assertFalse($license->has('supported'));
        $this->assertSame('klant@example.com', $license->issuedTo());
    }

    public function test_an_expired_key_with_the_brand_pack_can_still_be_pasted(): void
    {
        $key = $this->sign([
            'product' => 'pharos',
            'issued_to' => 'klant@example.com',
            'features' => [License::FEATURE_BRAND_PACK, 'supported'],
            'issued_at' => '2025-08-01',
            'expires_at' => '2026-08-01',
        ]);

        $license = new License;
        $this->assertTrue($license->store($key));
        $this->assertTrue($license->has(License::FEATURE_BRAND_PACK));
    }

    public function test_an_expired_key_without_a_perpetual_feature_is_refused_on_paste(): void
    {
        $key = $this->sign([
            'product' => 'pharos',
            'issued_to' => 'klant@example.com',
            'features' => ['supported'],
            'issued_at' => '2025-08-01',
            'expires_at' => '2026-08-01',
        ]);

        $this->assertFalse((new License)->store($key));
    }

    public function test_it_says_when_a_licence_is_nearly_up(): void
    {
        $license = new License;

        Setting::put('license.key', $this->sign([
            'product' => 'pharos', 'issued_to' => 'k@example.com',
            'features' => [License::FEATURE_BRAND_PACK],
            'issued_at' => '2026-08-01', 'expires_at' => now()->addDays(10)->toDateString(),
        ]));
        Cache::forget('license.payload');

        $this->assertSame(10, $license->daysLeft());
        $this->assertTrue($license->expiringSoon());
    }

    public function test_a_licence_with_room_left_is_not_nearly_up(): void
    {
        $license = new License;

        Setting::put('license.key', $this->sign([
            'product' => 'pharos', 'issued_to' => 'k@example.com',
            'features' => [License::FEATURE_BRAND_PACK],
            'issued_at' => '2026-08-01', 'expires_at' => now()->addMonths(6)->toDateString(),
        ]));
        Cache::forget('license.payload');

        $this->assertFalse($license->expiringSoon());

        // A perpetual key has nothing to count down.
        Setting::put('license.key', $this->validKey());
        Cache::forget('license.payload');
        $this->assertNull((new License)->daysLeft());
    }

    public function test_the_signing_command_can_put_a_term_on_a_key(): void
    {
        $keyFile = tempnam(sys_get_temp_dir(), 'phk');
        file_put_contents($keyFile, sodium_bin2hex($this->secret));

        $this->artisan('pharos:license:sign', [
            'email' => 'klant@example.com',
            '--key' => $keyFile,
            '--months' => 12,
        ])->assertSuccessful();

        unlink($keyFile);
    }

    public function test_the_branding_screen_shows_when_a_licence_runs_out(): void
    {
        Setting::put('license.key', $this->sign([
            'product' => 'pharos', 'issued_to' => 'klant@example.com',
            'features' => [License::FEATURE_BRAND_PACK],
            'issued_at' => '2026-08-01', 'expires_at' => now()->addDays(9)->toDateString(),
        ]));
        Cache::forget('license.payload');

        $this->actingAs($this->user)->get('/admin/branding')
            ->assertOk()
            ->assertSee('Runs out in 9 days');
    }

    public function test_a_perpetual_licence_says_so_rather_than_showing_a_date(): void
    {
        Setting::put('license.key', $this->validKey());
        Cache::forget('license.payload');

        $this->actingAs($this->user)->get('/admin/branding')
            ->assertOk()
            ->assertSee('no end date')
            ->assertDontSee('Runs out');
    }

    public function test_an_empty_env_line_does_not_wipe_the_shipped_public_key(): void
    {
        // A `PHAROS_LICENSE_PUBLIC_KEY=` line left behind in .env reads as "" and
        // an empty value is a value, so env()'s default never applied: every paid
        // key was refused on such an install.
        putenv('PHAROS_LICENSE_PUBLIC_KEY=');
        $_ENV['PHAROS_LICENSE_PUBLIC_KEY'] = $_SERVER['PHAROS_LICENSE_PUBLIC_KEY'] = '';

        try {
            $shipped = require config_path('pharos.php');
        } finally {
            putenv('PHAROS_LICENSE_PUBLIC_KEY');
            unset($_ENV['PHAROS_LICENSE_PUBLIC_KEY'], $_SERVER['PHAROS_LICENSE_PUBLIC_KEY']);
        }

        $this->assertSame(SODIUM_CRYPTO_SIGN_PUBLICKEYBYTES,
            strlen(sodium_hex2bin((string) $shipped['license_public_key'])));

        // The failure mode it guards against: with no public key, nothing verifies.
        config(['pharos.license_public_key' => '']);
        $this->assertNull((new License)->verify($this->validKey()));
    }

    // ---------- reading a key back ----------

    public function test_the_verify_command_shows_what_a_key_says(): void
    {
        $this->artisan('pharos:license:verify', ['key' => $this->validKey()])
            ->expectsOutputToContain('klant@example.com')
            ->expectsOutputToContain(License::FEATURE_BRAND_PACK)
            ->expectsOutputToContain('valid')
            ->assertSuccessful();
    }

    public function test_the_verify_command_rejects_a_tampered_key(): void
    {
        [, $signature] = explode('.', $this->validKey());
        $forged = rtrim(strtr(base64_encode(json_encode([
            'product' => 'pharos', 'issued_to' => 'pirate@example.com',
            'features' => [License::FEATURE_BRAND_PACK],
        ], JSON_UNESCAPED_SLASHES)), '+/', '-_'), '=');

        $this->artisan('pharos:license:verify', ['key' => $forged.'.'.$signature])
            ->expectsOutputToContain('invalid')
            ->assertFailed();
    }

    public function test_the_verify_command_tells_an_expired_key_from_a_forged_one(): void
    {
        // Support needs to see who a lapsed key belonged to, not just "no".
        $key = $this->sign([
            'product' => 'pharos', 'issued_to' => 'klant@example.com',
            'features' => [License::FEATURE_BRAND_PACK],
            'issued_at' => '2025-08-01', 'expires_at' => '2026-08-01',
        ]);

        $this->artisan('pharos:license:verify', ['key' => $key])
            ->expectsOutputToContain('klant@example.com')
            ->expectsOutputToContain('expired')
            ->assertFailed();
    }
}
