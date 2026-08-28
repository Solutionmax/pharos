<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
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

    public function backupsDir(): string
    {
        return rtrim((string) config('pharos.update.backups_dir'), '/');
    }

    /** Backup folders are named "<version>-<Ymd-His>" and nothing else. */
    public const NAME_PATTERN = '[A-Za-z0-9._-]+-\d{8}-\d{6}';

    /**
     * The folder for a backup name that came in over HTTP, or null. The name is
     * checked against the pattern *and* the resolved path is checked to sit
     * inside the backups folder: the second guard holds even if the first is
     * ever loosened.
     */
    public function backupPath(string $name): ?string
    {
        if (! preg_match('/^'.self::NAME_PATTERN.'$/', $name)) {
            return null;
        }

        $dir = realpath($this->backupsDir());
        $path = realpath($this->backupsDir().'/'.$name);

        if ($dir === false || $path === false || ! is_dir($path) || ! str_starts_with($path, $dir.DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $path;
    }

    /**
     * One backup as a zip in storage/app, for the operator to take off the
     * server. The caller deletes it once sent. Everything under the folder goes
     * in as "<name>/…": the backup was already filtered when it was taken.
     */
    public function zipBackup(string $name): string
    {
        $path = $this->backupPath($name);

        if ($path === null) {
            throw new \RuntimeException("No backup named {$name}.");
        }

        $archive = storage_path('app/update-dl-'.Str::random(8).'.zip');
        $zip = new \ZipArchive;

        if ($zip->open($archive, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Could not create the archive.');
        }

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->isFile() && ! $file->isLink()) {
                $zip->addFile($file->getPathname(), $name.'/'.Str::after($file->getPathname(), $path.DIRECTORY_SEPARATOR));
            }
        }

        $zip->close();

        return $archive;
    }

    /**
     * The previous versions still on disk, newest first. Nothing prunes them:
     * the operator decides when the new version has run long enough.
     *
     * @return list<array{name:string,version:string,created_at:Carbon,size:int}>
     */
    public function backups(): array
    {
        $dir = $this->backupsDir();

        if (! is_dir($dir)) {
            return [];
        }

        $backups = array_map(function (string $path) {
            $name = basename($path);
            // Folders are named "<version>-<Ymd-His>" by apply(); anything else is
            // shown as-is rather than hidden.
            preg_match('/^(.*)-(\d{8}-\d{6})$/', $name, $m);
            $created = isset($m[2]) ? Carbon::createFromFormat('Ymd-His', $m[2]) : Carbon::createFromTimestamp(filemtime($path));

            return ['name' => $name, 'version' => $m[1] ?? $name, 'created_at' => $created, 'size' => $this->treeSize($path)];
        }, File::directories($dir));

        usort($backups, fn ($a, $b) => $b['created_at'] <=> $a['created_at']);

        return $backups;
    }

    /**
     * The version about to be replaced, kept whole so it can be put back as one
     * piece: code and vendor together, plus a consistent copy of the SQLite
     * database — the migrations that follow are what make old code unusable.
     * Secrets, storage and uploads are left out: an update never touches them,
     * and storage is where the backups themselves live.
     */
    public function backupCurrent(?string $from = null, ?string $database = null): string
    {
        $backup = $this->backupsDir().'/'.$this->updater->current().'-'.now()->format('Ymd-His');
        File::ensureDirectoryExists($backup);

        $this->copyTree($from ?? config('pharos.update.backup_source') ?? base_path(), $backup, skip: ['.env', 'storage', 'public/storage', 'node_modules', 'database/database.sqlite']);
        $this->backupDatabase($backup, $database);

        return $backup;
    }

    /**
     * SQLite only: VACUUM INTO on a connection of its own writes a self-contained,
     * consistent file even mid-write (WAL included) — the app's own connection may
     * sit inside a transaction, where VACUUM is refused. MySQL and Postgres are the
     * operator's to dump; the screen says so.
     */
    protected function backupDatabase(string $backup, ?string $live = null): void
    {
        $live ??= DB::getDatabaseName();

        if (DB::getDriverName() !== 'sqlite' || ! is_file($live)) {
            return;
        }

        File::ensureDirectoryExists($backup.'/database');
        $target = $backup.'/database/database.sqlite';

        try {
            $pdo = new \PDO('sqlite:'.$live);
            $pdo->exec('VACUUM INTO '.$pdo->quote($target));
        } catch (\Throwable) {
            File::copy($live, $target); // last resort: may miss what still sits in the WAL
        }
    }

    /** du-style: the sum of every file underneath, symlinks not followed. */
    public function treeSize(string $path): int
    {
        $total = 0;
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));

        foreach ($files as $file) {
            if ($file->isFile() && ! $file->isLink()) {
                $total += $file->getSize();
            }
        }

        return $total;
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

            $zip = new \ZipArchive;
            if ($zip->open($archive) !== true) {
                return ['ok' => false, 'message' => 'The release archive could not be opened.'];
            }

            if (($problem = $this->unsafeEntry($zip)) !== null) {
                $zip->close();

                return ['ok' => false, 'message' => "The release archive was refused: {$problem}. Nothing was installed."];
            }

            $extracted = $work.'/files';
            File::ensureDirectoryExists($extracted);

            $zip->extractTo($extracted);
            $zip->close();

            $root = $this->archiveRoot($extracted);

            if (! is_file($root.'/artisan')) {
                return ['ok' => false, 'message' => 'That archive does not look like a Pharos release.'];
            }

            $backup = $this->backupCurrent();

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

    /**
     * An entry that would land outside the folder it is unpacked into, or a
     * symlink, which the copy that follows would walk through. PHP strips ".."
     * on extraction, but nothing we built carries either, so the whole archive
     * is refused before a byte of it is written.
     */
    protected function unsafeEntry(\ZipArchive $zip): ?string
    {
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            $segments = preg_split('#[\\\\/]+#', $name) ?: [];

            if (str_starts_with($name, '/') || in_array('..', $segments, true) || preg_match('/^[a-z]:/i', $name)) {
                return "{$name} points outside the archive";
            }

            $zip->getExternalAttributesIndex($i, $opsys, $attributes);

            if ($opsys === \ZipArchive::OPSYS_UNIX && (($attributes >> 16) & 0170000) === 0120000) {
                return "{$name} is a symlink";
            }
        }

        return null;
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
