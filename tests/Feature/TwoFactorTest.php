<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\RecoveryCode;
use App\Models\User;
use App\Services\Totp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Totp $totp;

    protected function setUp(): void
    {
        parent::setUp();

        $this->totp = new Totp;
        $this->user = User::create([
            'name' => 'Raymon', 'email' => 'raymon@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);
    }

    /** Puts a confirmed second factor on the account and returns the secret. */
    protected function enrol(?User $user = null): string
    {
        $user ??= $this->user;
        $secret = $this->totp->secret();
        $user->forceFill(['totp_secret' => $secret, 'totp_confirmed_at' => now()])->save();
        RecoveryCode::replaceFor($user);

        return $secret;
    }

    protected function code(string $secret): string
    {
        return $this->totp->at($secret, intdiv(time(), Totp::PERIOD));
    }

    // ---------- turning it on ----------

    public function test_the_profile_offers_a_secret_and_an_otpauth_uri(): void
    {
        $this->actingAs($this->user)->post('/admin/profile/two-factor')->assertRedirect('/admin/profile');

        $this->assertNotNull($this->user->fresh()->totp_secret);
        // Not switched on yet: an app that never got the secret must not lock you out.
        $this->assertFalse($this->user->fresh()->hasTwoFactor());

        $this->actingAs($this->user)->get('/admin/profile')
            ->assertOk()
            ->assertSee('otpauth://', false);
    }

    public function test_a_wrong_code_does_not_switch_it_on(): void
    {
        $this->actingAs($this->user)->post('/admin/profile/two-factor');

        $this->actingAs($this->user)->post('/admin/profile/two-factor/confirm', ['code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertFalse($this->user->fresh()->hasTwoFactor());
    }

    public function test_a_correct_code_switches_it_on_and_hands_out_recovery_codes(): void
    {
        $this->actingAs($this->user)->post('/admin/profile/two-factor');
        $secret = $this->user->fresh()->totp_secret;

        $this->actingAs($this->user)
            ->post('/admin/profile/two-factor/confirm', ['code' => $this->code($secret)])
            ->assertRedirect('/admin/profile')
            ->assertSessionHas('recovery_codes');

        $this->assertTrue($this->user->fresh()->hasTwoFactor());
        $this->assertSame(10, $this->user->recoveryCodes()->count());
    }

    // ---------- signing in ----------

    public function test_the_password_alone_no_longer_signs_you_in(): void
    {
        $this->enrol();

        $this->post('/admin/login', ['email' => 'raymon@example.com', 'password' => 'correct-horse-battery'])
            ->assertRedirect('/admin/two-factor');

        $this->assertGuest();
    }

    public function test_a_code_completes_the_sign_in(): void
    {
        $secret = $this->enrol();

        $this->post('/admin/login', ['email' => 'raymon@example.com', 'password' => 'correct-horse-battery']);
        $this->post('/admin/two-factor', ['code' => $this->code($secret)])
            ->assertRedirect('/admin/components');

        $this->assertAuthenticatedAs($this->user);
    }

    public function test_the_second_step_cannot_be_skipped(): void
    {
        $this->enrol();

        // Straight to the challenge without a password behind it.
        $this->get('/admin/two-factor')->assertRedirect('/admin/login');
        $this->post('/admin/two-factor', ['code' => '123456'])->assertRedirect('/admin/login');
        $this->assertGuest();
    }

    public function test_remember_me_only_takes_effect_after_the_code(): void
    {
        // Portalis' lesson: a second door that skips the gate makes the gate decorative.
        $this->enrol();

        $response = $this->post('/admin/login', [
            'email' => 'raymon@example.com', 'password' => 'correct-horse-battery', 'remember' => '1',
        ]);

        $this->assertGuest();
        $this->assertEmpty(array_filter(
            $response->headers->getCookies(),
            fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web'),
        ));
    }

    public function test_a_wrong_code_at_the_challenge_keeps_you_out(): void
    {
        $this->enrol();

        $this->post('/admin/login', ['email' => 'raymon@example.com', 'password' => 'correct-horse-battery']);
        $this->post('/admin/two-factor', ['code' => '000000'])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    public function test_a_code_cannot_be_used_twice(): void
    {
        $secret = $this->enrol();
        $code = $this->code($secret);

        $this->post('/admin/login', ['email' => 'raymon@example.com', 'password' => 'correct-horse-battery']);
        $this->post('/admin/two-factor', ['code' => $code]);
        $this->post('/admin/logout');

        $this->post('/admin/login', ['email' => 'raymon@example.com', 'password' => 'correct-horse-battery']);
        $this->post('/admin/two-factor', ['code' => $code])->assertSessionHasErrors('code');

        $this->assertGuest();
    }

    // ---------- recovery ----------

    public function test_a_recovery_code_gets_you_in_once(): void
    {
        $this->enrol();
        $codes = RecoveryCode::replaceFor($this->user);

        $this->post('/admin/login', ['email' => 'raymon@example.com', 'password' => 'correct-horse-battery']);
        $this->post('/admin/two-factor', ['code' => $codes[0]])->assertRedirect('/admin/components');
        $this->assertAuthenticatedAs($this->user);

        $this->post('/admin/logout');
        $this->post('/admin/login', ['email' => 'raymon@example.com', 'password' => 'correct-horse-battery']);
        $this->post('/admin/two-factor', ['code' => $codes[0]])->assertSessionHasErrors('code');
        $this->assertGuest();
    }

    // ---------- turning it off ----------

    public function test_switching_it_off_needs_your_password_and_clears_the_codes(): void
    {
        $this->enrol();

        $this->actingAs($this->user)->delete('/admin/profile/two-factor', ['current_password' => 'wrong'])
            ->assertSessionHasErrors('current_password');
        $this->assertTrue($this->user->fresh()->hasTwoFactor());

        $this->actingAs($this->user)
            ->delete('/admin/profile/two-factor', ['current_password' => 'correct-horse-battery'])
            ->assertRedirect('/admin/profile');

        $this->assertFalse($this->user->fresh()->hasTwoFactor());
        $this->assertSame(0, $this->user->recoveryCodes()->count());
    }

    public function test_the_cli_can_switch_it_off_when_the_phone_is_gone(): void
    {
        $this->enrol();

        $this->artisan('pharos:2fa:disable', ['email' => 'raymon@example.com'])->assertSuccessful();

        $this->assertFalse($this->user->fresh()->hasTwoFactor());
        $this->assertSame(0, $this->user->recoveryCodes()->count());
    }

    public function test_the_secret_never_reaches_the_audit_log(): void
    {
        $this->enrol();

        $this->assertSame(0, AuditEntry::whereRaw(
            'LOWER(COALESCE(changes, "")) LIKE ?', ['%totp_secret%']
        )->count());
    }

    public function test_the_profile_shows_the_state_of_your_second_factor(): void
    {
        $this->actingAs($this->user)->get('/admin/profile')->assertOk()->assertSee('Two-factor');

        $this->enrol();
        $this->actingAs($this->user)->get('/admin/profile')->assertOk()->assertSee('10 unused');
    }

    public function test_the_challenge_screen_renders(): void
    {
        $this->enrol();

        $this->post('/admin/login', ['email' => 'raymon@example.com', 'password' => 'correct-horse-battery']);
        $this->get('/admin/two-factor')->assertOk()->assertSee('recovery code');
    }

    public function test_your_name_in_the_sidebar_is_the_way_to_your_profile(): void
    {
        // One menu item fewer: the name row itself is the control.
        $this->actingAs($this->user)->get('/admin/components')
            ->assertOk()
            ->assertSee('class="whorow" href="'.route('admin.profile').'"', false)
            ->assertDontSee('Your profile</a>', false);
    }
}
