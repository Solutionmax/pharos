<?php

namespace App\Mail;

use App\Mail\Concerns\Branded;
use App\Models\User;
use App\Services\MailConfig;
use App\Services\PageContext;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Sent to the signed-in admin to prove delivery works, from Settings, Central
 * mail or from a page's Email, Delivery screen. It says which of the two sent
 * it and how it left, in words: an internal mailer key means nothing to a reader.
 */
class TestMail extends Mailable
{
    use Branded;

    public const FROM_CENTRAL = 'central';

    public const FROM_PAGE = 'page';

    public function __construct(public User $user, public string $origin = self::FROM_PAGE)
    {
        $this->captureBrandContext();
    }

    public static function central(User $user): self
    {
        return new self($user, self::FROM_CENTRAL);
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
            with: $this->brandVars() + $this->describeOrigin() + [
                'user' => $this->user,
            ],
        ));
    }

    /**
     * Where the test was sent from and the transport it took, as sentences.
     *
     * @return array{source: string, transport: string}
     */
    protected function describeOrigin(): array
    {
        if ($this->origin === self::FROM_CENTRAL) {
            return ['source' => 'Settings, Central mail', 'transport' => 'the central mail transport'];
        }

        $effective = app(MailConfig::class)->effectivePage();

        return [
            'source' => 'Email, Delivery for '.app(PageContext::class)->page()->name,
            'transport' => $effective['mode'] === 'custom'
                ? 'this page’s own SMTP server ('.$effective['host'].')'
                : 'the central mail transport',
        ];
    }
}
