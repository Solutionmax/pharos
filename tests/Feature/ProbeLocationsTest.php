<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Models\Check;
use App\Models\Component;
use App\Models\Incident;
use App\Models\ProbeJob;
use App\Models\ProbeLocation;
use App\Models\ProbeSample;
use App\Models\StatusPage;
use App\Models\UptimeDay;
use App\Models\User;
use App\Services\CheckRunner;
use App\Services\OutgoingWebhook;
use App\Services\PageContext;
use App\Services\Probe;
use App\Services\ProbeQuorum;
use App\Services\ProbeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProbeLocationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_of_three_locations_healthy_prevents_an_outage_and_stale_cannot_vote(): void
    {
        $c = Component::create(['name' => 'Web']);
        $check = Check::create(['component_id' => $c->id, 'type' => 'http', 'target' => 'https://example.net']);
        $a = ProbeLocation::create(['name' => 'A', 'token_hash' => hash('sha256', 'a')]);
        $b = ProbeLocation::create(['name' => 'B', 'token_hash' => hash('sha256', 'b')]);
        $check->locations()->attach([$a->id, $b->id]);
        ProbeSample::create(['check_id' => $check->id, 'probe_location_id' => $a->id, 'ok' => true, 'checked_at' => now(), 'latency_ms' => 20]);
        ProbeSample::create(['check_id' => $check->id, 'probe_location_id' => $b->id, 'ok' => false, 'checked_at' => now()]);
        $quorum = app(ProbeQuorum::class);
        $this->assertTrue($quorum->combine($check, new ProbeResult(true, 10))->ok);
        ProbeSample::where('probe_location_id', $a->id)->update(['checked_at' => now()->subHours(1)]);
        $this->assertTrue($quorum->combine($check, new ProbeResult(true, 10))->inconclusive);
    }

    public function test_probe_token_only_receives_assigned_checks_and_result_nonce_is_one_use(): void
    {
        $a = ProbeLocation::create(['name' => 'A', 'token_hash' => hash('sha256', 'secret')]);
        $c = Component::create(['name' => 'Web']);
        $check = Check::create(['component_id' => $c->id, 'type' => 'http', 'target' => 'https://example.net']);
        $check->locations()->attach($a);
        $other = Component::create(['name' => 'Hidden']);
        Check::create(['component_id' => $other->id, 'type' => 'http', 'target' => 'https://private.example.net']);
        $jobs = $this->withToken('secret')->getJson('/api/v1/probe/jobs')->assertOk()->json('jobs');
        $this->assertCount(1, $jobs);
        $body = ['job' => $jobs[0]['id'], 'ok' => true, 'latency_ms' => 12];
        $this->withToken('secret')->postJson('/api/v1/probe/results', $body)->assertOk();
        $this->withToken('secret')->postJson('/api/v1/probe/results', $body)->assertStatus(409);
        $this->withToken('wrong')->getJson('/api/v1/probe/jobs')->assertUnauthorized();
    }

    public function test_page_ownership_and_location_management_authority(): void
    {
        $admin = User::factory()->create(['role' => 'user']);
        $page = StatusPage::default();
        $admin->statusPages()->attach($page->id, ['role' => 'admin']);
        $other = StatusPage::create(['name' => 'Other', 'slug' => 'other']);
        $outside = app(PageContext::class)->run($other->id, function () {
            $c = Component::create(['name' => 'Outside']);

            return Check::create(['component_id' => $c->id, 'type' => 'http', 'target' => 'https://private.example.net']);
        });
        $this->actingAs($admin)->post('/admin/locations', ['name' => 'Attempt', 'checks' => [$outside->id]])->assertSessionHasErrors('checks.0');
        $this->assertSame(0, ProbeLocation::count());
        $foreign = app(PageContext::class)->run($other->id, fn () => ProbeLocation::create(['name' => 'Foreign', 'token_hash' => hash('sha256', 'foreign')]));
        $this->actingAs($admin)->delete('/admin/locations/'.$foreign->id)->assertNotFound();
        $admin->statusPages()->updateExistingPivot($page->id, ['role' => 'viewer']);
        $this->get('/admin/locations')->assertForbidden();
        $this->post('/admin/locations', ['name' => 'Attempt', 'checks' => []])->assertForbidden();
        $this->get('/admin/system-monitoring')->assertForbidden();
    }

    public function test_another_location_cannot_submit_job_and_expired_or_malformed_results_fail(): void
    {
        $a = ProbeLocation::create(['name' => 'A', 'token_hash' => hash('sha256', 'secret-a')]);
        $b = ProbeLocation::create(['name' => 'B', 'token_hash' => hash('sha256', 'secret-b')]);
        $c = Component::create(['name' => 'Web']);
        $check = Check::create(['component_id' => $c->id, 'type' => 'http', 'target' => 'https://example.net']);
        $check->locations()->attach($a);
        $job = $this->withToken('secret-a')->getJson('/api/v1/probe/jobs')->assertOk()->json('jobs.0.id');
        $this->withToken('secret-b')->postJson('/api/v1/probe/results', ['job' => $job, 'ok' => true])->assertNotFound();
        $this->withToken('secret-a')->postJson('/api/v1/probe/results', ['job' => $job, 'ok' => true, 'latency_ms' => -1])->assertUnprocessable();
        ProbeJob::whereKey($job)->update(['expires_at' => now()->subMinute()]);
        $this->withToken('secret-a')->postJson('/api/v1/probe/results', ['job' => $job, 'ok' => true])->assertStatus(409);
        $this->assertSame(0, ProbeSample::count());
    }

    public function test_missing_quorum_cannot_show_green_or_manufacture_uptime_and_two_remote_successes_prevent_incident(): void
    {
        $c = Component::create(['name' => 'Web']);
        $check = Check::create(['component_id' => $c->id, 'type' => 'http', 'target' => 'https://example.net', 'retries' => 1]);
        $a = ProbeLocation::create(['name' => 'A', 'token_hash' => hash('sha256', 'a')]);
        $b = ProbeLocation::create(['name' => 'B', 'token_hash' => hash('sha256', 'b')]);
        $check->locations()->attach([$a->id, $b->id]);
        $probe = new class extends Probe
        {
            public function run(Check $check): ProbeResult
            {
                return new ProbeResult(true, 20);
            }
        };
        $runner = new CheckRunner($probe, app(OutgoingWebhook::class));
        $runner->runOne($check);
        $this->assertSame(ComponentStatus::PerformanceIssues, $c->fresh()->status);
        $this->assertSame(0, UptimeDay::count());
        $this->assertSame(0, Incident::count());
        foreach ([$a, $b] as $location) {
            ProbeSample::create(['check_id' => $check->id, 'probe_location_id' => $location->id, 'ok' => true, 'checked_at' => now()]);
        }$failed = new class extends Probe
        {
            public function run(Check $check): ProbeResult
            {
                return new ProbeResult(false, 20);
            }
        };
        (new CheckRunner($failed, app(OutgoingWebhook::class)))->runOne($check->fresh());
        $this->assertSame(ComponentStatus::Operational, $c->fresh()->status);
        $this->assertSame(0, Incident::count());
    }
}
