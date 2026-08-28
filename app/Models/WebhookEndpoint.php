<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class WebhookEndpoint extends Model
{
    use Auditable;

    protected $auditName = 'notification';

    /** Columns the check runner and delivery code touch on their own. */
    protected $auditIgnore = ['last_attempt_at', 'last_status', 'last_error'];

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'last_attempt_at' => 'datetime',
        ];
    }

    /** The shapes we can speak, and what to call them in the interface. */
    public const FORMATS = [
        'generic' => 'Generic JSON (n8n, Zapier, your own)',
        'slack' => 'Slack',
        'teams' => 'Microsoft Teams',
    ];

    /**
     * A Slack or Teams webhook URL is a bearer credential: whoever has it can
     * post to that channel. Shown masked so a screen share or a screenshot of
     * this page does not hand it over.
     */
    public function maskedUrl(): string
    {
        $parts = parse_url($this->url);
        $host = $parts['host'] ?? $this->url;
        $path = $parts['path'] ?? '';

        return ($parts['scheme'] ?? 'https').'://'.$host.
            (strlen($path) > 8 ? substr($path, 0, 8).'…' : $path);
    }

    public function formatLabel(): string
    {
        return self::FORMATS[$this->format] ?? $this->format;
    }
}
