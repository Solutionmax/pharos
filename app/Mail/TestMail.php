<?php

namespace App\Mail;

use App\Mail\Concerns\Branded;
use App\Models\User;
use App\Services\MailConfig;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sent to the signed-in admin from Settings → Mail, to prove MAIL_* works. */
class TestMail extends Mailable
{
    use Branded;

    public function __construct(public User $user)
    {
        $this->captureBrandContext();
    }

    public function envelope(): Envelope
    {
        return $this->inBrandContext(fn () => new Envelope(
            from: $this->brandedFrom(),
            replyTo: $this->brandedReplyTo(),
            subject: 'Test email from '.$this->branding()->name(),
        ));
    }

    public function content(): Content
    {
        return $this->inBrandContext(fn () => new Content(
            view: 'mail.test',
            text: 'mail.text.test',
            with: $this->brandVars() + [
                'mailer' => app(MailConfig::class)->effectivePage()['mailer'],
                'user' => $this->user,
            ],
        ));
    }
}
