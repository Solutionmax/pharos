<?php

namespace Tests\Feature;

use App\Mail\SubscribeConfirmMail;
use App\Models\StatusPage;
use App\Models\User;
use App\Notifications\ResetAccountPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Review 15 nov 2026, F3: links and the logo in a mail are built from the
 * configured URLs, never from the Host header of the request that sent it.
 */
class MailHostHeaderTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        config(['app.url' => 'https://status.example.com']);
        Mail::fake();
        $this->admin = User::create(['name' => 'Raymon', 'email' => 'raymon@example.com', 'password' => Hash::make('correct-horse-battery')]);
    }

    protected function render(SubscribeConfirmMail $mail): string
    {
        $content = $mail->content();

        return $content->htmlString."\n".view($content->text, $content->with)->render();
    }

    public function test_a_forged_host_posting_subscribe_produces_no_mail_with_that_host(): void
    {
        $this->post('http://evil.example/subscribe', ['email' => 'victim@example.net'])->assertNotFound();

        foreach (Mail::sent(SubscribeConfirmMail::class) as $mail) {
            $this->assertStringNotContainsString('evil.example', $this->render($mail));
        }
    }

    public function test_a_forged_port_on_the_real_host_does_not_reach_the_mail(): void
    {
        $this->post('https://status.example.com:6666/subscribe', ['email' => 'victim@example.net'])->assertRedirect();

        $mail = Mail::sent(SubscribeConfirmMail::class)->sole();
        $html = $this->render($mail);
        $this->assertStringNotContainsString(':6666', $html);
        $this->assertStringContainsString('https://status.example.com/brand/pharos-email-logo.png?v=', $html);
        $this->assertStringContainsString('https://status.example.com/subscribe/confirm/', $html);
    }

    public function test_a_page_on_its_own_domain_mails_its_logo_from_that_domain(): void
    {
        StatusPage::create(['name' => 'Shop', 'slug' => 'shop', 'is_published' => true, 'domain' => 'status.shop.test']);

        $this->post('https://status.shop.test:6666/subscribe', ['email' => 'victim@example.net'])->assertRedirect();

        $html = $this->render(Mail::sent(SubscribeConfirmMail::class)->sole());
        $this->assertStringNotContainsString(':6666', $html);
        $this->assertStringContainsString('https://status.shop.test/brand/pharos-email-logo.png?v=', $html);
    }

    public function test_the_password_reset_mail_ignores_the_host_it_was_asked_on(): void
    {
        Notification::fake();

        $this->post('https://status.example.com:6666/admin/forgot-password', ['email' => 'raymon@example.com'])->assertRedirect();

        Notification::assertSentTo($this->admin, ResetAccountPassword::class, function (ResetAccountPassword $notification) {
            $html = (string) $notification->toMail($this->admin)->render();
            $this->assertStringNotContainsString(':6666', $html);
            $this->assertStringContainsString('https://status.example.com/brand/pharos-email-logo.png?v=', $html);
            $this->assertStringContainsString('https://status.example.com/admin/reset-password/', $html);

            return true;
        });
    }
}
