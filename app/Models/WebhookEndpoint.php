<?php

namespace App\Models;

use App\Casts\LocalTime;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\LocalTimestamps;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

/**
 * @property string $url
 * @property array{number?: string, recipient?: string, token?: string}|null $options
 */
class WebhookEndpoint extends Model
{
    use Auditable, LocalTimestamps;

    protected $auditName = 'notification';

    /** Columns the check runner and delivery code touch on their own. */
    protected $auditIgnore = ['last_attempt_at', 'last_status', 'last_error'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'options' => 'encrypted:array',
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
        'signal' => 'Signal (own bridge)',
    ];

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
        $parts = parse_url($this->url);
        $host = ($parts['host'] ?? $this->url).(isset($parts['port']) ? ':'.$parts['port'] : ''); // :8799 is half the address on a LAN
        $path = $parts['path'] ?? '';

        return ($parts['scheme'] ?? 'https').'://'.$host.
            (strlen($path) > 8 ? substr($path, 0, 8).'…' : $path);
    }

    public function formatLabel(): string
    {
        return self::FORMATS[$this->format] ?? $this->format;
    }
}
