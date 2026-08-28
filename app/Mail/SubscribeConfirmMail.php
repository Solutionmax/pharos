<?php

namespace App\Mail;

use App\Mail\Concerns\Branded;
use App\Models\Subscriber;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** "Click to confirm": the only mail an unverified address ever receives. */
class SubscribeConfirmMail extends Mailable
{
    use Branded;

    public function __construct(public Subscriber $subscriber) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: $this->brandedFrom(),
            subject: 'Confirm your subscription to '.$this->branding()->name().' status updates',
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'mail.subscribe-confirm',
            text: 'mail.text.subscribe-confirm',
            with: $this->brandVars() + [
                'confirm' => $this->subscriber->confirmUrl(),
                'hours' => Subscriber::CONFIRM_HOURS,
            ],
        );
    }
}
