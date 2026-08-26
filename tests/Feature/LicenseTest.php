<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\License;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
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
}
