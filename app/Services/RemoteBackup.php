<?php

namespace App\Services;

use App\Models\BackupDestination;
use Aws\S3\S3Client;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\CurlHandler;
use Illuminate\Support\Facades\Cache;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use League\Flysystem\PhpseclibV3\SftpConnectionProvider;
use Psr\Http\Message\RequestInterface;

class RemoteBackup
{
    public function __construct(private BackupArchive $archive, private SafeHttp $safe) {}

    public function filesystem(BackupDestination $destination): Filesystem
    {
        $c = $destination->configuration;
        $secret = $destination->credentials;
        $allow = (bool) ($c['allow_private'] ?? false);
        if ($destination->driver === 'sftp') {
            if (! SftpFingerprint::valid($c['fingerprint'] ?? null)) {
                throw new \RuntimeException('A verified SSH host fingerprint is required.');
            }
            $ip = $allow ? $this->safe->resolveOwn($c['host']) : $this->safe->resolve($c['host']);

            return new Filesystem(new SftpAdapter(new SftpConnectionProvider(host: $ip, username: $c['username'], password: $secret['password'], port: (int) ($c['port'] ?? 22), timeout: 20, maxTries: 1, hostFingerprint: $c['fingerprint']), $c['root'] ?? '/'));
        }
        $endpoint = $c['endpoint'] ?? 'https://s3.'.($c['region'] ?? 'us-east-1').'.amazonaws.com';
        $handler = function (RequestInterface $request, array $options) use ($allow) {
            $url = (string) $request->getUri();
            if (! str_starts_with($url, 'https://')) {
                throw new \RuntimeException('HTTPS storage required');
            }
            $host = $request->getUri()->getHost();
            $ip = $allow ? $this->safe->resolveOwn($host) : $this->safe->resolve($host);
            $options['allow_redirects'] = false;
            $options['verify'] = true;
            $options['timeout'] = 60;
            $options['curl'][CURLOPT_RESOLVE] = [$host.':'.($request->getUri()->getPort() ?? 443).':'.$ip];

            return (new Client(['handler' => new CurlHandler]))->sendAsync($request, $options);
        };
        $client = new S3Client(['version' => 'latest', 'region' => $c['region'], 'endpoint' => $endpoint, 'use_path_style_endpoint' => true, 'credentials' => ['key' => $secret['key'], 'secret' => $secret['secret']], 'http_handler' => $handler]);

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
            $path = $this->archive->create();
            $fs = $this->filesystem($destination);
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
