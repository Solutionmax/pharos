<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ApiToken;
use App\Models\AuditEntry;
use App\Models\Passkey;
use App\Models\Setting;
use App\Models\StatusPage;
use App\Models\User;
use App\Services\SafeHttp;
use App\Services\Sso;
use App\Services\TokenAccess;
use App\Services\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/** Review 15 nov 2026, C6: the small points around signing in and page access. */
class SignInEdgesTest extends TestCase
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
    }

    // ---------- the TOTP replay guard is one conditional UPDATE ----------

    public function test_a_code_raced_in_by_a_second_request_is_refused(): void
    {
        $totp = new Totp;
        $secret = $totp->secret();
        $this->user->forceFill(['totp_secret' => $secret, 'totp_confirmed_at' => now()])->save();
        $step = intdiv(time(), Totp::PERIOD);

        $this->post('/admin/login', ['email' => 'raymon@example.com', 'password' => 'correct-horse-battery'])
            ->assertRedirect('/admin/two-factor');

        // The other request wins the race: it marks the step used right after
        // this one read the account, so this one still sees the old value.
        User::retrieved(function (User $user) use ($step) {
            DB::table('users')->where('id', $user->id)->update(['totp_last_step' => $step]);
        });

        $this->post('/admin/two-factor', ['code' => $totp->at($secret, $step)])
            ->assertSessionHasErrors(['code' => 'That code has already been used. Wait for the next one.']);

        $this->assertGuest();
        $this->assertSame(1, AuditEntry::where('action', 'auth.2fa_failed')->count());
    }

    public function test_a_fresh_code_still_signs_in_and_marks_its_step(): void
    {
        $totp = new Totp;
        $secret = $totp->secret();
        $this->user->forceFill(['totp_secret' => $secret, 'totp_confirmed_at' => now(), 'totp_last_step' => 5])->save();
        $step = intdiv(time(), Totp::PERIOD);

        $this->post('/admin/login', ['email' => 'raymon@example.com', 'password' => 'correct-horse-battery']);
        $this->post('/admin/two-factor', ['code' => $totp->at($secret, $step)])->assertRedirect('/admin/overview');

        $this->assertAuthenticatedAs($this->user);
        $this->assertSame($step, (int) $this->user->fresh()->totp_last_step);
    }

    // ---------- an archived page closes for its members ----------

    public function test_members_of_an_archived_page_lose_access_and_admins_keep_it(): void
    {
        $page = StatusPage::create(['name' => 'Shop', 'slug' => 'shop', 'is_published' => true]);
        $member = User::factory()->create(['role' => UserRole::User]);
        $member->statusPages()->attach($page->id, ['role' => 'admin']);

        $this->assertTrue($member->canAccessPage($page->id));
        $this->actingAs($member)->get('/admin/pages/'.$page->id.'/overview')->assertOk();

        $page->forceFill(['archived_at' => now(), 'is_published' => false])->save();

        $this->assertFalse($member->canAccessPage($page->id));
        $this->assertFalse($member->canEditPage($page->id));
        $this->assertFalse($member->canAdministerPage($page->id));
        $this->actingAs($member)->get('/admin/pages/'.$page->id.'/overview')->assertNotFound();
        $this->actingAs($member)->get('/admin/pages/'.$page->id.'/components')->assertNotFound();

        $this->assertTrue($this->user->canAccessPage($page->id));
        $this->flushSession();
        $this->actingAs($this->user)->get('/admin/pages/'.$page->id.'/edit')->assertOk();

        // Brought back, the membership works again: nothing was taken away for good.
        $page->forceFill(['archived_at' => null])->save();
        $this->assertTrue($member->canAdministerPage($page->id));
    }

    public function test_a_members_token_stops_working_when_the_page_is_archived(): void
    {
        $page = StatusPage::create(['name' => 'Shop', 'slug' => 'shop', 'is_published' => true]);
        $member = User::factory()->create(['role' => UserRole::User]);
        $member->statusPages()->attach($page->id, ['role' => 'admin']);
        $token = ApiToken::create(['name' => 'ci', 'token_hash' => hash('sha256', 'plain'), 'scope' => 'read', 'user_id' => $member->id, 'status_page_id' => $page->id]);

        $this->assertTrue(TokenAccess::allows($token, $page->id));
        $page->forceFill(['archived_at' => now()])->save();
        $this->assertFalse(TokenAccess::allows($token, $page->id));
    }

    // ---------- failed passkey sign ins are audited ----------

    public function test_a_failed_passkey_sign_in_is_written_to_the_audit_log(): void
    {
        config(['monitoring.webauthn_origin' => 'https://localhost']);
        $credential = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        Passkey::create(['user_id' => $this->user->id, 'credential_id' => $credential, 'public_key' => 'not-a-key', 'name' => 'Laptop', 'counter' => 0]);
        $junk = ['clientDataJSON' => 'e30', 'authenticatorData' => 'AA', 'signature' => 'AA', 'userHandle' => 'AA'];

        $this->postJson('/admin/passkeys/login/options')->assertOk();
        $this->postJson('/admin/passkeys/login', ['id' => $credential] + $junk)->assertStatus(422);

        $this->postJson('/admin/passkeys/login/options')->assertOk();
        $this->postJson('/admin/passkeys/login', ['id' => 'not-one-of-ours'] + $junk)->assertStatus(422);

        $this->assertGuest();
        $entries = AuditEntry::where('action', 'auth.passkey_failed')->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertSame('raymon@example.com', $entries[0]->actor);
        $this->assertSame('Laptop', $entries[0]->subject_label);
        $this->assertSame('Unknown passkey', $entries[1]->actor);
        $this->assertNull($entries[1]->subject_label);
    }

    // ---------- SSO endpoints on https ----------

    /** @var array<string, string> what the stand-in provider's discovery document lists */
    protected array $endpoints = [];

    /**
     * A provider whose discovery document is what the test says. A closure:
     * Http::fake() merges stubs, so a second call would not replace the first.
     */
    protected function provider(array $endpoints): Sso
    {
        $this->endpoints = $endpoints;
        cache()->flush();
        Setting::put('sso.issuer', 'https://id.example.net');
        $this->swap(SafeHttp::class, new class extends SafeHttp
        {
            public function to(string $url): PendingRequest
            {
                return Http::timeout(6)->withoutRedirecting();
            }
        });
        Http::fake(['id.example.net/.well-known/openid-configuration' => fn () => Http::response(['issuer' => 'https://id.example.net'] + $this->endpoints)]);

        return app(Sso::class);
    }

    public function test_discovered_endpoints_on_plain_http_are_refused(): void
    {
        foreach (['authorization_endpoint', 'token_endpoint', 'jwks_uri'] as $plain) {
            $endpoints = [
                'authorization_endpoint' => 'https://id.example.net/authorize',
                'token_endpoint' => 'https://id.example.net/token',
                'jwks_uri' => 'https://id.example.net/jwks',
            ];
            $endpoints[$plain] = 'http://id.example.net/'.$plain;

            try {
                $this->provider($endpoints)->discover();
                $this->fail("{$plain} on http was accepted");
            } catch (\RuntimeException $e) {
                $this->assertStringContainsString($plain, $e->getMessage());
                $this->assertStringContainsString('not on https', $e->getMessage());
            }
        }
    }

    public function test_odd_schemes_are_refused_and_a_missing_jwks_uri_is_fine(): void
    {
        $sso = $this->provider(['authorization_endpoint' => 'javascript:alert(1)', 'token_endpoint' => 'https://id.example.net/token']);
        $this->expectException(\RuntimeException::class);
        $sso->discover();
    }

    public function test_https_endpoints_without_a_jwks_uri_pass(): void
    {
        $doc = $this->provider([
            'authorization_endpoint' => 'https://id.example.net/authorize',
            'token_endpoint' => 'https://login.example.org/token',
        ])->discover();

        $this->assertSame('https://login.example.org/token', $doc['token_endpoint']);
    }

    public function test_plain_http_is_allowed_for_a_host_on_the_internal_list(): void
    {
        $endpoints = [
            'authorization_endpoint' => 'http://sso.lan:9000/authorize',
            'token_endpoint' => 'http://sso.lan:9000/token',
            'jwks_uri' => 'http://sso.lan:9000/jwks',
        ];

        Setting::put('sso.internal_hosts', 'sso.lan');
        $this->assertSame('http://sso.lan:9000/token', $this->provider($endpoints)->discover()['token_endpoint']);

        // Taken off the list, the cached document is refused at once.
        Setting::put('sso.internal_hosts', '');
        $this->expectException(\RuntimeException::class);
        app(Sso::class)->discover();
    }

    public function test_the_sign_in_button_falls_back_to_the_password_when_the_provider_is_on_http(): void
    {
        Setting::put('sso.enabled', '1');
        Setting::put('sso.client_id', 'pharos');
        Setting::put('sso.client_secret', 'shhh');
        $this->provider(['authorization_endpoint' => 'https://id.example.net/authorize', 'token_endpoint' => 'http://id.example.net/token']);

        $this->get('/admin/sso/redirect')->assertRedirect('/admin/login')->assertSessionHasErrors('email');
        $this->assertNull(session('sso.verifier'));
    }
}
