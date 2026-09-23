<?php

namespace App\Mail\Concerns;

use App\Services\Branding;
use App\Services\Clock;
use App\Services\MailConfig;
use App\Services\MailTemplates;
use App\Services\PageContext;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;

/**
 * What every mail from this install shares: who it is from and how it looks.
 * The customer-editable templates (MailTemplates) swap the body, not this.
 */
trait Branded
{
    protected ?int $brandPageId = null;

    protected function captureBrandContext(): void
    {
        $this->brandPageId = app(PageContext::class)->id();
    }

    /**
     * The page the mail belongs to, and the installation zone: a mail is read by
     * someone else, never in the personal zone of the admin who triggered it.
     */
    protected function inBrandContext(callable $callback): mixed
    {
        return Clock::withInstallationZone(
            fn () => app(PageContext::class)->run($this->brandPageId ?? app(PageContext::class)->id(), $callback),
        );
    }

    protected function branding(): Branding
    {
        return app(Branding::class);
    }

    /** The sender. MAIL_FROM_NAME left blank means the brand name, not "Laravel". */
    protected function brandedFrom(): Address
    {
        $sender = app(MailConfig::class)->sender();

        return new Address($sender['address'], $sender['name']);
    }

    /** @return list<Address> */
    protected function brandedReplyTo(): array
    {
        $replyTo = app(MailConfig::class)->sender()['reply_to'];

        return $replyTo !== '' ? [new Address($replyTo)] : [];
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
