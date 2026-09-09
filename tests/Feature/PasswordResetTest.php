<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\PasswordResetController;
use App\Models\User;
use App\Notifications\ResetAccountPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_existing_and_unknown_accounts_receive_the_same_response(): void
    {
        Notification::fake();
        $user = User::factory()->create();
        foreach ([$user->email, 'unknown@example.com'] as $email) {
            $this->post(route('admin.password.email'), ['email' => $email])
                ->assertRedirect(route('admin.password.request'))
                ->assertSessionHas('status', PasswordResetController::SENT_MESSAGE);
        }
        Notification::assertSentTo($user, ResetAccountPassword::class);
        Notification::assertCount(1);
    }

    public function test_reset_url_uses_configured_origin_and_supports_subdirectories(): void
    {
        config(['app.url' => 'https://status.example.com/pharos']);
        $user = User::factory()->create(['email' => 'owner+status@example.com']);
        $message = (new ResetAccountPassword('test-token'))->toMail($user);
        $this->assertSame('https://status.example.com/pharos/admin/reset-password/test-token?email=owner%2Bstatus%40example.com', $message->actionUrl);
    }

    public function test_reset_changes_password_consumes_token_and_keeps_two_factor(): void
    {
        $user = User::factory()->create(['remember_token' => 'old-remember-token']);
        $user->forceFill(['totp_secret' => 'JBSWY3DPEHPK3PXP', 'totp_confirmed_at' => now()])->save();
        $token = Password::createToken($user);
        $payload = ['email' => $user->email, 'token' => $token, 'password' => 'A-new-password-2026', 'password_confirmation' => 'A-new-password-2026'];
        $this->post(route('admin.password.update'), $payload)->assertRedirect(route('admin.login'));
        $user->refresh();
        $this->assertTrue(Hash::check($payload['password'], $user->password));
        $this->assertNotSame('old-remember-token', $user->remember_token);
        $this->assertTrue($user->hasTwoFactor());
        $this->assertGuest();
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => $user->email]);
        $this->post(route('admin.password.update'), $payload)->assertSessionHasErrors('email');
        $this->post(route('admin.login.attempt'), ['email' => $user->email, 'password' => $payload['password']])
            ->assertRedirect(route('admin.two-factor'));
        $this->assertGuest();
    }

    public function test_expired_or_wrong_token_does_not_change_password(): void
    {
        $user = User::factory()->create();
        $original = $user->password;
        $token = Password::createToken($user);
        $this->travel(61)->minutes();
        foreach ([$token, 'incorrect-token'] as $value) {
            $this->post(route('admin.password.update'), ['email' => $user->email, 'token' => $value, 'password' => 'A-new-password-2026', 'password_confirmation' => 'A-new-password-2026'])
                ->assertSessionHasErrors('email');
        }
        $this->assertSame($original, $user->fresh()->password);
    }

    public function test_password_policy_is_enforced_and_reset_page_protects_referrer(): void
    {
        $user = User::factory()->create();
        $token = Password::createToken($user);
        $this->get(route('admin.password.reset', ['token' => $token, 'email' => $user->email]))
            ->assertOk()->assertHeader('Referrer-Policy', 'no-referrer')->assertSee('Choose a new password');
        $this->post(route('admin.password.update'), ['email' => $user->email, 'token' => $token, 'password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');
    }

    public function test_reset_requests_are_rate_limited(): void
    {
        Notification::fake();
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('admin.password.email'), ['email' => 'unknown@example.com'])->assertRedirect();
        }
        $this->post(route('admin.password.email'), ['email' => 'unknown@example.com'])->assertStatus(429);
    }

    public function test_broken_mail_configuration_does_not_reveal_whether_an_account_exists(): void
    {
        $user = User::factory()->create();
        config(['mail.default' => 'not-configured']);
        $this->post(route('admin.password.email'), ['email' => $user->email])
            ->assertRedirect(route('admin.password.request'))
            ->assertSessionHas('status', PasswordResetController::SENT_MESSAGE);
    }

    public function test_existing_authenticated_session_cannot_survive_password_reset(): void
    {
        $user = User::factory()->create();
        $oldHash = $user->password;
        $token = Password::createToken($user);
        $this->post(route('admin.password.update'), ['email' => $user->email, 'token' => $token, 'password' => 'A-new-password-2026', 'password_confirmation' => 'A-new-password-2026'])
            ->assertRedirect(route('admin.login'));
        $this->actingAs($user->fresh())->withSession(['password_hash_web' => $oldHash])->get(route('admin.components'))
            ->assertRedirect(route('admin.login'));
        $this->assertGuest();
    }
}
