<?php

namespace Tests\Feature;

use App\Models\AuditEntry;
use App\Models\User;
use App\Services\SelfUpdater;
use App\Services\Updater;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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

    public function test_a_refused_archive_leaves_a_failed_progress_sample_at_its_stage(): void
    {
        $manifest = ['purpose' => 'pharos-release', 'version' => '1.1.0', 'url' => 'https://releases.example.net/pharos-1.1.0.zip', 'sha256' => str_repeat('0', 64)];
        Http::fake(['releases.example.net/pharos-1.1.0.zip' => Http::response('not the release')]);

        $result = app(SelfUpdater::class)->apply($manifest);

        $this->assertFalse($result['ok']);
        $sample = app(SelfUpdater::class)->progress();
        $this->assertSame('failed', $sample['state']);
        $this->assertSame('verify', $sample['stage']);
        $this->assertSame($result['message'], $sample['message']);
    }

    public function test_the_screen_gets_json_when_it_asks_for_it(): void
    {
        Http::fake(['releases.example.net/*' => Http::response('rubbish')]);

        $this->actingAs($this->user)->postJson('/admin/updates')
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'message' => 'No verified release available.']);
    }

    public function test_the_install_button_reports_its_steps_through_the_job_dialog(): void
    {
        Http::fake(['releases.example.net/*' => Http::response($this->manifest())]);

        $this->actingAs($this->user)->get('/admin/updates')
            ->assertOk()
            ->assertSee('data-job="update"', false)
            ->assertSee('id="job-dialog"', false)
            ->assertSee('Backing up the current version');
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

    // ---------- backup management ----------

    /** A tiny stand-in for base_path(): backing up the whole repo per test is far too slow. */
    protected function fakeTree(): string
    {
        return $this->fakeSourceTree();
    }

    protected function fakeBackupsDir(): string
    {
        $dir = storage_path('app/testing/backups-'.Str::random(6));
        File::ensureDirectoryExists($dir);
        config(['pharos.update.backups_dir' => $dir]);

        return $dir;
    }

    protected function fakeSourceTree(): string
    {
        $src = storage_path('app/testing/src-'.Str::random(6));
        foreach (['artisan', 'vendor/x.php', '.env'] as $f) {
            File::ensureDirectoryExists(dirname($src.'/'.$f));
            File::put($src.'/'.$f, 'x');
        }
        config(['pharos.update.backup_source' => $src]);

        return $src;
    }

    protected function fakeBackup(string $name = '1.0.0-20260801-030000'): string
    {
        $dir = storage_path('app/testing/backups');
        config(['pharos.update.backups_dir' => $dir]);
        File::ensureDirectoryExists("{$dir}/{$name}/vendor");
        File::put("{$dir}/{$name}/artisan", 'x');
        File::put("{$dir}/{$name}/vendor/x.php", str_repeat('x', 1024));

        return "{$dir}/{$name}";
    }

    public function test_an_admin_can_make_a_backup_from_the_screen(): void
    {
        Http::fake(['releases.example.net/*' => Http::response('', 404)]);
        $this->fakeSourceTree();
        config(['pharos.update.backups_dir' => storage_path('app/testing/backups')]);

        $this->actingAs($this->user)->get('/admin/updates')->assertOk()->assertSee('Back up now');

        $response = $this->actingAs($this->user)->post('/admin/updates/backup')
            ->assertRedirect('/admin/updates');

        $backups = app(SelfUpdater::class)->backups();
        $this->assertCount(1, $backups);
        $this->assertSame('1.0.0', $backups[0]['version']);
        $this->assertFileExists(storage_path("app/testing/backups/{$backups[0]['name']}/vendor/x.php"));
        $this->assertFileDoesNotExist(storage_path("app/testing/backups/{$backups[0]['name']}/.env"));

        $response->assertSessionHas('status', fn ($status) => str_starts_with($status, "Backup made: {$backups[0]['name']} ("));

        $entry = AuditEntry::where('action', 'backup.created')->firstOrFail();
        $this->assertSame($backups[0]['name'], $entry->changes['name']);
    }

    public function test_a_failed_backup_is_reported_not_swallowed(): void
    {
        $this->fakeSourceTree();
        // A file where the backups folder should be: nothing can be created underneath it.
        $blocked = storage_path('app/testing/not-a-dir');
        File::ensureDirectoryExists(dirname($blocked));
        File::put($blocked, 'x');
        config(['pharos.update.backups_dir' => $blocked]);

        $this->actingAs($this->user)->from('/admin/updates')->post('/admin/updates/backup')
            ->assertRedirect('/admin/updates')
            ->assertSessionHasErrors('backup');
        $this->assertSame(0, AuditEntry::where('action', 'backup.created')->count());
    }

    public function test_a_backup_can_be_downloaded_as_a_zip(): void
    {
        $name = '1.0.0-20260801-030000';
        $this->fakeBackup($name);

        $response = $this->actingAs($this->user)->get("/admin/updates/backup/{$name}")->assertOk();
        $this->assertStringContainsString("{$name}.zip", $response->headers->get('content-disposition'));

        $file = storage_path('app/testing/downloaded.zip');
        // BinaryFileResponse streams: ob_start() catches what sendContent() writes.
        ob_start();
        $response->sendContent();
        File::put($file, ob_get_clean());

        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($file));
        $this->assertNotFalse($zip->locateName("{$name}/artisan"));
        $this->assertNotFalse($zip->locateName("{$name}/vendor/x.php"));
        $zip->close();

        // The temporary archive does not linger in storage/app.
        $this->assertSame([], File::glob(storage_path('app/update-dl-*.zip')));
        $this->assertSame(1, AuditEntry::where('action', 'backup.downloaded')->count());
    }

    public function test_a_backup_can_be_removed(): void
    {
        $name = '1.0.0-20260801-030000';
        $path = $this->fakeBackup($name);

        $this->actingAs($this->user)->from('/admin/updates')->delete("/admin/updates/backup/{$name}")
            ->assertRedirect('/admin/updates')
            ->assertSessionHas('status', "Backup {$name} removed.");

        $this->assertDirectoryDoesNotExist($path);
        $entry = AuditEntry::where('action', 'backup.deleted')->firstOrFail();
        $this->assertSame($name, $entry->changes['name']);
    }

    public function test_the_backup_row_offers_download_and_a_guarded_delete(): void
    {
        Http::fake(['releases.example.net/*' => Http::response('', 404)]);
        $name = '1.0.0-20260801-030000';
        $this->fakeBackup($name);

        $this->actingAs($this->user)->get('/admin/updates')->assertOk()
            ->assertSee("/admin/updates/backup/{$name}")
            ->assertSee("Remove backup {$name}?")
            ->assertSee('data-confirm-action="Remove backup"', false)
            ->assertDontSee('data-confirm-safe', false)
            ->assertSee('Download');
    }

    public function test_backup_names_are_never_paths(): void
    {
        $name = '1.0.0-20260801-030000';
        $path = $this->fakeBackup($name);
        // Something a traversal would reach if the name were used as-is.
        File::put(storage_path('app/testing/outside.txt'), 'x');

        foreach (['../outside.txt', '%2e%2e/outside.txt', '/etc/passwd', '..%2Foutside.txt', '9.9.9-20260101-000000'] as $bad) {
            $this->actingAs($this->user)->get("/admin/updates/backup/{$bad}")->assertNotFound();
            $this->actingAs($this->user)->delete("/admin/updates/backup/{$bad}")->assertNotFound();
        }

        $this->assertFileExists(storage_path('app/testing/outside.txt'));
        $this->assertDirectoryExists($path);
        $this->assertSame(0, AuditEntry::whereIn('action', ['backup.downloaded', 'backup.deleted'])->count());
    }

    public function test_backup_management_is_admin_only(): void
    {
        $name = '1.0.0-20260801-030000';
        $path = $this->fakeBackup($name);
        $this->fakeSourceTree();
        $member = User::create(['name' => 'Tom', 'email' => 'tom@example.com', 'password' => Hash::make('correct-horse-battery')]);

        $this->actingAs($member)->post('/admin/updates/backup')->assertForbidden();
        $this->actingAs($member)->get("/admin/updates/backup/{$name}")->assertForbidden();
        $this->actingAs($member)->delete("/admin/updates/backup/{$name}")->assertForbidden();

        $this->assertDirectoryExists($path);
        $this->assertCount(1, app(SelfUpdater::class)->backups());
    }

    // ---------- backup progress ----------

    /** A tree big enough for the every-25-files tick to fire inside vendor/. */
    protected function fakeBigSourceTree(int $code = 20, int $vendor = 40): string
    {
        $src = storage_path('app/testing/src-'.Str::random(6));
        for ($i = 0; $i < $code; $i++) {
            File::ensureDirectoryExists($src.'/app/Sub');
            File::put($src.'/app/Sub/'.$i.'.php', 'x');
        }
        for ($i = 0; $i < $vendor; $i++) {
            File::ensureDirectoryExists($src.'/vendor/pkg');
            File::put($src.'/vendor/pkg/'.$i.'.php', 'x');
        }
        File::put($src.'/.env', 'secret');
        File::ensureDirectoryExists($src.'/storage/app');
        File::put($src.'/storage/app/keep.txt', 'x');
        config(['pharos.update.backup_source' => $src, 'pharos.update.backups_dir' => storage_path('app/testing/backups')]);

        return $src;
    }

    /** A SelfUpdater that keeps every progress sample as the screen would read it, in order. */
    protected function observedUpdater(): SelfUpdater
    {
        return new class(app(Updater::class)) extends SelfUpdater
        {
            public array $samples = [];

            protected function report(array $sample): void
            {
                parent::report($sample);
                $this->samples[] = Cache::get(SelfUpdater::PROGRESS_KEY);
            }
        };
    }

    public function test_a_backup_leaves_a_done_progress_entry_that_counts_every_file(): void
    {
        $this->fakeBigSourceTree(20, 40);
        $live = storage_path('app/testing/live.sqlite');
        (new \PDO('sqlite:'.$live))->exec('create table settings (k text)');

        $backup = app(SelfUpdater::class)->backupCurrent(database: $live);

        // The rule: one tick per file copied (.env and storage/ are skipped), plus one for the database step.
        $progress = Cache::get(SelfUpdater::PROGRESS_KEY);
        $this->assertSame('done', $progress['state']);
        $this->assertSame(61, $progress['total']);
        $this->assertSame($progress['total'], $progress['done']);
        $this->assertSame(basename($backup), $progress['name']);
        $this->assertMatchesRegularExpression('/\d.*B$/', $progress['message']); // the human size
        $this->assertNotEmpty($progress['started_at']);
        $this->assertSame(['state', 'stage', 'done', 'total', 'name', 'message', 'started_at'], array_keys($progress));
    }

    public function test_progress_is_reported_while_vendor_is_being_copied(): void
    {
        $this->fakeBigSourceTree(20, 40);
        $updater = $this->observedUpdater();

        $updater->backupCurrent();

        $running = array_filter($updater->samples, fn ($s) => $s['state'] === 'running' && $s['stage'] === 'vendor' && $s['done'] < $s['total']);
        $this->assertNotEmpty($running, 'no mid-run sample from the vendor stage');
        $this->assertSame('counting', $updater->samples[0]['stage']);
        $this->assertSame(['code', 'vendor', 'database', 'finishing'], array_values(array_unique(array_column(array_slice($updater->samples, 1), 'stage'))));
    }

    public function test_the_progress_endpoint_is_idle_until_a_backup_runs(): void
    {
        $this->getJson('/admin/updates/backup/progress')->assertUnauthorized();

        $response = $this->actingAs($this->user)->getJson('/admin/updates/backup/progress')
            ->assertOk()
            ->assertExactJson(['state' => 'idle']);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_the_progress_endpoint_returns_the_last_sample_to_admins_only(): void
    {
        $this->fakeBigSourceTree(5, 5);
        app(SelfUpdater::class)->backupCurrent();

        $this->actingAs($this->user)->getJson('/admin/updates/backup/progress')
            ->assertOk()
            ->assertJson(['state' => 'done', 'stage' => 'finishing', 'done' => 11, 'total' => 11]);
    }

    public function test_the_progress_endpoint_is_closed_to_members(): void
    {
        // Its own test: AuthenticateSession logs a second actor out of the same session.
        $member = User::create(['name' => 'Tom', 'email' => 'tom@example.com', 'password' => Hash::make('correct-horse-battery')]);

        $this->actingAs($member)->getJson('/admin/updates/backup/progress')->assertForbidden();
    }

    public function test_the_backup_button_gets_json_when_it_asks_for_it(): void
    {
        $this->fakeBigSourceTree(3, 3);

        $response = $this->actingAs($this->user)->postJson('/admin/updates/backup')->assertOk();

        $name = app(SelfUpdater::class)->backups()[0]['name'];
        $response->assertJson(['ok' => true, 'name' => $name]);
        $this->assertMatchesRegularExpression('/\d.*B$/', $response->json('size'));
        $this->assertSame($name, AuditEntry::where('action', 'backup.created')->firstOrFail()->changes['name']);
    }

    public function test_a_failed_backup_is_reported_as_such_in_the_progress_and_the_json(): void
    {
        $this->fakeBigSourceTree(3, 3);
        $blocked = storage_path('app/testing/not-a-dir');
        File::put($blocked, 'x');
        config(['pharos.update.backups_dir' => $blocked]);

        $this->actingAs($this->user)->postJson('/admin/updates/backup')
            ->assertStatus(422)
            ->assertJson(['ok' => false])
            ->assertJsonPath('message', fn ($m) => str_starts_with($m, 'Backup failed: '));

        $progress = Cache::get(SelfUpdater::PROGRESS_KEY);
        $this->assertSame('failed', $progress['state']);
        $this->assertNotEmpty($progress['message']);
        $this->assertSame(0, AuditEntry::where('action', 'backup.created')->count());
    }

    public function test_the_next_backup_replaces_the_previous_progress_entry(): void
    {
        Cache::put(SelfUpdater::PROGRESS_KEY, ['state' => 'failed', 'message' => 'old'], 600);
        $this->fakeBigSourceTree(2, 2);

        app(SelfUpdater::class)->backupCurrent();

        $this->assertSame('done', Cache::get(SelfUpdater::PROGRESS_KEY)['state']);
    }

    public function test_the_screen_wires_the_button_to_the_progress_endpoint(): void
    {
        Http::fake(['releases.example.net/*' => Http::response('', 404)]);

        $this->actingAs($this->user)->get('/admin/updates')->assertOk()
            ->assertSee('id="backup-form"', false)
            ->assertSee('/admin/updates/backup/progress')
            ->assertSee('<progress class="bar"', false);
    }

    // ---------- rollback ----------

    /**
     * A live tree and a backup that disagree on every file, so a restore is
     * visible: v1 everywhere live, v2 everywhere in the backup.
     *
     * @return array{0:string,1:string,2:string} backup name, live tree, live database
     */
    protected function fakeRollbackPair(bool $withDatabase = true): array
    {
        $live = storage_path('app/testing/live-'.Str::random(6));
        foreach (['artisan', 'vendor/x.php'] as $f) {
            File::ensureDirectoryExists(dirname($live.'/'.$f));
            File::put($live.'/'.$f, 'v1');
        }
        File::put($live.'/.env', 'secret');
        $liveDb = $live.'/live.sqlite';
        $this->sqliteWith($liveDb, 'v1');
        config(['pharos.update.backup_source' => $live, 'pharos.update.backups_dir' => storage_path('app/testing/backups')]);

        $name = '1.0.0-20260101-000000';
        $backup = storage_path("app/testing/backups/{$name}");
        File::ensureDirectoryExists($backup.'/vendor');
        File::put($backup.'/artisan', 'v2');
        File::put($backup.'/vendor/x.php', 'v2');
        if ($withDatabase) {
            File::ensureDirectoryExists($backup.'/database');
            $this->sqliteWith($backup.'/database/database.sqlite', 'v2');
        }

        return [$name, $live, $liveDb];
    }

    protected function sqliteWith(string $path, string $row): void
    {
        $pdo = new \PDO('sqlite:'.$path);
        $pdo->exec('create table settings (k text)');
        $pdo->exec("insert into settings values ('{$row}')");
    }

    protected function sqliteRow(string $path): string
    {
        return (string) (new \PDO('sqlite:'.$path))->query('select k from settings')->fetchColumn();
    }

    public function test_a_rollback_puts_code_vendor_and_the_database_back(): void
    {
        [$name, $live, $liveDb] = $this->fakeRollbackPair();

        $result = app(SelfUpdater::class)->rollback($name, $live, $liveDb);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('v2', File::get($live.'/artisan'));
        $this->assertSame('v2', File::get($live.'/vendor/x.php'));
        $this->assertSame('v2', $this->sqliteRow($liveDb));
        $this->assertSame('secret', File::get($live.'/.env'));

        // What was replaced is a backup of its own now: a rollback can be undone.
        $this->assertNotSame($name, $result['safety']);
        $safety = storage_path('app/testing/backups/'.$result['safety']);
        $this->assertSame('v1', File::get($safety.'/artisan'));
        $this->assertSame('v1', File::get($safety.'/vendor/x.php'));
        $this->assertSame('v1', $this->sqliteRow($safety.'/database/database.sqlite'));
        $this->assertStringContainsString('Rolled back to 1.0.0', $result['message']);
        $this->assertStringContainsString($result['safety'], $result['message']);

        $progress = Cache::get(SelfUpdater::PROGRESS_KEY);
        $this->assertSame('done', $progress['state']);
        $this->assertSame('finishing', $progress['stage']);
        $this->assertSame($name, $progress['name']);
        $this->assertSame($progress['total'], $progress['done']);
        $this->assertSame(['state', 'stage', 'done', 'total', 'name', 'message', 'started_at'], array_keys($progress));
    }

    public function test_a_rollback_reports_the_safety_copy_as_a_stage_of_its_own(): void
    {
        [$name, $live, $liveDb] = $this->fakeRollbackPair();
        $updater = $this->observedUpdater();

        $updater->rollback($name, $live, $liveDb);

        // Never a "done" from the safety copy: to the screen it is one stage of the rollback.
        $this->assertSame('done', end($updater->samples)['state']);
        $this->assertCount(1, array_filter($updater->samples, fn ($s) => $s['state'] === 'done'));
        $this->assertSame(['safety', 'code', 'vendor', 'database', 'finishing'], array_values(array_unique(array_column($updater->samples, 'stage'))));
    }

    public function test_a_rollback_without_a_database_copy_says_so(): void
    {
        [$name, $live, $liveDb] = $this->fakeRollbackPair(withDatabase: false);

        $result = app(SelfUpdater::class)->rollback($name, $live, $liveDb);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertSame('v2', File::get($live.'/artisan'));
        $this->assertSame('v1', $this->sqliteRow($liveDb));
        $this->assertStringContainsString('the database was not in this backup', $result['message']);
        $this->assertStringContainsString($result['safety'], $result['message']);
    }

    public function test_a_rollback_refuses_a_folder_that_is_not_a_backup(): void
    {
        $src = $this->fakeSourceTree();
        $dir = storage_path('app/testing/backups');
        config(['pharos.update.backups_dir' => $dir]);
        // Right name, wrong contents: no artisan, so nothing to put back.
        File::ensureDirectoryExists("{$dir}/notes-20260101-000000");
        File::put("{$dir}/notes-20260101-000000/readme.txt", 'x');

        $result = app(SelfUpdater::class)->rollback('notes-20260101-000000');

        $this->assertFalse($result['ok']);
        $this->assertSame('No such backup.', $result['message']);
        $this->assertSame('x', File::get($src.'/artisan'));
        $this->assertCount(1, app(SelfUpdater::class)->backups()); // no safety copy taken either

        $this->assertSame(['ok' => false, 'message' => 'No such backup.'], app(SelfUpdater::class)->rollback('9.9.9-20260101-000000'));
    }

    public function test_rollback_is_admin_only_and_names_are_never_paths(): void
    {
        $name = '1.0.0-20260801-030000';
        $this->fakeBackup($name);
        $src = $this->fakeSourceTree();
        $member = User::create(['name' => 'Tom', 'email' => 'tom@example.com', 'password' => Hash::make('correct-horse-battery')]);

        $this->actingAs($member)->post("/admin/updates/backup/{$name}/rollback")->assertForbidden();

        // AuthenticateSession ties the session to one account; a fresh one for the next.
        $this->flushSession();
        foreach (['../x', '%2e%2e/x', '9.9.9-20260101-000000'] as $bad) {
            $this->actingAs($this->user)->post("/admin/updates/backup/{$bad}/rollback")->assertNotFound();
        }

        $this->assertSame('x', File::get($src.'/artisan'));
        $this->assertCount(1, app(SelfUpdater::class)->backups());
        $this->assertSame(0, AuditEntry::where('action', 'backup.restored')->count());
    }

    public function test_the_rollback_button_asks_first(): void
    {
        Http::fake(['releases.example.net/*' => Http::response('', 404)]);
        $name = '1.0.0-20260801-030000';
        $this->fakeBackup($name);

        $this->actingAs($this->user)->get('/admin/updates')->assertOk()
            ->assertSee("/admin/updates/backup/{$name}/rollback")
            ->assertSee('Roll back to 1.0.0?')
            ->assertSee('copy taken on 1 Aug 2026 03:00')
            ->assertSee('data-confirm-action="Roll back"', false)
            ->assertSee('data-job="rollback"', false)
            ->assertDontSee('data-confirm-safe', false)
            ->assertDontSee('no button for it yet');
    }

    public function test_the_rollback_endpoint_returns_json_and_audits(): void
    {
        [$name, $live] = $this->fakeRollbackPair();

        $response = $this->actingAs($this->user)
            ->post("/admin/updates/backup/{$name}/rollback", [], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJson(['ok' => true])
            ->assertJsonPath('message', fn ($m) => str_starts_with($m, 'Rolled back to 1.0.0'));

        $this->assertSame('v2', File::get($live.'/artisan'));

        $entry = AuditEntry::where('action', 'backup.restored')->firstOrFail();
        $this->assertSame($name, $entry->changes['name']);
        $this->assertStringContainsString($entry->changes['safety'], $response->json('message'));
        $this->assertDirectoryExists(storage_path('app/testing/backups/'.$entry->changes['safety']));

        // A folder that is not a backup: refused, said so, not audited.
        File::ensureDirectoryExists(storage_path('app/testing/backups/notes-20260101-000000'));
        $this->actingAs($this->user)
            ->post('/admin/updates/backup/notes-20260101-000000/rollback', [], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJson(['ok' => false, 'message' => 'No such backup.']);
        $this->assertSame(1, AuditEntry::where('action', 'backup.restored')->count());
    }

    public function test_a_rollback_from_the_plain_form_redirects_with_the_message(): void
    {
        [$name] = $this->fakeRollbackPair();

        // Signed out on purpose: the session store came back with the database.
        $this->actingAs($this->user)->post("/admin/updates/backup/{$name}/rollback")
            ->assertRedirect('/admin/login?after=rollback');
        $this->assertGuest();
        $this->get('/admin/login?after=rollback')->assertOk()->assertSee('password you had at the time of that backup');

        File::ensureDirectoryExists(storage_path('app/testing/backups/notes-20260101-000000'));
        $this->actingAs($this->user)->from('/admin/updates')->post('/admin/updates/backup/notes-20260101-000000/rollback')
            ->assertRedirect('/admin/updates')
            ->assertSessionHasErrors('rollback');
    }

    /**
     * Restoring the database also restores the session store and, possibly, a
     * users table without the account that pressed the button. Both must end in a
     * clean sign-out with the audit line written, never in a 500 after the fact.
     */
    public function test_a_rollback_signs_the_operator_out_and_still_audits_when_their_account_is_gone(): void
    {
        $user = $this->user;
        $this->mock(SelfUpdater::class, function ($mock) use ($user) {
            $mock->shouldReceive('backupPath')->andReturn('/tmp/x');
            $mock->shouldReceive('rollback')->andReturnUsing(function () use ($user) {
                // The account was created after the backup: gone from the restored
                // database without any model event, exactly like a file swap.
                DB::table('users')->where('id', $user->id)->delete();

                return ['ok' => true, 'message' => 'Rolled back to 1.0.0.', 'safety' => '1.0.1-20260828-120000'];
            });
        });

        $response = $this->actingAs($user)->postJson('/admin/updates/backup/1.0.0-20260101-000000/rollback');

        $response->assertOk()->assertJsonPath('ok', true);
        $this->assertStringContainsString('signed out', $response->json('message'));
        $this->assertDatabaseHas('audit_log', ['action' => 'backup.restored', 'user_id' => null]);
        $this->assertGuest();
    }

    /** Backups are ~30 MB each and nothing else ever removes them: keep the newest few. */
    public function test_only_the_newest_backups_are_kept(): void
    {
        config(['pharos.update.keep_backups' => 3]);
        $src = $this->fakeTree();
        $dir = $this->fakeBackupsDir();
        foreach (['0.9.0-20260101-000000', '0.9.1-20260201-000000', '0.9.2-20260301-000000', '0.9.3-20260401-000000'] as $old) {
            File::ensureDirectoryExists("$dir/$old");
            File::put("$dir/$old/artisan", 'x');
        }

        $updater = app(SelfUpdater::class);
        $made = basename($updater->backupCurrent($src));

        $names = array_column($updater->backups(), 'name');
        $this->assertCount(3, $names);
        $this->assertSame([$made, '0.9.3-20260401-000000', '0.9.2-20260301-000000'], $names);
        $this->assertSame(['0.9.1-20260201-000000', '0.9.0-20260101-000000'], $updater->pruned());
    }

    public function test_pruning_can_be_switched_off(): void
    {
        config(['pharos.update.keep_backups' => 0]);
        $src = $this->fakeTree();
        $dir = $this->fakeBackupsDir();
        foreach (['0.9.0-20260101-000000', '0.9.1-20260201-000000', '0.9.2-20260301-000000'] as $old) {
            File::ensureDirectoryExists("$dir/$old");
            File::put("$dir/$old/artisan", 'x');
        }

        app(SelfUpdater::class)->backupCurrent($src);

        $this->assertCount(4, app(SelfUpdater::class)->backups());
    }

    /** The safety copy a rollback makes must never push the backup being restored out of the window. */
    public function test_a_rollback_never_prunes_its_own_target(): void
    {
        config(['pharos.update.keep_backups' => 3]);
        [$name, $live, $liveDb] = $this->fakeRollbackPair();
        $dir = dirname(app(SelfUpdater::class)->backupPath($name));
        foreach (['1.0.1-20260201-000000', '1.0.2-20260301-000000'] as $newer) {
            File::ensureDirectoryExists("$dir/$newer");
            File::put("$dir/$newer/artisan", 'x');
        }
        // $name (1.0.0-2026-01-01) is now the oldest of three; the safety copy makes four.

        $result = app(SelfUpdater::class)->rollback($name, $live, $liveDb);

        $this->assertTrue($result['ok'], $result['message']);
        $this->assertDirectoryExists("$dir/$name");
        // The target sits outside the window for this one run: four for now, three again after the next backup.
        $this->assertCount(4, app(SelfUpdater::class)->backups());
        $this->assertDirectoryExists("$dir/1.0.1-20260201-000000");
    }
}
