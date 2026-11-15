<?php

namespace Tests\Feature;

use App\Models\Setting;
use App\Models\User;
use App\Services\SelfUpdater;
use App\Services\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * G2: a replayed older manifest is refused, and only one update or rollback
 * runs at a time.
 */
class UpdaterSafetyTest extends TestCase
{
    use RefreshDatabase;

    protected string $secret;

    /** @var resource|null a lock held as if by another process */
    protected $held = null;

    protected function setUp(): void
    {
        parent::setUp();

        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);

        config([
            'pharos.license_public_key' => sodium_bin2hex(sodium_crypto_sign_publickey($pair)),
            'pharos.version' => '1.1.0',
            'pharos.update.manifest_url' => 'https://releases.example.net/latest.json',
            'pharos.update.check_enabled' => true,
            'pharos.update.status_file' => storage_path('app/testing-safety/update-status.json'),
            'pharos.update.backups_dir' => storage_path('app/testing-safety/backups'),
            'pharos.update.lock_file' => storage_path('app/testing-safety/pharos-update.lock'),
        ]);

        File::deleteDirectory(storage_path('app/testing-safety'));
        Cache::flush();
    }

    protected function tearDown(): void
    {
        if (is_resource($this->held)) {
            fclose($this->held);
        }
        File::deleteDirectory(storage_path('app/testing-safety'));
        parent::tearDown();
    }

    protected function manifest(array $overrides = []): string
    {
        $json = json_encode(array_merge([
            'purpose' => 'pharos-release',
            'version' => '1.2.0',
            'url' => 'https://releases.example.net/pharos-1.2.0.zip',
            'sha256' => str_repeat('a', 64),
            'notes' => '',
            'released_at' => '2026-11-20T14:00:00Z',
        ], $overrides), JSON_UNESCAPED_SLASHES);
        $b64 = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        return $b64($json).'.'.$b64(sodium_crypto_sign_detached($json, $this->secret));
    }

    protected string $served = '';

    /** What a fresh check finds when the release server answers with this manifest. */
    protected function checkWith(string $manifest): array
    {
        // One fake that answers with whatever is served now: a second Http::fake
        // for the same URL would never be reached.
        if ($this->served === '') {
            Http::fake(['releases.example.net/*' => fn () => Http::response($this->served)]);
        }
        $this->served = $manifest;

        return app(Updater::class)->lastCheck(fresh: true);
    }

    // ---------- rollback protection ----------

    public function test_an_older_manifest_is_refused_once_a_newer_one_was_accepted(): void
    {
        $this->assertSame('ok', $this->checkWith($this->manifest(['version' => '1.2.1', 'released_at' => '2026-11-25T09:30:00Z']))['state']);
        $this->assertSame(strtotime('2026-11-25T09:30:00Z'), app(Updater::class)->highestReleasedAt());

        // The 1.2.0 manifest, signed and valid, played back afterwards.
        $check = $this->checkWith($this->manifest());

        $this->assertSame('stale', $check['state']);
        $this->assertNull($check['manifest']);
        $this->assertStringContainsString('older than one this installation accepted before', (string) $check['error']);
        $this->assertFalse(app(Updater::class)->updateAvailable());
        // The mark never goes down.
        $this->assertSame(strtotime('2026-11-25T09:30:00Z'), app(Updater::class)->highestReleasedAt());
    }

    public function test_the_same_or_a_newer_release_date_is_accepted_and_raises_the_mark(): void
    {
        $this->assertSame('ok', $this->checkWith($this->manifest())['state']);
        $this->assertSame('ok', $this->checkWith($this->manifest())['state']);
        $this->assertSame('ok', $this->checkWith($this->manifest(['version' => '1.2.1', 'released_at' => '2026-11-21T08:00:00+01:00']))['state']);

        $this->assertSame(strtotime('2026-11-21T07:00:00Z'), app(Updater::class)->highestReleasedAt());
    }

    public function test_an_undated_manifest_only_works_until_a_dated_one_was_seen(): void
    {
        // A 1.1 style manifest without the field: nothing to compare yet, so fine.
        $this->assertSame('ok', $this->checkWith($this->manifest(['released_at' => null]))['state']);
        $this->assertNull(app(Updater::class)->highestReleasedAt());

        $this->assertSame('ok', $this->checkWith($this->manifest())['state']);

        $check = $this->checkWith($this->manifest(['released_at' => null]));
        $this->assertSame('stale', $check['state']);
        $this->assertStringContainsString('carries no release date', (string) $check['error']);
    }

    public function test_a_date_without_a_time_counts_as_midnight_utc(): void
    {
        // The 1.1.0 manifests carry the day only.
        $this->assertSame('ok', $this->checkWith($this->manifest(['version' => '1.1.0', 'released_at' => '2026-11-01']))['state']);
        $this->assertSame(strtotime('2026-11-01T00:00:00Z'), app(Updater::class)->highestReleasedAt());

        $this->assertSame('ok', $this->checkWith($this->manifest(['released_at' => '2026-11-01T10:00:00Z']))['state']);
        $this->assertSame('stale', $this->checkWith($this->manifest(['version' => '1.1.0', 'released_at' => '2026-11-01']))['state']);
    }

    public function test_a_release_date_in_a_form_we_cannot_read_makes_the_manifest_invalid(): void
    {
        foreach (['next tuesday', '2026-13-45', '', '1763208000'] as $odd) {
            $this->assertSame('invalid', $this->checkWith($this->manifest(['released_at' => $odd]))['state'], $odd);
        }
        $this->assertNull(app(Updater::class)->verify($this->manifest(['released_at' => ['2026-11-20']])));
    }

    public function test_apply_refuses_a_replayed_manifest_without_downloading_anything(): void
    {
        Setting::put(Updater::RELEASED_AT_KEY, (string) strtotime('2026-12-01T00:00:00Z'));
        Http::fake();
        $manifest = app(Updater::class)->verify($this->manifest());
        $this->assertNotNull($manifest);

        $result = app(SelfUpdater::class)->apply($manifest);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('older than one this installation accepted before', $result['message']);
        Http::assertNothingSent();
    }

    public function test_the_updates_screen_names_a_refused_replay(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Setting::put(Updater::RELEASED_AT_KEY, (string) strtotime('2026-12-01T00:00:00Z'));
        Http::fake(['releases.example.net/*' => Http::response($this->manifest())]);

        $this->actingAs($admin)->get('/admin/updates')->assertOk()
            ->assertSee('Manifest refused: older than a release this installation already saw');
        $this->actingAs($admin)->get('/admin/updates?refresh=1')
            ->assertSessionHas('status', 'Checked just now: the release server offered a manifest older than one this installation already accepted, so it was refused.');
    }

    public function test_the_screen_shows_the_release_day_of_a_timestamped_manifest(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Http::fake(['releases.example.net/*' => Http::response($this->manifest())]);

        $this->actingAs($admin)->get('/admin/updates')->assertOk()->assertSee('Released 2026-11-20');
    }

    public function test_the_signing_command_signs_a_release_time_to_the_second(): void
    {
        $dir = storage_path('app/testing-safety');
        File::ensureDirectoryExists($dir);
        File::put($dir.'/key.hex', sodium_bin2hex($this->secret));
        File::put($dir.'/pharos-1.2.0.zip', 'release');
        $this->travelTo(now()->setTimezone('Europe/Amsterdam')->setDate(2026, 11, 20)->setTime(15, 4, 5));

        $this->assertSame(0, Artisan::call('pharos:release:sign', ['version' => '1.2.0', 'url' => 'https://releases.example.net/pharos-1.2.0.zip', 'archive' => $dir.'/pharos-1.2.0.zip', '--key' => $dir.'/key.hex']));
        $signed = trim(Artisan::output());

        $manifest = app(Updater::class)->verify($signed);
        $this->assertNotNull($manifest, 'the command output must verify');
        $this->assertSame('2026-11-20T14:04:05Z', $manifest['released_at']);
        $this->assertSame(strtotime('2026-11-20T14:04:05Z'), Updater::releasedAt($manifest));
        $this->assertSame(hash('sha256', 'release'), $manifest['sha256']);

        // A test manifest is signed with the moment of latest.json, so the install that took it
        // does not refuse the real manifest of the same release as older.
        $this->assertSame(0, Artisan::call('pharos:release:sign', ['version' => '1.2.0', 'url' => 'https://upload.example.net/pharos-1.2.0.zip', 'archive' => $dir.'/pharos-1.2.0.zip', '--key' => $dir.'/key.hex', '--released-at' => '2026-11-19T08:00:00Z']));
        $this->assertSame('2026-11-19T08:00:00Z', app(Updater::class)->verify(trim(Artisan::output()))['released_at']);
        $this->assertSame(1, Artisan::call('pharos:release:sign', ['version' => '1.2.0', 'url' => 'https://upload.example.net/pharos-1.2.0.zip', 'archive' => $dir.'/pharos-1.2.0.zip', '--key' => $dir.'/key.hex', '--released-at' => 'tomorrow']));
    }

    // ---------- one at a time ----------

    /** Takes the lock from a separate handle, as a second PHP process would. */
    protected function holdLock(): void
    {
        $path = app(SelfUpdater::class)->lockPath();
        File::ensureDirectoryExists(dirname($path));
        $this->held = fopen($path, 'c');
        $this->assertTrue(flock($this->held, LOCK_EX | LOCK_NB));
    }

    protected function lockIsFree(): bool
    {
        $handle = fopen(app(SelfUpdater::class)->lockPath(), 'c');
        $free = flock($handle, LOCK_EX | LOCK_NB);
        fclose($handle);

        return $free;
    }

    public function test_an_update_is_refused_while_another_one_runs(): void
    {
        Http::fake();
        $this->holdLock();

        $result = app(SelfUpdater::class)->apply(app(Updater::class)->verify($this->manifest()));

        $this->assertFalse($result['ok']);
        $this->assertSame('Another update or rollback is running. Wait for it to finish, then try again.', $result['message']);
        Http::assertNothingSent();
    }

    public function test_a_rollback_is_refused_while_an_update_runs(): void
    {
        $dir = storage_path('app/testing-safety/backups/1.0.0-20260801-030000');
        File::ensureDirectoryExists($dir);
        File::put($dir.'/artisan', 'x');
        $this->holdLock();

        $result = app(SelfUpdater::class)->rollback('1.0.0-20260801-030000');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Another update or rollback is running', $result['message']);
        $this->assertFileExists($dir.'/artisan');
    }

    public function test_the_screen_reports_the_lock_as_an_error(): void
    {
        $admin = User::factory()->create(['role' => 'admin']);
        Http::fake(['releases.example.net/latest.json' => Http::response($this->manifest())]);
        $this->holdLock();

        $this->actingAs($admin)->postJson('/admin/updates')->assertStatus(422)
            ->assertJson(['ok' => false, 'message' => 'Another update or rollback is running. Wait for it to finish, then try again.']);
    }

    public function test_the_lock_is_held_during_the_update_and_let_go_after_it_failed(): void
    {
        $site = storage_path('app/testing-safety/site-'.Str::random(5));
        File::ensureDirectoryExists($site.'/vendor');
        File::put($site.'/artisan', 'old');
        File::put($site.'/vendor/x.php', 'x');
        config(['pharos.update.backup_source' => $site]);

        $zipPath = storage_path('app/testing-safety/release.zip');
        $zip = new \ZipArchive;
        $zip->open($zipPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $zip->addFromString('pharos/artisan', 'new');
        $zip->addFromString('pharos/app/Added.php', 'new');
        $zip->close();
        Http::fake(['releases.example.net/pharos-1.2.0.zip' => Http::response(file_get_contents($zipPath))]);

        $seenLocked = null;
        $mock = \Mockery::mock(SelfUpdater::class, [app(Updater::class)])->makePartial()->shouldAllowMockingProtectedMethods();
        $mock->shouldReceive('migrate')->once()->andReturnUsing(function () use (&$seenLocked) {
            $seenLocked = ! $this->lockIsFree();

            throw new \RuntimeException('migration failed');
        });
        $mock->shouldReceive('resetOpcodeCache')->andReturn('');

        $result = $mock->apply(app(Updater::class)->verify($this->manifest(['sha256' => hash_file('sha256', $zipPath)])));

        $this->assertTrue($seenLocked, 'another process must find the lock taken mid update');
        $this->assertFalse($result['ok']);
        // The rollback inside the failed update ran under the same lock, not against it.
        $this->assertStringContainsString('The previous version was put back', $result['message']);
        $this->assertFileDoesNotExist($site.'/app/Added.php');
        $this->assertTrue($this->lockIsFree());
    }
}
