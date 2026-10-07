<?php

namespace Tests\Feature;

use App\Models\Component;
use App\Models\ComponentGroup;
use App\Models\StatusPage;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\PageContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CachedPageFeatureContextTest extends TestCase
{
    use RefreshDatabase;

    public static function routeModes(): array
    {
        return ['uncached' => [false], 'compiled' => [true]];
    }

    private function compile(bool $compiled): void
    {
        if ($compiled) {
            $router = app('router');
            $router->setCompiledRoutes($router->getRoutes()->compile());
            app('url')->setRoutes($router->getRoutes());
        }
    }

    private function export(): array
    {
        return ['groups' => [['id' => 10, 'name' => 'Tenant imported group', 'visible' => 1]],
            'components' => [['id' => 20, 'name' => 'Tenant imported component', 'group_id' => 10, 'status' => 1]],
            'incidents' => [['id' => 30, 'name' => 'Tenant imported incident', 'component_id' => 20, 'status' => 4, 'visible' => 1, 'message' => 'Historical', 'occurred_at' => '2026-09-03T00:00:00Z']],
            'subscribers' => [['id' => 40, 'email' => 'tenant-import@example.invalid', 'global' => false, 'subscriptions' => [['component_id' => 20]]]]];
    }

    #[DataProvider('routeModes')]
    public function test_explicit_tenant_preview_apply_and_replay_leave_default_page_untouched(bool $compiled): void
    {
        Mail::fake();
        $this->compile($compiled);
        $owner = User::factory()->create(['role' => 'admin']);
        $tenant = StatusPage::create(['name' => 'Tenant destination', 'slug' => 'tenant-destination', 'is_published' => true]);
        $foreign = StatusPage::create(['name' => 'Foreign destination', 'slug' => 'foreign-destination']);
        $default = Component::create(['name' => 'DEFAULT-DATA-PRESERVED']);
        $defaultSubscriber = Subscriber::create(['email' => 'tenant-import@example.invalid', 'token' => 'DEFAULT-TOKEN-PRESERVED', 'verified_at' => now(), 'unsubscribed_at' => now()]);
        $path = '/admin/pages/'.$tenant->id.'/integrations/cachet';
        $this->actingAs($owner)->get($path)->assertOk();
        $preview = $this->post($path.'/preview', ['export' => UploadedFile::fake()->createWithContent('cachet.json', json_encode($this->export()))])->assertOk();
        $preview->assertViewHas('preview', fn ($value) => $value['counts']['components'] === 1 && $value['existing_subscribers'] === 0);
        $token = $preview->viewData('previewToken');
        $this->assertDatabaseCount('components', 1);
        $this->post('/admin/pages/'.$foreign->id.'/integrations/cachet/apply', ['preview_token' => $token])->assertNotFound();
        $this->assertDatabaseCount('cachet_import_batches', 0);
        $this->post($path.'/apply', ['preview_token' => $token])->assertRedirect();
        $this->assertDatabaseHas('cachet_import_batches', ['status_page_id' => $tenant->id]);
        $this->assertSame(0, DB::table('cachet_import_batches')->where('status_page_id', StatusPage::defaultId())->count());
        $this->assertSame($tenant->id, Component::withoutGlobalScopes()->where('name', 'Tenant imported component')->sole()->status_page_id);
        $this->assertSame($tenant->id, ComponentGroup::withoutGlobalScopes()->where('name', 'Tenant imported group')->sole()->status_page_id);
        $this->assertSame(1, Component::count());
        $this->assertSame('DEFAULT-DATA-PRESERVED', $default->fresh()->name);
        $this->assertSame('DEFAULT-TOKEN-PRESERVED', $defaultSubscriber->fresh()->token);
        $this->assertNotNull($defaultSubscriber->fresh()->unsubscribed_at);
        $this->assertSame(2, Subscriber::withoutGlobalScopes()->where('email', 'tenant-import@example.invalid')->count());
        $this->assertDatabaseCount('subscriber_notifications', 0);
        Mail::assertNothingSent();
        $this->post($path.'/apply', ['preview_token' => $token])->assertNotFound();
    }

    #[DataProvider('routeModes')]
    public function test_tenant_admin_reports_and_public_sharing_bind_requested_page_with_current_roles(bool $compiled): void
    {
        $this->compile($compiled);
        $tenant = StatusPage::create(['name' => 'Tenant reporting', 'slug' => 'tenant-reporting', 'is_published' => true]);
        $foreign = StatusPage::create(['name' => 'Foreign reporting', 'slug' => 'foreign-reporting', 'is_published' => true]);
        Component::create(['name' => 'DEFAULT-PRIVATE-REPORT']);
        $component = app(PageContext::class)->run($tenant->id, fn () => Component::create(['name' => 'TENANT-ONLY-REPORT', 'enabled' => true]));
        $other = app(PageContext::class)->run($foreign->id, fn () => Component::create(['name' => 'FOREIGN-PRIVATE-REPORT', 'enabled' => true]));
        $viewer = User::factory()->create(['role' => 'user']);
        $viewer->statusPages()->attach($tenant->id, ['role' => 'viewer']);
        $prefix = '/admin/pages/'.$tenant->id;
        $this->actingAs($viewer)->get($prefix.'/reports.csv?month=2026-09')->assertOk()->assertSee('TENANT-ONLY-REPORT')->assertDontSee('DEFAULT-PRIVATE-REPORT')->assertDontSee('FOREIGN-PRIVATE-REPORT');
        $this->get($prefix.'/reports')->assertOk()->assertSee('TENANT-ONLY-REPORT')->assertDontSee('DEFAULT-PRIVATE-REPORT');
        $this->get($prefix.'/integrations/cachet')->assertForbidden();
        $this->get('/admin/pages/'.$foreign->id.'/reports.csv?month=2026-09')->assertNotFound();
        $this->get('/status/tenant-reporting/badges/components/'.$component->id.'.svg')->assertOk()->assertSee('TENANT-ONLY-REPORT')->assertDontSee('DEFAULT-PRIVATE-REPORT');
        $this->get('/status/tenant-reporting/badges/components/'.$other->id.'.svg')->assertNotFound();
        $this->get('/status/tenant-reporting/widget.json')->assertOk()->assertJsonPath('name', 'Tenant reporting');
        $viewer->statusPages()->detach($tenant->id);
        $this->get($prefix.'/reports.csv?month=2026-09')->assertNotFound();
    }

    #[DataProvider('routeModes')]
    public function test_import_requires_requested_page_admin_and_rechecks_revoked_rights(bool $compiled): void
    {
        $this->compile($compiled);
        $tenant = StatusPage::create(['name' => 'Scoped admin', 'slug' => 'scoped-admin']);
        $owner = User::factory()->create(['role' => 'user']);
        $owner->statusPages()->attach($tenant->id, ['role' => 'admin']);
        $path = '/admin/pages/'.$tenant->id.'/integrations/cachet';
        $preview = $this->actingAs($owner)->post($path.'/preview', ['export' => UploadedFile::fake()->createWithContent('cachet.json', json_encode($this->export()))])->assertOk();
        $token = $preview->viewData('previewToken');
        $owner->statusPages()->updateExistingPivot($tenant->id, ['role' => 'editor']);
        $this->get($path)->assertForbidden();
        $this->post($path.'/apply', ['preview_token' => $token])->assertForbidden();
        $this->assertDatabaseCount('components', 0);
        $this->assertDatabaseCount('cachet_import_batches', 0);
        $owner->statusPages()->detach($tenant->id);
        $this->post($path.'/apply', ['preview_token' => $token])->assertNotFound();
    }

    public function test_all_cached_page_clones_bind_their_context_parameters_and_keep_authoritative_uri(): void
    {
        $original = app('router')->getRoutes()->getRoutes();
        $this->compile(true);
        foreach ($original as $route) {
            if (! str_starts_with((string) $route->getName(), 'page.') && ! str_starts_with($route->uri(), 'api/v1/pages/')) {
                continue;
            }
            $path = preg_replace_callback('/\{([^}:?]+)(?:[^}]*)\}/', fn ($match) => match ($match[1]) {
                'statusPage' => '9', 'slug' => 'tenant-binding', 'kind' => 'components', 'token' => str_repeat('a', 40), default => '1',
            }, $route->uri());
            $request = Request::create(rtrim(config('app.url'), '/').'/'.$path, $route->methods()[0]);
            $bound = app('router')->getRoutes()->match($request);
            $this->assertSame($route->uri(), $bound->uri(), (string) $route->getName());
            if (str_contains($route->uri(), '{statusPage}')) {
                $this->assertSame('9', $bound->parameter('statusPage'), (string) $route->getName());
            } else {
                $this->assertSame('tenant-binding', $bound->parameter('slug'), (string) $route->getName());
            }
        }
    }
}
