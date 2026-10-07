<?php

namespace Tests\Unit;

use App\Services\BoundedSftpAdapter;
use League\Flysystem\PhpseclibV3\ConnectionProvider;
use phpseclib3\Net\SFTP;
use PHPUnit\Framework\TestCase;

class BoundedSftpAdapterTest extends TestCase
{
    public function test_oversized_sftp_data_aborts_during_transfer_and_requests_only_expected_bytes(): void
    {
        $connection = new class extends SFTP
        {
            public int $requested = 0;

            public function __construct()
            {
                parent::__construct('203.0.113.10');
            }

            public function get($remote_file, $local_file = false, $offset = 0, $length = -1, $progressCallback = null)
            {
                $this->requested = $length;
                $local_file(str_repeat('x', 100));

                return true;
            }
        };
        $provider = new class($connection) implements ConnectionProvider
        {
            public function __construct(private SFTP $connection) {}

            public function provideConnection(): SFTP
            {
                return $this->connection;
            }
        };
        $adapter = new BoundedSftpAdapter($provider, '/backups', 8, microtime(true) + 10);
        try {
            $adapter->readStream('object');
            $this->fail('Oversized bytes accepted');
        } catch (\RuntimeException $e) {
            $this->assertSame('Remote response exceeds verification size.', $e->getMessage());
        }$this->assertSame(9, $connection->requested);
    }

    public function test_expired_sftp_budget_aborts_before_connecting(): void
    {
        $provider = new class implements ConnectionProvider
        {
            public function provideConnection(): SFTP
            {
                throw new \LogicException('Must not connect');
            }
        };
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Remote transfer budget expired.');
        (new BoundedSftpAdapter($provider, '/backups', 8, microtime(true) - 1))->readStream('object');
    }

    public function test_native_callback_success_can_return_empty_string_and_preserves_stream_bytes(): void
    {
        $connection = new class extends SFTP
        {
            public function __construct()
            {
                parent::__construct('203.0.113.10');
            }

            public function get($remote_file, $local_file = false, $offset = 0, $length = -1, $progressCallback = null)
            {
                $local_file('fixture');

                return '';
            }
        };
        $provider = new class($connection) implements ConnectionProvider
        {
            public function __construct(private SFTP $connection) {}

            public function provideConnection(): SFTP
            {
                return $this->connection;
            }
        };
        $adapter = new BoundedSftpAdapter($provider, '/backups', 8, microtime(true) + 10);
        $stream = $adapter->readStream('object');
        $this->assertSame('fixture', stream_get_contents($stream));
        fclose($stream);
    }
}
