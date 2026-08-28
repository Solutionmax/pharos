<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Models\IncidentUpdate;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The mails subscribers receive, as Markdown templates the customer may edit.
 * Only the body and the subject are theirs; the frame around it (logo, accent,
 * the status-page link and the unsubscribe link in the footer) stays ours, so a
 * template can never lose the way out.
 */
class MailTemplates
{
    /** Same rules as IncidentUpdate::messageHtml(): tags are shown, never run. */
    /** The status page's incident colours (partials/tokens), so a mail reads like the page it points to. */
    public const TONES = ['ok' => '#12b76a', 'w' => '#f79009', 'p' => '#e86b1c', 'b' => '#f04438'];

    public const MARKDOWN = [
        'html_input' => 'escape',
        'allow_unsafe_links' => false,
        'renderer' => ['soft_break' => "<br>\n"],
    ];

    protected const INCIDENT_TAGS = ['brand', 'incident', 'status', 'message', 'components', 'link', 'unsubscribe', 'when', 'name'];

    protected const INCIDENT_BODY = <<<'MD'
    {status}

    # {incident}

    Affects **{components}**

    > {message}

    {when}

    [View status page]({link})
    MD;

    /** @var array<string, array{label: string, tags: list<string>, subject: string, body: string}> */
    protected const TEMPLATES = [
        'subscribe_confirm' => [
            'label' => 'Subscribe confirmation',
            'tags' => ['brand', 'link', 'hours', 'name'],
            'subject' => 'Confirm your subscription to {brand} status updates',
            'body' => <<<'MD'
            # Confirm your subscription

            You asked to be told about incidents on the {brand} status page. Confirm the address and we will mail you when something happens — and when it is fixed.

            [Confirm subscription]({link})

            The link is good for {hours} hours. If you did not ask for this, ignore this mail; the address is forgotten on its own.
            MD,
        ],
        'incident_opened' => [
            'label' => 'Incident opened',
            'tags' => self::INCIDENT_TAGS,
            'subject' => '[{brand}] {incident} — {status}',
            'body' => self::INCIDENT_BODY,
        ],
        'incident_updated' => [
            'label' => 'Incident updated',
            'tags' => self::INCIDENT_TAGS,
            'subject' => '[{brand}] {incident} — {status}',
            'body' => self::INCIDENT_BODY,
        ],
        'incident_resolved' => [
            'label' => 'Incident resolved',
            'tags' => self::INCIDENT_TAGS,
            'subject' => '[{brand}] {incident} — {status}',
            'body' => self::INCIDENT_BODY,
        ],
    ];

    /** @return list<string> */
    public static function keys(): array
    {
        return array_keys(self::TEMPLATES);
    }

    /** @return array<string, string> key => label */
    public static function labels(): array
    {
        return array_map(fn ($t) => $t['label'], self::TEMPLATES);
    }

    /** @return list<string> */
    public static function tags(string $key): array
    {
        return array_map(fn ($tag) => '{'.$tag.'}', self::TEMPLATES[$key]['tags']);
    }

    public static function defaultSubject(string $key): string
    {
        return self::TEMPLATES[$key]['subject'];
    }

    public static function defaultBody(string $key): string
    {
        return self::TEMPLATES[$key]['body'];
    }

    /** Which incident template an update gets: first word, last word, or one in between. */
    public static function forUpdate(IncidentUpdate $update): string
    {
        if ($update->status === IncidentStatus::Resolved) {
            return 'incident_resolved';
        }

        $first = ! $update->incident->updates()->where('id', '<', $update->id)->exists();

        return $first ? 'incident_opened' : 'incident_updated';
    }

    /** The {name} tag: the part before the @, which is as close to a name as a subscriber gets. */
    public static function nameFor(string $email): string
    {
        return Str::before($email, '@') ?: $email;
    }

    public function subject(string $key): string
    {
        return (string) (Setting::get("mail.template.$key.subject") ?? self::defaultSubject($key));
    }

    public function body(string $key): string
    {
        return (string) (Setting::get("mail.template.$key.body") ?? self::defaultBody($key));
    }

    public function isDefault(string $key): bool
    {
        return Setting::get("mail.template.$key.subject") === null
            && Setting::get("mail.template.$key.body") === null;
    }

    public function save(string $key, string $subject, string $body): void
    {
        Setting::put("mail.template.$key.subject", $subject);
        Setting::put("mail.template.$key.body", str_replace("\r\n", "\n", $body));
    }

    public function reset(string $key): void
    {
        Setting::put("mail.template.$key.subject", null);
        Setting::put("mail.template.$key.body", null);
    }

