<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\PasswordResetController;
use App\Models\User;
use App\Notifications\ResetAccountPassword;
use App\Support\EqualTime;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Timebox;
use Tests\Support\RecordingTimebox;
use Tests\TestCase;

/** Review 15 nov 2026, C4: "forgot password" takes as long for an unknown address. */
class ForgotPasswordTimingTest extends TestCase
{
    use RefreshDatabase;

    public function test_known_and_unknown_addresses_go_through_the_same_time_box(): void
    {
        Notification::fake();
        config(['pharos.equal_time_ms' => 2000]);
        $this->app->instance(Timebox::class, $box = new RecordingTimebox);
        $user = User::factory()->create();

        foreach ([$user->email, 'unknown@example.com'] as $email) {
            $this->post('/admin/forgot-password', ['email' => $email])
                ->assertRedirect('/admin/forgot-password')
                ->assertSessionHas('status', PasswordResetController::SENT_MESSAGE);
        }

        $this->assertSame([2_000_000, 2_000_000], $box->calls);
        Notification::assertSentTo($user, ResetAccountPassword::class);
        Notification::assertCount(1);
    }

    public function test_both_answers_take_at_least_the_configured_time(): void
    {
        Notification::fake();
        config(['pharos.equal_time_ms' => 250]);
        $user = User::factory()->create();

        foreach ([$user->email, 'unknown@example.com'] as $email) {
            $started = microtime(true);
            $this->post('/admin/forgot-password', ['email' => $email])->assertRedirect('/admin/forgot-password');
            $this->assertGreaterThanOrEqual(0.25, microtime(true) - $started, $email);
        }
    }

    public function test_a_mail_that_fails_still_waits_out_the_box(): void
    {
        config(['pharos.equal_time_ms' => 1500]);
        $this->app->instance(Timebox::class, $box = new RecordingTimebox);
        $user = User::factory()->create();
        Password::shouldReceive('sendResetLink')->once()->andThrow(new \RuntimeException('Connection refused'));

        $this->post('/admin/forgot-password', ['email' => $user->email])
            ->assertSessionHas('status', PasswordResetController::SENT_MESSAGE);

        $this->assertSame([1_500_000], $box->calls);
    }

    public function test_the_box_never_goes_negative(): void
    {
        config(['pharos.equal_time_ms' => -5]);

        $this->assertSame(0, EqualTime::microseconds());
    }
}
