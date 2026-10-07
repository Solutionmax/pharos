<?php

namespace Tests\Feature;

use App\Services\BackupArchive;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BackupPublicRootTest extends TestCase
{
    use RefreshDatabase;

    public function test_configured_external_public_tree_is_authoritative_and_excludes_secrets_and_symlinks(): void
    {
        $root = sys_get_temp_dir().'/pharos-httpdocs-'.bin2hex(random_bytes(5));
        File::makeDirectory($root.'/assets', 0755, true);
        file_put_contents($root.'/assets/pharos-ui.js', 'external-authoritative-content');
        file_put_contents($root.'/external-only.txt', 'must be retained');
        file_put_contents($root.'/.env', 'public-secret');
        file_put_contents($root.'/assets/.env.production', 'nested-secret');
        symlink(base_path('.env'), $root.'/secret-link');
        $source = tempnam(sys_get_temp_dir(), 'backup-source');
        $db = new \SQLite3($source);
        $db->exec('CREATE TABLE fixture(value TEXT)');
        $db->close();
        $previous = public_path();
        $path = null;
        try {
            $this->app->usePublicPath($root);
            $path = app(BackupArchive::class)->create($source);
            $zip = new \ZipArchive;
            $this->assertTrue($zip->open($path) === true);
            $this->assertSame('external-authoritative-content', $zip->getFromName('public/assets/pharos-ui.js'));
            $this->assertSame('must be retained', $zip->getFromName('public/external-only.txt'));
            foreach (['public/.env', 'public/assets/.env.production', 'public/secret-link'] as $secret) {
                $this->assertFalse($zip->locateName($secret));
            }$this->assertSame(1, count(array_filter(range(0, $zip->numFiles - 1), fn ($i) => $zip->getNameIndex($i) === 'public/assets/pharos-ui.js')));
            $layout = json_decode($zip->getFromName('BACKUP-LAYOUT.json'), true);
            $this->assertSame($root, $layout['public_source']);
            $zip->close();
        } finally {
            $this->app->usePublicPath($previous);
            if ($path) {
                unlink($path);
            }unlink($source);
            File::deleteDirectory($root);
        }
    }
}
