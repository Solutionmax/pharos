<?php

namespace Database\Seeders;

use App\Enums\CheckType;
use App\Enums\ComponentStatus;
use App\Enums\Impact;
use App\Enums\IncidentStatus;
use App\Models\Check;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\IncidentTemplate;
use App\Models\IncidentUpdate;
use App\Models\Maintenance;
use App\Models\Setting;
use App\Models\Subscriber;
use App\Models\SubscriberNotification;
use App\Models\UptimeDay;
use App\Models\WebhookDelivery;
use App\Models\WebhookEndpoint;
use App\Services\Subscriptions;
use Illuminate\Support\Carbon;

/**
 * What sits on each demo page. Every method runs inside PageContext::run for
 * its own page, so the page scope of the models puts each row where it belongs.
 * Rows are found by a natural key (a name, a title, an address) and updated in
 * place, which is what lets the seeder run twice.
 */
class DemoPageContent
{
    protected const DAY_SECONDS = 86400;

    /** The default page: a shared hosting company with one open incident. */
    public function northwind(): void
    {
        $this->brand('Northwind Hosting', '#0079d2');

        $hosting = $this->group('Shared hosting', 1, collapsed: true);
        $email = $this->group('Email', 2, collapsed: false);
        $network = $this->group('Network and DNS', 3, collapsed: true);

        foreach (['web-01', 'web-02', 'web-03', 'web-04', 'web-05', 'web-06', 'web-07', 'web-08'] as $i => $name) {
            $component = $this->component($hosting, $name, $i, [
                'description' => "Availability of {$name}.example.net",
                'link' => "https://{$name}.example.net",
                'tags' => 'shared, cpanel',
                'source' => 'check',
            ], [CheckType::Http, "https://{$name}.example.net/"]);
            $this->history($component, match ($name) {
                'web-08' => [35 => 2400, 58 => 900, 71 => 4100],
                'web-06' => [2 => 2040],
                'web-03' => [17 => 600],
                default => [],
            });
        }

        $queue = $this->component($email, 'Outbound queue', 1, [
            'description' => 'Delivery to external providers',
            'tags' => 'mail',
            'status' => ComponentStatus::PartialOutage,
            'source' => 'webhook',
        ]);
        $this->history($queue, [0 => 5200, 26 => 1800, 44 => 700], ComponentStatus::PartialOutage);

        $imap = $this->component($email, 'IMAP and SMTP', 2, ['description' => 'mail.example.net', 'source' => 'check'], [CheckType::Tcp, 'mail.example.net:993']);
        $this->history($imap, [44 => 300]);

        $ns = $this->component($network, 'Nameservers', 1, ['description' => 'ns1 and ns2', 'source' => 'check'], [CheckType::Tcp, 'ns1.example.net:53']);
        $this->history($ns, []);

        $backups = $this->component($network, 'Backups', 2, [
            'description' => 'Nightly backup run',
            'source' => 'heartbeat',
            'show_uptime' => false,
        ], [CheckType::Heartbeat, 'hb_northwind_nightly', 86400]);
        $this->history($backups, []);

        $this->templates([
            ['Server unreachable', 'server-unreachable', '{{server}} unreachable',
                'We identified an outage on **{{server}}**, starting at {{started_at}}. We are investigating and will post an update within 30 minutes.'],
            ['Mail delivery delayed', 'mail-delayed', 'Outbound email delayed',
                'Outgoing mail is queued and delivered with a delay. Nothing is lost; messages go out as soon as the queue drains.'],
            ['Resolved after monitoring', 'resolved', '{{title}}',
                'Everything has been running normally since {{resolved_at}}. We are closing this incident.'],
        ]);

        $this->incident('Outbound email delayed', Impact::Major, 'api', now()->subMinutes(118), null, [$queue->id => ComponentStatus::PartialOutage], [
            [IncidentStatus::Investigating, 0, 'Queue length above threshold (more than 500 messages).', true],
            [IncidentStatus::Identified, 28, 'One of our outbound IP addresses was listed on a blocklist. Traffic now runs through a backup IP and a delisting request is in progress.', false],
            [IncidentStatus::Watching, 116, 'The queue is draining, 1,240 messages left. We keep this open until it reaches zero.', false],
        ]);

        $start = now()->subDays(2)->setTime(16, 53);
        $this->incident('web-06 unreachable', Impact::Major, 'check', $start, $start->copy()->addMinutes(34), [$this->id('web-06') => ComponentStatus::MajorOutage], [
            [IncidentStatus::Investigating, 0, 'Automatic check failed: no response on HTTP.', true],
            [IncidentStatus::Identified, 12, 'A kernel update left the web server stopped after the reboot. We are starting it by hand.', false],
            [IncidentStatus::Resolved, 34, 'The component responded normally again for 3 consecutive checks.', true],
        ], auto: true);

        $start = now()->subDays(26)->setTime(8, 10);
        $this->incident('Mail from Northwind flagged as spam', Impact::Minor, 'manual', $start, $start->copy()->addMinutes(95), [$queue->id => ComponentStatus::PerformanceIssues], [
            [IncidentStatus::Investigating, 0, 'Some recipients report our mail landing in spam folders.', false],
            [IncidentStatus::Resolved, 95, 'A missing DKIM record on one relay was restored. Delivery is back to normal.', false],
        ]);

        $this->maintenance('Storage upgrade on web-03 and web-04', now()->addDays(2)->setTime(2, 0), 120,
            'The disks behind web-03 and web-04 move to faster storage. Sites stay online; expect a short pause of about a minute each.',
            [$this->id('web-03'), $this->id('web-04')], done: false);
        $this->maintenance('Nameserver software update', now()->subDays(9)->setTime(3, 0), 60,
            'ns1 and ns2 are updated one after the other, so name resolution keeps working throughout.',
            [$ns->id], done: true);

        $this->subscribers([
            'alerts@example.net', 'webmaster@example.net', 'it@bakery.example.net', 'ops@studio.example.net',
            'owner@garden.example.net', 'support@shop.example.net', 'admin@clinic.example.net',
        ], pending: ['new@example.net'], unsubscribed: ['old@example.net']);
        $this->mailed('web-06 unreachable');

        $this->destinations([
            ['Slack #ops alerts', 'slack', 'https://hooks.slack.example.net/services/T000/B000/demo', true, null],
            ['Teams support channel', 'teams', 'https://teams.example.net/webhook/demo', true, 'HTTP 503 from receiver'],
            ['n8n workflow', 'generic', 'https://n8n.example.net/webhook/pharos-demo', false, null],
        ], ['Outbound email delayed', 'web-06 unreachable']);
    }

