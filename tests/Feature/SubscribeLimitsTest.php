<?php

namespace Tests\Feature;

use App\Http\Controllers\SubscribeController;
use App\Mail\SubscribeConfirmMail;
use App\Models\StatusPage;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\MailConfig;
use App\Services\PageContext;
use App\Services\SubscribeLimits;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Timebox;
use Illuminate\Testing\TestResponse;
use Symfony\Component\Mailer\Exception\TransportException;
use Tests\Support\RecordingTimebox;
use Tests\TestCase;

/**
 * Review 15 nov 2026, F2: confirmation mails per address and per page. The
 * route limit per IP does not stop someone with many IPs flooding one inbox.
 */
class SubscribeLimitsTest extends TestCase
{
    use RefreshDatabase;

    /** Every sign up from its own IP, so only the limits under test can bite. */
    protected int $ip = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        User::create(['name' => 'Admin', 'email' => 'admin@example.net', 'password' => Hash::make('correct-horse-battery')]);
    }

    protected function signUp(string $email, string $path = '/subscribe'): TestResponse
    {
        $this->ip++;

        return $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.'.$this->ip])->post($path, ['email' => $email]);
    }

    /** What a visitor can see of an answer. */
    protected function shape(TestResponse $response): array
    {
        return [$response->status(), $response->headers->get('Location'), session('subscribed'), session('errors')];
    }

    public function test_one_address_gets_one_confirmation_per_ten_minutes(): void
    {
        $first = $this->signUp('victim@example.net');
        $token = Subscriber::where('email', 'victim@example.net')->value('token');
        $second = $this->signUp('victim@example.net');

        Mail::assertSentCount(1);
        // The same answer, and the link in the first mail still works.
        $this->assertSame($this->shape($first), $this->shape($second));
        $this->assertSame([302, url('/'), SubscribeController::REPLY, null], $this->shape($second));
        $this->assertSame($token, Subscriber::where('email', 'victim@example.net')->value('token'));

        $this->travel(11)->minutes();
        $this->signUp('victim@example.net');
        Mail::assertSentCount(2);
    }

    public function test_one_address_gets_at_most_three_confirmations_a_day(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->signUp('victim@example.net')->assertSessionHas('subscribed', SubscribeController::REPLY);
            $this->travel(11)->minutes();
        }
        Mail::assertSentCount(3);

        $this->travel(1)->day();
        $this->signUp('victim@example.net');
        Mail::assertSentCount(4);
    }

    public function test_the_address_is_counted_whatever_its_case(): void
    {
        $this->signUp('Victim@Example.net');
        $this->signUp('  victim@example.NET ');

        Mail::assertSentCount(1);
    }

    public function test_the_limiter_keys_hold_a_hash_not_the_address(): void
    {
        foreach (array_keys(SubscribeLimits::limits('Victim@Example.net')) as $key) {
            $this->assertStringNotContainsStringIgnoringCase('victim', $key);
            $this->assertStringNotContainsStringIgnoringCase('example', $key);
        }
        $this->assertSame(array_keys(SubscribeLimits::limits('victim@example.net')), array_keys(SubscribeLimits::limits('VICTIM@example.net')));
    }

    public function test_a_page_sends_at_most_a_hundred_confirmations_an_hour(): void
    {
        $pageKey = array_key_last(SubscribeLimits::limits('anyone@example.net'));
        RateLimiter::increment($pageKey, SubscribeLimits::PER_PAGE_HOUR[1], SubscribeLimits::PER_PAGE_HOUR[0] - 1);

        $last = $this->signUp('number100@example.net');
        $over = $this->signUp('number101@example.net');

        Mail::assertSentCount(1);
        Mail::assertSent(SubscribeConfirmMail::class, fn ($mail) => $mail->hasTo('number100@example.net'));
        $this->assertSame($this->shape($last), $this->shape($over));
        // Turned away before anything was stored.
        $this->assertNull(Subscriber::where('email', 'number101@example.net')->first());

        $this->travel(61)->minutes();
        $this->signUp('number101@example.net');
        Mail::assertSentCount(2);
    }

    public function test_each_page_counts_its_own_mails(): void
    {
        $other = StatusPage::create(['name' => 'Shop', 'slug' => 'shop', 'is_published' => true]);

        $this->signUp('victim@example.net');
        $this->signUp('victim@example.net', '/status/shop/subscribe');

        Mail::assertSentCount(2);
        $this->assertNotNull(app(PageContext::class)->run($other->id, fn () => Subscriber::where('email', 'victim@example.net')->first()));
    }

    public function test_a_confirmed_address_and_a_limited_one_answer_alike_and_both_are_time_boxed(): void
    {
        config(['pharos.equal_time_ms' => 2000]);
        $this->app->instance(Timebox::class, $box = new RecordingTimebox);
        Subscriber::create(['email' => 'ann@example.net', 'token' => Subscriber::freshToken(), 'verified_at' => now()]);

        $new = $this->signUp('victim@example.net');
        $limited = $this->signUp('victim@example.net');
        $confirmed = $this->signUp('ann@example.net');

        $this->assertSame($this->shape($new), $this->shape($limited));
        $this->assertSame($this->shape($new), $this->shape($confirmed));
        $this->assertSame([2_000_000, 2_000_000, 2_000_000], $box->calls);
        Mail::assertSentCount(1);
    }

    public function test_the_answers_take_at_least_the_configured_time(): void
    {
        config(['pharos.equal_time_ms' => 250]);

        foreach (['victim@example.net', 'victim@example.net'] as $email) {
            $started = microtime(true);
            $this->signUp($email)->assertSessionHas('subscribed', SubscribeController::REPLY);
            $this->assertGreaterThanOrEqual(0.25, microtime(true) - $started);
        }
        Mail::assertSentCount(1);
    }

    public function test_a_mail_that_could_not_be_sent_does_not_use_up_the_limit(): void
    {
        $this->mock(MailConfig::class)->shouldReceive('sendTo')->once()->andThrow(new TransportException('Connection refused'));
        $this->signUp('someone@example.com')->assertSessionHasErrors('email');

        // Mail works again: the second try is not taken for a repeat.
        $this->app->forgetInstance(MailConfig::class);
        $this->signUp('someone@example.com')->assertSessionHas('subscribed', SubscribeController::REPLY);
        Mail::assertSentCount(1);
    }
}
