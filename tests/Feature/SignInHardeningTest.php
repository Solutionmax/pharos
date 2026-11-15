<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\AuthController;
use App\Models\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/** Review 15 nov 2026, C2, C3 and C5: the sign in form, the session and "remember me". */
class SignInHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected const IP = '203.0.113.9';

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Raymon', 'email' => 'raymon@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);
    }

    /** @return array<string, string> */
    protected function fromIp(string $ip = self::IP): array
    {
        return ['REMOTE_ADDR' => $ip];
    }

    // ---------- C2: a limit per IP beside the one per address and IP ----------

    public function test_one_ip_trying_many_addresses_is_stopped_after_twenty_failures(): void
    {
        for ($i = 0; $i < AuthController::MAX_PER_IP; $i++) {
            $this->withServerVariables($this->fromIp())
                ->post('/admin/login', ['email' => "guess{$i}@example.com", 'password' => 'Summer2026!'])
                ->assertSessionHasErrors(['email' => 'Those details do not match an account.']);
        }

        // Even the right password for a real account waits now, with the usual message.
        $this->withServerVariables($this->fromIp())
            ->post('/admin/login', ['email' => 'raymon@example.com', 'password' => 'correct-horse-battery'])
            ->assertSessionHasErrors('email');
        $this->assertStringStartsWith('Too many attempts. Try again in 5 minutes', session('errors')->first('email'));
        $this->assertGuest();
        $this->assertSame(AuthController::MAX_PER_IP, AuditEntry::where('action', 'auth.failed')->count());

        // Somebody else, on another address, is not held up.
        $this->withServerVariables($this->fromIp('198.51.100.7'))
            ->post('/admin/login', ['email' => 'raymon@example.com', 'password' => 'correct-horse-battery'])
            ->assertRedirect('/admin/overview');
        $this->assertAuthenticatedAs($this->user);
    }

    public function test_the_per_address_limit_still_applies_to_one_account(): void
    {
        for ($i = 0; $i < AuthController::MAX_PER_ACCOUNT; $i++) {
            $this->withServerVariables($this->fromIp())->post('/admin/login', ['email' => 'raymon@example.com', 'password' => 'nope']);
        }

        $this->withServerVariables($this->fromIp())
            ->post('/admin/login', ['email' => 'raymon@example.com', 'password' => 'correct-horse-battery'])
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_signing_in_to_an_own_account_does_not_wipe_the_count_per_ip(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->withServerVariables($this->fromIp())->post('/admin/login', ['email' => "guess{$i}@example.com", 'password' => 'nope']);
        }

        $this->withServerVariables($this->fromIp())
            ->post('/admin/login', ['email' => 'raymon@example.com', 'password' => 'correct-horse-battery'])
            ->assertRedirect('/admin/overview');

        $this->assertSame(3, RateLimiter::attempts('login-ip:'.self::IP));
    }

    public function test_an_overlong_address_is_refused_before_it_reaches_the_audit_log(): void
    {
        $long = str_repeat('a', 60).'@'.str_repeat('b', 60).'.'.str_repeat('c', 60).'.'.str_repeat('d', 61).'.example.com';
        $this->assertGreaterThan(255, strlen($long));

        $this->post('/admin/login', ['email' => $long, 'password' => 'nope'])
            ->assertSessionHasErrors(['email' => 'The email field must not be greater than 255 characters.']);

        $this->assertSame(0, AuditEntry::count());
    }

    // ---------- C3: sessions are encrypted at rest ----------

    public function test_sessions_are_encrypted_unless_the_environment_says_otherwise(): void
    {
        $saved = [$_ENV['SESSION_ENCRYPT'] ?? null, $_SERVER['SESSION_ENCRYPT'] ?? null, getenv('SESSION_ENCRYPT')];
        unset($_ENV['SESSION_ENCRYPT'], $_SERVER['SESSION_ENCRYPT']);
        putenv('SESSION_ENCRYPT');

        try {
            $this->assertTrue((require config_path('session.php'))['encrypt']);
        } finally {
            [$env, $server, $put] = $saved;
            if ($env !== null) {
                $_ENV['SESSION_ENCRYPT'] = $env;
            }
            if ($server !== null) {
                $_SERVER['SESSION_ENCRYPT'] = $server;
            }
            if ($put !== false) {
                putenv('SESSION_ENCRYPT='.$put);
            }
        }
    }

    public function test_an_encrypted_session_row_does_not_show_its_contents(): void
    {
        $this->databaseSessions(encrypt: true);

        $this->post('/admin/login', ['email' => 'raymon@example.com', 'password' => 'correct-horse-battery'])
            ->assertRedirect('/admin/overview');

        $payload = base64_decode((string) DB::table('sessions')->where('user_id', $this->user->id)->value('payload'));
        $this->assertNotSame('', $payload);
        $this->assertStringNotContainsString('login_web_', $payload);
        $this->assertNull(json_decode($payload, true));
    }

    /** Sessions in the database table, as installs keep them, built afresh with this config. */
    protected function databaseSessions(bool $encrypt): void
    {
        config(['session.driver' => 'database', 'session.encrypt' => $encrypt]);
        $this->app['session']->forgetDrivers();
        $this->app->forgetInstance('session.store');
        Auth::forgetGuards();
    }

    /**
     * A session written before the update, in plain JSON, as an existing
     * install has them. The cookie still names it.
     */
    protected function legacySession(): string
    {
        $id = Str::random(40);
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $this->user->id,
            'ip_address' => '127.0.0.1',
            'user_agent' => 'Test',
            'payload' => base64_encode(json_encode([
                '_token' => Str::random(40),
                Auth::guard('web')->getName() => $this->user->id,
            ])),
            'last_activity' => time(),
        ]);

        return $id;
    }

    public function test_a_plain_session_from_before_the_update_signed_in_while_encryption_was_off(): void
    {
        // The control for the test below: the fixture is a working session.
        $this->databaseSessions(encrypt: false);
        $id = $this->legacySession();

        $this->withCookie(config('session.cookie'), $id)->get('/admin/overview')->assertOk();
    }

    public function test_a_plain_session_from_before_the_update_starts_a_new_session_instead_of_failing(): void
    {
        $this->databaseSessions(encrypt: true);
        $id = $this->legacySession();

        $this->withCookie(config('session.cookie'), $id)->get('/admin/overview')->assertRedirect('/admin/login');
        $this->assertGuest();

        $this->withCookie(config('session.cookie'), $id)->get('/admin/login')->assertOk();
        $this->withCookie(config('session.cookie'), $id)
            ->post('/admin/login', ['email' => 'raymon@example.com', 'password' => 'correct-horse-battery'])
            ->assertRedirect('/admin/overview');
        $this->assertAuthenticatedAs($this->user);
    }

    // ---------- C5: "remember me" lasts 30 days ----------

    public function test_remember_me_lasts_thirty_days(): void
    {
        $this->assertSame(60 * 24 * 30, config('auth.guards.web.remember'));

        $response = $this->post('/admin/login', [
            'email' => 'raymon@example.com', 'password' => 'correct-horse-battery', 'remember' => '1',
        ])->assertRedirect('/admin/overview');

        $cookie = collect($response->headers->getCookies())
            ->first(fn ($c) => $c->getName() === Auth::guard('web')->getRecallerName());
        $this->assertNotNull($cookie);
        $this->assertEqualsWithDelta(now()->addDays(30)->getTimestamp(), $cookie->getExpiresTime(), 120);
    }
}
