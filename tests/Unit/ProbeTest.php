<?php

namespace Tests\Unit;

use App\Enums\CheckType;
use App\Models\Check;
use App\Services\Probe;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProbeTest extends TestCase
{
    protected function check(CheckType $type, string $target): Check
    {
        return (new Check)->forceFill(['type' => $type, 'target' => $target, 'timeout_seconds' => 5, 'interval_seconds' => 60]);
    }

    public function test_an_http_check_never_reaches_cloud_metadata_or_this_machine(): void
    {
        Http::fake();

        foreach (['http://169.254.169.254/latest/meta-data/', 'http://127.0.0.1:8080/', 'http://[::1]/'] as $target) {
            $result = (new Probe)->run($this->check(CheckType::Http, $target));

            $this->assertFalse($result->ok, $target);
            $this->assertStringContainsString('never allowed', $result->message);
        }

        Http::assertNothingSent();
    }

    public function test_an_http_check_reads_the_status_code_and_follows_no_redirect(): void
    {
        Http::fake([
            '203.0.113.10/up' => Http::response('ok', 200),
            '203.0.113.10/down' => Http::response('nope', 503),
            '203.0.113.10/moved' => Http::response('', 302, ['Location' => 'http://169.254.169.254/']),
        ]);

        $this->assertTrue((new Probe)->run($this->check(CheckType::Http, 'http://203.0.113.10/up'))->ok);
        $this->assertFalse((new Probe)->run($this->check(CheckType::Http, 'http://203.0.113.10/down'))->ok);

        $moved = (new Probe)->run($this->check(CheckType::Http, 'http://203.0.113.10/moved'));
        $this->assertTrue($moved->ok);
        $this->assertSame('HTTP 302', $moved->message);

        Http::assertSentCount(3);
    }

    public function test_a_tcp_check_refuses_this_machine_and_link_local(): void
    {
        foreach (['127.0.0.1:22', '169.254.169.254:80', '[::1]:22'] as $target) {
            $result = (new Probe)->run($this->check(CheckType::Tcp, $target));

            $this->assertFalse($result->ok, $target);
            $this->assertStringContainsString('never allowed', $result->message);
        }
    }
}