    /** A second brand on the same installation, with its own colour and plans. */
    public function harbor(): void
    {
        $this->brand('Harbor Logistics', '#0f766e');

        $apps = $this->group('Apps', 1, collapsed: false);
        $integrations = $this->group('Partner integrations', 2, collapsed: false);

        $portal = $this->component($apps, 'Tracking portal', 1, ['description' => 'track.example.net', 'source' => 'check'], [CheckType::Http, 'https://track.example.net/']);
        $this->history($portal, [13 => 1300]);
        $driver = $this->component($apps, 'Driver app API', 2, ['description' => 'Routes, scans and proof of delivery', 'source' => 'check'], [CheckType::Http, 'https://api.example.net/health']);
        $this->history($driver, [40 => 2700, 41 => 600]);
        $edi = $this->component($integrations, 'EDI gateway', 1, ['description' => 'Orders from partner systems', 'source' => 'webhook']);
        $this->history($edi, [6 => 900]);
        $scan = $this->component($integrations, 'Warehouse scanners', 2, ['description' => 'Rotterdam and Antwerp sites', 'source' => 'heartbeat'], [CheckType::Heartbeat, 'hb_harbor_scanners', 300]);
        $this->history($scan, []);

        $this->templates([
            ['Carrier delay', 'carrier-delay', 'Delays with {{carrier}}',
                'Shipments handed to {{carrier}} are running behind schedule. Tracking updates follow as soon as the carrier reports them.'],
        ]);

        $start = now()->subDays(6)->setTime(14, 5);
        $this->incident('EDI orders arriving late', Impact::Minor, 'manual', $start, $start->copy()->addMinutes(48), [$edi->id => ComponentStatus::PerformanceIssues], [
            [IncidentStatus::Investigating, 0, 'Orders from two partners arrive up to 20 minutes late.', false],
            [IncidentStatus::Resolved, 48, 'A stuck import job was restarted and the backlog is processed.', false],
        ]);

        $this->maintenance('Driver app API migration', now()->addDays(5)->setTime(22, 0), 90,
            'The driver app API moves to a new cluster. Drivers may need to sign in again afterwards.',
            [$driver->id], done: false);

        $this->subscribers(['dispatch@example.net', 'planning@example.net', 'customer@retail.example.net'], pending: [], unsubscribed: []);

        $this->destinations([
            ['Slack #dispatch', 'slack', 'https://hooks.slack.example.net/services/T000/B111/demo', true, null],
        ], ['EDI orders arriving late']);
    }

    /** A draft page nobody outside sees yet. */
    public function internal(): void
    {
        $this->brand('Northwind Internal', '#6d28d9');

        $tools = $this->group('Internal tools', 1, collapsed: false);
        foreach (['Billing', 'Ticket desk', 'VPN'] as $i => $name) {
            $component = $this->component($tools, $name, $i + 1, ['description' => strtolower($name).'.internal.example.net']);
            $this->history($component, $name === 'VPN' ? [21 => 1500] : []);
        }
    }

