<?php

namespace App\Mail;

use App\Enums\IncidentStatus;
use App\Mail\Concerns\Branded;
use App\Models\IncidentUpdate;
use App\Models\Subscriber;
use App\Services\MailTemplates;
use App\Services\PageUrls;
use App\Services\PublicComponents;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * One incident update, to one subscriber, through whichever of the three
 * incident templates fits the update (opened, updated, resolved).
 */
class IncidentNoticeMail extends Mailable
{
    use Branded;

    /** @var array{subject: string, html: string, text: string}|null */
    protected ?array $rendered = null;

    public function __construct(public IncidentUpdate $update, public Subscriber $subscriber)
    {
        $this->captureBrandContext();
    }

    public function envelope(): Envelope
    {
        return $this->inBrandContext(function () {
            return new Envelope(
                from: $this->brandedFrom(),
                replyTo: $this->brandedReplyTo(),
                subject: $this->rendered()['subject'],
            );
        });
    }

    public function content(): Content
    {
        return $this->inBrandContext(fn () => $this->templateContent($this->rendered()));
    }

    /** RFC 8058: lets Gmail and co. offer their own unsubscribe button, which POSTs to the same signed URL. */
    public function headers(): Headers
    {
        return $this->inBrandContext(fn () => new Headers(text: [
            'List-Unsubscribe' => '<'.$this->subscriber->unsubscribeUrl().'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]));
    }

    protected function rendered(): array
    {
        $incident = $this->update->incident;

        return $this->rendered ??= app(MailTemplates::class)->render(MailTemplates::forUpdate($this->update), [
            'brand' => $this->branding()->name(),
            'incident' => $incident->name,
            'status' => __($this->update->status->label()),
            // Inserted as Markdown; the renderer escapes anything that looks like a tag.
            'message' => (string) $this->update->message,
            'components' => PublicComponents::query()->whereIn('id', $incident->components->modelKeys())->pluck('name')->implode(', '),
            'link' => PageUrls::route('public.incident', $incident),
            'unsubscribe' => $this->subscriber->unsubscribeUrl(),
            'preferences' => $this->subscriber->preferencesUrl(),
            'when' => $this->update->created_at->translatedFormat('j F Y, H:i'),
            'name' => MailTemplates::nameFor($this->subscriber->email),
            // Same rule as the status page: resolved is green, a major outage red, anything else open is orange.
            'tone' => $this->update->status === IncidentStatus::Resolved ? 'ok'
                : ((int) ($incident->components->max('pivot.status') ?? 0) >= 4 ? 'b' : 'p'),
        ]);
    }
}
