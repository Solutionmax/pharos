<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;

class BackupArchive
{
    public function create(?string $sqliteSource = null): string
    {
        $dir = storage_path('app/private/remote-backups');
        if (! is_dir($dir)) {
            mkdir($dir, 0700, true);
        }
        $path = $dir.'/pharos-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(8)).'.zip';
        $snapshot = $path.'.database';
        $zip = new \ZipArchive;
        try {
            $connection = DB::connection();
            $driver = $sqliteSource ? 'sqlite' : $connection->getDriverName();
            if ($driver === 'sqlite') {
                $source = new \SQLite3($sqliteSource ?? $connection->getConfig('database'), SQLITE3_OPEN_READONLY);
                $copy = new \SQLite3($snapshot);
                if (! $source->backup($copy)) {
                    throw new \RuntimeException('Snapshot failed');
                }
                if ($copy->querySingle('PRAGMA integrity_check') !== 'ok') {
                    throw new \RuntimeException('Snapshot invalid');
                }
                $source->close();
                $copy->close();
                $databaseName = 'database/database.sqlite';
            } elseif ($driver === 'mysql') {
                $this->mysqlSnapshot($snapshot, $connection->getConfig());
                $databaseName = 'database/database.sql';
            } else {
                throw new \RuntimeException('Unsupported backup database');
            }
            if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::EXCL) !== true) {
                throw new \RuntimeException('Archive failed');
            }
            $zip->addFile($snapshot, $databaseName);
            $root = base_path();
            $iterator = new \RecursiveIteratorIterator(new \RecursiveCallbackFilterIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), function ($file) use ($root) {
                $relative = substr($file->getPathname(), strlen($root) + 1);
                if ($file->isLink()) {
                    return false;
                }
                if (preg_match('#^(?:\.git|vendor|node_modules|bootstrap/cache)(?:/|$)#', $relative)) {
                    return false;
                }
                if (str_starts_with($relative, 'storage/')) {
                    return in_array($relative, ['storage/app', 'storage/app/public'], true) || str_starts_with($relative, 'storage/app/public/');
                }
                if (preg_match('#^database/.*\.(?:sqlite|sqlite-wal|sqlite-shm)$#', $relative)) {
                    return false;
                }

                return true;
            }));
            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                $relative = substr($file->getPathname(), strlen($root) + 1);
                if ($relative === '.env' || ! str_starts_with(basename($relative), '.env')) {
                    $zip->addFile($file->getPathname(), $relative);
                }
            }
            $zip->addFromString('BACKUP-README.txt', "Pharos backup. Restore the application files and .env (preserves APP_KEY), uploaded storage/app/public, then restore database/database.sqlite or import database/database.sql. Install Composer dependencies from composer.lock. Keep this archive private: it contains credentials and account data.\n");
            if (! $zip->close()) {
                throw new \RuntimeException('Archive failed');
            }chmod($path, 0600);

            return $path;
        } catch (\Throwable $e) {
            @$zip->close();
            @unlink($path);
            throw $e;
        } finally {
            @unlink($snapshot);
        }
    }

    private function mysqlSnapshot(string $path, array $config): void
    {
        $cnf = $path.'.cnf';
        $escape = fn ($v) => '"'.str_replace(['\\', '"', "\n", "\r"], ['\\\\', '\\"', '\\n', '\\r'], (string) $v).'"';
        file_put_contents($cnf, "[client]\nuser=".$escape($config['username'])."\npassword=".$escape($config['password'])."\nhost=".$escape($config['host'])."\nport=".(int) ($config['port'] ?? 3306)."\n");
        chmod($cnf, 0600);
        try {
            $process = new Process(['mysqldump', '--defaults-extra-file='.$cnf, '--single-transaction', '--quick', '--skip-lock-tables', '--result-file='.$path, '--', $config['database']]);
            $process->setTimeout(300);
            $process->run();
            if (! $process->isSuccessful()) {
                throw new \RuntimeException('Database snapshot failed');
            }
        } finally {
            @unlink($cnf);
        }
    }
}
