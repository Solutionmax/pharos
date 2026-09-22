<?php

namespace App\Mail;

use App\Mail\Concerns\Branded;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** "Send test to me" on Mail templates: a template rendered with sample data, to the signed-in admin. */
class TemplatePreviewMail extends Mailable
{
    use Branded;

    /** @param  array{subject: string, html: string, text: string}  $rendered */
    public function __construct(public array $rendered)
    {
        $this->captureBrandContext();
    }

    public function envelope(): Envelope
    {
        return $this->inBrandContext(fn () => new Envelope(
            from: $this->brandedFrom(),
            replyTo: $this->brandedReplyTo(),
            subject: $this->rendered['subject'],
        ));
    }

    public function content(): Content
    {
        return $this->inBrandContext(fn () => $this->templateContent($this->rendered));
    }
}
