<?php

namespace App\Mail;

use App\Mail\Concerns\Branded;
use App\Models\User;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Sent to the signed-in admin from Settings → Mail, to prove MAIL_* works. */
class TestMail extends Mailable
{
    use Branded;

    public function __construct(public User $user) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->brandedFrom(),
            subject: 'Test e-mail from '.$this->branding()->name(),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.test',
            text: 'mail.text.test',
            with: $this->brandVars() + [
                'mailer' => (string) config('mail.default'),
                'user' => $this->user,
            ],
        );
    }
}
