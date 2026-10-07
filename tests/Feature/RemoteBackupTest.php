<?php

namespace Tests\Feature;

use App\Models\BackupDestination;
use App\Models\User;
use App\Services\BackupArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RemoteBackupTest extends TestCase
{
    use RefreshDatabase;

    public function test_credentials_are_encrypted_and_never_serialized(): void
    {
        $d = BackupDestination::create(['name' => 'Private', 'driver' => 'sftp', 'configuration' => ['host' => 'backup.example.net'], 'credentials' => ['password' => 'secret-value']]);
        $this->assertStringNotContainsString('secret-value', $d->getRawOriginal('credentials'));
        $this->assertSame('secret-value', $d->credentials['password']);
        $this->assertArrayNotHasKey('credentials', $d->toArray());
    }

    public function test_global_authority_and_secret_validation_do_not_echo_credentials(): void
    {
        $reader = User::factory()->create(['role' => 'user']);
        $this->actingAs($reader)->get('/admin/system-monitoring')->assertForbidden();
        $this->actingAs($reader)->post('/admin/backup-destinations', ['name' => 'X', 'driver' => 'sftp'])->assertForbidden();
        $admin = User::factory()->create(['role' => 'admin']);
        $this->actingAs($admin)->post('/admin/backup-destinations', ['name' => 'X', 'driver' => 'sftp', 'host' => '127.0.0.1', 'username' => 'a', 'password' => 'never-flash', 'fingerprint' => 'ab'])->assertSessionHasErrors();
        $this->assertStringNotContainsString('never-flash', json_encode(session()->getOldInput()));
    }

    public function test_consistent_sqlite_snapshot_and_private_files_are_in_archive(): void
    {
        $source = tempnam(sys_get_temp_dir(), 'backup-source');
        $db = new \SQLite3($source);
        $db->exec('PRAGMA journal_mode=WAL; CREATE TABLE evidence(value TEXT); INSERT INTO evidence VALUES ("retained");');
        $archive = app(BackupArchive::class)->create($source);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($archive) === true);
        $snapshot = tempnam(sys_get_temp_dir(), 'snapshot');
        file_put_contents($snapshot, $zip->getFromName('database/database.sqlite'));
        $copy = new \SQLite3($snapshot);
        $this->assertSame('retained', $copy->querySingle('SELECT value FROM evidence'));
        $this->assertSame('ok', $copy->querySingle('PRAGMA integrity_check'));
        $this->assertNotFalse($zip->getFromName('composer.json'));
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            $this->assertFalse(str_starts_with($name, 'storage/app/private/'), $name);
            $this->assertFalse(str_starts_with($name, 'vendor/'), $name);
            $this->assertFalse(str_starts_with($name, '.git/'), $name);
        }$zip->close();
        $copy->close();
        $db->close();
        unlink($source);
        unlink($snapshot);
        unlink($archive);
    }
}
