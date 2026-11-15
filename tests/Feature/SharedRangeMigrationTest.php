<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Models\Setting;
use App\Services\SafeHttp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 1.2.0 refuses CGNAT (Tailscale) and the other shared ranges for single sign
 * on and remote backups. An install that already used one keeps working.
 */
class SharedRangeMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->app->instance(SafeHttp::class, new class extends SafeHttp
        {
            public function addresses(string $host): array
            {
                return filter_var($host, FILTER_VALIDATE_IP) ? [$host] : ([
                    'id.tailnet.test' => ['100.101.102.103'],
                    'id.public.test' => ['203.0.113.7'],
                    'id.lan.test' => ['192.168.1.20'],
                    'sftp.tailnet.test' => ['100.90.1.2'],
                    'minio.tailnet.test' => ['100.90.1.3'],
                ][$host] ?? []);
            }
        });
    }

    protected function migrate(): void
    {
        (require database_path('migrations/2026_11_15_120000_keep_shared_range_destinations_reachable.php'))->up();
    }

    public function test_an_sso_provider_on_a_tailnet_is_added_to_the_internal_hosts(): void
    {
        Setting::put('sso.issuer', 'https://id.tailnet.test/application/o/pharos');
        Setting::put('sso.internal_hosts', 'other.example.net');

        $this->migrate();

        $this->assertSame('other.example.net, id.tailnet.test', Setting::get('sso.internal_hosts'));
        $this->assertTrue((new SafeHttp)->isAllowed('id.tailnet.test'));

        // Running it again adds nothing twice.
        $this->migrate();
        $this->assertSame('other.example.net, id.tailnet.test', Setting::get('sso.internal_hosts'));
    }

    public function test_a_probe_hub_on_a_tailnet_is_added_once_next_to_the_provider(): void
    {
        Setting::put('sso.issuer', 'https://id.tailnet.test');
        config(['monitoring.probe_hub' => 'https://minio.tailnet.test']);

        $this->migrate();
        $this->migrate();

        $this->assertSame('id.tailnet.test, minio.tailnet.test', Setting::get('sso.internal_hosts'));
    }

    public function test_an_sso_provider_on_a_public_or_private_address_is_left_alone(): void
    {
        // Public was allowed and stays allowed; private was refused and stays refused.
        foreach (['https://id.public.test', 'https://id.lan.test', 'https://id.nowhere.test'] as $issuer) {
            Setting::put('sso.issuer', $issuer);
            $this->migrate();
            $this->assertNull(Setting::get('sso.internal_hosts'), $issuer);
        }
    }

    public function test_a_backup_destination_on_a_tailnet_gets_the_private_network_permission(): void
    {
        $sftp = BackupDestination::create(['name' => 'Tail SFTP', 'driver' => 'sftp', 'configuration' => ['host' => 'sftp.tailnet.test', 'username' => 'b'], 'credentials' => ['password' => 'x']]);
        $s3 = BackupDestination::create(['name' => 'Tail S3', 'driver' => 's3', 'configuration' => ['endpoint' => 'https://minio.tailnet.test:9000', 'region' => 'eu', 'bucket' => 'b'], 'credentials' => ['key' => 'k', 'secret' => 's']]);
        $public = BackupDestination::create(['name' => 'Public', 'driver' => 'sftp', 'configuration' => ['host' => 'id.public.test'], 'credentials' => ['password' => 'x']]);
        $lan = BackupDestination::create(['name' => 'LAN', 'driver' => 'sftp', 'configuration' => ['host' => 'id.lan.test'], 'credentials' => ['password' => 'x']]);

        $this->migrate();

        $this->assertTrue($sftp->fresh()->configuration['allow_private']);
        $this->assertTrue($s3->fresh()->configuration['allow_private']);
        $this->assertSame('minio.tailnet.test:9000', parse_url($s3->fresh()->configuration['endpoint'], PHP_URL_HOST).':'.parse_url($s3->fresh()->configuration['endpoint'], PHP_URL_PORT));
        $this->assertArrayNotHasKey('allow_private', $public->fresh()->configuration);
        $this->assertArrayNotHasKey('allow_private', $lan->fresh()->configuration);
        $this->assertSame('x', $sftp->fresh()->credentials['password']);
    }
}
