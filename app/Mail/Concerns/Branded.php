<?php

namespace App\Mail\Concerns;

use App\Services\Branding;
use App\Services\MailTemplates;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;

/**
 * What every mail from this install shares: who it is from and how it looks.
 * The customer-editable templates (MailTemplates) swap the body, not this.
 */
trait Branded
{
    protected function branding(): Branding
    {
        return app(Branding::class);
    }

    /** The sender. MAIL_FROM_NAME left blank means the brand name, not "Laravel". */
    protected function brandedFrom(): Address
    {
        return new Address(
            (string) config('mail.from.address'),
            (string) (config('mail.from.name') ?: $this->branding()->name()),
        );
    }

    /**
     * The layout's variables, for the mails that still have a view of their own.
     *
     * @return array<string, mixed>
     */
    protected function brandVars(): array
    {
        return MailTemplates::frame();
    }

    /**
     * A rendered template as mail content. "plain", not "text": the text part
     * is a view, and Laravel hands every mail view a $message of its own, so the
     * variable names here stay clear of anything Laravel might set.
     *
     * @param  array{subject: string, html: string, text: string}  $rendered
     */
    protected function templateContent(array $rendered): Content
    {
        return new Content(htmlString: $rendered['html'], text: 'mail.text.raw', with: ['plain' => $rendered['text']]);
    }
}
