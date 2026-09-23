<?php

namespace App\Models;

use App\Casts\LocalTime;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToStatusPage;
use App\Models\Concerns\LocalTimestamps;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * @property string $url
 * @property array{number?: string, recipient?: string, token?: string, chat_id?: string}|null $options
 * @property int $status_page_id
 * @property list<string>|null $events
 */
class WebhookEndpoint extends Model
{
    use Auditable, BelongsToStatusPage, LocalTimestamps;

    protected $auditName = 'notification';

    /** Columns the check runner and delivery code touch on their own. */
    protected $auditIgnore = ['last_attempt_at', 'last_status', 'last_error'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'options' => 'encrypted:array',
            'events' => 'array',
            'last_attempt_at' => LocalTime::class,
            'created_at' => LocalTime::class,
            'updated_at' => LocalTime::class,
        ];
    }

    /** The shapes we can speak, and what to call them in the interface. */
    public const FORMATS = [
        'generic' => 'Generic JSON (n8n, Zapier, your own)',
        'slack' => 'Slack',
        'teams' => 'Microsoft Teams',
        'discord' => 'Discord',
        'telegram' => 'Telegram',
        'signal' => 'Signal (own bridge)',
    ];

    /**
     * The moments a destination can be told about. Resolution arrives as an
     * incident.updated payload, so this is a choice of category, not of the
     * event name inside the payload.
     */
    public const EVENTS = [
        'incident.opened' => 'Incident opened',
        'incident.updated' => 'Update posted',
        'incident.resolved' => 'Resolved',
        'maintenance' => 'Maintenance',
    ];

    /** What a new destination starts with. */
    public const DEFAULT_EVENTS = ['incident.opened', 'incident.updated', 'incident.resolved'];

    /** Null is the choice every destination had before events existed: all of them. */
    public function wants(string $category): bool
    {
        return $this->events === null || in_array($category, $this->events, true);
    }

    /** @return list<string> */
    public function chosenEvents(): array
    {
        return $this->events ?? array_keys(self::EVENTS);
    }

    /** @return Attribute<string, string> */
    protected function url(): Attribute
    {
        return Attribute::make(
            get: fn ($value) => str_starts_with($value, 'http') ? $value : Crypt::decryptString($value),
            set: fn ($value) => Crypt::encryptString($value),
        );
    }

    public function auditFilter(array $changes): array
    {
        if (isset($changes['options'])) {
            $changes['options'] = ['from' => '****', 'to' => '****'];
        }

        return $changes;
    }

    /**
     * A Slack or Teams webhook URL is a bearer credential: whoever has it can
     * post to that channel. Shown masked so a screen share or a screenshot of
     * this page does not hand it over.
     */
    public function maskedUrl(): string
    {
        if ($this->format === 'telegram') {
            return 'https://api.telegram.org/bot…/sendMessage';
        }
        $parts = parse_url($this->url);
        $host = ($parts['host'] ?? $this->url).(isset($parts['port']) ? ':'.$parts['port'] : ''); // :8799 is half the address on a LAN
        $path = $parts['path'] ?? '';

        return ($parts['scheme'] ?? 'https').'://'.$host.
            (strlen($path) > 8 ? substr($path, 0, 8).'…' : $path);
    }

    /** @return array{0: string, 1: string} tone and word for the destination list */
    public function health(): array
    {
        return match (true) {
            ! $this->enabled => ['off', 'Paused'],
            $this->last_attempt_at === null => ['off', 'Not tried yet'],
            $this->last_error !== null => ['b', 'Failing'],
            default => ['ok', 'Working'],
        };
    }

    /** What a failing answer usually means, in words; null while it works. */
    public function hint(): ?string
    {
        if ($this->last_error === null || $this->last_attempt_at === null) {
            return null;
        }
        $status = (int) $this->last_status;

        return match (true) {
            $status === 0 => 'could not be reached. Check the address, DNS and TLS, and that the receiver is running.',
            $status === 404 && $this->format === 'generic' => 'answers 404. Usually the workflow is not active, or a Test URL was used instead of the Production URL.',
            $status === 404, $status === 410 => "answers {$status}. The webhook was probably removed on the other side; create a new one and replace this destination.",
            in_array($status, [401, 403], true) => "answers {$status}. The receiver refuses the request: check the token or the permissions of the webhook.",
            $status === 429 => 'is rate limiting Pharos. Deliveries retry on their own after the pause it asks for.',
            $status >= 500 => "answers {$status}. The receiving side had an error; Pharos retries with growing pauses.",
            default => "answers {$status}. See the delivery log for each attempt.",
        };
    }

    public function formatLabel(): string
    {
        return self::FORMATS[$this->format] ?? $this->format;
    }
}
