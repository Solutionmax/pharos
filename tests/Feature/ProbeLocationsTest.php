<?php

namespace Tests\Feature;

use App\Models\Check;
use App\Models\Component;
use App\Models\ProbeLocation;
use App\Models\ProbeSample;
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
}
