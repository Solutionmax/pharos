<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;
use ZipArchive;

class LocalPackageTest extends TestCase
{
    public function test_local_package_has_plain_metadata_clean_files_and_a_composer_repository(): void
    {
        $fixture = sys_get_temp_dir().'/pharos-package-test-'.bin2hex(random_bytes(6));
        File::makeDirectory($fixture.'/source/config', 0755, true);
        File::makeDirectory($fixture.'/source/vendor', 0755, true);
        File::makeDirectory($fixture.'/source/public', 0755, true);
        $source = $fixture.'/source';
        $files = [
            'artisan' => '<?php echo "artisan";',
            'config/pharos.php' => "<?php return ['version' => env('PHAROS_VERSION', '0.8.0-beta.3')];",
            'composer.json' => '{"name":"solutionmax/pharos","type":"project","require":{}}',
            'vendor/autoload.php' => '<?php // controlled production dependency fixture',
            'public/index.php' => '<?php echo "index";',
            'LICENSE' => 'AGPL-3.0-only',
            '.env.example' => 'APP_DEBUG=false',
            '.env' => 'SECRET=must-never-ship',
            'Dockerfile' => 'FROM php',
            'compose.yaml' => 'services: {}',
            'package.json' => '{}',
            'vite.config.js' => 'export default {}',
        ];
        foreach ($files as $name => $contents) {
            file_put_contents($source.'/'.$name, $contents);
        }
        $zip = null;
        try {
            foreach ([['git', 'init', '-q'], ['git', 'add', '.'], ['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.net', 'commit', '-qm', 'fixture']] as $command) {
                (new Process($command, $source))->mustRun();
            }
            $process = new Process([PHP_BINARY, base_path('scripts/build-local-package.php'), '1.0.0-beta.1', $fixture.'/output', '--source='.$source, '--vendor='.$source.'/vendor']);
            $process->run();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $manifest = json_decode(file_get_contents($fixture.'/output/release-info.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('1.0.0-beta.1', $manifest['version']);
            $this->assertSame(hash_file('sha256', $fixture.'/output/pharos-1.0.0-beta.1.zip'), $manifest['sha256']);
            $this->assertFileDoesNotExist($fixture.'/output/latest.json', 'the existing signed updater manifest must never be overwritten');
            $this->assertSame(hash_file('sha256', $fixture.'/output/pharos-latest.zip'), $manifest['sha256']);
            $composer = json_decode(file_get_contents($fixture.'/output/packages.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame('project', $composer['packages']['solutionmax/pharos']['1.0.0-beta.1']['type']);

            $zip = new ZipArchive;
            $this->assertTrue($zip->open($fixture.'/output/pharos-latest.zip'));
            $this->assertSame("1.0.0-beta.1\n", $zip->getFromName('VERSION'));
            $this->assertNotFalse($zip->locateName('artisan'));
            $this->assertNotFalse($zip->locateName('vendor/autoload.php'));
            $this->assertStringContainsString("'1.0.0-beta.1'", $zip->getFromName('config/pharos.php'));
            foreach (['.env', 'Dockerfile', 'compose.yaml', 'package.json', 'vite.config.js', '.git/config'] as $forbidden) {
                $this->assertFalse($zip->locateName($forbidden), $forbidden.' must not ship');
            }
            $zip->close();
            $zip = null;
        } finally {
            $zip?->close();
            File::deleteDirectory($fixture);
        }
    }

    public function test_invalid_version_is_rejected_before_creating_an_output_directory(): void
    {
        $target = sys_get_temp_dir().'/pharos-package-invalid-'.bin2hex(random_bytes(6));
        $process = new Process([PHP_BINARY, base_path('scripts/build-local-package.php'), '../escape', $target]);
        $process->run();
        $this->assertNotSame(0, $process->getExitCode());
        $this->assertDirectoryDoesNotExist($target);
    }
}
