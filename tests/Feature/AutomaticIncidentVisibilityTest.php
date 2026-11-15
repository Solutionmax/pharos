<?php

namespace Tests\Feature;

use App\Enums\CheckType;
use App\Enums\ComponentStatus;
use App\Enums\IncidentStatus;
use App\Enums\UserRole;
use App\Models\ApiToken;
use App\Models\Check;
use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\Incident;
use App\Models\StatusPage;
use App\Models\User;
use App\Services\CheckRunner;
use App\Services\OutgoingWebhook;
use App\Services\PageContext;
use App\Services\Probe;
use App\Services\ProbeResult;
use App\Services\PublicComponents;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Security review E1: an incident a machine opens for a service the public
 * page does not show must not show up there itself.
 */
class AutomaticIncidentVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = 'migrations/2026_11_15_120000_hide_automatic_incidents_of_hidden_services.php';

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        [, $this->token] = ApiToken::issue('zabbix', $admin, StatusPage::defaultId(), 'write');
    }

    private function hiddenGroup(): ComponentGroup
    {
        return ComponentGroup::create(['name' => 'Internal systems', 'visible' => false]);
    }

    private function service(string $name, array $attributes = []): Component
    {
        return Component::create(['name' => $name, 'status' => ComponentStatus::Operational] + $attributes);
    }

    private function failingCheck(Component $component, array $attributes = []): Check
    {
        return Check::create($attributes + [
            'component_id' => $component->id,
            'type' => CheckType::Http,
            'target' => 'https://example.net/',
            'interval_seconds' => 60,
            'retries' => 1,
        ]);
    }

    private function runFailing(Check $check): void
    {
        $down = new class extends Probe
        {
            public function run(Check $check): ProbeResult
            {
                return new ProbeResult(false, 12, 'No response');
            }
        };
        (new CheckRunner($down, app(OutgoingWebhook::class)))->runOne($check);
    }

    private function assertNotOnThePublicPage(Incident $incident): void
    {
        $this->flushHeaders(); // as a stranger: no API token
        $this->get('/')->assertOk()->assertDontSee($incident->name);
        $this->get('/incidents/'.$incident->id)->assertNotFound();
        $this->get('/feed.xml')->assertOk()->assertDontSee($incident->name);
        $this->getJson('/api/v1/incidents')->assertOk()->assertJsonMissing(['name' => $incident->name]);
    }

    public function test_a_check_on_a_service_in_a_hidden_group_opens_an_internal_incident(): void
    {
        $umami = $this->service('Umami', ['component_group_id' => $this->hiddenGroup()->id]);

        $this->runFailing($this->failingCheck($umami));

        $incident = Incident::sole();
        $this->assertSame('Umami unreachable', $incident->name);
        $this->assertSame('internal', $incident->visibility);
        $this->assertSame(ComponentStatus::MajorOutage, $umami->fresh()->status);
        $this->assertNotOnThePublicPage($incident);
    }

    public function test_a_check_on_a_public_service_still_opens_a_public_incident(): void
    {
        $web = $this->service('Website');

        $this->runFailing($this->failingCheck($web));

        $incident = Incident::sole();
        $this->assertSame('public', $incident->visibility);
        $this->get('/incidents/'.$incident->id)->assertOk()->assertSee('Website unreachable');
    }

    public function test_a_switched_off_service_opens_an_internal_incident(): void
    {
        $old = $this->service('Old portal', ['enabled' => false]);

        $this->runFailing($this->failingCheck($old));

        $this->assertSame('internal', Incident::sole()->visibility);
    }

    public function test_a_silent_heartbeat_of_a_hidden_service_opens_an_internal_incident(): void
    {
        $backup = $this->service('Nightly backup', ['component_group_id' => $this->hiddenGroup()->id]);
        $this->failingCheck($backup, [
            'type' => CheckType::Heartbeat,
            'target' => 'hb_backup',
            'interval_seconds' => 3600,
            'last_run_at' => now()->subHours(5),
        ]);

        // The scheduler path, with the real probe: silence is the failure here.
        (new CheckRunner(new Probe, app(OutgoingWebhook::class)))->runDue();

        $incident = Incident::sole();
        $this->assertSame('internal', $incident->visibility);
        $this->assertNotOnThePublicPage($incident);

        // The ping that ends it resolves the same internal incident.
        $this->postJson('/api/v1/heartbeat/hb_backup')->assertOk();
        $this->assertNotNull($incident->fresh()->resolved_at);
        $this->assertSame('internal', $incident->fresh()->visibility);
    }

    public function test_an_api_incident_for_a_hidden_service_is_internal_unless_it_says_otherwise(): void
    {
        $hidden = $this->service('Authentik', ['component_group_id' => $this->hiddenGroup()->id]);

        // Zabbix style: the Cachet pair, no visibility.
        $this->withToken($this->token)->postJson('/api/v1/incidents', [
            'name' => 'Authentik down', 'status' => 'investigating', 'message' => 'Trigger fired',
            'component_id' => $hidden->id,
        ])->assertCreated()->assertJsonPath('data.visibility', 'internal');

        // A components map by name counts the same.
        $this->withToken($this->token)->postJson('/api/v1/incidents', [
            'name' => 'Authentik slow', 'status' => 'investigating', 'message' => 'Trigger fired',
            'components' => ['Authentik' => 'degraded'],
        ])->assertCreated()->assertJsonPath('data.visibility', 'internal');

        // An explicit choice is respected.
        $this->withToken($this->token)->postJson('/api/v1/incidents', [
            'name' => 'Authentik announced', 'status' => 'investigating', 'message' => 'We know',
            'component_id' => $hidden->id, 'visibility' => 'public',
        ])->assertCreated()->assertJsonPath('data.visibility', 'public');

        $this->assertNotOnThePublicPage(Incident::where('name', 'Authentik down')->sole());
    }

    public function test_an_api_incident_that_touches_a_public_service_or_none_stays_public(): void
    {
        $hidden = $this->service('Database', ['component_group_id' => $this->hiddenGroup()->id]);
        $public = $this->service('Website');

        $this->withToken($this->token)->postJson('/api/v1/incidents', [
            'name' => 'Both down', 'status' => 'investigating', 'message' => 'Hypervisor',
            'components' => [$hidden->id => 'major_outage', $public->id => 'major_outage'],
        ])->assertCreated()->assertJsonPath('data.visibility', 'public');

        $this->withToken($this->token)->postJson('/api/v1/incidents', [
            'name' => 'General notice', 'status' => 'investigating', 'message' => 'No service named',
        ])->assertCreated()->assertJsonPath('data.visibility', 'public');
    }

    public function test_kuma_and_status_reports_on_a_hidden_service_open_no_incident(): void
    {
        $hidden = $this->service('Grafana', ['component_group_id' => $this->hiddenGroup()->id]);

        $this->withToken($this->token)->postJson('/api/v1/integrations/kuma/'.$hidden->id, ['heartbeat' => ['status' => 0]])->assertOk();
        $this->withToken($this->token)->putJson('/api/v1/components/'.$hidden->id, ['status' => 4])->assertOk();

        $this->assertSame(ComponentStatus::MajorOutage, $hidden->fresh()->status);
        $this->assertSame(0, Incident::count());
    }

    public function test_the_visibility_helper_follows_the_public_boundary(): void
    {
        $hidden = $this->service('Hidden', ['component_group_id' => $this->hiddenGroup()->id]);
        $public = $this->service('Public');

        $this->assertSame('internal', PublicComponents::automaticVisibility([$hidden->id]));
        $this->assertSame('public', PublicComponents::automaticVisibility([$hidden->id, $public->id]));
        $this->assertSame('public', PublicComponents::automaticVisibility([]));
        // An id of another page is not a public service of this one.
        $other = StatusPage::create(['name' => 'Other', 'slug' => 'other', 'is_published' => true]);
        $foreign = app(PageContext::class)->run($other->id, fn () => $this->service('Foreign'));
        $this->assertSame('internal', PublicComponents::automaticVisibility([$foreign->id]));
    }

    public function test_the_migration_hides_only_recent_check_incidents_of_services_that_are_all_hidden(): void
    {
        $group = $this->hiddenGroup();
        $hidden = $this->service('Umami', ['component_group_id' => $group->id]);
        $off = $this->service('Old portal', ['enabled' => false]);
        $public = $this->service('Website');

        $open = $this->incident('Umami unreachable', [$hidden], 'check', now()->subDays(400), null);
        $recent = $this->incident('Old portal unreachable', [$off], 'check', now()->subDays(10), now()->subDays(9));
        $twoHidden = $this->incident('Both hidden', [$hidden, $off], 'check', now()->subDay(), null);
        $old = $this->incident('Umami long ago', [$hidden], 'check', now()->subDays(200), now()->subDays(199));
        $mixed = $this->incident('Mixed', [$hidden, $public], 'check', now()->subDay(), null);
        $visible = $this->incident('Website unreachable', [$public], 'check', now()->subDay(), null);
        $manual = $this->incident('Written by hand', [$hidden], 'manual', now()->subDay(), null);
        $api = $this->incident('From Zabbix', [$hidden], 'api', now()->subDay(), null);
        $bare = $this->incident('No services', [], 'check', now()->subDay(), null);

        $other = StatusPage::create(['name' => 'Other', 'slug' => 'other', 'is_published' => true]);
        $foreign = app(PageContext::class)->run($other->id, function () {
            $group = ComponentGroup::create(['name' => 'Back office', 'visible' => false]);

            return $this->incident('Back office unreachable', [$this->service('ERP', ['component_group_id' => $group->id])], 'check', now()->subDay(), null);
        });

        (require database_path(self::MIGRATION))->up();

        $visibility = fn (Incident $incident) => Incident::query()->withoutGlobalScopes()->whereKey($incident->id)->value('visibility');
        foreach ([$open, $recent, $twoHidden, $foreign] as $incident) {
            $this->assertSame('internal', $visibility($incident), $incident->name);
        }
        foreach ([$old, $mixed, $visible, $manual, $api, $bare] as $incident) {
            $this->assertSame('public', $visibility($incident), $incident->name);
        }

        // Running it again changes nothing more.
        (require database_path(self::MIGRATION))->up();
        $this->assertSame(4, Incident::query()->withoutGlobalScopes()->where('visibility', 'internal')->count());
    }

    /** @param  array<int, Component>  $components */
    private function incident(string $name, array $components, string $source, \DateTimeInterface $occurred, ?\DateTimeInterface $resolved): Incident
    {
        $incident = Incident::create([
            'name' => $name,
            'status' => $resolved ? IncidentStatus::Resolved : IncidentStatus::Investigating,
            'source' => $source,
            'occurred_at' => $occurred,
            'resolved_at' => $resolved,
        ]);
        foreach ($components as $component) {
            $incident->components()->attach($component->id, ['status' => ComponentStatus::MajorOutage->value]);
        }

        return $incident;
    }
}
