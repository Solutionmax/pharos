<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\AuditEntry;
use App\Models\BackupDestination;
use App\Models\Setting;
use App\Models\User;
use App\Services\BackupArchive;
use App\Services\BackupCrypto;
use App\Services\BackupPassphrase;
use App\Services\RemoteBackup;
use App\Services\SafeHttp;
use Illuminate\Encryption\Encrypter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use RuntimeException;
use Tests\TestCase;

/**
 * G1: a remote backup is encrypted with a passphrase of its own, or leaves
 * .env (APP_KEY) out when there is none.
 */
class BackupEncryptionTest extends TestCase
{
    use RefreshDatabase;

    protected string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pharos-backup-test-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->dir.'/remote');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    protected function crypto(): BackupCrypto
    {
        // The real cost is three passes over 64 MiB; the format is what is tested here.
        return new BackupCrypto(BackupCrypto::MIN_OPSLIMIT, BackupCrypto::MIN_MEMLIMIT);
    }

    // ---------- the file format ----------

    public function test_a_round_trip_returns_the_same_bytes_across_chunks(): void
    {
        foreach (['empty' => '', 'small' => 'PK pharos', 'exact chunk' => random_bytes(1048576), 'several chunks' => random_bytes(2621440)] as $label => $content) {
            file_put_contents($plain = $this->dir.'/'.md5($label), $content);

            $this->crypto()->encryptFile($plain, $plain.'.enc', 'correct horse battery');
            $this->assertTrue(BackupCrypto::looksEncrypted($plain.'.enc'), $label);
            $this->assertStringNotContainsString('PK pharos', (string) file_get_contents($plain.'.enc'));
            $this->assertSame(0600, fileperms($plain.'.enc') & 0777, $label);

            $this->crypto()->decryptFile($plain.'.enc', $plain.'.out', 'correct horse battery');
            $this->assertSame(hash('sha256', $content), hash_file('sha256', $plain.'.out'), $label);
        }
    }

    public function test_the_real_cost_settings_round_trip_too(): void
    {
        file_put_contents($plain = $this->dir.'/plain', 'archive');
        app(BackupCrypto::class)->encryptFile($plain, $plain.'.enc', 'a long enough passphrase');

        $header = unpack('Nops/Nmem', substr((string) file_get_contents($plain.'.enc'), 10, 8));
        $this->assertSame(SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, $header['ops']);
        $this->assertSame(SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE, $header['mem']);

        // Decrypting reads the cost from the file, whatever this install would use now.
        $this->crypto()->decryptFile($plain.'.enc', $plain.'.out', 'a long enough passphrase');
        $this->assertSame('archive', file_get_contents($plain.'.out'));
    }

    public function test_a_wrong_passphrase_is_refused_and_leaves_nothing_behind(): void
    {
        file_put_contents($plain = $this->dir.'/plain', random_bytes(5000));
        $this->crypto()->encryptFile($plain, $plain.'.enc', 'the right passphrase');

        try {
            $this->crypto()->decryptFile($plain.'.enc', $plain.'.out', 'the wrong passphrase');
            $this->fail('A wrong passphrase must not decrypt.');
        } catch (RuntimeException $e) {
            $this->assertSame('The passphrase is wrong, or the file is damaged.', $e->getMessage());
        }
        $this->assertFileDoesNotExist($plain.'.out');
    }

    public function test_a_changed_cut_or_extended_file_is_refused(): void
    {
        file_put_contents($plain = $this->dir.'/plain', random_bytes(1500000));
        $this->crypto()->encryptFile($plain, $plain.'.enc', 'passphrase here');
        $good = (string) file_get_contents($plain.'.enc');

        $flipped = $good;
        $flipped[strlen($good) - 100] = chr(ord($flipped[strlen($good) - 100]) ^ 1);
        $changedSalt = $good;
        $changedSalt[30] = chr(ord($changedSalt[30]) ^ 1);
        $cases = [
            'one bit in the last chunk' => $flipped,
            'salt' => $changedSalt,
            'last chunk missing' => substr($good, 0, 62 + 1048576 + 17),
            'cut mid chunk' => substr($good, 0, strlen($good) - 10),
            'data after the end' => $good.'x',
            'not ours' => 'PK'.substr($good, 2),
            'cost out of range' => substr_replace($good, pack('N', 4294967295), 14, 4),
        ];

        foreach ($cases as $label => $bytes) {
            file_put_contents($bad = $this->dir.'/bad', $bytes);
            try {
                $this->crypto()->decryptFile($bad, $bad.'.out', 'passphrase here');
                $this->fail("Accepted: {$label}");
            } catch (RuntimeException) {
                $this->assertFileDoesNotExist($bad.'.out', $label);
            }
        }
    }

    // ---------- the remote backup ----------

    /** A RemoteBackup that writes to a local folder and snapshots a small SQLite file. */
    protected function remote(): RemoteBackup
    {
        $sqlite = $this->dir.'/source.sqlite';
        (new \SQLite3($sqlite))->exec('CREATE TABLE evidence(value TEXT); INSERT INTO evidence VALUES ("kept")');
        $remoteDir = $this->dir.'/remote';

        $archive = new class($sqlite) extends BackupArchive
        {
            public function __construct(private string $sqlite) {}

            public function create(?string $sqliteSource = null, bool $includeEnv = true): string
            {
                return parent::create($this->sqlite, $includeEnv);
            }
        };

        return new class($archive, app(SafeHttp::class), $this->crypto(), $remoteDir) extends RemoteBackup
        {
            public function __construct(BackupArchive $archive, SafeHttp $safe, BackupCrypto $crypto, private string $remoteDir)
            {
                parent::__construct($archive, $safe, $crypto);
            }

            public function filesystem(BackupDestination $destination, int $maxReadBytes = 16777216, ?float $deadline = null): Filesystem
            {
                return new Filesystem(new LocalFilesystemAdapter($this->remoteDir));
            }
        };
    }

    protected function destination(): BackupDestination
    {
        return BackupDestination::create(['name' => 'Offsite', 'driver' => 'sftp', 'configuration' => ['host' => 'backup.example.net'], 'credentials' => ['password' => 'x']]);
    }

    /** @return list<string> */
    protected function uploaded(): array
    {
        return array_values(array_map('basename', glob($this->dir.'/remote/*') ?: []));
    }

    /** @return array<string, string|false> name => content, for the few entries a test reads */
    protected function zipEntries(string $zipPath, array $names): array
    {
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($zipPath) === true);
        $entries = [];
        foreach ($names as $name) {
            $entries[$name] = $zip->getFromName($name);
        }
        $zip->close();

        return $entries;
    }

    public function test_with_a_passphrase_the_upload_is_encrypted_and_carries_env(): void
    {
        $this->assertFileExists(base_path('.env'));
        BackupPassphrase::set('a passphrase for the bucket');
        $destination = $this->destination();

        $this->assertTrue($this->remote()->run($destination));

        $files = $this->uploaded();
        $this->assertCount(1, $files);
        $this->assertMatchesRegularExpression('/^pharos-\d{8}-\d{6}-[0-9a-f]{16}\.zip\.enc$/', $files[0]);
        $this->assertNotNull($destination->fresh()->last_success_at);

        $this->crypto()->decryptFile($this->dir.'/remote/'.$files[0], $this->dir.'/restored.zip', 'a passphrase for the bucket');
        $entries = $this->zipEntries($this->dir.'/restored.zip', ['.env', 'BACKUP-README.txt', 'composer.json']);
        $this->assertSame(file_get_contents(base_path('.env')), $entries['.env']);
        $this->assertStringStartsWith('Pharos backup. Restore application files and .env', (string) $entries['BACKUP-README.txt']);
        $this->assertNotFalse($entries['composer.json']);

        // Nothing stays behind on this server.
        $this->assertSame([], glob(storage_path('app/private/remote-backups/*')) ?: []);
    }

    public function test_without_a_passphrase_the_upload_leaves_env_out(): void
    {
        $this->assertTrue($this->remote()->run($this->destination()));

        $files = $this->uploaded();
        $this->assertCount(1, $files);
        $this->assertStringEndsWith('.zip', $files[0]);
        $entries = $this->zipEntries($this->dir.'/remote/'.$files[0], ['.env', 'BACKUP-README.txt', 'database/database.sqlite']);
        $this->assertFalse($entries['.env']);
        $this->assertStringContainsString('WITHOUT .env', (string) $entries['BACKUP-README.txt']);
        $this->assertNotFalse($entries['database/database.sqlite']);
    }

    public function test_a_passphrase_that_cannot_be_read_stops_the_backup_instead_of_uploading_it_plain(): void
    {
        Setting::put(BackupPassphrase::KEY, (new Encrypter(random_bytes(32), 'aes-256-cbc'))->encryptString('old key'));
        $destination = $this->destination();

        $this->assertFalse($this->remote()->run($destination));

        $this->assertSame([], $this->uploaded());
        $this->assertSame('Backup failed: the backup passphrase cannot be read. Set it again.', $destination->fresh()->last_error);
    }

    public function test_the_archive_can_still_be_made_with_env_for_other_callers(): void
    {
        $this->assertFileExists(base_path('.env'));
        $sqlite = $this->dir.'/source.sqlite';
        (new \SQLite3($sqlite))->exec('CREATE TABLE t(v TEXT)');

        $path = app(BackupArchive::class)->create($sqlite);
        $entries = $this->zipEntries($path, ['.env']);
        @unlink($path);

        $this->assertNotFalse($entries['.env']);
    }

    // ---------- the screen ----------

    public function test_an_admin_sets_the_passphrase_and_it_is_stored_encrypted(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->get('/admin/system-monitoring')->assertOk()
            ->assertSee('Not encrypted')
            ->assertSee('Keep APP_KEY somewhere else');

        $this->actingAs($admin)->put('/admin/backup-passphrase', ['passphrase' => 'short', 'passphrase_confirmation' => 'short'])
            ->assertSessionHasErrors('passphrase');
        $this->assertStringNotContainsString('short', (string) json_encode(session()->getOldInput()));
        $this->actingAs($admin)->put('/admin/backup-passphrase', ['passphrase' => 'long enough passphrase', 'passphrase_confirmation' => 'something else'])
            ->assertSessionHasErrors('passphrase');
        $this->assertFalse(BackupPassphrase::isSet());

        $this->actingAs($admin)->put('/admin/backup-passphrase', ['passphrase' => 'long enough passphrase', 'passphrase_confirmation' => 'long enough passphrase'])
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame('long enough passphrase', BackupPassphrase::get());
        $this->assertStringNotContainsString('long enough', (string) Setting::get(BackupPassphrase::KEY));
        $audit = AuditEntry::where('action', 'like', 'setting.%')->latest('id')->firstOrFail();
        $this->assertStringNotContainsString('long enough', (string) json_encode($audit->changes));

        $this->actingAs($admin)->get('/admin/system-monitoring')->assertOk()
            ->assertSee('Archives encrypted')
            ->assertSee('encrypted with APP_KEY, because scheduled uploads need it')
            ->assertDontSee('long enough passphrase');

        $this->actingAs($admin)->delete('/admin/backup-passphrase')->assertRedirect();
        $this->assertFalse(BackupPassphrase::isSet());
        $this->assertNull(BackupPassphrase::get());
    }

    public function test_only_installation_admins_manage_the_passphrase(): void
    {
        $user = User::factory()->create(['role' => UserRole::User]);

        $this->actingAs($user)->put('/admin/backup-passphrase', ['passphrase' => 'long enough passphrase', 'passphrase_confirmation' => 'long enough passphrase'])->assertForbidden();
        $this->actingAs($user)->delete('/admin/backup-passphrase')->assertForbidden();
        $this->assertFalse(BackupPassphrase::isSet());
    }

    // ---------- the command ----------

    public function test_the_command_decrypts_with_the_passphrase_it_asks_for(): void
    {
        $this->app->instance(BackupCrypto::class, $this->crypto());
        file_put_contents($plain = $this->dir.'/pharos-1.zip', 'zip bytes');
        $this->crypto()->encryptFile($plain, $plain.'.enc', 'the passphrase');
        unlink($plain);

        $this->artisan('pharos:backup:decrypt', ['file' => $plain.'.enc'])
            ->expectsQuestion('Backup passphrase', 'not it')
            ->expectsOutputToContain('The passphrase is wrong, or the file is damaged.')
            ->assertFailed();
        $this->assertFileDoesNotExist($plain);

        $this->artisan('pharos:backup:decrypt', ['file' => $plain.'.enc'])
            ->expectsQuestion('Backup passphrase', 'the passphrase')
            ->expectsOutputToContain('Decrypted to '.$plain)
            ->assertSuccessful();
        $this->assertSame('zip bytes', file_get_contents($plain));

        // It never overwrites, and --out chooses the place.
        $this->artisan('pharos:backup:decrypt', ['file' => $plain.'.enc'])->expectsOutputToContain('exists already')->assertFailed();
        $this->artisan('pharos:backup:decrypt', ['file' => $plain.'.enc', '--out' => $this->dir.'/elsewhere.zip'])
            ->expectsQuestion('Backup passphrase', 'the passphrase')->assertSuccessful();
        $this->assertSame('zip bytes', file_get_contents($this->dir.'/elsewhere.zip'));

        $this->artisan('pharos:backup:decrypt', ['file' => $this->dir.'/elsewhere.zip'])->expectsOutputToContain('not an encrypted Pharos backup')->assertFailed();
    }
}
