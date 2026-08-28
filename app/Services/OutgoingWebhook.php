<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\Setting;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Log;

/**
 * Fires on every incident change, to as many places as are configured.
 *
 * Slack and Teams each insist on their own JSON shape, so pointing a raw
 * webhook at them returns 400 and nothing arrives. Each endpoint therefore
 * carries the shape it wants, and the payload is built per endpoint.
 *
 * There is no retry and no queue: Pharos has to run on shared hosting with one
 * cron line, and a slow receiver must never hold up publishing an outage. A
 * failed delivery is recorded on the endpoint so it is visible in the admin
 * instead of only in a log file.
 */
class OutgoingWebhook
{
    public function __construct(protected SafeHttp $safe = new SafeHttp) {}

    public function incidentChanged(Incident $incident, string $event): void
    {
        $endpoints = WebhookEndpoint::where('enabled', true)->get();

        foreach ($endpoints as $endpoint) {
            $this->deliver($endpoint, $this->payload($endpoint->format, $incident, $event));
        }
    }

    /** One fake incident, so an operator can prove the wiring before an outage does. */
    public function test(WebhookEndpoint $endpoint): bool
    {
        $incident = new Incident([
            'name' => 'Test notification from Pharos',
            'status' => \App\Enums\IncidentStatus::Investigating,
            'impact' => 'minor',
            'occurred_at' => now(),
        ]);
        $incident->id = 0;
        $incident->setRelation('components', collect());

        return $this->deliver($endpoint, $this->payload($endpoint->format, $incident, 'incident.test'));
    }

    /** @return array<string, mixed> */
    protected function payload(string $format, Incident $incident, string $event): array
    {
        return match ($format) {
            'slack' => $this->slack($incident, $event),
            'teams' => $this->teams($incident, $event),
            default => $this->generic($incident, $event),
        };
    }

    /** @return array<string, mixed> */
    protected function generic(Incident $incident, string $event): array
    {
        return [
            'event' => $event,
            'incident' => [
                'id' => $incident->id,
                'name' => $incident->name,
                'status' => $incident->status->name,
                'impact' => $incident->impact->value,
                'occurred_at' => $incident->occurred_at?->toIso8601String(),
                'resolved_at' => $incident->resolved_at?->toIso8601String(),
                'components' => $incident->components->pluck('name')->all(),
            ],
        ];
    }

    /** @return array<string, mixed> */
    protected function slack(Incident $incident, string $event): array
    {
        $resolved = $incident->resolved_at !== null;

        // text is the notification and the fallback; blocks are what is read.
        return [
            'text' => ($resolved ? 'Resolved: ' : 'Incident: ').$incident->name,
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => ($resolved ? ':white_check_mark: *Resolved* — ' : ':rotating_light: *'.$incident->status->label().'* — ')
                            .$incident->name,
                    ],
                ],
                [
                    'type' => 'context',
                    'elements' => [[
                        'type' => 'mrkdwn',
                        'text' => implode('  ·  ', array_filter([
                            ucfirst($incident->impact->value).' impact',
                            $this->componentLine($incident),
                            Setting::get('brand.name', 'Pharos'),
                        ])),
                    ]],
                ],
            ],
        ];
    }

    /**
     * The Adaptive Card envelope, which is what a Teams Workflow accepts. The
     * old Office 365 connector took a MessageCard instead; that route is being
     * retired, so this deliberately targets the one with a future.
     *
     * @return array<string, mixed>
     */
    protected function teams(Incident $incident, string $event): array
    {
        $resolved = $incident->resolved_at !== null;

        return [
            'type' => 'message',
            'attachments' => [[
                'contentType' => 'application/vnd.microsoft.card.adaptive',
                'content' => [
                    '$schema' => 'http://adaptivecards.io/schemas/adaptive-card.json',
                    'type' => 'AdaptiveCard',
                    'version' => '1.4',
                    'body' => [
                        [
                            'type' => 'TextBlock',
                            'size' => 'Medium',
                            'weight' => 'Bolder',
                            'wrap' => true,
                            'color' => $resolved ? 'Good' : 'Attention',
                            'text' => ($resolved ? 'Resolved: ' : $incident->status->label().': ').$incident->name,
                        ],
                        [
                            'type' => 'FactSet',
                            'facts' => array_values(array_filter([
                                ['title' => 'Impact', 'value' => ucfirst($incident->impact->value)],
                                $incident->components->isNotEmpty()
                                    ? ['title' => 'Affected', 'value' => $incident->components->pluck('name')->join(', ')]
                                    : null,
                                ['title' => 'Since', 'value' => $incident->occurred_at?->toDayDateTimeString() ?? '—'],
                                ['title' => 'Status page', 'value' => Setting::get('brand.name', 'Pharos')],
                            ])),
                        ],
                    ],
                ],
            ]],
        ];
    }

    protected function componentLine(Incident $incident): string
    {
        return $incident->components->isEmpty()
            ? ''
            : 'Affects '.$incident->components->pluck('name')->join(', ');
    }

    /** @param array<string, mixed> $payload */
    protected function deliver(WebhookEndpoint $endpoint, array $payload): bool
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $headers = ['Content-Type' => 'application/json'];

        // Only the generic shape is signed. Slack and Teams drop unknown headers,
        // and their URL is the credential in the first place.
        if ($endpoint->format === 'generic') {
            $secret = Setting::get('integrations.webhook_secret', '');
            $headers['X-Pharos-Signature'] = hash_hmac('sha256', $body, $secret);
        }

        try {
            // Vetted again here, not only when the endpoint was saved: a name can
            // be re-pointed at the metadata service in between (DNS rebinding).
            $response = $this->safe->toOwn($endpoint->url)->timeout(5)->withHeaders($headers)
                ->withBody($body, 'application/json')->post($endpoint->url);

            $endpoint->forceFill([
                'last_status' => $response->status(),
                // The status is what diagnoses it. The body is the receiver's,
                // could be anything, and would be stored and shown in the admin.
                'last_error' => $response->successful() ? null : "HTTP {$response->status()}",
                'last_attempt_at' => now(),
            ])->save();

            return $response->successful();
        } catch (\Throwable $e) {
            Log::warning('Outgoing webhook failed', ['endpoint' => $endpoint->id, 'error' => $e->getMessage()]);

            $endpoint->forceFill([
                'last_status' => null,
                'last_error' => \Illuminate\Support\Str::limit($e->getMessage(), 180),
                'last_attempt_at' => now(),
            ])->save();

            return false;
        }
    }
}
