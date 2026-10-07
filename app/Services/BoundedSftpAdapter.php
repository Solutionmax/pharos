<?php

namespace App\Services;

use League\Flysystem\Config;
use League\Flysystem\PathPrefixer;
use League\Flysystem\PhpseclibV3\ConnectionProvider;
use League\Flysystem\PhpseclibV3\SftpAdapter;
use phpseclib3\Net\SFTP;

/** Native SFTP callbacks bound verification bytes and all transfer wall time. */
class BoundedSftpAdapter extends SftpAdapter
{
    private PathPrefixer $boundedPrefix;

    public function __construct(private ConnectionProvider $boundedProvider, string $root, private int $maxBytes, private float $deadline)
    {
        parent::__construct($boundedProvider, $root);
        $this->boundedPrefix = new PathPrefixer($root);
    }

    private function guard(int $bytes = 0): void
    {
        if ($bytes > $this->maxBytes) {
            throw new \RuntimeException('Remote response exceeds verification size.');
        }if (microtime(true) >= $this->deadline) {
            throw new \RuntimeException('Remote transfer budget expired.');
        }
    }

    private function connection(): SFTP
    {
        $this->guard();
        $connection = $this->boundedProvider->provideConnection();
        $this->guard();
        $connection->setTimeout(min(20, max(1, $this->deadline - microtime(true))));

        return $connection;
    }

    public function readStream(string $path)
    {
        $connection = $this->connection();
        $stream = fopen('php://temp', 'w+b');
        $bytes = 0;
        try {
            $ok = $connection->get($this->boundedPrefix->prefixPath($path), function (string $chunk) use ($stream, &$bytes): void {
                $bytes += strlen($chunk);
                $this->guard($bytes);
                if (fwrite($stream, $chunk) !== strlen($chunk)) {
                    throw new \RuntimeException('Could not verify remote data.');
                }
            }, 0, $this->maxBytes + 1);
            $this->guard($bytes);
            if ($ok === false) {
                throw new \RuntimeException('Could not verify remote data.');
            }rewind($stream);

            return $stream;
        } catch (\Throwable $e) {
            fclose($stream);
            throw $e;
        }
    }

    public function read(string $path): string
    {
        $stream = $this->readStream($path);
        try {
            return stream_get_contents($stream);
        } finally {
            fclose($stream);
        }
    }

    public function write(string $path, string $contents, Config $config): void
    {
        $stream = fopen('php://temp', 'w+b');
        try {
            fwrite($stream, $contents);
            rewind($stream);
            $this->writeStream($path, $stream, $config);
        } finally {
            fclose($stream);
        }
    }

    public function writeStream(string $path, $contents, Config $config): void
    {
        $connection = $this->connection();
        $this->createDirectory(dirname($path), new Config(['visibility' => 'private']));
        $this->guard();
        $ok = $connection->put($this->boundedPrefix->prefixPath($path), $contents, SFTP::SOURCE_STRING, -1, -1, fn ($bytes) => $this->guard((int) $bytes));
        $this->guard();
        if ($ok === false) {
            throw new \RuntimeException('Could not deliver remote data.');
        }$this->connection();
        $this->setVisibility($path, 'private');
    }
}