    /**
     * Subject, HTML and plain text for one template. Tag values arrive as text
     * and stay text; only {message} is Markdown, because the operator wrote it
     * as Markdown. Unknown tags are left as typed.
     *
     * @param  array<string, mixed>  $vars  tag name (without braces) => value
     * @return array{subject: string, html: string, text: string}
     */
    public function render(string $key, array $vars, ?string $subject = null, ?string $body = null): array
    {
        $vars = array_map(fn ($v) => str_replace("\r\n", "\n", (string) $v), $vars);
        $frame = self::frame($vars['unsubscribe'] ?? null);

        // Not a tag: the state colour is the frame's business, never something to type.
        $line = self::TONES[$vars['tone'] ?? ''] ?? null;
        unset($vars['tone']);

        $subject = strtr($subject ?? $this->subject($key), self::pairs($vars, escape: false));
        $markdown = self::substitute(str_replace("\r\n", "\n", $body ?? $this->body($key)), $vars);
        $html = Str::markdown($markdown, self::MARKDOWN);

        $footer = "\n\n{$frame['brand']} status page: {$frame['link']}"
            .($frame['unsubscribe'] ? "\nUnsubscribe: {$frame['unsubscribe']}" : '');

        return [
            // A newline in a subject is a header injection; the line is folded instead.
            'subject' => trim(preg_replace('/\s*\n\s*/', ' ', $subject)),
            'html' => view('mail.template', $frame + ['tone' => $line, 'body' => MailMarkup::style($html, $frame['accent'], $line)])->render(),
            'text' => MailMarkup::text($html).$footer,
        ];
    }

    /**
     * What the frame needs. url() rather than the stored path: a mail is read
     * away from the site, so a relative logo path shows a broken image.
     *
     * @return array{brand: string, accent: string, logo: ?string, link: string, unsubscribe: ?string}
     */
    public static function frame(?string $unsubscribe = null): array
    {
        $branding = app(Branding::class);
        $logo = $branding->logoUrl();

        return [
            'brand' => $branding->name(),
            'accent' => $branding->accent(),
            'logo' => $logo ? url($logo) : null,
            'link' => route('status'),
            'unsubscribe' => $unsubscribe ?: null,
        ];
    }

    /**
     * Sample values for the preview and the test mail, so an admin sees a real
     * incident rather than a row of braces.
     *
     * @return array<string, string|int>
     */
    public function sample(string $key, User $user): array
    {
        $name = self::nameFor($user->email);

        if ($key === 'subscribe_confirm') {
            return [
                'brand' => self::frame()['brand'],
                'link' => url('/subscribe/confirm/preview'),
                'hours' => Subscriber::CONFIRM_HOURS,
                'name' => $name,
            ];
        }

        $status = match ($key) {
            'incident_opened' => IncidentStatus::Investigating,
            'incident_resolved' => IncidentStatus::Resolved,
            default => IncidentStatus::Identified,
        };

        return [
            'brand' => self::frame()['brand'],
            'incident' => 'Outbound e-mail delayed',
            'status' => $status->label(),
            'message' => "We are seeing **delays of up to 20 minutes** on outbound mail while the queue drains.\n\n- Incoming mail is not affected\n- No messages are lost",
            'components' => 'Mail, Outbound queue',
            'link' => route('status'),
            'unsubscribe' => url('/unsubscribe/preview'),
            'when' => \App\Services\Clock::now()->format('j F Y, H:i'), // the customer's zone, like a real notice
            'name' => $name,
            'tone' => $key === 'incident_resolved' ? 'ok' : 'p',
        ];
    }

    /** @param  array<string, string>  $vars */
    protected static function substitute(string $body, array $vars): string
    {
        // "Affects {components}" with nothing affected would print "Affects":
        // a line whose only tag is empty is left out altogether.
        foreach ($vars as $tag => $value) {
            if ($value === '') {
                $body = preg_replace('/^[^\n{]*\{'.$tag.'\}[^\n{]*(?:\n|$)/m', '', $body);
            }
        }

        // "> {message}" must quote the whole message, not just its first line:
        // whatever precedes the tag on its line is repeated for every line.
        if (isset($vars['message'])) {
            $body = preg_replace_callback(
                '/^([ >]+)\{message\}/m',
                fn ($m) => $m[1].str_replace("\n", "\n".$m[1], $vars['message']),
                $body,
            );
        }

        return strtr($body, self::pairs($vars, escape: true));
    }

    /**
     * @param  array<string, string>  $vars
     * @return array<string, string>
     */
    protected static function pairs(array $vars, bool $escape): array
    {
        $pairs = [];
        foreach ($vars as $tag => $value) {
            $pairs['{'.$tag.'}'] = $escape && $tag !== 'message' ? self::escape($value) : $value;
        }

        return $pairs;
    }

    /** Backslash-escapes Markdown punctuation, so a value is printed as typed. */
    protected static function escape(string $value): string
    {
        return preg_replace('/([\\\\`*_{}\[\]()#+\-.!<>|~])/', '\\\\$1', $value);
    }
}
