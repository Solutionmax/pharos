<?php

namespace App\Services;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * Replaces the application's own files from a signed release archive. For the
 * shared-hosting case, where there is no Docker, no root and no daemon — which
 * is most of the market this is aimed at.
 *
 * Refuses to do anything it cannot verify, and keeps the previous version on
 * disk so a failed update is a rename away from being undone.
 */
class SelfUpdater
{
    /** Never replaced: this is the customer's data and configuration. */
    public const KEEP = ['.env', 'storage', 'database/database.sqlite', 'public/storage'];

    public function __construct(protected Updater $updater) {}

    public function canWrite(): bool
    {
        return is_writable(base_path()) && is_writable(base_path('app'));
    }

    /**
     * @return array{ok:bool,message:string,version?:string,backup?:string}
     */
    public function apply(?array $manifest = null): array
    {
        $manifest ??= $this->updater->latest(fresh: true);

        if ($manifest === null) {
            return ['ok' => false, 'message' => 'No verified release available.'];
        }

        if (version_compare($manifest['version'], $this->updater->current(), '<=')) {
            // Refusing a downgrade is not politeness: an older build may not
            // survive the migrations the current one already ran.
            return ['ok' => false, 'message' => "Already on {$this->updater->current()}; the release server offers {$manifest['version']}."];
        }

        if (! $this->canWrite()) {
            return ['ok' => false, 'message' => 'The application directory is not writable, so it cannot update itself. Update by hand, or give the web user write access.'];
        }

        $work = storage_path('app/update-'.Str::random(8));
        File::ensureDirectoryExists($work);

        try {
            $archive = $work.'/release.zip';

            $response = Http::timeout(120)->sink($archive)->get($manifest['url']);

            if (! $response->successful() || ! is_file($archive)) {
                return ['ok' => false, 'message' => 'Could not download the release.'];
            }

            $actual = hash_file('sha256', $archive);

            if (! hash_equals(strtolower($manifest['sha256']), strtolower($actual))) {
                // The manifest is signed, so a mismatch means the archive was
                // swapped or truncated. Either way it does not get unpacked.
                return ['ok' => false, 'message' => 'The download does not match the signed checksum. Nothing was installed.'];
            }

            $extracted = $work.'/files';
            File::ensureDirectoryExists($extracted);

            $zip = new \ZipArchive;
            if ($zip->open($archive) !== true) {
                return ['ok' => false, 'message' => 'The release archive could not be opened.'];
            }
            $zip->extractTo($extracted);
            $zip->close();

            $root = $this->archiveRoot($extracted);

            if (! is_file($root.'/artisan')) {
                return ['ok' => false, 'message' => 'That archive does not look like a Pharos release.'];
            }

            $backup = storage_path('app/backups/'.$this->updater->current().'-'.now()->format('Ymd-His'));
            File::ensureDirectoryExists($backup);
            $this->copyTree(base_path(), $backup, skip: array_merge(self::KEEP, ['vendor', 'node_modules']));

            $this->copyTree($root, base_path(), skip: self::KEEP);

            Artisan::call('migrate', ['--force' => true]);
            Artisan::call('optimize:clear');

            return [
                'ok' => true,
                'version' => $manifest['version'],
                'backup' => $backup,
                'message' => "Updated to {$manifest['version']}. The previous version is in {$backup}.",
            ];
        } catch (\Throwable $e) {
            return ['ok' => false, 'message' => 'Update failed: '.$e->getMessage()];
        } finally {
            File::deleteDirectory($work);
        }
    }

    /** Release archives usually wrap everything in one folder. */
    protected function archiveRoot(string $extracted): string
    {
        $entries = array_values(array_diff(scandir($extracted) ?: [], ['.', '..']));

        return count($entries) === 1 && is_dir($extracted.'/'.$entries[0])
            ? $extracted.'/'.$entries[0]
            : $extracted;
    }

    /** @param string[] $skip paths relative to $from */
    protected function copyTree(string $from, string $to, array $skip = []): void
    {
        foreach (File::directories($from) as $directory) {
            $name = basename($directory);
            if (in_array($name, $skip, true)) {
                continue;
            }
            File::ensureDirectoryExists($to.'/'.$name);
            $this->copyTree($directory, $to.'/'.$name, $this->descend($skip, $name));
        }

        foreach (File::files($from) as $file) {
            $name = $file->getFilename();
            if (in_array($name, $skip, true)) {
                continue;
            }
            File::copy($file->getPathname(), $to.'/'.$name);
        }
    }

    /** Turns "database/database.sqlite" into "database.sqlite" once inside database/. */
    protected function descend(array $skip, string $directory): array
    {
        $prefix = $directory.'/';

        return array_values(array_map(
            fn ($path) => Str::after($path, $prefix),
            array_filter($skip, fn ($path) => str_starts_with($path, $prefix)),
        ));
    }
}
