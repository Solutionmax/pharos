<?php

namespace App\Services;

use App\Models\BackupDestination;
use Aws\S3\S3Client;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Support\Facades\Cache;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use Psr\Http\Message\RequestInterface;

class RemoteBackup
{
    public function __construct(private BackupArchive $archive, private SafeHttp $safe) {}

    public function filesystem(BackupDestination $destination, int $maxReadBytes = 16777216, ?float $deadline = null): Filesystem
    {
        $deadline ??= microtime(true) + 300;
        $c = $destination->configuration;
        $secret = $destination->credentials;
        $allow = (bool) ($c['allow_private'] ?? false);
        if ($destination->driver === 'sftp') {
            if (! SftpFingerprint::valid($c['fingerprint'] ?? null)) {
                throw new \RuntimeException('A verified SSH host fingerprint is required.');
            }
            $ip = $allow ? $this->safe->resolveOwn($c['host']) : $this->safe->resolve($c['host']);

            return new Filesystem(new BoundedSftpAdapter(new SftpConnectionProvider(host: $ip, username: $c['username'], password: $secret['password'], port: (int) ($c['port'] ?? 22), timeout: 20, maxTries: 1, hostFingerprint: $c['fingerprint']), $c['root'] ?? '/', $maxReadBytes, $deadline));
        }
        $endpoint = $c['endpoint'] ?? 'https://s3.'.($c['region'] ?? 'us-east-1').'.amazonaws.com';
        $parts = parse_url($endpoint);
        if (! is_array($parts) || strtolower($parts['scheme'] ?? '') !== 'https' || array_intersect(['user', 'pass', 'query', 'fragment'], array_keys($parts)) !== []) {
            throw new \RuntimeException('Storage endpoint must use HTTPS without credentials or query strings.');
        }
        $handler = function (RequestInterface $request, array $options) use ($allow, $maxReadBytes, $deadline) {
            $url = (string) $request->getUri();
            if (! str_starts_with($url, 'https://')) {
                throw new \RuntimeException('HTTPS storage required');
            }
            $host = $request->getUri()->getHost();
            $ip = $allow ? $this->safe->resolveOwn($host) : $this->safe->resolve($host);
            $options['allow_redirects'] = false;
            $options['verify'] = true;
            $options = array_replace($options, TransferLimits::options($request->getMethod() === 'GET' ? $maxReadBytes : 262144, 60, $deadline));
            $options['curl'][CURLOPT_RESOLVE] = [$host.':'.($request->getUri()->getPort() ?? 443).':'.$ip];

            return (new Client(['handler' => new CurlHandler]))->sendAsync($request, $options);
        };
        $client = new S3Client(['version' => 'latest', 'region' => $c['region'], 'endpoint' => $endpoint, 'use_path_style_endpoint' => true, 'credentials' => ['key' => $secret['key'], 'secret' => $secret['secret']], 'http_handler' => $handler, 'retries' => 0]);

        return new Filesystem(new AwsS3V3Adapter($client, $c['bucket'], $c['prefix'] ?? ''));
    }

    public function run(BackupDestination $destination): bool
    {
        $lock = Cache::lock('pharos:remote-backup', 600);
        if (! $lock->get()) {
            return false;
        }
        $path = null;
        $source = null;
        $returned = null;
        try {
            $destination->update(['last_attempt_at' => now(), 'last_error' => null]);
            $deadline = microtime(true) + 300;
            $path = $this->archive->create();
            $expectedBytes = filesize($path);
            if ($expectedBytes === false) {
                throw new \RuntimeException('Archive size unavailable');
            }
            $fs = $this->filesystem($destination, $expectedBytes, $deadline);
            $name = basename($path);
            $source = fopen($path, 'rb');
            $fs->writeStream($name, $source, ['visibility' => 'private']);
            $returned = $fs->readStream($name);
            $hash = hash_init('sha256');
            hash_update_stream($hash, $returned);
            if (! hash_equals(hash_file('sha256', $path), hash_final($hash))) {
                throw new \RuntimeException('Remote verification failed');
            }
            $destination->update(['last_success_at' => now(), 'last_error' => null]);

            return true;
        } catch (\Throwable $e) {
            $destination->update(['last_error' => __('Backup failed. Verify destination, credentials and server access.')]);

            return false;
        } finally {
            if (is_resource($source)) {
                fclose($source);
            }
            if (is_resource($returned)) {
                fclose($returned);
            }
            if ($path) {
                @unlink($path);
            }
            $lock->release();
        }
    }
}
