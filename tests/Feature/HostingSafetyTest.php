<?php

namespace Tests\Feature;

use App\Services\Branding;
use App\Services\CronSetup;
use App\Services\PublicFiles;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class HostingSafetyTest extends TestCase
{
    private string $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        Process::preventStrayProcesses();
        $this->fixture = sys_get_temp_dir().'/pharos-hosting-'.bin2hex(random_bytes(8));
        File::ensureDirectoryExists($this->fixture.'/app/public');
        File::ensureDirectoryExists($this->fixture.'/web');
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->fixture);
        parent::tearDown();
    }

    public function test_public_assets_and_entrypoint_follow_updates_without_replacing_panel_rules(): void
    {
        $base = $this->fixture.'/app';
        $web = $this->fixture.'/web';
        file_put_contents($base.'/.pharos-public', $web);
        file_put_contents($base.'/public/index.php', "<?php require __DIR__.'/../vendor/autoload.php';");
        file_put_contents($base.'/public/app.css', 'new-style');
        file_put_contents($base.'/public/.htaccess', 'new-rules');
        file_put_contents($web.'/.htaccess', 'panel-rules');
        (new PublicFiles)->sync($base);
        $this->assertSame('new-style', file_get_contents($web.'/app.css'));
        $this->assertSame('panel-rules', file_get_contents($web.'/.htaccess'));
        $this->assertStringContainsString($base.'/', file_get_contents($web.'/index.php'));
        $this->assertStringNotContainsString('__DIR__', file_get_contents($web.'/index.php'));
        file_put_contents($base.'/public/app.css', 'rollback-style');
        (new PublicFiles)->sync($base);
        $this->assertSame('rollback-style', file_get_contents($web.'/app.css'));
    }

    public function test_brand_assets_are_replaced_in_a_separate_hosting_webroot_and_urls_change(): void
    {
        $base = $this->fixture.'/app';
        $web = $this->fixture.'/web';
        file_put_contents($base.'/.pharos-public', $web);
        File::ensureDirectoryExists($base.'/public/brand');
        foreach (['pharos-logo.svg', 'pharos-logo-white.svg'] as $name) {
            file_put_contents($base.'/public/brand/'.$name, 'old-'.$name);
        }
        (new PublicFiles)->sync($base);
        $previousPublic = public_path();
        $this->app->usePublicPath($web);
        try {
            $branding = app(Branding::class);
            $old = $branding->builtInAssetUrl('pharos-logo.svg');
            foreach (['pharos-logo.svg', 'pharos-logo-white.svg'] as $name) {
                File::copy($previousPublic.'/brand/'.$name, $base.'/public/brand/'.$name);
            }
            (new PublicFiles)->sync($base);
            $this->assertNotSame($old, $branding->builtInAssetUrl('pharos-logo.svg'));
            foreach (['pharos-logo.svg', 'pharos-logo-white.svg'] as $name) {
                $this->assertSame(file_get_contents($previousPublic.'/brand/'.$name), file_get_contents($web.'/brand/'.$name));
            }
        } finally {
            $this->app->usePublicPath($previousPublic);
        }
    }

    public function test_obsolete_release_files_are_removed_on_update_and_rollback_but_uploads_survive(): void
    {
        $base = $this->fixture.'/app';
        $web = $this->fixture.'/web';
        file_put_contents($base.'/.pharos-public', $web);
        file_put_contents($base.'/public/old.php', 'old-release');
        file_put_contents($web.'/custom.txt', 'operator-file');
        (new PublicFiles)->sync($base);
        unlink($base.'/public/old.php');
        file_put_contents($base.'/public/new.css', 'new-release');
        (new PublicFiles)->sync($base);
        $this->assertFileDoesNotExist($web.'/old.php');
        $this->assertSame('operator-file', file_get_contents($web.'/custom.txt'));
        unlink($base.'/public/new.css');
        file_put_contents($base.'/public/old.php', 'old-release');
        (new PublicFiles)->sync($base);
        $this->assertFileDoesNotExist($web.'/new.css');
        $this->assertSame('old-release', file_get_contents($web.'/old.php'));
    }

    public function test_exact_release_source_does_not_republish_stale_files_from_the_application_tree(): void
    {
        $base = $this->fixture.'/app';
        $web = $this->fixture.'/web';
        file_put_contents($base.'/.pharos-public', $web);
        file_put_contents($base.'/public/old.php', 'old');
        (new PublicFiles)->sync($base);
        File::ensureDirectoryExists($this->fixture.'/release/public');
        file_put_contents($this->fixture.'/release/public/new.css', 'new');
        (new PublicFiles)->sync($base, $this->fixture.'/release/public');
        $this->assertFileDoesNotExist($web.'/old.php');
        $this->assertSame('new', file_get_contents($web.'/new.css'));
    }

    public function test_nested_symlink_cannot_create_directories_outside_webroot(): void
    {
        $base = $this->fixture.'/app';
        file_put_contents($base.'/.pharos-public', $this->fixture.'/web');
        File::ensureDirectoryExists($base.'/public/assets/nested');
        File::ensureDirectoryExists($this->fixture.'/outside');
        file_put_contents($base.'/public/assets/nested/file.css', 'release');
        symlink($this->fixture.'/outside', $this->fixture.'/web/assets');
        try {
            (new PublicFiles)->sync($base);
            $this->fail('Symlink must fail before mkdir');
        } catch (\RuntimeException) {
            $this->assertDirectoryDoesNotExist($this->fixture.'/outside/nested');
        }
    }

    public function test_public_publish_refuses_a_destination_symlink(): void
    {
        $base = $this->fixture.'/app';
        file_put_contents($base.'/.pharos-public', $this->fixture.'/web');
        file_put_contents($base.'/public/app.css', 'new');
        file_put_contents($this->fixture.'/outside', 'keep');
        symlink($this->fixture.'/outside', $this->fixture.'/web/app.css');
        try {
            (new PublicFiles)->sync($base);
            $this->fail('Symlink must be refused');
        } catch (\RuntimeException) {
            $this->assertSame('keep', file_get_contents($this->fixture.'/outside'));
        }
    }

    public function test_cron_read_failure_never_writes_a_new_crontab(): void
    {
        Process::fake(['*' => Process::result(errorOutput: 'permission denied', exitCode: 1)]);
        try {
            (new CronSetup)->install();
            $this->fail('Cannot install after a read failure');
        } catch (\RuntimeException) {
            Process::assertNotRan(fn ($process) => $process->command === ['crontab', '-']);
        }
    }

    public function test_existing_matching_cron_is_not_duplicated(): void
    {
        $cron = new CronSetup;
        Process::fake(['*' => Process::result(output: '* * * * * '.$cron->command()."\n")]);
        $this->assertSame('Cron already configured.', $cron->install());
        Process::assertNotRan(fn ($process) => $process->command === ['crontab', '-']);
    }

    public function test_cron_install_preserves_unrelated_jobs_and_verifies_the_new_job(): void
    {
        $cron = new CronSetup;
        $before = "MAILTO=ops@example.test\n0 0 * * * /home/user/backup\n";
        $after = $before.'* * * * * '.$cron->command()."\n";
        Process::fake(['*' => Process::sequence()->push(Process::result(output: $before))->push(Process::result(output: $before))->push(Process::result())->push(Process::result(output: $after))]);
        $this->assertStringContainsString('Cron saved', $cron->install());
        Process::assertRan(fn ($process) => $process->command === ['crontab', '-'] && $process->input === $after);
    }
}
