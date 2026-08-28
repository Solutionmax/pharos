<?php

namespace App\Mail;

use App\Mail\Concerns\Branded;
use App\Models\Subscriber;
use App\Services\MailTemplates;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** "Click to confirm": the only mail an unverified address ever receives. */
class SubscribeConfirmMail extends Mailable
{
    use Branded;

    /** @var array{subject: string, html: string, text: string}|null */
    protected ?array $rendered = null;

    public function __construct(public Subscriber $subscriber) {}

    public function envelope(): Envelope
    {
        return new Envelope(from: $this->brandedFrom(), subject: $this->rendered()['subject']);
    }

    public function content(): Content
    {
        return $this->templateContent($this->rendered());
    }

    protected function rendered(): array
    {
        return $this->rendered ??= app(MailTemplates::class)->render('subscribe_confirm', [
            'brand' => $this->branding()->name(),
            'link' => $this->subscriber->confirmUrl(),
            'hours' => Subscriber::CONFIRM_HOURS,
            'name' => MailTemplates::nameFor($this->subscriber->email),
        ]);
    }
}
