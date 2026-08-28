<?php

namespace App\Mail;

use App\Mail\Concerns\Branded;
use App\Models\IncidentUpdate;
use App\Models\Subscriber;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * One incident update, to one subscriber. Everything a customer template will
 * be able to place in phase 2 is handed to the view by name: brand, name,
 * status, body (the update's message as HTML), components, when, link, unsubscribe. "body", not
 * "message": Laravel hands every mail view a $message of its own.
 */
class IncidentNoticeMail extends Mailable
{
    use Branded;

    public function __construct(public IncidentUpdate $update, public Subscriber $subscriber) {}

    public function envelope(): Envelope
    {
        $incident = $this->update->incident;

        return new Envelope(
            from: $this->brandedFrom(),
            subject: '['.$this->branding()->name().'] '.$incident->name.' — '.$this->update->status->label(),
        );
    }

    public function content(): Content
    {
        $incident = $this->update->incident;

        return new Content(
            view: 'mail.incident-notice',
            text: 'mail.text.incident-notice',
            with: $this->brandVars() + [
                'name' => $incident->name,
                'status' => $this->update->status->label(),
                // The same escape rules as the status page: what looks like a tag is shown, never run.
                'body' => $this->update->messageHtml(),
                'bodyText' => (string) $this->update->message,
                'components' => $incident->components->pluck('name')->all(),
                'when' => $this->update->created_at,
                'unsubscribe' => $this->subscriber->unsubscribeUrl(),
            ],
        );
    }

    /** RFC 8058: lets Gmail and co. offer their own unsubscribe button, which POSTs to the same signed URL. */
    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.$this->subscriber->unsubscribeUrl().'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }
}
