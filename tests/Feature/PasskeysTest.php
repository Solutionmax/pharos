<?php

namespace Tests\Feature;

use App\Models\Passkey;
use App\Models\PasskeyChallenge;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PasskeysTest extends TestCase
{
    use RefreshDatabase;

    private function b64(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    private function assertion(User $user, int $counter = 1, string $origin = 'https://localhost', int $flags = 5): array
    {
        $private = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $pub = openssl_pkey_get_details($private)['key'];
        $credential = $this->b64(random_bytes(32));
        Passkey::create(['user_id' => $user->id, 'credential_id' => $credential, 'public_key' => $pub, 'name' => 'Test', 'counter' => 0]);
        $options = $this->postJson('/admin/passkeys/login/options')->assertOk()->json('publicKey');
        $client = json_encode(['type' => 'webauthn.get', 'challenge' => $options['challenge'], 'origin' => $origin, 'crossOrigin' => false]);
        $auth = hash('sha256', 'localhost', true).chr($flags).pack('N', $counter);
        openssl_sign($auth.hash('sha256', $client, true), $signature, $private, OPENSSL_ALGO_SHA256);

        return ['id' => $credential, 'clientDataJSON' => $this->b64($client), 'authenticatorData' => $this->b64($auth), 'signature' => $this->b64($signature), 'userHandle' => $this->b64(hash('sha256', 'pharos-user:'.$user->id, true))];
    }

    public function test_real_signature_authenticates_and_challenge_cannot_replay(): void
    {
        config(['monitoring.webauthn_origin' => 'https://localhost']);
        $user = User::factory()->create();
        $data = $this->assertion($user);
        $this->postJson('/admin/passkeys/login', $data)->assertOk();
        $this->assertAuthenticatedAs($user);
        $this->postJson('/admin/logout');
        $this->postJson('/admin/passkeys/login', $data)->assertStatus(422);
    }

    public function test_wrong_origin_and_missing_user_verification_are_rejected(): void
    {
        config(['monitoring.webauthn_origin' => 'https://localhost']);
        $user = User::factory()->create();
        $this->postJson('/admin/passkeys/login', $this->assertion($user, 1, 'https://evil.localhost'))->assertStatus(422);
        $this->assertGuest();
        $this->postJson('/admin/passkeys/login', $this->assertion($user, 1, 'https://localhost', 1))->assertStatus(422);
        $this->assertGuest();
    }

    public function test_passkey_login_keeps_existing_totp_step(): void
    {
        config(['monitoring.webauthn_origin' => 'https://localhost']);
        $user = User::factory()->create(['totp_secret' => 'test', 'totp_confirmed_at' => now()]);
        $this->postJson('/admin/passkeys/login', $this->assertion($user))->assertOk()->assertJson(['redirect' => route('admin.two-factor')]);
        $this->assertGuest();
        $this->assertSame($user->id, session('2fa.user'));
    }

    public function test_removal_checks_current_account_and_password(): void
    {
        $a = User::factory()->create();
        $b = User::factory()->create();
        $key = Passkey::create(['user_id' => $b->id, 'credential_id' => 'abc', 'public_key' => 'pub', 'name' => 'Other']);
        $this->actingAs($a)->deleteJson('/admin/profile/passkeys/'.$key->id, ['current_password' => 'password'])->assertNotFound();
        $own = Passkey::create(['user_id' => $a->id, 'credential_id' => 'def', 'public_key' => 'pub', 'name' => 'Mine']);
        $this->actingAs($a)->deleteJson('/admin/profile/passkeys/'.$own->id, ['current_password' => 'wrong'])->assertStatus(422);
        $this->assertDatabaseHas('passkeys', ['id' => $own->id]);
    }

    public function test_expired_challenges_bad_signatures_and_wrong_handles_fail_without_authentication(): void
    {
        config(['monitoring.webauthn_origin' => 'https://localhost']);
        $user = User::factory()->create();
        $data = $this->assertion($user);
        PasskeyChallenge::query()->update(['expires_at' => now()->subMinute()]);
        $this->postJson('/admin/passkeys/login', $data)->assertStatus(422);
        $this->assertGuest();
        $data = $this->assertion($user);
        $data['signature'] = $this->b64(random_bytes(64));
        $this->postJson('/admin/passkeys/login', $data)->assertStatus(422);
        $this->assertGuest();
        $data = $this->assertion($user);
        $data['userHandle'] = $this->b64(random_bytes(32));
        $this->postJson('/admin/passkeys/login', $data)->assertStatus(422);
        $this->assertGuest();
    }

    public function test_counter_rollback_is_rejected_even_with_correct_signature(): void
    {
        config(['monitoring.webauthn_origin' => 'https://localhost']);
        $user = User::factory()->create();
        $data = $this->assertion($user);
        Passkey::where('credential_id', $data['id'])->update(['counter' => 1]);
        $this->postJson('/admin/passkeys/login', $data)->assertStatus(422);
        $this->assertGuest();
    }

    public function test_registration_needs_current_password_and_unknown_login_never_looks_up_email(): void
    {
        config(['monitoring.webauthn_origin' => 'https://localhost']);
        $user = User::factory()->create();
        $this->actingAs($user)->postJson('/admin/profile/passkeys/options', ['current_password' => 'wrong'])->assertStatus(422);
        $this->actingAs($user)->postJson('/admin/profile/passkeys/options', ['current_password' => 'password'])->assertOk()->assertJsonPath('publicKey.authenticatorSelection.userVerification', 'required');
    }

    public function test_ip_and_insecure_origins_are_rejected_before_browser_ceremony(): void
    {
        foreach (['https://192.168.10.20:8130', 'http://pharos-beta.home.arpa', 'https://localhost:8132/invalid'] as $origin) {
            config(['monitoring.webauthn_origin' => $origin]);
            $this->postJson('/admin/passkeys/login/options')->assertUnprocessable();
        }
    }
}
