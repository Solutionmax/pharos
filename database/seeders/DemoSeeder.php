<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\ApiToken;
use App\Models\AuditEntry;
use App\Models\StatusPage;
use App\Models\User;
use App\Services\PageContext;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Demo data shaped like a real hosting company, so the screens are not a toy:
 * three status pages, a few people with page roles, API tokens and an audit
 * trail. What sits on each page (components, incidents, maintenance,
 * subscribers, destinations) lives in DemoPageContent.
 *
 * Every name is invented and every address is on example.net. Running it again
 * updates the same rows instead of adding new ones. It never sends mail and
 * never makes an HTTP request: destinations point at example URLs and their
 * delivery history is written straight into the log.
 */
class DemoSeeder extends Seeder
{
    public const ADMIN_EMAIL = 'ops@example.net';

    public function run(): void
    {
        $pages = $this->pages();
        $content = new DemoPageContent;

        foreach ($pages as $key => $page) {
            app(PageContext::class)->run($page->id, fn () => $content->{$key}());
        }

        $users = $this->users($pages);
        $this->tokens($pages, $users);
        $this->audit($pages);
    }

    /** @return array<string, StatusPage> keyed by the DemoPageContent method that fills it */
    protected function pages(): array
    {
        $default = StatusPage::default();
        $default->update(['name' => 'Northwind Hosting', 'is_published' => true]);

        $harbor = StatusPage::query()->updateOrCreate(['slug' => 'harbor'], [
            'name' => 'Harbor Logistics',
            'is_published' => true,
            'tag_label' => 'Customer',
            'tag_color' => 'teal',
        ]);

        $internal = StatusPage::query()->updateOrCreate(['slug' => 'internal'], [
            'name' => 'Northwind Internal',
            'is_published' => false,
            'tag_label' => 'Internal',
            'tag_color' => 'violet',
        ]);

        return ['northwind' => $default, 'harbor' => $harbor, 'internal' => $internal];
    }

    /**
     * One administrator of the installation and three people with page roles.
     * Passwords are random and printed once, when the account is first made.
     *
     * @param  array<string, StatusPage>  $pages
     * @return array<string, User>
     */
    protected function users(array $pages): array
    {
        $people = [
            'ops' => ['Northwind Ops', self::ADMIN_EMAIL, UserRole::Admin, []],
            'support' => ['Support desk', 'support@example.net', UserRole::User, ['northwind' => 'editor', 'internal' => 'editor']],
            'noc' => ['NOC wall screen', 'noc@example.net', UserRole::User, ['northwind' => 'viewer', 'harbor' => 'viewer']],
            'harbor' => ['Harbor IT', 'it@harbor.example.net', UserRole::User, ['harbor' => 'admin']],
        ];

        $users = [];
        foreach ($people as $key => [$name, $email, $role, $roles]) {
            $user = User::query()->where('email', $email)->first();
            if ($user === null) {
                $password = (string) (getenv('PHAROS_DEMO_PASSWORD') ?: Str::random(20));
                $user = User::query()->create(['name' => $name, 'email' => $email, 'password' => $password, 'role' => $role]);
                $this->command->info("Demo account {$email}, password: {$password}");
            }

            $pivot = [];
            foreach ($roles as $pageKey => $pageRole) {
                $pivot[$pages[$pageKey]->id] = ['role' => $pageRole];
            }
            $user->statusPages()->syncWithoutDetaching($pivot);
            $users[$key] = $user;
        }

        return $users;
    }

    /**
     * @param  array<string, StatusPage>  $pages
     * @param  array<string, User>  $users
     */
    protected function tokens(array $pages, array $users): void
    {
        $tokens = [
            ['Uptime Kuma bridge', 'northwind', 'write', 'ops', 4],
            ['Grafana status panel', 'northwind', 'read', 'ops', 1],
            ['Dispatch board', 'harbor', 'read', 'harbor', 12],
            ['Warehouse monitor', 'harbor', 'write', 'harbor', null],
        ];

        foreach ($tokens as [$name, $pageKey, $scope, $userKey, $usedMinutesAgo]) {
            $pageId = $pages[$pageKey]->id;
            $token = ApiToken::query()->where('name', $name)->where('status_page_id', $pageId)->first();
            if ($token === null) {
                // The plaintext is thrown away: a demo token is there to be looked at, not used.
                [$token] = ApiToken::issue($name, $users[$userKey], $pageId, $scope);
            }
            $token->forceFill(['last_used_at' => $usedMinutesAgo === null ? null : now()->subMinutes($usedMinutesAgo)])->save();
        }
    }

    /**
     * The trail a few weeks of use would leave. Written directly: the seeder has
     * no signed in actor, so the model events record nothing on their own.
     *
     * @param  array<string, StatusPage>  $pages
     */
    protected function audit(array $pages): void
    {
        $ops = User::query()->where('email', self::ADMIN_EMAIL)->first();
        $support = User::query()->where('email', 'support@example.net')->first();
        $harborIt = User::query()->where('email', 'it@harbor.example.net')->first();

        $lines = [
            [$ops, null, 'auth.login', null, null, 10],
            [$ops, 'northwind', 'incident.updated', 'Incident', 'Outbound email delayed', 25, ['status' => ['from' => 'Identified', 'to' => 'Watching']]],
            [null, 'northwind', 'incident_update.created', 'IncidentUpdate', 'Update on "Outbound email delayed"', 26, null, 'API token: Uptime Kuma bridge'],
            [$support, 'northwind', 'incident.created', 'Incident', 'Outbound email delayed', 118],
            [$ops, 'northwind', 'maintenance.created', 'Maintenance', 'Storage upgrade on web-03 and web-04', 60 * 20],
            [$harborIt, 'harbor', 'maintenance.created', 'Maintenance', 'Driver app API migration', 60 * 26],
            [$harborIt, 'harbor', 'setting.updated', 'StatusPageSetting', 'brand.accent', 60 * 30, ['value' => ['from' => '#0079d2', 'to' => '#0f766e']]],
            [$ops, 'northwind', 'notification.created', 'WebhookEndpoint', 'Slack #ops alerts', 60 * 50],
            [$ops, 'northwind', 'api_token.created', 'ApiToken', 'Grafana status panel', 60 * 52],
            [$ops, 'harbor', 'status_page.created', 'StatusPage', 'Harbor Logistics', 60 * 24 * 6],
            [$ops, null, 'user.created', 'User', 'Harbor IT', 60 * 24 * 6 - 5],
            [$support, 'northwind', 'component.updated', 'Component', 'web-06', 60 * 24 * 2, ['status' => ['from' => 'Major outage', 'to' => 'Operational']]],
        ];

        foreach ($lines as $line) {
            [$user, $pageKey, $action, $type, $label, $minutesAgo] = $line;
            $changes = $line[6] ?? null;
            $actor = $line[7] ?? ($user ? $user->name.' ('.$user->email.')' : 'System');

            AuditEntry::query()->updateOrCreate(
                ['action' => $action, 'subject_label' => $label, 'actor' => $actor],
                [
                    'user_id' => $user?->id,
                    'status_page_id' => $pageKey ? $pages[$pageKey]->id : null,
                    'subject_type' => $type,
                    'changes' => $changes,
                    'ip' => '198.51.100.24',
                    'created_at' => now()->subMinutes($minutesAgo),
                ],
            );
        }
    }
}
