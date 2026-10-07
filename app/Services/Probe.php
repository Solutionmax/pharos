<?php

namespace App\Services;

use App\Enums\CheckType;
use App\Models\Check;
use GuzzleHttp\TransferStats;
use Illuminate\Support\Carbon;

/**
 * Performs one check. Kept free of database writes so it can be tested and
 * swapped out (an external probe network would implement the same contract).
 *
 * Targets go through SafeHttp like every other URL an operator types: a check
 * may watch the LAN it belongs to, never this machine or the cloud metadata
 * address, and it follows no redirect — the response code is the result.
 */
class Probe
{
    public function __construct(protected ?SafeHttp $safe = null)
    {
        $this->safe ??= new SafeHttp;
    }

    public function run(Check $check): ProbeResult
    {
        return match ($check->type) {
            CheckType::Http => $this->http($check),
            CheckType::Tcp => $this->tcp($check),
            CheckType::Dns => $this->dns($check),
            CheckType::Heartbeat => $this->heartbeat($check),
        };
    }

    protected function http(Check $check): ProbeResult
    {
        try {
            $request = $this->safe->toOwn($check->target);
        } catch (\RuntimeException $e) {
            return new ProbeResult(false, null, $e->getMessage());
        }

        $start = microtime(true);

        try {
            $expiry = null;
            $response = $request->timeout($check->timeout_seconds)
                ->withOptions([
                    'curl' => [CURLOPT_CERTINFO => true],
                    'on_stats' => function (TransferStats $stats) use (&$expiry) {
                        $cert = $stats->getHandlerStats()['certinfo'][0]['Cert'] ?? null;
                        if ($cert && ($parsed = openssl_x509_parse($cert)) && isset($parsed['validTo_time_t'])) {
                            $expiry = Carbon::createFromTimestampUTC($parsed['validTo_time_t']);
                        }
                    },
                    'on_headers' => function ($response) {
                        if ((int) $response->getHeaderLine('Content-Length') > 1048576) {
                            throw new \RuntimeException('Response exceeds 1 MiB');
                        }
                    },
                    'progress' => function ($total, $downloaded) {
                        if ($downloaded > 1048576) {
                            throw new \RuntimeException('Response exceeds 1 MiB');
                        }
                    },
                ])
                ->withHeaders(['User-Agent' => 'Pharos/1.0 (status monitor)', 'Accept-Encoding' => 'identity'])
                ->get($check->target);
            $code = $response->status();
            if (strlen($response->body()) > 1048576) {
                return new ProbeResult(false, null, 'Response exceeds 1 MiB');
            }
        } catch (\Throwable $e) {
            return new ProbeResult(false, (int) round((microtime(true) - $start) * 1000), $this->reason($e));
        }

        $ms = (int) round((microtime(true) - $start) * 1000);

        $ok = $code >= 200 && $code < 400;
        if ($ok && $check->expected_keyword !== null && $check->expected_keyword !== '' && ! str_contains($response->body(), $check->expected_keyword)) {
            return new ProbeResult(false, $ms, 'Expected text is missing', $expiry);
        }
        $soon = $expiry && $expiry <= now()->addDays(14);

        return new ProbeResult($ok, $ms, $soon ? 'TLS certificate expires '.$expiry->toDateString() : "HTTP $code", $expiry, $ok && $expiry && $expiry <= now()->addDays(3));
    }

    protected function dns(Check $check): ProbeResult
    {
        $start = microtime(true);
        try {
            $dns = app(DnsResolver::class);
            $records = $dns->records($check->target, $check->dns_type ?? 'A');
            $ok = $dns->matches($check->dns_type ?? 'A', $check->dns_expected ?? '', $records);

            return new ProbeResult($ok, (int) round((microtime(true) - $start) * 1000), $ok ? 'DNS record matches' : 'Expected DNS record is missing');
        } catch (\Throwable $e) {
            return new ProbeResult(false, null, 'DNS lookup failed');
        }
    }

    protected function tcp(Check $check): ProbeResult
    {
        // host:port, with brackets around an IPv6 literal as in a URL.
        if (! preg_match('/^(\[[^\]]+\]|[^:]+):(\d{1,5})$/', $check->target, $m)) {
            return new ProbeResult(false, null, 'Target must be host:port');
        }
        $host = trim($m[1], '[]');
        $port = (int) $m[2];

        try {
            $connectTo = $this->safe->resolveOwn($host);
        } catch (\RuntimeException $e) {
            return new ProbeResult(false, null, $e->getMessage());
        }
        $connectTo = str_contains($connectTo, ':') ? "[{$connectTo}]" : $connectTo;

        $start = microtime(true);
        $sock = @fsockopen($connectTo, $port, $errno, $errstr, $check->timeout_seconds);
        $ms = (int) round((microtime(true) - $start) * 1000);

        if ($sock === false) {
            return new ProbeResult(false, $ms, $errstr ?: 'Connection refused');
        }
        fclose($sock);

        return new ProbeResult(true, $ms, 'Connected');
    }

    /** One line a person understands, without the curl error number. */
    protected function reason(\Throwable $e): string
    {
        $message = $e->getMessage();

        return match (true) {
            str_contains($message, 'timed out') => 'Timed out',
            str_contains($message, 'Could not resolve') => 'Name does not resolve',
            str_contains($message, 'certificate') => 'TLS certificate problem',
            $message === '' => 'No response',
            default => 'No response ('.substr(strtok($message, "\n") ?: $message, 0, 80).')',
        };
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
