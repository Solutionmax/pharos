<?php

namespace Database\Seeders;

use App\Enums\CheckType;
use App\Enums\ComponentStatus;
use App\Enums\Impact;
use App\Enums\IncidentStatus;
use App\Models\ApiToken;
use App\Models\Check;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\IncidentTemplate;
use App\Models\IncidentUpdate;
use App\Models\Setting;
use App\Models\UptimeDay;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

/** Shaped like a real hosting company's status page, so the demo is not a toy.
 *  Every name here is invented — web-01.example.net and friends. */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        Setting::put('brand.name', 'Pharos');
        Setting::put('brand.accent', '#0079d2');
        Setting::put('brand.credit_hidden', '0');

        $hosting = ComponentGroup::create(['name' => 'Shared hosting', 'position' => 1, 'collapsed' => true]);
        $email = ComponentGroup::create(['name' => 'Email', 'position' => 2, 'collapsed' => false]);
        $network = ComponentGroup::create(['name' => 'Network & DNS', 'position' => 3, 'collapsed' => true]);

        $servers = ['web-01', 'web-02', 'web-03', 'web-04', 'web-05', 'web-06', 'web-07', 'web-08'];
        foreach ($servers as $i => $name) {
            $component = Component::create([
                'component_group_id' => $hosting->id,
                'name' => $name,
                'description' => "Availability of {$name}.example.net",
                'link' => "https://{$name}.example.net",
                'tags' => 'shared, cpanel',
                'status' => ComponentStatus::Operational,
                'source' => 'check',
                'position' => $i,
            ]);

            Check::create([
                'component_id' => $component->id,
                'type' => CheckType::Http,
                'target' => "https://{$name}.example.net/",
                'interval_seconds' => 60,
                'retries' => 2,
            ]);

            $this->fakeHistory($component, failureDays: $name === 'web-08' ? [35, 58, 71] : ($name === 'web-06' ? [12] : []));
        }

        $queue = Component::create([
            'component_group_id' => $email->id,
            'name' => 'Outbound queue',
            'description' => 'Delivery to external providers',
            'tags' => 'mail',
            'status' => ComponentStatus::PartialOutage,
            'source' => 'webhook',
            'position' => 1,
        ]);
        $this->fakeHistory($queue, failureDays: [0, 26, 44]);

        $imap = Component::create([
            'component_group_id' => $email->id,
            'name' => 'IMAP / SMTP',
            'description' => 'mail.example.net',
            'status' => ComponentStatus::Operational,
            'source' => 'check',
            'position' => 2,
        ]);
        Check::create([
            'component_id' => $imap->id,
            'type' => CheckType::Tcp,
            'target' => 'mail.example.net:993',
            'interval_seconds' => 60,
        ]);
        $this->fakeHistory($imap, failureDays: []);

        $ns = Component::create([
            'component_group_id' => $network->id,
            'name' => 'Nameservers',
            'description' => 'ns1 / ns2',
            'status' => ComponentStatus::Operational,
            'source' => 'check',
            'position' => 1,
        ]);
        $this->fakeHistory($ns, failureDays: []);

        $backups = Component::create([
            'component_group_id' => $network->id,
            'name' => 'Backups',
            'description' => 'Nightly restic run',
            'status' => ComponentStatus::Operational,
            'source' => 'heartbeat',
            'show_uptime' => false,
            'position' => 2,
        ]);
        Check::create([
            'component_id' => $backups->id,
            'type' => CheckType::Heartbeat,
            'target' => 'hb_'.str_repeat('a', 8),
            'interval_seconds' => 86400,
            'last_run_at' => now()->subHours(3),
        ]);

        IncidentTemplate::create([
            'name' => 'Server unreachable',
            'slug' => 'server-unreachable',
            'title_template' => '{{server}} unreachable',
            'body_template' => 'We identified an outage on **{{server}}**, starting at {{started_at}}. '
                .'We are investigating and will post an update within 30 minutes.',
        ]);

        $open = Incident::create([
            'name' => 'Outbound email delayed',
            'status' => IncidentStatus::Watching,
            'impact' => Impact::Major,
            'source' => 'api',
            'occurred_at' => now()->setTime(10, 52),
        ]);
        $open->components()->attach($queue->id, ['status' => ComponentStatus::PartialOutage->value]);

        foreach ([
            [IncidentStatus::Investigating, '10:52', 'Queue length above threshold (> 500 messages).', true],
            [IncidentStatus::Identified, '11:20', 'One of our outbound IP addresses was listed on a blocklist. Traffic now runs through a backup IP and a delisting request is in progress.', false],
            [IncidentStatus::Watching, '12:48', 'The queue is draining, 1,240 messages left. We will keep this open until it reaches zero.', false],
        ] as [$status, $time, $message, $auto]) {
            IncidentUpdate::create([
                'incident_id' => $open->id,
                'status' => $status,
                'message' => $message,
                'automatic' => $auto,
                'created_at' => Carbon::today()->setTimeFromTimeString($time),
                'updated_at' => Carbon::today()->setTimeFromTimeString($time),
            ]);
        }

        $resolved = Incident::create([
            'name' => 'web-06 unreachable',
            'status' => IncidentStatus::Resolved,
            'impact' => Impact::Major,
            'source' => 'check',
            'auto_resolve' => true,
            'grouping_key' => 'check:7',
            'occurred_at' => now()->subDays(2)->setTime(16, 53),
            'resolved_at' => now()->subDays(2)->setTime(17, 27),
        ]);
        $resolved->components()->attach(
            Component::where('name', 'web-06')->first()->id,
            ['status' => ComponentStatus::MajorOutage->value],
        );
        foreach ([
            [IncidentStatus::Investigating, '16:53', 'Automatic check failed: no response on HTTP.', true],
            [IncidentStatus::Resolved, '17:27', 'The component responded normally again for 3 consecutive checks.', true],
        ] as [$status, $time, $message, $auto]) {
            IncidentUpdate::create([
                'incident_id' => $resolved->id,
                'status' => $status,
                'message' => $message,
                'automatic' => $auto,
                'created_at' => now()->subDays(2)->setTimeFromTimeString($time),
                'updated_at' => now()->subDays(2)->setTimeFromTimeString($time),
            ]);
        }

        [$token, $plain] = ApiToken::issue('demo');
        $this->command->info("Demo API token: {$plain}");
    }

    /** Fills 90 days of roll-up data so the bars have something to show. */
    protected function fakeHistory(Component $component, array $failureDays): void
    {
        $perDay = 86400;

        for ($i = 0; $i < 90; $i++) {
            $down = in_array($i, $failureDays, true) ? random_int(300, 5400) : 0;

            UptimeDay::create([
                'component_id' => $component->id,
                'day' => Carbon::today()->subDays($i)->format('Y-m-d'),
                'up_seconds' => $perDay - $down,
                'down_seconds' => $down,
                'worst_status' => $down > 0
                    ? ComponentStatus::MajorOutage->value
                    : ComponentStatus::Operational->value,
            ]);
        }
    }
}
