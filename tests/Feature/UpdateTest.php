<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\SelfUpdater;
use App\Services\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class UpdateTest extends TestCase
{
    use RefreshDatabase;

    protected string $secret;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $pair = sodium_crypto_sign_keypair();
        $this->secret = sodium_crypto_sign_secretkey($pair);

        config([
            'pharos.license_public_key' => sodium_bin2hex(sodium_crypto_sign_publickey($pair)),
            'pharos.version' => '1.0.0',
            'pharos.update.manifest_url' => 'https://releases.example.net/latest.json',
            'pharos.update.check_enabled' => true,
            'pharos.update.status_file' => storage_path('app/testing/update-status.json'),
            'pharos.update.trigger_file' => storage_path('app/testing/update.trigger'),
        ]);

        File::deleteDirectory(storage_path('app/testing'));
        Cache::flush();

        $this->user = User::create([
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => Hash::make('correct-horse-battery'),
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(storage_path('app/testing'));
        parent::tearDown();
    }

    protected function sign(array $payload, ?string $secret = null): string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
        $b64 = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        return $b64($json).'.'.$b64(sodium_crypto_sign_detached($json, $secret ?? $this->secret));
    }

    protected function manifest(array $overrides = []): string
    {
        return $this->sign(array_merge([
            'purpose' => 'pharos-release',
            'version' => '1.1.0',
            'url' => 'https://releases.example.net/pharos-1.1.0.zip',
            'sha256' => str_repeat('a', 64),
            'notes' => 'Faster checks.',
            'released_at' => '2026-09-01',
        ], $overrides));
    }

    // ---------- verifying the manifest ----------

    public function test_a_signed_manifest_is_accepted(): void
    {
        Http::fake(['releases.example.net/*' => Http::response($this->manifest())]);

        $latest = app(Updater::class)->latest();

        $this->assertSame('1.1.0', $latest['version']);
        $this->assertTrue(app(Updater::class)->updateAvailable());
    }

    public function test_a_manifest_signed_with_another_key_is_refused(): void
    {
        $other = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
        Http::fake(['releases.example.net/*' => Http::response($this->sign([
            'purpose' => 'pharos-release', 'version' => '9.9.9',
            'url' => 'https://evil.example/x.zip', 'sha256' => str_repeat('b', 64),
        ], $other))]);

        $this->assertNull(app(Updater::class)->latest());
        $this->assertFalse(app(Updater::class)->updateAvailable());
    }

    public function test_a_licence_key_cannot_be_replayed_as_a_release(): void
    {
        // Same key signs both, so the purpose field is what keeps them apart.
        Http::fake(['releases.example.net/*' => Http::response($this->sign([
            'product' => 'pharos', 'issued_to' => 'someone@example.com',
            'features' => ['brand_pack'], 'version' => '9.9.9',
            'url' => 'https://example.net/x.zip', 'sha256' => str_repeat('c', 64),
        ]))]);

        $this->assertNull(app(Updater::class)->latest());
    }

    public function test_a_manifest_missing_its_download_is_refused(): void
    {
        Http::fake(['releases.example.net/*' => Http::response($this->sign([
            'purpose' => 'pharos-release', 'version' => '1.1.0', 'sha256' => str_repeat('a', 64),
        ]))]);

        $this->assertNull(app(Updater::class)->latest());
    }

    public function test_an_unreachable_release_server_reads_as_no_news(): void
    {
        // Never an error the operator sees mid-outage.
        Http::fake(fn () => throw new \RuntimeException('connection refused'));

        $this->assertNull(app(Updater::class)->latest());
        $this->assertFalse(app(Updater::class)->updateAvailable());
    }

    public function test_checking_can_be_switched_off(): void
    {
        config(['pharos.update.check_enabled' => false]);
        Http::fake();

        $this->assertNull(app(Updater::class)->latest());
        Http::assertNothingSent();
    }

    public function test_an_older_release_is_not_an_update(): void
    {
        Http::fake(['releases.example.net/*' => Http::response($this->manifest(['version' => '0.9.0']))]);

        $this->assertFalse(app(Updater::class)->updateAvailable());
    }

    // ---------- installing ----------

    public function test_a_downgrade_is_refused(): void
    {
        // An older build may not survive migrations the current one already ran.
        $manifest = json_decode(base64_decode(strtr(explode('.', $this->manifest(['version' => '0.9.0']))[0], '-_', '+/')), true);

        $result = app(SelfUpdater::class)->apply($manifest);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('Already on 1.0.0', $result['message']);
    }

    public function test_an_archive_that_does_not_match_the_checksum_is_never_unpacked(): void
    {
        Http::fake(['releases.example.net/pharos-1.1.0.zip' => Http::response('not the release')]);

        $manifest = [
            'purpose' => 'pharos-release', 'version' => '1.1.0',
            'url' => 'https://releases.example.net/pharos-1.1.0.zip',
            'sha256' => str_repeat('a', 64),
        ];

        $result = app(SelfUpdater::class)->apply($manifest);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('does not match the signed checksum', $result['message']);
    }

    public function test_it_refuses_without_a_verified_manifest(): void
    {
        Http::fake(['releases.example.net/*' => Http::response('rubbish')]);

        $result = app(SelfUpdater::class)->apply();

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('No verified release', $result['message']);
    }

    // ---------- managed (Docker) installs ----------

    public function test_an_install_is_unmanaged_until_a_host_says_otherwise(): void
    {
        $this->assertFalse(app(Updater::class)->managed());
    }

    public function test_a_status_file_makes_it_managed_and_apply_drops_a_trigger(): void
    {
        File::ensureDirectoryExists(storage_path('app/testing'));
        File::put(config('pharos.update.status_file'), json_encode(['updateAvailable' => true]));

        $updater = app(Updater::class);

        $this->assertTrue($updater->managed());
        $this->assertSame(['updateAvailable' => true], $updater->managedStatus());
        $this->assertTrue($updater->requestManagedUpdate());
        $this->assertFileExists(config('pharos.update.trigger_file'));
    }

    public function test_an_unmanaged_install_cannot_drop_a_trigger(): void
    {
        $this->assertFalse(app(Updater::class)->requestManagedUpdate());
        $this->assertFileDoesNotExist(config('pharos.update.trigger_file'));
    }

    // ---------- the screen ----------

    public function test_the_updates_screen_is_closed_to_strangers(): void
    {
        $this->get('/admin/updates')->assertRedirect('/admin/login');
    }

    public function test_the_screen_reports_what_is_installed_and_available(): void
    {
        Http::fake(['releases.example.net/*' => Http::response($this->manifest())]);

        $this->actingAs($this->user)->get('/admin/updates')
            ->assertOk()
            ->assertSee('1.0.0')
            ->assertSee('1.1.0')
            ->assertSee('Faster checks.');
    }

    public function test_a_managed_install_is_told_the_host_owns_the_image(): void
    {
        File::ensureDirectoryExists(storage_path('app/testing'));
        File::put(config('pharos.update.status_file'), json_encode(['updateAvailable' => true]));
        Http::fake(['releases.example.net/*' => Http::response($this->manifest())]);

        $this->actingAs($this->user)->get('/admin/updates')
            ->assertOk()
            ->assertSee('managed from outside')
            ->assertSee('docker compose pull');
    }

    public function test_the_sidebar_flags_an_available_update(): void
    {
        Http::fake(['releases.example.net/*' => Http::response($this->manifest())]);

        $this->actingAs($this->user)->get('/admin/components')
            ->assertOk()
            ->assertSee('class="dot-new"', false);
    }

    public function test_the_sidebar_stays_quiet_when_there_is_nothing_to_install(): void
    {
        // A second Http::fake() appends a stub rather than replacing it, and the
        // first match wins, so this needs its own test rather than a second half.
        Http::fake(['releases.example.net/*' => Http::response($this->manifest(['version' => '1.0.0']))]);

        $this->actingAs($this->user)->get('/admin/components')
            ->assertOk()
            ->assertDontSee('class="dot-new"', false);
    }

    // ---------- the version has to travel with the release ----------

    public function test_the_shipped_env_example_does_not_pin_the_version(): void
    {
        // .env is never replaced by an update, so a version pinned there survives
        // the upgrade: the install keeps reporting the old number and offers the
        // same release for ever. The number has to come from the code that ships.
        $this->assertStringNotContainsString(
            'PHAROS_VERSION=',
            (string) file_get_contents(base_path('.env.example')),
        );
    }

    public function test_it_warns_when_the_version_is_pinned_in_the_environment(): void
    {
        putenv('PHAROS_VERSION=0.1.0-dev');
        $_ENV['PHAROS_VERSION'] = $_SERVER['PHAROS_VERSION'] = '0.1.0-dev';
        Http::fake(['releases.example.net/*' => Http::response('', 404)]); // no live DNS lookup from the suite

        try {
            $this->assertTrue(app(Updater::class)->versionIsPinned());

            $this->actingAs($this->user)->get('/admin/updates')
                ->assertOk()
                ->assertSee('PHAROS_VERSION');
        } finally {
            putenv('PHAROS_VERSION');
            unset($_ENV['PHAROS_VERSION'], $_SERVER['PHAROS_VERSION']);
        }
    }

    public function test_nothing_is_flagged_when_the_version_comes_from_the_code(): void
    {
        putenv('PHAROS_VERSION');
        unset($_ENV['PHAROS_VERSION'], $_SERVER['PHAROS_VERSION']);

        $this->assertFalse(app(Updater::class)->versionIsPinned());
    }

    // ---------- what an archive may contain ----------

    /** Builds a release archive under storage/app/testing and returns its path. */
    protected function archive(callable $fill): string
    {
        File::ensureDirectoryExists(storage_path('app/testing'));
        $path = storage_path('app/testing/release.zip');

        $zip = new \ZipArchive;
        $zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE);
        $fill($zip);
        $zip->close();

        return $path;
    }

    /** @return array{ok:bool,message:string} */
    protected function applyArchive(string $path): array
    {
        Http::fake(['releases.example.net/pharos-1.1.0.zip' => Http::response(file_get_contents($path))]);

        return app(SelfUpdater::class)->apply([
            'purpose' => 'pharos-release', 'version' => '1.1.0',
            'url' => 'https://releases.example.net/pharos-1.1.0.zip',
            'sha256' => hash_file('sha256', $path),
        ]);
    }

    public function test_an_archive_that_climbs_out_of_its_folder_is_refused(): void
    {
        // Neither entry belongs in anything we built; the checksum passes because
        // the point is what happens after it.
        $result = $this->applyArchive($this->archive(function (\ZipArchive $zip) {
            $zip->addFromString('pharos/README.md', 'fine');
            $zip->addFromString('../evil.txt', 'gotcha');
        }));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('../evil.txt', $result['message']);
        $this->assertStringContainsString('Nothing was installed', $result['message']);
        $this->assertFileDoesNotExist(storage_path('app/evil.txt'));
    }

    public function test_an_archive_with_a_symlink_is_refused(): void
    {
        $result = $this->applyArchive($this->archive(function (\ZipArchive $zip) {
            $zip->addFromString('pharos/README.md', 'fine');
            $zip->addFromString('pharos/storage', '/etc');
            $zip->setExternalAttributesName('pharos/storage', \ZipArchive::OPSYS_UNIX, (0120000 | 0777) << 16);
        }));

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('pharos/storage', $result['message']);
        $this->assertStringContainsString('symlink', $result['message']);
    }

    // ---------- the outcome of a check is cached, not just a manifest ----------

    public function test_an_unreachable_server_is_asked_once_an_hour_not_once_a_page(): void
    {
        // Cache::remember drops a null result, so before this every admin page
        // sat through the 8 s timeout whenever the release server was down.
        Http::fake(['releases.example.net/*' => Http::failedConnection()]);

        $updater = app(Updater::class);

        $this->assertNull($updater->latest());
        $this->assertNull($updater->latest());
        $this->assertSame('unreachable', $updater->lastCheck()['state']);

        $this->actingAs($this->user)->get('/admin/updates')->assertOk()
            ->assertSee('Release server not reachable')
            ->assertSee('No newer release known')
            ->assertDontSee('Up to date');
        $this->actingAs($this->user)->get('/admin/components')->assertOk();

        Http::assertSentCount(1);
    }

    public function test_a_missing_manifest_reads_as_no_release_and_is_asked_once(): void
    {
        Http::fake(['releases.example.net/*' => Http::response('', 404)]);

        $this->assertNull(app(Updater::class)->latest());
        $this->assertSame('no_release', app(Updater::class)->lastCheck()['state']);

        $this->actingAs($this->user)->get('/admin/updates')->assertOk()
            ->assertSee('No release published yet')
            ->assertDontSee('Up to date');

        Http::assertSentCount(1);
    }

    public function test_a_refused_manifest_is_named_as_such(): void
    {
        Http::fake(['releases.example.net/*' => Http::response('rubbish')]);

        $this->assertSame('invalid', app(Updater::class)->lastCheck()['state']);

        $this->actingAs($this->user)->get('/admin/updates')->assertOk()
            ->assertSee('Manifest refused')
            ->assertSee('not signed by our key');
    }

    public function test_switched_off_checking_is_said_out_loud(): void
    {
        config(['pharos.update.check_enabled' => false]);
        Http::fake();

        $this->assertSame('disabled', app(Updater::class)->lastCheck()['state']);
        $this->assertNull(app(Updater::class)->lastCheck()['checked_at']);

        $this->actingAs($this->user)->get('/admin/updates')->assertOk()
            ->assertSee('Checking is switched off')
            ->assertSee('Never checked yet')
            ->assertDontSee('every hour');
    }

    public function test_up_to_date_is_only_claimed_after_a_successful_check(): void
    {
        Http::fake(['releases.example.net/*' => Http::response($this->manifest(['version' => '1.0.0']))]);

        $this->actingAs($this->user)->get('/admin/updates')->assertOk()
            ->assertSee('Up to date')
            ->assertSee('Released 2026-09-01')
            ->assertSee('Checks releases.example.net every hour')
            ->assertSee('Last checked')
            ->assertSee('next automatic check in');
    }

    // ---------- check again ----------

    public function test_check_again_asks_the_server_again_and_says_what_it_found(): void
    {
        Http::fake(['releases.example.net/*' => Http::response($this->manifest())]);

        $this->actingAs($this->user)->get('/admin/updates')->assertOk();
        Http::assertSentCount(1);

        $this->actingAs($this->user)->get('/admin/updates?refresh=1')
            ->assertRedirect('/admin/updates')
            ->assertSessionHas('status', 'Checked just now — 1.1.0 is available.');
        Http::assertSentCount(2);
    }

    public function test_check_again_reports_nothing_new(): void
    {
        Http::fake(['releases.example.net/*' => Http::response($this->manifest(['version' => '1.0.0']))]);

        $this->actingAs($this->user)->get('/admin/updates?refresh=1')
            ->assertRedirect('/admin/updates')
            ->assertSessionHas('status', 'Checked just now — nothing new.');
    }

    public function test_check_again_reports_an_unreachable_server(): void
    {
        Http::fake(['releases.example.net/*' => Http::failedConnection()]);

        $this->actingAs($this->user)->get('/admin/updates?refresh=1')
            ->assertRedirect('/admin/updates')
            ->assertSessionHas('status', 'Checked just now — the release server could not be reached.');
    }

    // ---------- release notes ----------

    public function test_release_notes_render_as_markdown(): void
    {
        Http::fake(['releases.example.net/*' => Http::response($this->manifest(['notes' => '**Faster** checks']))]);

        $this->actingAs($this->user)->get('/admin/updates')->assertOk()
            ->assertSee('<strong>Faster</strong> checks', false);
    }

    public function test_release_notes_cannot_smuggle_html(): void
    {
        Http::fake(['releases.example.net/*' => Http::response($this->manifest(['notes' => 'Hi <script>alert(1)</script>']))]);

        $this->actingAs($this->user)->get('/admin/updates')->assertOk()
            ->assertDontSee('<script>alert(1)</script>', false)
            ->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    // ---------- backups ----------

    public function test_backups_are_listed_newest_first(): void
    {
        config(['pharos.update.backups_dir' => storage_path('app/testing/backups')]);
        Http::fake(['releases.example.net/*' => Http::response($this->manifest())]);

        foreach (['1.0.0-20260801-030000', '1.0.1-20260815-041500'] as $name) {
            File::ensureDirectoryExists(storage_path("app/testing/backups/{$name}/app"));
            File::put(storage_path("app/testing/backups/{$name}/app/x.php"), str_repeat('x', 2048));
        }

        $backups = app(SelfUpdater::class)->backups();

        $this->assertSame(['1.0.1', '1.0.0'], array_column($backups, 'version'));
        $this->assertSame('2026-08-15 04:15:00', $backups[0]['created_at']->format('Y-m-d H:i:s'));
        $this->assertSame(2048, $backups[0]['size']);

        $this->actingAs($this->user)->get('/admin/updates')->assertOk()
            ->assertSee('Backups kept')
            ->assertSeeInOrder(['1.0.1', '1.0.0'])
            ->assertSee('storage/app/backups');
    }

    public function test_the_backups_panel_is_honest_when_empty(): void
    {
        config(['pharos.update.backups_dir' => storage_path('app/testing/backups')]);
        Http::fake(['releases.example.net/*' => Http::response($this->manifest())]);

        $this->assertSame([], app(SelfUpdater::class)->backups());

        $this->actingAs($this->user)->get('/admin/updates')->assertOk()
            ->assertSee('No backups yet');
    }

    /**
     * A backup is only worth something if it can be put back whole: code and
     * vendor together, plus a consistent copy of the SQLite database — the
     * migrations that follow are what make the old code unusable otherwise.
     * Secrets and uploads are not in it; an update never touches those.
     */
    public function test_a_backup_keeps_code_vendor_and_the_database_but_not_secrets_or_uploads(): void
    {
        $src = storage_path('app/testing/src-'.Str::random(6));
        foreach (['artisan', 'vendor/autoload.php', '.env', 'storage/app/keep.txt', 'public/storage/logo.png', 'node_modules/x/index.js', 'database/database.sqlite'] as $f) {
            File::ensureDirectoryExists(dirname($src.'/'.$f));
            File::put($src.'/'.$f, 'x');
        }
        $dir = storage_path('app/testing/backups-'.Str::random(6));
        config(['pharos.update.backups_dir' => $dir]);

        // A file database standing in for the live one (the suite itself runs in memory).
        $live = $src.'/live.sqlite';
        (new \PDO('sqlite:'.$live))->exec('create table settings (k text)');

        $backup = app(SelfUpdater::class)->backupCurrent($src, $live);

        $this->assertFileExists($backup.'/artisan');
        $this->assertFileExists($backup.'/vendor/autoload.php');
        $this->assertFileDoesNotExist($backup.'/.env');
        $this->assertDirectoryDoesNotExist($backup.'/storage');
        $this->assertDirectoryDoesNotExist($backup.'/public/storage');
        $this->assertDirectoryDoesNotExist($backup.'/node_modules');

        // Not the stale file from the tree: a live, consistent copy of the database in use.
        $this->assertFileExists($backup.'/database/database.sqlite');
        $copy = new \PDO('sqlite:'.$backup.'/database/database.sqlite');
        $this->assertContains('settings', $copy->query("select name from sqlite_master where type='table'")->fetchAll(\PDO::FETCH_COLUMN));

        File::deleteDirectory($src);
        File::deleteDirectory($dir);
    }

    public function test_the_screen_says_the_database_is_in_the_backup_on_sqlite(): void
    {
        Http::fake(['releases.example.net/*' => Http::response('', 404)]);

        $this->actingAs($this->user)->get('/admin/updates')->assertOk()
            ->assertSee('SQLite database is copied into the backup');
    }
}
