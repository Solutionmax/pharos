<?php

namespace Tests\Feature;

use App\Models\Check;
use App\Models\Component;
use App\Models\ProbeLocation;
use App\Models\ProbeSample;
use App\Models\StatusPage;
use App\Models\User;
use App\Services\ProbeQuorum;
use App\Services\ProbeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProbeCredentialAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private function setupLocation(): array
    {
        $owner = User::factory()->create(['role' => 'user']);
        $page = StatusPage::default();
        $owner->statusPages()->attach($page->id, ['role' => 'admin']);
        $component = Component::create(['name' => 'Private target']);
        $check = Check::create(['component_id' => $component->id, 'type' => 'http', 'target' => 'https://private.example.test']);
        $location = ProbeLocation::create(['name' => 'Remote', 'owner_id' => $owner->id, 'token_hash' => hash('sha256', 'authority-secret')]);
        $location->checks()->attach($check);

        return [$owner, $page, $location];
    }

    public function test_issuer_downgrade_revokes_job_polling_and_pending_results(): void
    {
        [$owner,$page] = $this->setupLocation();
        $job = $this->withToken('authority-secret')->getJson('/api/v1/probe/jobs')->assertOk()->json('jobs.0.id');
        $owner->statusPages()->updateExistingPivot($page->id, ['role' => 'viewer']);
        $this->getJson('/api/v1/probe/jobs')->assertForbidden()->assertDontSee('private.example.test');
        $this->postJson('/api/v1/probe/results', ['job' => $job, 'ok' => true])->assertForbidden();
        $this->assertDatabaseCount('probe_samples', 0);
    }

    public function test_archival_disable_and_issuer_deletion_revoke_probe_access(): void
    {
        [$owner,$page,$location] = $this->setupLocation();
        $job = $this->withToken('authority-secret')->getJson('/api/v1/probe/jobs')->assertOk()->json('jobs.0.id');
        $page->update(['archived_at' => now()]);
        $this->postJson('/api/v1/probe/results', ['job' => $job, 'ok' => true])->assertForbidden();
        $this->getJson('/api/v1/probe/jobs')->assertForbidden();
        $page->update(['archived_at' => null]);
        $location->update(['enabled' => false]);
        $this->getJson('/api/v1/probe/jobs')->assertUnauthorized();
        $location->update(['enabled' => true]);
        $owner->delete();
        $this->getJson('/api/v1/probe/jobs')->assertForbidden();
    }

    public function test_unowned_legacy_credentials_fail_closed_and_issue_binds_current_page_admin(): void
    {
        $location = ProbeLocation::create(['name' => 'Legacy', 'token_hash' => hash('sha256', 'legacy')]);
        $this->withToken('legacy')->getJson('/api/v1/probe/jobs')->assertForbidden();
        [$owner,$page] = $this->setupLocation();
        $check = Check::firstOrFail();
        $this->actingAs($owner)->post('/admin/locations', ['name' => 'Owned', 'checks' => [$check->id]])->assertRedirect();
        $this->assertSame($owner->id, ProbeLocation::where('name', 'Owned')->firstOrFail()->owner_id);
    }

    public function test_revoked_location_samples_cannot_vote_healthy_or_shrink_required_quorum(): void
    {
        [$owner,$page,$location] = $this->setupLocation();
        $check = Check::firstOrFail();
        ProbeSample::create(['check_id' => $check->id, 'probe_location_id' => $location->id, 'ok' => true, 'checked_at' => now()]);
        $owner->statusPages()->updateExistingPivot($page->id, ['role' => 'viewer']);
        $result = app(ProbeQuorum::class)->combine($check, new ProbeResult(true, 10));
        $this->assertTrue($result->inconclusive);
    }

    public function test_migration_disables_unattributable_legacy_credentials_without_deleting_history(): void
    {
        $migration = require database_path('migrations/2026_10_07_200400_bind_probe_location_issuers.php');
        $migration->down();
        $location = ProbeLocation::create(['name' => 'Legacy preserved', 'token_hash' => hash('sha256', 'legacy-upgrade')]);
        $migration->up();
        $this->assertSame('Legacy preserved', $location->fresh()->name);
        $this->assertFalse($location->fresh()->enabled);
        $this->assertNull($location->fresh()->owner_id);
    }
}
