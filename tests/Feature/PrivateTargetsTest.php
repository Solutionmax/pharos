<?php

namespace Tests\Feature;

use App\Enums\CheckType;
use App\Enums\UserRole;
use App\Models\Check;
use App\Models\Component;
use App\Models\Incident;
use App\Models\ProbeLocation;
use App\Models\Setting;
use App\Models\StatusPage;
use App\Models\User;
use App\Models\WebhookEndpoint;
use App\Services\InstallSettings;
use App\Services\OutgoingWebhook;
use App\Services\PageContext;
use App\Services\Probe;
use App\Services\SafeHttp;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * D2: page editors cannot use checks and destinations as a scanner for the
 * server's own networks unless the installation allows private targets.
 */
class PrivateTargetsTest extends TestCase
{
    use RefreshDatabase;

    protected StatusPage $page;

    protected User $admin;

    protected User $editor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->page = StatusPage::default();
        $this->admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->editor = User::factory()->create(['role' => UserRole::User]);
        $this->editor->statusPages()->attach($this->page->id, ['role' => 'editor']);

        $this->app->instance(SafeHttp::class, new class extends SafeHttp
        {
            public function addresses(string $host): array
            {
                return filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ([
                    'nas.lan.test' => ['192.168.1.20'],
                    'tail.test' => ['100.100.1.1'],
                    'shop.public.test' => ['203.0.113.40'],
                    'hooks.public.test' => ['203.0.113.50'],
                ][$host] ?? []);
            }
        });
    }

    protected function pageUrl(string $path): string
    {
        return '/admin/pages/'.$this->page->id.'/'.$path;
    }

    /** @param array<string, mixed> $with */
    protected function componentForm(array $with = []): array
    {
        return $with + ['name' => 'NAS', 'status' => 1, 'source' => 'check', 'check_type' => 'http', 'check_target' => 'http://nas.lan.test:5000/', 'check_interval' => 60, 'enabled' => 1];
    }

    protected function allowEditors(bool $on = true): void
    {
        Setting::put(InstallSettings::EDITOR_PRIVATE_TARGETS, $on ? '1' : '0');
    }

    // ---------- when a target is saved ----------

    public function test_a_fresh_install_does_not_let_page_editors_use_private_targets(): void
    {
        $this->assertFalse(InstallSettings::editorsMayTargetPrivate());

        foreach ([
            ['check_type' => 'http', 'check_target' => 'http://nas.lan.test:5000/'],
            ['check_type' => 'http', 'check_target' => 'http://10.0.0.8/'],
            ['check_type' => 'http', 'check_target' => 'https://tail.test/'],
            ['check_type' => 'tcp', 'check_target' => 'nas.lan.test:22'],
            ['check_type' => 'tcp', 'check_target' => '192.168.1.1:3306'],
        ] as $target) {
            $this->actingAs($this->editor)->post($this->pageUrl('components'), $this->componentForm($target))
                ->assertSessionHasErrors('check_target');
        }

        $this->assertSame(0, Component::count());
        $this->assertStringContainsString('private network (192.168.1.1)', (string) session('errors')->first('check_target'));
    }

    public function test_a_page_editor_may_still_check_public_targets(): void
    {
        $this->actingAs($this->editor)->post($this->pageUrl('components'), $this->componentForm(['check_target' => 'https://shop.public.test/health']))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->editor)->post($this->pageUrl('components'), $this->componentForm(['name' => 'Shop TCP', 'check_type' => 'tcp', 'check_target' => 'shop.public.test:443']))
            ->assertSessionHasNoErrors();

        $this->assertSame(2, Check::count());
        $this->assertFalse(Check::firstOrFail()->allow_private);
    }

    public function test_an_installation_admin_may_always_use_private_targets(): void
    {
        $this->actingAs($this->admin)->post($this->pageUrl('components'), $this->componentForm())->assertSessionHasNoErrors();

        $check = Check::sole();
        $this->assertSame('http://nas.lan.test:5000/', $check->target);
        $this->assertTrue($check->allow_private);
    }

    public function test_with_the_setting_on_a_page_editor_may_use_private_targets(): void
    {
        $this->allowEditors();

        $this->actingAs($this->editor)->post($this->pageUrl('components'), $this->componentForm())->assertSessionHasNoErrors();

        $this->assertFalse(Check::sole()->allow_private);
    }

    public function test_an_editor_may_edit_a_component_whose_private_check_an_admin_set(): void
    {
        $this->actingAs($this->admin)->post($this->pageUrl('components'), $this->componentForm())->assertSessionHasNoErrors();
        $component = Component::sole();

        // Same target, other description: allowed, and the admin's permission stays.
        $this->actingAs($this->editor)->put($this->pageUrl('components/'.$component->id), $this->componentForm(['description' => 'Disk station']))
            ->assertSessionHasNoErrors();
        $this->assertTrue($component->fresh()->check->allow_private);

        // Another private target is the editor's own choice, and refused.
        $this->actingAs($this->editor)->put($this->pageUrl('components/'.$component->id), $this->componentForm(['check_target' => 'http://10.0.0.9/']))
            ->assertSessionHasErrors('check_target');
        $this->assertSame('http://nas.lan.test:5000/', $component->fresh()->check->target);

        // A public target from the editor takes the permission away.
        $this->actingAs($this->editor)->put($this->pageUrl('components/'.$component->id), $this->componentForm(['check_target' => 'https://shop.public.test/']))
            ->assertSessionHasNoErrors();
        $this->assertFalse($component->fresh()->check->allow_private);
    }

    public function test_a_page_editor_cannot_add_a_private_notification_destination(): void
    {
        $form = ['label' => 'n8n', 'url' => 'http://nas.lan.test:5678/webhook/pharos', 'format' => 'generic'];

        $this->actingAs($this->editor)->post($this->pageUrl('integrations/notifications'), $form)
            ->assertSessionHasErrors(['url' => __('That address is on a private network (:ip). Only an installation administrator can use private addresses, unless page editors are allowed to under Settings, General.', ['ip' => '192.168.1.20'])]);
        $this->assertSame(0, WebhookEndpoint::count());

        $this->actingAs($this->editor)->post($this->pageUrl('integrations/notifications'), ['url' => 'https://hooks.public.test/x'] + $form)
            ->assertSessionHasNoErrors();
        $this->assertFalse(WebhookEndpoint::sole()->allow_private);

        $this->actingAs($this->admin)->post($this->pageUrl('integrations/notifications'), ['label' => 'LAN'] + $form)->assertSessionHasNoErrors();
        $this->assertTrue(WebhookEndpoint::where('label', 'LAN')->sole()->allow_private);

        $this->allowEditors();
        $this->actingAs($this->editor)->post($this->pageUrl('integrations/notifications'), ['label' => 'Editor LAN'] + $form)->assertSessionHasNoErrors();
        $this->assertFalse(WebhookEndpoint::where('label', 'Editor LAN')->sole()->allow_private);
    }

    // ---------- every time a target is used ----------

    protected function check(string $target, bool $allowPrivate, CheckType $type = CheckType::Http): Check
    {
        return (new Check)->forceFill(['type' => $type, 'target' => $target, 'timeout_seconds' => 2, 'interval_seconds' => 60, 'allow_private' => $allowPrivate]);
    }

    public function test_a_check_an_editor_saved_is_refused_at_run_time_when_private_targets_are_off(): void
    {
        Http::fake(['*' => Http::response('ok')]);

        $result = app(Probe::class)->run($this->check('http://nas.lan.test:5000/', false));
        $this->assertFalse($result->ok);
        $this->assertSame('Target is on a private network, which page editors may not check', $result->message);

        $tcp = app(Probe::class)->run($this->check('192.168.1.20:22', false, CheckType::Tcp));
        $this->assertFalse($tcp->ok);
        $this->assertSame('Target is on a private network, which page editors may not check', $tcp->message);
        Http::assertNothingSent();

        // An installation administrator's check runs.
        $this->assertTrue(app(Probe::class)->run($this->check('http://nas.lan.test:5000/', true))->ok);

        // And so does the editor's once the installation allows it.
        $this->allowEditors();
        $this->assertTrue(app(Probe::class)->run($this->check('http://nas.lan.test:5000/', false))->ok);
        Http::assertSentCount(2);
    }

    public function test_a_public_name_that_moved_to_a_private_address_is_refused_at_run_time(): void
    {
        Http::fake(['*' => Http::response('ok')]);
        $this->app->instance(SafeHttp::class, new class extends SafeHttp
        {
            public function addresses(string $host): array
            {
                return $host === 'shop.public.test' ? ['10.20.30.40'] : [];
            }
        });

        $result = app(Probe::class)->run($this->check('https://shop.public.test/', false));

        $this->assertFalse($result->ok);
        Http::assertNothingSent();
    }

    public function test_a_destination_an_editor_saved_is_refused_at_run_time_when_private_targets_are_off(): void
    {
        Http::fake(['*' => Http::response('', 204)]);
        $editors = WebhookEndpoint::create(['label' => 'Editor', 'url' => 'http://nas.lan.test:5678/hook', 'format' => 'generic', 'enabled' => true]);
        $admins = WebhookEndpoint::create(['label' => 'Admin', 'url' => 'http://nas.lan.test:5678/hook', 'format' => 'generic', 'enabled' => true, 'allow_private' => true]);

        $this->assertFalse(app(OutgoingWebhook::class)->test($editors));
        $this->assertSame('Destination is on a private network, which page editors may not use.', $editors->fresh()->last_error);
        Http::assertNothingSent();

        $this->assertTrue(app(OutgoingWebhook::class)->test($admins));
        $this->allowEditors();
        $this->assertTrue(app(OutgoingWebhook::class)->test($editors));
        Http::assertSentCount(2);
    }

    public function test_a_remote_probe_job_carries_the_decision_of_the_hub(): void
    {
        $location = ProbeLocation::create(['owner_id' => $this->admin->id, 'name' => 'Amsterdam', 'token_hash' => hash('sha256', 'secret')]);
        $component = Component::create(['name' => 'NAS']);
        $check = Check::create(['component_id' => $component->id, 'type' => 'http', 'target' => 'http://nas.lan.test/', 'allow_private' => false]);
        $check->locations()->attach($location);

        $jobs = fn () => $this->withToken('secret')->getJson('/api/v1/probe/jobs')->assertOk()->json('jobs');

        $this->assertFalse($jobs()[0]['allow_private']);

        DB::table('probe_jobs')->delete();
        $check->update(['allow_private' => true]);
        $this->assertTrue($jobs()[0]['allow_private']);
    }

    // ---------- the setting ----------

    public function test_only_an_installation_admin_changes_the_setting_and_it_is_audited(): void
    {
        $form = ['timezone' => 'UTC', 'audit_days' => 180, 'keep_backups' => 3, 'update_check' => '1'];

        $this->actingAs($this->editor)->put('/admin/settings', $form + ['private_targets' => '1'])->assertForbidden();
        $this->assertFalse(InstallSettings::editorsMayTargetPrivate());

        $this->actingAs($this->admin)->get('/admin/settings')->assertOk()
            ->assertSee('Let page editors check private network addresses')
            ->assertDontSee('name="private_targets" value="1" checked', false);

        $this->actingAs($this->admin)->put('/admin/settings', $form + ['private_targets' => '1'])->assertSessionHasNoErrors();
        $this->assertTrue(InstallSettings::editorsMayTargetPrivate());
        $this->actingAs($this->admin)->get('/admin/settings')->assertSee('name="private_targets" value="1" checked', false);
        $this->assertDatabaseHas('audit_log', ['action' => 'settings.saved']);

        // An unticked box is absent from the request: off again.
        $this->actingAs($this->admin)->put('/admin/settings', $form)->assertSessionHasNoErrors();
        $this->assertFalse(InstallSettings::editorsMayTargetPrivate());
    }

    // ---------- the update from 1.1 ----------

    /** Back to a 1.1 schema, then the update's migration, as an updating install runs it. */
    protected function migrate(bool $keepSetting = false): void
    {
        foreach (['checks', 'webhook_endpoints'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('allow_private'));
        }
        if (! $keepSetting) {
            DB::table('settings')->where('key', InstallSettings::EDITOR_PRIVATE_TARGETS)->delete();
        }
        Cache::flush();
        (require database_path('migrations/2026_11_15_130000_add_private_target_permission.php'))->up();
    }

    public function test_the_update_keeps_private_targets_working_where_they_are_in_use(): void
    {
        $this->assertTrue(Schema::hasColumn('checks', 'allow_private'));
        $this->assertTrue(Schema::hasColumn('webhook_endpoints', 'allow_private'));
        $component = Component::create(['name' => 'x']);

        foreach ([
            ['http', 'https://shop.public.test/', false],
            ['tcp', 'shop.public.test:443', false],
            ['http', 'http://localhost:8080/', false],
            ['http', 'http://nas.lan.test/', true],
            ['tcp', '10.0.0.4:22', true],
            ['http', 'http://fileserver/', true],
            ['http', 'https://printer.home.arpa/', true],
            ['tcp', 'tail.test:443', true],
        ] as [$type, $target, $expected]) {
            Check::query()->delete();
            Check::create(['component_id' => $component->id, 'type' => $type, 'target' => $target]);
            $this->migrate();
            $this->assertSame($expected, InstallSettings::editorsMayTargetPrivate(), $target);
        }

        Check::query()->delete();
        WebhookEndpoint::create(['label' => 'n8n', 'url' => 'http://192.168.1.30:5678/hook', 'format' => 'generic']);
        $this->migrate();
        $this->assertTrue(InstallSettings::editorsMayTargetPrivate());
    }

    public function test_the_update_leaves_a_decision_that_was_already_made(): void
    {
        WebhookEndpoint::create(['label' => 'n8n', 'url' => 'http://192.168.1.30:5678/hook', 'format' => 'generic']);
        $this->allowEditors(false);

        $this->migrate(keepSetting: true);

        $this->assertFalse(InstallSettings::editorsMayTargetPrivate());
    }

    public function test_an_incident_still_reaches_a_public_destination_of_an_editor(): void
    {
        Http::fake(['*' => Http::response('', 204)]);
        WebhookEndpoint::create(['label' => 'Public', 'url' => 'https://hooks.public.test/x', 'format' => 'generic', 'enabled' => true]);
        $incident = Incident::create(['name' => 'Down', 'status' => 1, 'impact' => 'major', 'occurred_at' => now()]);

        app(PageContext::class)->run($this->page->id, fn () => app(OutgoingWebhook::class)->incidentChanged($incident, 'incident.created'));

        $this->assertSame(1, app(OutgoingWebhook::class)->sendPending());
    }
}
