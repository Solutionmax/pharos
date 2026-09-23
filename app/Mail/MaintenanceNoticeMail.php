<?php

namespace App\Mail;

use App\Mail\Concerns\Branded;
use App\Models\Maintenance;
use App\Models\Subscriber;
use App\Services\MailTemplates;
use App\Services\PageUrls;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/** "Planned work ahead": sent once per window, a lead time before it starts. */
class MaintenanceNoticeMail extends Mailable
{
    use Branded;

    /** @var array{subject: string, html: string, text: string}|null */
    protected ?array $rendered = null;

    public function __construct(public Maintenance $maintenance, public Subscriber $subscriber)
    {
        $this->captureBrandContext();
    }

    public function envelope(): Envelope
    {
        return $this->inBrandContext(fn () => new Envelope(
            from: $this->brandedFrom(),
            replyTo: $this->brandedReplyTo(),
            subject: $this->rendered()['subject'],
        ));
    }

    public function content(): Content
    {
        return $this->inBrandContext(fn () => $this->templateContent($this->rendered()));
    }

    public function headers(): Headers
    {
        return $this->inBrandContext(fn () => new Headers(text: [
            'List-Unsubscribe' => '<'.$this->subscriber->unsubscribeUrl().'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]));
    }

    protected function rendered(): array
    {
        return $this->rendered ??= app(MailTemplates::class)->render('maintenance_scheduled', [
            'brand' => $this->branding()->name(),
            'maintenance' => $this->maintenance->title,
            'message' => (string) $this->maintenance->message,
            'components' => $this->maintenance->components->pluck('name')->implode(', '),
            'starts' => $this->maintenance->starts_at->format('j F Y, H:i'),
            'ends' => $this->maintenance->ends_at->format('j F Y, H:i'),
            'link' => PageUrls::route('status'),
            'unsubscribe' => $this->subscriber->unsubscribeUrl(),
            'name' => MailTemplates::nameFor($this->subscriber->email),
            'tone' => 'w',
        ]);
    }
}
