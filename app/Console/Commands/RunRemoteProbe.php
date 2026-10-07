<?php

namespace App\Console\Commands;

use App\Models\Check;
use App\Services\Probe;
use App\Services\SafeHttp;
use App\Services\TransferLimits;
use Illuminate\Console\Command;

class RunRemoteProbe extends Command
{
    protected $signature = 'pharos:probe-remote';

    protected $description = 'Fetch and run checks assigned to this remote location';

    public function handle(SafeHttp $safe, Probe $probe): int
    {
        $hub = rtrim((string) config('monitoring.probe_hub'), '/');
        $token = (string) config('monitoring.probe_token');
        if ($hub === '' && $token === '') {
            return self::SUCCESS;
        }
        if (! str_starts_with($hub, 'https://') || strlen($token) < 32) {
            $this->error('Configure an HTTPS probe hub and credential in .env.');

            return self::FAILURE;
        }
        try {
            $deadline = microtime(true) + 50;
            for ($claimed = 0; $claimed < 20 && $deadline - microtime(true) >= 44; $claimed++) {
                $url = $hub.'/api/v1/probe/jobs';
                $response = $safe->to($url)->withToken($token)->withHeaders(['Accept-Encoding' => 'identity'])
                    ->withOptions(TransferLimits::options(262144, 6, $deadline))->get($url);
                if (! $response->successful() || strlen($response->body()) > 262144) {
                    throw new \RuntimeException;
                }
                $jobs = $response->json('jobs');
                if (! is_array($jobs) || count($jobs) > 1) {
                    throw new \RuntimeException;
                }
                if ($jobs === []) {
                    break;
                }
                $data = validator($jobs[0], ['id' => 'required|uuid', 'type' => 'required|in:http,tcp,dns', 'target' => 'required|string|max:255', 'timeout_seconds' => 'required|integer|min:1|max:30', 'expected_keyword' => 'nullable|string|max:1000', 'dns_type' => 'nullable|in:A,AAAA,CNAME,MX,TXT', 'dns_expected' => 'nullable|string|max:1000'])->validate();
                $check = new Check(collect($data)->except('id')->all());
                $result = $probe->run($check);
                $url = $hub.'/api/v1/probe/results';
                $sent = $safe->to($url)->withToken($token)->withOptions(TransferLimits::options(16384, 6, $deadline))
                    ->post($url, ['job' => $data['id'], 'ok' => $result->ok, 'latency_ms' => $result->latencyMs]);
                if (! $sent->successful()) {
                    throw new \RuntimeException;
                }
            }
            $this->info('Remote probes completed.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('Remote probe failed. Verify hub configuration and credential.');

            return self::FAILURE;
        }
    }
}
