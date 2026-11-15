<?php

namespace App\Console\Commands;

use App\Services\BackupCrypto;
use Illuminate\Console\Command;

/**
 * Opens a remote backup that was encrypted with the backup passphrase. Needs
 * no database and no APP_KEY, so it also runs on a fresh install that is about
 * to be restored from the archive.
 */
class DecryptBackup extends Command
{
    protected $signature = 'pharos:backup:decrypt
        {file : The encrypted archive, pharos-....zip.enc}
        {--out= : Where to write the zip; defaults to the file name without .enc}';

    protected $description = 'Decrypt a remote backup archive with the backup passphrase';

    public function handle(BackupCrypto $crypto): int
    {
        $file = (string) $this->argument('file');
        if (! is_file($file) || ! is_readable($file)) {
            $this->error("Cannot read {$file}.");

            return self::FAILURE;
        }
        if (! BackupCrypto::looksEncrypted($file)) {
            $this->error("{$file} is not an encrypted Pharos backup.");

            return self::FAILURE;
        }

        $out = (string) ($this->option('out') ?: (str_ends_with($file, BackupCrypto::EXTENSION) ? substr($file, 0, -strlen(BackupCrypto::EXTENSION)) : $file.'.zip'));
        if (file_exists($out)) {
            $this->error("{$out} exists already. Choose another place with --out.");

            return self::FAILURE;
        }

        $passphrase = (string) $this->secret('Backup passphrase');
        if ($passphrase === '') {
            $this->error('No passphrase given.');

            return self::FAILURE;
        }

        try {
            $crypto->decryptFile($file, $out, $passphrase);
        } catch (\RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Decrypted to {$out}. It holds .env with APP_KEY: keep it private and remove it when the restore is done.");

        return self::SUCCESS;
    }
}
