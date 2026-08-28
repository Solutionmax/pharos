<?php

namespace Tests\Feature;

use App\Models\RecoveryCode;
use App\Models\Setting;
use App\Models\User;
use App\Services\SafeHttp;
use App\Services\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SsoTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Raymon', 'email' => 'raymon@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);

        Setting::put('sso.enabled', true);
        Setting::put('sso.provider_name', 'Authentik');
        Setting::put('sso.issuer', 'https://id.example.net');
        Setting::put('sso.client_id', 'pharos');
        Setting::put('sso.client_secret', 'shhh');

        Cache::forget('sso.discovery.'.md5('https://id.example.net'));

        // id.example.net has no DNS entry, and the guard is meant to resolve for
        // real. Its own behaviour is covered in SafeHttpTest; that it is wired
        // into this flow is covered by the private-issuer test below.
        $this->swap(SafeHttp::class, new class extends SafeHttp
        {
            public function to(string $url): \Illuminate\Http\Client\PendingRequest
            {
                return Http::timeout(6)->withoutRedirecting();
            }
        });

        $this->fakeProvider();
    }

    /** Claims the stand-in provider will mint on the next exchange. */
    protected array $claimOverrides = [];

    /**
     * A stand-in provider: no network, no real identity provider in the suite.
     * The token stub is a closure because Http::fake() merges stubs rather than
     * replacing them, and the nonce only exists once the redirect has run.
     */
    protected function fakeProvider(): void
    {
        Http::fake([
            'id.example.net/.well-known/openid-configuration' => Http::response([
                'issuer' => 'https://id.example.net',
                'authorization_endpoint' => 'https://id.example.net/authorize',
                'token_endpoint' => 'https://id.example.net/token',
            ]),
            'id.example.net/token' => fn () => Http::response(['id_token' => $this->idToken($this->claimOverrides)]),
        ]);
    }

    protected function idToken(array $overrides = []): string
    {
        $claims = array_merge([
            'iss' => 'https://id.example.net',
            'aud' => ['pharos'],
            'nonce' => null,
            'exp' => time() + 300,
            'email' => 'raymon@example.com',
            'email_verified' => true,
            'name' => 'Raymon',
        ], $overrides);

        $b64 = fn (array $data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');

        return $b64(['alg' => 'RS256']).'.'.$b64($claims).'.signature-not-checked-back-channel';
    }

    /** Walks the redirect so state, nonce and verifier are in the session. */
    protected function begin(): void
    {
        $this->get('/admin/sso/redirect')->assertRedirect();
    }

    protected function returnFromProvider(array $claims = [], ?string $state = null): \Illuminate\Testing\TestResponse
    {
        // Read now: the controller pulls the nonce out of the session before it
        // exchanges the code, so the stub can no longer find it by then.
        $this->claimOverrides = array_merge(['nonce' => session('sso.nonce')], $claims);

        return $this->get('/admin/sso/callback?'.http_build_query([
            'code' => 'the-code',
            'state' => $state ?? session('sso.state'),
        ]));
    }

    // ---------- the button ----------

    public function test_the_login_page_offers_the_provider_when_it_is_configured(): void
    {
        $this->get('/admin/login')->assertOk()->assertSee('Authentik');
    }

    public function test_no_button_when_sso_is_off(): void
    {
        Setting::put('sso.enabled', false);

        $this->get('/admin/login')->assertOk()->assertDontSee('Authentik');
        $this->get('/admin/sso/redirect')->assertRedirect('/admin/login');
    }

    // ---------- signing in ----------

    public function test_a_known_account_is_signed_in(): void
    {
        $this->begin();
        $this->returnFromProvider()->assertRedirect('/admin/components');

        $this->assertAuthenticatedAs($this->user);
    }

    public function test_an_unknown_address_does_not_become_an_account(): void
    {
        $this->begin();
        $this->returnFromProvider(['email' => 'stranger@example.net'])->assertRedirect('/admin/login');

        $this->assertGuest();
        $this->assertSame(1, User::count());
    }

    public function test_an_unverified_email_is_refused(): void
    {
        // Otherwise anyone who can register at the provider claims a colleague's
        // address and walks into their account.
        $this->begin();
        $this->returnFromProvider(['email_verified' => false])->assertRedirect('/admin/login');

        $this->assertGuest();
    }

    public function test_a_token_from_another_issuer_is_refused(): void
    {
        $this->begin();
        $this->returnFromProvider(['iss' => 'https://evil.example.net'])->assertRedirect('/admin/login');

        $this->assertGuest();
    }

    public function test_a_token_for_another_application_is_refused(): void
    {
        $this->begin();
        $this->returnFromProvider(['aud' => ['some-other-app']])->assertRedirect('/admin/login');

        $this->assertGuest();
    }

    public function test_a_replayed_token_is_refused(): void
    {
        $this->begin();
        $this->returnFromProvider(['nonce' => 'a-nonce-from-an-older-sign-in'])->assertRedirect('/admin/login');

        $this->assertGuest();
    }

    public function test_an_expired_token_is_refused(): void
    {
        $this->begin();
        $this->returnFromProvider(['exp' => time() - 10])->assertRedirect('/admin/login');

        $this->assertGuest();
    }

    public function test_a_mismatched_state_is_refused(): void
    {
        $this->begin();
        $this->returnFromProvider([], 'not-the-state-we-sent')->assertRedirect('/admin/login');

        $this->assertGuest();
    }

    public function test_a_callback_without_a_started_flow_is_refused(): void
    {
        $this->get('/admin/sso/callback?code=x&state=y')->assertRedirect('/admin/login');

        $this->assertGuest();
    }

    // ---------- two-factor still applies ----------

    public function test_someone_with_two_factor_still_gets_the_code_screen(): void
    {
        // A second door that skips the gate makes the gate decorative.
        $this->user->forceFill([
            'totp_secret' => (new Totp)->secret(), 'totp_confirmed_at' => now(),
        ])->save();
        RecoveryCode::replaceFor($this->user);

        $this->begin();
        $this->returnFromProvider()->assertRedirect('/admin/two-factor');

        $this->assertGuest();
    }

    // ---------- audit ----------

    public function test_both_outcomes_are_written_to_the_audit_log(): void
    {
        $this->begin();
        $this->returnFromProvider();
        $this->assertSame(1, \App\Models\AuditEntry::where('action', 'sso.login')->count());

        $this->post('/admin/logout');
        $this->begin();
        $this->returnFromProvider(['email' => 'stranger@example.net']);
        $this->assertSame(1, \App\Models\AuditEntry::where('action', 'sso.rejected')->count());
    }

    public function test_an_issuer_on_our_own_network_never_gets_fetched(): void
    {
        // The administrator types this URL, so it is attacker-controlled input:
        // without the guard the server becomes a probe for networks it can reach
        // and the administrator cannot, cloud metadata included.
        $this->swap(SafeHttp::class, new SafeHttp);
        Setting::put('sso.issuer', 'http://169.254.169.254');
        Cache::forget('sso.discovery.'.md5('http://169.254.169.254'));

        $this->get('/admin/sso/redirect')->assertRedirect('/admin/login');

        $this->assertGuest();
        Http::assertNothingSent();
    }

    // ---------- the settings screen ----------

    public function test_the_settings_screen_is_for_administrators_only(): void
    {
        $member = User::create([
            'name' => 'Tom', 'email' => 'tom@example.net',
            'password' => Hash::make('correct-horse-battery'), 'role' => \App\Enums\UserRole::User,
        ]);

        $this->actingAs($member)->get('/admin/settings')->assertForbidden();

        $this->flushSession();
        $this->actingAs($this->user)->get('/admin/settings')->assertOk();
    }

    public function test_the_secret_is_never_rendered_back_into_the_form(): void
    {
        $this->actingAs($this->user)->get('/admin/settings')->assertOk()->assertDontSee('shhh');
    }

    public function test_an_empty_secret_box_leaves_the_stored_one_alone(): void
    {
        // The box is always empty, so submitting the form would otherwise wipe it.
        $this->actingAs($this->user)->put('/admin/sso', [
            'issuer' => 'https://id.example.net',
            'client_id' => 'pharos',
            'client_secret' => '',
            'enabled' => '1',
        ])->assertRedirect('/admin/settings#sso');

        $this->assertSame('shhh', Setting::get('sso.client_secret'));
    }

    public function test_it_will_not_switch_on_against_a_provider_it_cannot_reach(): void
    {
        // A button that only ever fails is worse than no button. A different host,
        // because Http::fake() merges stubs and setUp already answers for the other.
        Http::fake(['broken.example.net/*' => Http::response('nope', 500)]);
        Cache::forget('sso.discovery.'.md5('https://broken.example.net'));

        $this->actingAs($this->user)->put('/admin/sso', [
            'issuer' => 'https://broken.example.net',
            'client_id' => 'pharos',
            'enabled' => '1',
        ])->assertSessionHasErrors('issuer');

        $this->assertFalse((bool) Setting::get('sso.enabled'));
    }

    public function test_the_menu_hides_settings_from_a_user(): void
    {
        $member = User::create([
            'name' => 'Tom', 'email' => 'tom2@example.net',
            'password' => Hash::make('correct-horse-battery'), 'role' => \App\Enums\UserRole::User,
        ]);

        $this->actingAs($member)->get('/admin/components')
            ->assertOk()
            ->assertDontSee(route('admin.settings'), false);
    }

    public function test_an_internal_provider_works_once_its_host_is_vouched_for(): void
    {
        // Back to the real one: swap() in setUp replaced it with a stub.
        $this->app->bind(SafeHttp::class, fn () => new SafeHttp);

        Setting::put('sso.issuer', 'https://192.168.18.163');
        Cache::forget('sso.discovery.'.md5('https://192.168.18.163'));
        $this->actingAs($this->user)->get('/admin/settings')->assertOk();

        // Not vouched for yet.
        $this->assertFalse(app(SafeHttp::class)->isAllowed('192.168.18.163'));

        Setting::put('sso.internal_hosts', 'id.intern.example.net, 192.168.18.163');

        $this->assertTrue(app(SafeHttp::class)->isAllowed('192.168.18.163'));
        $this->assertSame('192.168.18.163', app(SafeHttp::class)->resolve('192.168.18.163'));
    }

    public function test_no_setting_opens_cloud_metadata(): void
    {
        $this->app->bind(SafeHttp::class, fn () => new SafeHttp);
        Setting::put('sso.internal_hosts', '169.254.169.254');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('link-local');

        app(SafeHttp::class)->resolve('169.254.169.254');
    }

    public function test_a_token_endpoint_on_link_local_is_never_contacted(): void
    {
        // The discovery document is the provider's word, not ours. One that names
        // 169.254.169.254 as its token endpoint would have this server post the
        // client secret to the cloud metadata service.
        $this->swap(SafeHttp::class, new SafeHttp(allowedHosts: []));
        Cache::put('sso.discovery.'.md5('https://id.example.net'), [
            'issuer' => 'https://id.example.net',
            'authorization_endpoint' => 'https://id.example.net/authorize',
            'token_endpoint' => 'http://169.254.169.254/token',
        ], 3600);
        // Were the request to get out, this stub would sign the user in.
        Http::fake(['169.254.169.254/*' => fn () => Http::response(['id_token' => $this->idToken($this->claimOverrides)])]);

        $this->begin();
        $this->returnFromProvider()->assertRedirect('/admin/login');

        $this->assertGuest();
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '169.254.169.254'));
    }

    /** The form says "stored encrypted"; the database must agree, and an older plain value must keep working. */
    public function test_the_client_secret_is_stored_encrypted_and_a_plain_legacy_value_still_reads(): void
    {
        $this->actingAs($this->user)->put('/admin/sso', [
            'issuer' => 'https://id.example.net', 'client_id' => 'pharos', 'client_secret' => 'plain-secret-123',
        ]);

        $stored = \App\Models\Setting::get('sso.client_secret');
        $this->assertNotSame('plain-secret-123', $stored);
        $this->assertSame('plain-secret-123', \Illuminate\Support\Facades\Crypt::decryptString($stored));
        $this->assertSame('plain-secret-123', app(\App\Services\Sso::class)->clientSecret());

        \App\Models\Setting::put('sso.client_secret', 'legacy-plain');
        $this->assertSame('legacy-plain', app(\App\Services\Sso::class)->clientSecret());
    }
}
