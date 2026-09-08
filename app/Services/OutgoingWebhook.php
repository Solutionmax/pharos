<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Setting;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/** Incident changes enqueue immutable payloads; the minute scheduler delivers them. */
class OutgoingWebhook
{
    private ?int $retryAfter = null;

    public function __construct(protected SafeHttp $safe = new SafeHttp) {}

    public function incidentChanged(Incident $incident, string $event): void
    {
        $endpoints = WebhookEndpoint::where('enabled', true)->get();

        foreach ($endpoints as $endpoint) {
            $key = hash('sha256', implode(':', [$incident->id, $event, $incident->updates()->max('id') ?? 0, $incident->status->value, $incident->updated_at?->format('U.u')]));
            WebhookDelivery::firstOrCreate(['webhook_endpoint_id' => $endpoint->id, 'event_key' => $key],
                ['payload' => $this->payload($endpoint->format, $incident, $event), 'next_attempt_at' => now()]);
        }
    }

    /** A bounded batch fits inside the lock and shared-hosting execution limits. */
    public function sendPending(): int
    {
        $lock = Cache::lock('pharos:webhook-outbox', 180);
        if (! $lock->get()) {
            return 0;
        }
        $sent = 0;
        try {
            $due = WebhookDelivery::with('endpoint')->whereNull('sent_at')->where('attempts', '<', 6)
                ->where('next_attempt_at', '<=', now())->oldest('id')->limit(10)->get();
            foreach ($due as $delivery) {
                $endpoint = $delivery->endpoint;
                if (! $endpoint?->enabled) {
                    $delivery->update(['attempts' => 6, 'error' => 'Destination disabled or removed']);

                    continue;
                }
                $delivery->increment('attempts');
                $ok = $this->deliver($endpoint, $delivery->payload);
                $status = $endpoint->last_status;
                $permanent = $status >= 400 && $status < 500 && ! in_array($status, [408, 429], true);
                $delivery->update([
                    'sent_at' => $ok ? now() : null,
                    'attempts' => $permanent ? 6 : $delivery->attempts,
                    'last_status' => $status,
                    'error' => $endpoint->last_error,
                    'next_attempt_at' => now()->addSeconds($this->retryAfter ?? min(3600, 60 * (2 ** $delivery->attempts))),
                ]);
                $sent += (int) $ok;
            }
            WebhookDelivery::where('created_at', '<', now()->subDays(30))->where(fn ($q) => $q->whereNotNull('sent_at')->orWhere('attempts', '>=', 6))->delete();
        } finally {
            $lock->release();
        }

        return $sent;
    }

    /** One fake incident, so an operator can prove the wiring before an outage does. */
    public function test(WebhookEndpoint $endpoint): bool
    {
        $incident = new Incident([
            'name' => 'Test notification from Pharos',
            'status' => IncidentStatus::Investigating,
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
            'discord' => ['content' => Str::limit(($incident->resolved_at ? 'Resolved: ' : 'Incident: ').$incident->name, 1800)."\n".route('status'), 'allowed_mentions' => ['parse' => []]],
            'telegram' => ['text' => Str::limit($incident->status->label().': '.$incident->name, 1800)."\n".route('status')],
            'signal' => ['message' => Str::limit(($incident->resolved_at ? 'Resolved: ' : 'Incident: ').$incident->name, 1800)."\n".route('status')],
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
        $this->retryAfter = null;
        if ($endpoint->format === 'signal') {
            $payload += ['number' => $endpoint->options['number'], 'recipients' => [$endpoint->options['recipient']]];
        }
        if ($endpoint->format === 'telegram') {
            $payload['chat_id'] = $endpoint->options['chat_id'];
        }
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $headers = ['Content-Type' => 'application/json'];

        // Only the generic shape is signed. Slack and Teams drop unknown headers,
        // and their URL is the credential in the first place.
        if ($endpoint->format === 'generic') {
            $secret = Setting::get('integrations.webhook_secret', '');
            $headers['X-Pharos-Signature'] = hash_hmac('sha256', $body, $secret);
        }

        if ($endpoint->format === 'signal') {
            $headers['Authorization'] = 'Bearer '.$endpoint->options['token'];
        }

        try {
            // Vetted again here, not only when the endpoint was saved: a name can
            // be re-pointed at the metadata service in between (DNS rebinding).
            $response = $this->safe->toOwn($endpoint->url)->timeout(5)->connectTimeout(5)->withHeaders($headers)
                ->withBody($body, 'application/json')->post($endpoint->url);

            $successful = $response->successful() && ($endpoint->format !== 'telegram' || $response->json('ok') === true);
            $retry = $endpoint->format === 'telegram'
                ? ($response->json('parameters.retry_after') ?? $response->header('Retry-After'))
                : $response->header('Retry-After');
            if ($retry !== '') {
                $seconds = is_numeric($retry) ? (int) $retry : max(0, (strtotime($retry) ?: time()) - time());
                $this->retryAfter = min(86400, max(60, $seconds));
            }
            $endpoint->forceFill([
                'last_status' => $response->status(),
                // The status is what diagnoses it. The body is the receiver's,
                // could be anything, and would be stored and shown in the admin.
                'last_error' => $successful ? null : ($response->successful() ? 'Telegram rejected the message.' : "HTTP {$response->status()}"),
                'last_attempt_at' => now(),
            ])->save();

            return $successful;
        } catch (\Throwable $e) {
            Log::warning('Outgoing webhook failed', ['endpoint' => $endpoint->id, 'type' => get_class($e)]);

            $endpoint->forceFill([
                'last_status' => null,
                'last_error' => str_contains($e->getMessage(), 'never allowed') ? 'Destination is never allowed.' : 'Connection failed; check the address, DNS, TLS and receiver.',
                'last_attempt_at' => now(),
            ])->save();

            return false;
        }
    }
}
