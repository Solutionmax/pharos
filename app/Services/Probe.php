<?php

namespace App\Services;

use App\Enums\CheckType;
use App\Models\Check;

/**
 * Performs one check. Kept free of database writes so it can be tested and
 * swapped out (an external probe network would implement the same contract).
 */
class Probe
{
    public function run(Check $check): ProbeResult
    {
        return match ($check->type) {
            CheckType::Http => $this->http($check),
            CheckType::Tcp => $this->tcp($check),
            CheckType::Heartbeat => $this->heartbeat($check),
        };
    }

    protected function http(Check $check): ProbeResult
    {
        $start = microtime(true);
        $ctx = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $check->timeout_seconds,
                'ignore_errors' => true,
                'header' => "User-Agent: Pharos/1.0 (status monitor)\r\n",
            ],
            'ssl' => ['verify_peer' => true, 'verify_peer_name' => true],
        ]);

        $body = @file_get_contents($check->target, false, $ctx);
        $ms = (int) round((microtime(true) - $start) * 1000);

        // PHP fills $http_response_header after file_get_contents; static analysis cannot see that.
        if ($body === false && ! isset($http_response_header)) { // @phpstan-ignore booleanAnd.alwaysFalse, isset.variable
            return new ProbeResult(false, $ms, 'No response');
        }

        $code = 0;
        foreach ($http_response_header ?? [] as $line) { // @phpstan-ignore nullCoalesce.variable
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $code = (int) $m[1];
            }
        }

        return $code >= 200 && $code < 400
            ? new ProbeResult(true, $ms, "HTTP $code")
            : new ProbeResult(false, $ms, "HTTP $code");
    }

    protected function tcp(Check $check): ProbeResult
    {
        [$host, $port] = array_pad(explode(':', $check->target, 2), 2, null);
        if (! $port) {
            return new ProbeResult(false, null, 'Target must be host:port');
        }

        $start = microtime(true);
        $sock = @fsockopen($host, (int) $port, $errno, $errstr, $check->timeout_seconds);
        $ms = (int) round((microtime(true) - $start) * 1000);

        if ($sock === false) {
            return new ProbeResult(false, $ms, $errstr ?: 'Connection refused');
        }
        fclose($sock);

        return new ProbeResult(true, $ms, 'Connected');
    }

    /**
     * Inverted check: something out there is expected to call in. Stale means down.
     * ponytail: grace is one full interval; make it configurable if flapping shows up.
     */
    protected function heartbeat(Check $check): ProbeResult
    {
        if ($check->last_run_at === null) {
            return new ProbeResult(false, null, 'Never reported in');
        }

        $late = $check->last_run_at->addSeconds($check->interval_seconds * 2)->isPast();

        return $late
            ? new ProbeResult(false, null, 'No heartbeat since '.$check->last_run_at->diffForHumans())
            : new ProbeResult(true, null, 'Heartbeat received');
    }
}
