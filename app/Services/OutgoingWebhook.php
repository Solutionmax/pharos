<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\Setting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fires on every status change so n8n (or anything else) can hang a Telegram
 * message, a customer email or a ticket off it.
 */
class OutgoingWebhook
{
    public function incidentChanged(Incident $incident, string $event): void
    {
        $url = Setting::get('integrations.webhook_url');

        if (! $url) {
            return;
        }

        $payload = [
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

        $this->post($url, $payload);
    }

    protected function post(string $url, array $payload): void
    {
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $secret = Setting::get('integrations.webhook_secret', '');

        try {
            // Short timeout on purpose: a slow receiver must not hold up publishing
            // an incident. Failure is logged, never surfaced to the operator mid-outage.
            Http::timeout(5)
                ->withHeaders([
                    'Content-Type' => 'application/json',
                    'X-Pharos-Signature' => hash_hmac('sha256', $body, $secret),
                ])
                ->withBody($body, 'application/json')
                ->post($url);
        } catch (\Throwable $e) {
            Log::warning('Outgoing webhook failed', ['url' => $url, 'error' => $e->getMessage()]);
        }
    }
}
