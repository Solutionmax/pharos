<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Component;
use App\Models\Incident;
use App\Models\IncidentUpdate;
use App\Models\StatusPage;
use App\Models\Subscriber;
use App\Models\User;
use App\Services\CachetImporter;
use App\Services\PageContext;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CachetImporterTest extends TestCase
{
    use RefreshDatabase;

    private function export(): array
    {
        return ['groups' => ['data' => [['id' => 9, 'name' => 'Imported group', 'visible' => 1]]],
            'components' => ['data' => [['id' => 20, 'name' => 'Imported component', 'group_id' => 9, 'status' => 3]]],
            'incidents' => ['data' => [['id' => 30, 'name' => 'Imported incident', 'component_id' => 20, 'status' => 2, 'message' => 'Original message', 'visible' => 1, 'occurred_at' => '2026-09-20 10:00:00', 'updates' => [['status' => 2, 'message' => 'Latest message', 'created_at' => '2026-09-20 11:00:00']]]]],
            'subscribers' => ['data' => [['id' => 40, 'email' => 'import@example.com', 'verified_at' => '2026-09-01', 'global' => false, 'subscriptions' => [['component_id' => 20]]]]]];
    }

    public function test_dry_run_and_full_transactional_import_preserve_relations_without_mail_or_webhooks(): void
    {
        Mail::fake();
        $page = StatusPage::create(['name' => 'Destination', 'slug' => 'destination', 'is_published' => true]);
        $importer = app(CachetImporter::class);
        app(PageContext::class)->run($page->id, function () use ($importer) {
            Subscriber::create(['email' => 'existing@example.com', 'verified_at' => now(), 'token' => Subscriber::freshToken()]);
            $preview = $importer->preview($this->export());
            $this->assertSame(1, $preview['counts']['groups']);
            $this->assertDatabaseCount('components', 0);
            $importer->apply($this->export());
            $this->assertSame('Imported group', Component::sole()->group->name);
            $this->assertSame('public', Incident::sole()->visibility);
            $this->assertSame(Component::sole()->id, Incident::sole()->components->sole()->id);
            $this->assertSame(2, IncidentUpdate::count());
            $this->assertFalse(Subscriber::where('email', 'import@example.com')->sole()->all_services);
            $this->assertTrue(Subscriber::where('email', 'import@example.com')->sole()->isActive());
            $this->assertSame(Component::sole()->id, Subscriber::where('email', 'import@example.com')->sole()->components->sole()->id);
        });
        $this->assertSame(0, Component::count());
        $this->assertDatabaseCount('subscriber_notifications', 0);
        $this->assertDatabaseCount('webhook_deliveries', 0);
        Mail::assertNothingSent();
        $this->assertSame($page->id, Component::withoutGlobalScope('status_page')->sole()->status_page_id);
    }

    public function test_invalid_relations_duplicates_and_untrusted_links_fail_without_partial_rows(): void
    {
        $data = $this->export();
        $data['components']['data'][0]['group_id'] = 100;
        try {
            app(CachetImporter::class)->apply($data);
            $this->fail('Expected validation error');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('components.0.group_id', $e->errors());
        }
        $this->assertDatabaseCount('component_groups', 0);
        $data = $this->export();
        $data['components']['data'][0]['link'] = 'javascript:alert(1)';
        try {
            app(CachetImporter::class)->apply($data);
            $this->fail('Expected validation error');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('components.0.link', $e->errors());
        }
        $this->assertDatabaseCount('components', 0);
        $data = $this->export();
        $data['groups']['data'][] = $data['groups']['data'][0];
        try {
            app(CachetImporter::class)->preview($data);
            $this->fail('Expected validation error');
        } catch (ValidationException $e) {
            $this->assertNotEmpty($e->errors());
        }
    }

    public function test_failure_during_apply_rolls_back_the_entire_import_and_identical_replay_is_rejected(): void
    {
        DB::statement("CREATE TRIGGER reject_import_component BEFORE INSERT ON components WHEN NEW.name = 'Imported component' BEGIN SELECT RAISE(ABORT, 'controlled failure'); END");
        try {
            app(CachetImporter::class)->apply($this->export());
            $this->fail('Expected insertion failure');
        } catch (QueryException $e) {
            $this->assertStringContainsString('controlled failure', $e->getMessage());
        }
        $this->assertDatabaseCount('component_groups', 0);
        $this->assertDatabaseCount('cachet_import_batches', 0);
        DB::statement('DROP TRIGGER reject_import_component');
        app(CachetImporter::class)->apply($this->export());
        try {
            app(CachetImporter::class)->apply($this->export());
            $this->fail('Expected duplicate import rejection');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('export', $e->errors());
        }
        $this->assertDatabaseCount('components', 1);
        $this->assertDatabaseCount('cachet_import_batches', 1);
    }

    public function test_preview_confirmation_is_owned_by_user_page_one_use_and_cannot_mutate_other_pages(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $page = StatusPage::create(['name' => 'Other', 'slug' => 'other']);
        $file = UploadedFile::fake()->createWithContent('cachet.json', json_encode($this->export()));
        $response = $this->actingAs($admin)->post('/admin/integrations/cachet/preview', ['export' => $file])->assertOk()->assertSee('Imported group');
        $preview = $response->viewData('previewToken');
        $this->assertDatabaseCount('components', 0);
        $this->post('/admin/pages/'.$page->id.'/integrations/cachet/apply', ['preview_token' => $preview])->assertNotFound();
        $this->post('/admin/integrations/cachet/apply', ['preview_token' => $preview])->assertRedirect();
        $this->assertDatabaseCount('components', 1);
        $this->post('/admin/integrations/cachet/apply', ['preview_token' => $preview])->assertNotFound();
        $viewer = User::factory()->create(['role' => UserRole::User]);
        $viewer->statusPages()->attach(StatusPage::defaultId(), ['role' => 'viewer']);
        $this->actingAs($viewer)->get('/admin/integrations/cachet')->assertForbidden();
    }
}
