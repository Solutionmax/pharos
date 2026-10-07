<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Models\Check;
use App\Models\Component;
use App\Models\ProbeLocation;
use App\Models\ProbeSample;
use App\Models\User;
use App\Services\Probe;
use App\Services\ProbeResult;
use App\Services\RemoteBackup;
use App\Services\TransferLimits;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MonitoringTransferLimitsTest extends TestCase
{
    use RefreshDatabase;

    public function test_oversized_probe_result_is_rejected_before_unknown_fields_are_ignored(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $location = ProbeLocation::create(['owner_id' => $owner->id, 'name' => 'Remote', 'token_hash' => hash('sha256', 'remote')]);
        $c = Component::create(['name' => 'A']);
        $check = Check::create(['component_id' => $c->id, 'type' => 'http', 'target' => 'https://example.net']);
        $location->checks()->attach($check);
        $job = $this->withToken('remote')->getJson('/api/v1/probe/jobs')->json('jobs.0.id');
        $this->postJson('/api/v1/probe/results', ['job' => $job, 'ok' => true, 'padding' => str_repeat('x', 20000)])->assertStatus(413);
        $this->assertDatabaseCount('probe_samples', 0);
    }

    public function test_probe_limit_precedes_global_json_transformers_without_content_length(): void
    {
        $request = new class extends Request
        {
            public bool $parsed = false;

            public function json($key = null, $default = null)
            {
                $this->parsed = true;

                return parent::json($key, $default);
            }
        };
        $request->initialize([], [], [], [], [], ['REQUEST_URI' => '/api/v1/probe/results', 'REQUEST_METHOD' => 'POST', 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{"padding":"'.str_repeat('x', 20000).'"}');
        $response = app(Kernel::class)->handle($request);
        $this->assertSame(413, $response->getStatusCode());
        $this->assertFalse($request->parsed, 'Global request transformer parsed oversized JSON');
    }

    public function test_remote_command_accepts_full_ui_http_target_length(): void
    {
        config(['monitoring.probe_hub' => 'https://203.0.113.10', 'monitoring.probe_token' => str_repeat('x', 40)]);
        $target = 'https://example.net/'.str_repeat('x', 235);
        $this->assertSame(255, strlen($target));
        $probe = new class extends Probe
        {
            public ?string $target = null;

            public function run(Check $check): ProbeResult
            {
                $this->target = $check->target;

                return new ProbeResult(true, 1);
            }
        };
        $this->app->instance(Probe::class, $probe);
        Http::fake(['*/jobs' => Http::sequence()->push(['jobs' => [['id' => '550e8400-e29b-41d4-a716-446655440000', 'type' => 'http', 'target' => $target, 'timeout_seconds' => 1]]])->push(['jobs' => []]), '*/results' => Http::response(['ok' => true])]);
        $this->artisan('pharos:probe-remote')->assertSuccessful();
        $this->assertSame($target, $probe->target);
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/results') && $request['ok'] === true);
    }

    public function test_s3_userinfo_and_query_credentials_are_rejected_and_never_flashed(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (['https://user:secret@203.0.113.10', 'https://203.0.113.10?token=secret'] as $endpoint) {
            $this->actingAs($admin)->post('/admin/backup-destinations', ['driver' => 's3', 'endpoint' => $endpoint, 'region' => 'us-east-1', 'bucket' => 'fixture-bucket', 'key' => 'keep-private', 'secret' => 'keep-private'])->assertSessionHasErrors('endpoint');
            $this->assertNull(session()->getOldInput('endpoint'));
        }$this->assertDatabaseCount('backup_destinations', 0);
    }

    public function test_headers_and_deadlines_abort_before_oversized_download_finishes(): void
    {
        $options = TransferLimits::options(16, 10);
        try {
            $options['on_headers'](new Response(200, ['Content-Length' => 100]));
            $this->fail('Declared oversized response accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('Remote response exceeds verification size.', $e->getMessage());
        }$this->expectException(\RuntimeException::class);
        TransferLimits::options(16, 10, microtime(true) - 1);
    }

    public function test_chunked_response_sink_refuses_bytes_before_buffering_excess(): void
    {
        $options = TransferLimits::options(16, 10);
        $this->assertSame(8, $options['sink']->write(str_repeat('x', 8)));
        $this->assertSame(0, $options['sink']->write(str_repeat('x', 9)));
        $this->assertSame(8, $options['sink']->getSize());
    }

    public function test_serial_slow_jobs_are_claimed_one_at_a_time_without_expiry_or_starvation(): void
    {
        $owner = User::factory()->create(['role' => 'admin']);
        $location = ProbeLocation::create(['owner_id' => $owner->id, 'name' => 'Remote', 'token_hash' => hash('sha256', 'slow-remote')]);
        $checks = [];
        for ($i = 0; $i < 8; $i++) {
            $c = Component::create(['name' => 'Slow '.$i]);
            $checks[] = Check::create(['component_id' => $c->id, 'type' => 'http', 'target' => 'https://example.net/'.$i, 'timeout_seconds' => 30, 'interval_seconds' => 30]);
        }$location->checks()->attach(array_map(fn ($check) => $check->id, $checks));
        for ($i = 0; $i < 8; $i++) {
            $jobs = $this->withToken('slow-remote')->getJson('/api/v1/probe/jobs')->assertOk()->json('jobs');
            $this->assertCount(1, $jobs);
            $this->travel(30)->seconds();
            $this->postJson('/api/v1/probe/results', ['job' => $jobs[0]['id'], 'ok' => true, 'latency_ms' => 30000])->assertOk();
        }$this->assertSame(8, ProbeSample::distinct()->count('check_id'));
    }

    public function test_corrupt_persisted_endpoint_credentials_fail_before_transport_creation(): void
    {
        $d = new BackupDestination(['driver' => 's3', 'configuration' => ['endpoint' => 'https://user:secret@203.0.113.10', 'region' => 'us-east-1', 'bucket' => 'fixture-bucket'], 'credentials' => ['key' => 'key', 'secret' => 'secret']]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Storage endpoint must use HTTPS without credentials or query strings.');
        app(RemoteBackup::class)->filesystem($d);
    }
}