    // ---------------------------------------------------------------- building blocks

    protected function brand(string $name, string $accent): void
    {
        Setting::put('brand.name', $name);
        Setting::put('brand.accent', $accent);
        Setting::put('brand.credit_hidden', '0');
        Setting::put(Subscriptions::KEY, '1');
    }

    protected function group(string $name, int $position, bool $collapsed): ComponentGroup
    {
        return ComponentGroup::query()->updateOrCreate(['name' => $name], ['position' => $position, 'collapsed' => $collapsed]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array{0: CheckType, 1: string, 2?: int}|null  $check  type, target and interval in seconds
     */
    protected function component(ComponentGroup $group, string $name, int $position, array $attributes, ?array $check = null): Component
    {
        $component = Component::query()->updateOrCreate(['name' => $name], $attributes + [
            'component_group_id' => $group->id,
            'position' => $position,
            'status' => ComponentStatus::Operational,
        ]);

        if ($check !== null) {
            Check::query()->updateOrCreate(['component_id' => $component->id], [
                'type' => $check[0],
                'target' => $check[1],
                'interval_seconds' => $check[2] ?? 60,
                'last_run_at' => now()->subSeconds(40),
            ]);
        }

        return $component;
    }

    protected function id(string $componentName): int
    {
        return (int) Component::query()->where('name', $componentName)->value('id');
    }

    /**
     * 90 days of roll up data. $outages maps days ago to seconds down; a short
     * outage marks the day with performance issues, a long one with an outage.
     *
     * @param  array<int, int>  $outages
     */
    protected function history(Component $component, array $outages, ComponentStatus $today = ComponentStatus::MajorOutage): void
    {
        for ($i = 0; $i < 90; $i++) {
            $down = min($outages[$i] ?? 0, self::DAY_SECONDS);
            $worst = match (true) {
                $down === 0 => ComponentStatus::Operational,
                $i === 0 => $today,
                $down < 1000 => ComponentStatus::PerformanceIssues,
                $down < 3000 => ComponentStatus::PartialOutage,
                default => ComponentStatus::MajorOutage,
            };

            UptimeDay::query()->updateOrCreate(
                // A Carbon, not a string: the date cast stores "Y-m-d 00:00:00".
                ['component_id' => $component->id, 'day' => Carbon::today()->subDays($i)],
                ['up_seconds' => self::DAY_SECONDS - $down, 'down_seconds' => $down, 'worst_status' => $worst->value],
            );
        }
    }

    /** @param  list<array{0: string, 1: string, 2: string, 3: string}>  $templates */
    protected function templates(array $templates): void
    {
        foreach ($templates as [$name, $slug, $title, $body]) {
            IncidentTemplate::query()->updateOrCreate(['slug' => $slug], [
                'name' => $name, 'title_template' => $title, 'body_template' => $body,
            ]);
        }
    }

    /**
     * @param  array<int, ComponentStatus>  $components  component id => status the incident set
     * @param  list<array{0: IncidentStatus, 1: int, 2: string, 3: bool}>  $timeline  status, minutes after start, message, automatic
     */
    protected function incident(string $name, Impact $impact, string $source, Carbon $start, ?Carbon $resolved, array $components, array $timeline, bool $auto = false): void
    {
        $incident = Incident::query()->updateOrCreate(['name' => $name], [
            'status' => end($timeline)[0],
            'impact' => $impact,
            'source' => $source,
            'auto_resolve' => $auto,
            'occurred_at' => $start,
            'resolved_at' => $resolved,
        ]);
        $incident->components()->syncWithoutDetaching(
            array_map(fn (ComponentStatus $status) => ['status' => $status->value], $components),
        );

        // Without events: an update normally queues mail for every subscriber.
        IncidentUpdate::withoutEvents(function () use ($incident, $start, $timeline): void {
            foreach ($timeline as [$status, $minutes, $message, $automatic]) {
                $at = $start->copy()->addMinutes($minutes);
                IncidentUpdate::query()->updateOrCreate(
                    ['incident_id' => $incident->id, 'message' => $message],
                    ['status' => $status, 'automatic' => $automatic, 'created_at' => $at, 'updated_at' => $at],
                );
            }
        });
    }

    /** @param  list<int>  $componentIds */
    protected function maintenance(string $title, Carbon $start, int $minutes, string $message, array $componentIds, bool $done): void
    {
        $end = $start->copy()->addMinutes($minutes);
        $maintenance = Maintenance::query()->updateOrCreate(['title' => $title], [
            'message' => $message,
            'starts_at' => $start,
            'ends_at' => $end,
            'announce_minutes' => 1440,
            'announced_at' => $done ? $start->copy()->subDay() : null,
            'started_at' => $done ? $start : null,
            'completed_at' => $done ? $end : null,
        ]);

        $pivot = [];
        foreach ($componentIds as $id) {
            $pivot[$id] = $done
                ? ['previous_status' => ComponentStatus::Operational->value, 'applied_status' => ComponentStatus::UnderMaintenance->value]
                : ['previous_status' => null, 'applied_status' => null];
        }
        $maintenance->components()->syncWithoutDetaching($pivot);
    }

    /**
     * @param  list<string>  $active
     * @param  list<string>  $pending
     * @param  list<string>  $unsubscribed
     */
    protected function subscribers(array $active, array $pending, array $unsubscribed): void
    {
        $rows = array_merge(
            array_map(fn ($email) => [$email, true, false], $active),
            array_map(fn ($email) => [$email, false, false], $pending),
            array_map(fn ($email) => [$email, true, true], $unsubscribed),
        );

        foreach ($rows as $i => [$email, $verified, $gone]) {
            $subscriber = Subscriber::query()->firstOrCreate(['email' => $email], ['token' => Subscriber::freshToken()]);
            $subscriber->forceFill([
                'verified_at' => $verified ? now()->subDays(40 - $i * 4) : null,
                'unsubscribed_at' => $gone ? now()->subDays(3) : null,
                'created_at' => now()->subDays(40 - $i * 4)->subMinutes(5),
            ])->save();
        }
    }

    /**
     * The mail that went out about an incident, already marked as sent, so the
     * subscriber list shows a last notification and pharos:notify has nothing to do.
     */
    protected function mailed(string $incidentName): void
    {
        $updates = Incident::query()->where('name', $incidentName)->firstOrFail()->updates()->get();

        foreach (Subscriber::query()->active()->get() as $subscriber) {
            foreach ($updates as $update) {
                $sent = Carbon::parse($update->created_at)->addMinute();
                SubscriberNotification::query()->updateOrCreate(
                    ['subscriber_id' => $subscriber->id, 'incident_update_id' => $update->id],
                    ['sent_at' => $sent, 'attempts' => 1, 'error' => null, 'created_at' => $sent, 'updated_at' => $sent],
                );
            }
        }
    }

    /**
     * Destinations on example URLs and a delivery log written by hand. Nothing
     * is sent: the rows are finished (sent, or given up after six attempts), so
     * the delivery runner has nothing left to try.
     *
     * @param  list<array{0: string, 1: string, 2: string, 3: bool, 4: string|null}>  $destinations  label, format, url, enabled, last error
     * @param  list<string>  $incidents
     */
    protected function destinations(array $destinations, array $incidents): void
    {
        foreach ($destinations as [$label, $format, $url, $enabled, $error]) {
            $endpoint = WebhookEndpoint::query()->updateOrCreate(['label' => $label], [
                'url' => $url,
                'format' => $format,
                'enabled' => $enabled,
                'events' => WebhookEndpoint::DEFAULT_EVENTS,
                'last_attempt_at' => $enabled ? now()->subMinutes(26) : null,
                'last_status' => $enabled ? ($error ? 503 : 200) : null,
                'last_error' => $enabled ? $error : null,
            ]);

            if (! $enabled) {
                continue;
            }

            foreach ($incidents as $n => $name) {
                $incident = Incident::query()->where('name', $name)->first();
                if ($incident === null) {
                    continue;
                }
                foreach ($incident->updates()->reorder('created_at')->get() as $k => $update) {
                    $this->delivery($endpoint, $incident, $update, $k === 0 ? 'incident.opened' : 'incident.updated', $error !== null && $n === 0 && $k === 2);
                }
            }
        }
    }

    protected function delivery(WebhookEndpoint $endpoint, Incident $incident, IncidentUpdate $update, string $event, bool $failed): void
    {
        $at = Carbon::parse($update->created_at);

        WebhookDelivery::query()->updateOrCreate(
            ['webhook_endpoint_id' => $endpoint->id, 'event_key' => hash('sha256', 'demo:'.$endpoint->id.':'.$update->id)],
            [
                'status_page_id' => $endpoint->status_page_id,
                'event' => $update->status === IncidentStatus::Resolved ? 'incident.resolved' : $event,
                'payload' => ['event' => $event, 'incident' => ['name' => $incident->name, 'status' => $update->status->label()]],
                'attempts' => $failed ? 6 : 1,
                'next_attempt_at' => null,
                'sent_at' => $failed ? null : $at->copy()->addSeconds(4),
                'last_status' => $failed ? 503 : 200,
                'error' => $failed ? 'HTTP 503 from receiver' : null,
                'created_at' => $at,
                'updated_at' => $at->copy()->addSeconds(4),
            ],
        );
    }
}
