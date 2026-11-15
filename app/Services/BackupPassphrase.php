<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

/**
 * The passphrase remote backups are encrypted with (BackupCrypto). Scheduled
 * uploads run without anyone to type it, so it is kept on this server,
 * encrypted with APP_KEY. That protects the bucket, not this server: whoever
 * holds this server holds APP_KEY already.
 */
final class BackupPassphrase
{
    public const KEY = 'backup.passphrase';

    public const MIN_LENGTH = 12;

    public static function isSet(): bool
    {
        return filled(Setting::get(self::KEY));
    }

    /**
     * The passphrase, or null when none is set.
     *
     * @throws RuntimeException when one is set but cannot be read (APP_KEY changed):
     *                          better no backup than one that silently stops being encrypted
     */
    public static function get(): ?string
    {
        $stored = Setting::get(self::KEY);

        if (! is_string($stored) || $stored === '') {
            return null;
        }

        try {
            return Crypt::decryptString($stored);
        } catch (DecryptException) {
            throw new RuntimeException('The backup passphrase cannot be read. Set it again.');
        }
    }

    public static function set(?string $passphrase): void
    {
        Setting::put(self::KEY, $passphrase === null || $passphrase === '' ? '' : Crypt::encryptString($passphrase));
    }
}
