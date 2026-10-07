<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Models\User;
use App\Services\BackupArchive;
use App\Services\RemoteBackup;
use App\Services\SafeHttp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use League\Flysystem\Filesystem;
use Tests\TestCase;

class MonitoringTransportSecurityTest extends TestCase
{
    use RefreshDatabase;

    public function test_mixed_case_schemes_pin_the_effective_http_port_in_both_policies(): void
    {
        $safe = new class(allowedHosts: []) extends SafeHttp
        {
            public function addresses(string $host): array
            {
                return ['203.0.113.10'];
            }
        };
        foreach (['HTTP://example.test/up' => 80, 'hTtP://example.test/up' => 80, 'HTTPS://example.test/up' => 443, 'HtTpS://example.test/up' => 443, 'HTTP://example.test:8080/up' => 8080, 'HTTPS://example.test:8443/up' => 8443] as $url => $port) {
            foreach (['to', 'toOwn'] as $policy) {
                $options = $safe->$policy($url)->getOptions();
                $this->assertSame(['example.test:'.$port.':203.0.113.10'], $options['curl'][CURLOPT_RESOLVE], $url.' '.$policy);
            }
        }
    }

    public function test_false_like_or_noncryptographic_sftp_fingerprints_are_rejected_by_admin_form(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        foreach (['0', 'false', 'abc', 'SHA256:0', 'SHA256:abcdef'] as $fingerprint) {
            $this->actingAs($admin)->post('/admin/backup-destinations', ['name' => 'X', 'driver' => 'sftp', 'host' => '203.0.113.10', 'username' => 'fixture', 'password' => 'keep-private', 'fingerprint' => $fingerprint, 'root' => '/backups'])->assertSessionHasErrors('fingerprint');
        }$this->assertDatabaseCount('backup_destinations', 0);
    }

    public function test_corrupt_persisted_fingerprints_fail_closed_before_any_resolution(): void
    {
        $safe = new class extends SafeHttp
        {
            public function resolve(string $host): string
            {
                throw new \LogicException('Resolution must not run');
            }

            public function resolveOwn(string $host): string
            {
                throw new \LogicException('Resolution must not run');
            }
        };
        $backup = new RemoteBackup(app(BackupArchive::class), $safe);
        foreach ([null, '', false, 0, '0', 'garbage', 'SHA256:0'] as $fingerprint) {
            $d = BackupDestination::create(['name' => 'X', 'driver' => 'sftp', 'configuration' => ['host' => '203.0.113.10', 'username' => 'fixture', 'fingerprint' => $fingerprint, 'root' => '/backups'], 'credentials' => ['password' => 'secret']]);
            try {
                $backup->filesystem($d);
                $this->fail('Invalid fingerprint reached adapter');
            } catch (\RuntimeException $e) {
                $this->assertSame('A verified SSH host fingerprint is required.', $e->getMessage());
            }
        }
    }

    public function test_valid_openssh_hex_and_base64_fingerprints_remain_usable(): void
    {
        foreach (['SHA256:'.rtrim(base64_encode(str_repeat('a', 32)), '='), 'sha512:'.base64_encode(str_repeat('b', 64)), str_repeat('ab', 32), 'SHA256:'.implode(':', array_fill(0, 32, 'AB'))] as $fingerprint) {
            $d = new BackupDestination(['driver' => 'sftp', 'configuration' => ['host' => '203.0.113.10', 'username' => 'fixture', 'fingerprint' => $fingerprint, 'root' => '/backups'], 'credentials' => ['password' => 'secret']]);
            $this->assertInstanceOf(Filesystem::class, app(RemoteBackup::class)->filesystem($d));
        }
    }
}
