<?php

namespace Tests\Feature;

use App\Enums\ComponentStatus;
use App\Models\Check;
use App\Models\CheckResult;
use App\Models\Component;
use App\Services\CheckRunner;
use App\Services\DnsResolver;
use App\Services\LatencyHistory;
use App\Services\OutgoingWebhook;
use App\Services\Probe;
use App\Services\ProbeResult;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MonitoringChecksTest extends TestCase
{
    use RefreshDatabase;

    public function test_missing_keyword_is_down_and_body_is_bounded(): void
    {
        Http::fake(['203.0.113.10/*' => Http::sequence()->push('healthy page', 200)->push('healthy page', 200)->push(str_repeat('a', 1048577), 200)]);
        $check = new Check(['type' => 'http', 'target' => 'http://203.0.113.10/', 'expected_keyword' => 'ready']);
        $this->assertFalse(app(Probe::class)->run($check)->ok);
        $check->expected_keyword = 'healthy';
        $this->assertTrue(app(Probe::class)->run($check)->ok);
        $this->assertFalse(app(Probe::class)->run($check)->ok);
    }

    public function test_dns_matches_all_supported_record_shapes(): void
    {
        $resolver = new DnsResolver;
        foreach ([['A', '192.0.2.1', ['ip' => '192.0.2.1']], ['AAAA', '2001:db8::1', ['ipv6' => '2001:0db8:0:0:0:0:0:1']], ['CNAME', 'Example.NET.', ['target' => 'example.net']], ['MX', '10 mail.example.net', ['pri' => 10, 'target' => 'mail.example.net.']], ['TXT', 'hello world', ['entries' => ['hello ', 'world']]]] as [$type,$expected,$record]) {
            $this->assertTrue($resolver->matches($type, $expected, [$record]));
            $this->assertFalse($resolver->matches($type, 'wrong', [$record]));
        }
    }

    public function test_tls_three_days_degrades_without_an_outage(): void
    {
        $component = Component::create(['name' => 'Website']);
        $check = Check::create(['component_id' => $component->id, 'type' => 'http', 'target' => 'https://example.net']);
        $probe = new class extends Probe
        {
            public function run(Check $check): ProbeResult
            {
                return new ProbeResult(true, 20, 'Certificate expires soon', now()->addDays(2), true);
            }
        };
        (new CheckRunner($probe, app(OutgoingWebhook::class)))->runOne($check);
        $this->assertSame(ComponentStatus::PerformanceIssues, $component->fresh()->status);
        $this->assertNotNull($check->fresh()->tls_expires_at);
    }

    public function test_latency_has_fixed_buckets_gaps_and_24_hour_cutoff(): void
    {
        $component = Component::create(['name' => 'Latency']);
        foreach ([[-25, 900], [-1, 23]] as [$hours,$latency]) {
            CheckResult::create(['component_id' => $component->id, 'ok' => true, 'latency_ms' => $latency, 'checked_at' => now()->addHours($hours)]);
        }
        $data = app(LatencyHistory::class)->for($component);
        $this->assertCount(288, $data);
        $this->assertContains(23.0, array_column($data, 'ms'));
        $this->assertNotContains(900.0, array_column($data, 'ms'));
        $this->assertContains(null, array_column($data, 'ms'));
    }

    public function test_dns_runtime_uses_record_fixture_and_invalid_configuration_fails(): void
    {
        $this->app->instance(DnsResolver::class, new class extends DnsResolver
        {
            public function records(string $name, string $type): array
            {
                return [['ip' => '192.0.2.55']];
            }
        });
        $check = new Check(['type' => 'dns', 'target' => 'fixture.example.test', 'dns_type' => 'A', 'dns_expected' => '192.0.2.55']);
        $this->assertTrue(app(Probe::class)->run($check)->ok);
        $check->dns_expected = '192.0.2.56';
        $this->assertFalse(app(Probe::class)->run($check)->ok);
        $dns = new DnsResolver;
        try {
            $dns->records('localhost/evil', 'A');
            $this->fail('Invalid DNS name accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('Invalid DNS name', $e->getMessage());
        }
    }
}
