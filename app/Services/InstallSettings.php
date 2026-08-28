<?php

namespace App\Services;

use App\Models\Setting;

/**
 * Installation knobs that started life as .env lines and can now be set on
 * Settings → General. The saved value wins; with nothing saved the config —
 * and so the env line — still applies, which keeps an existing install doing
 * exactly what it did before the screen existed. Every reader comes through
 * here so the fallback lives in one place.
 */
class InstallSettings
{
    public const AUDIT_DAYS = 'audit.days';

    public const KEEP_BACKUPS = 'update.keep_backups';

    public const UPDATE_CHECK = 'update.check_enabled';

    /** How long the audit trail keeps a line. */
    public static function auditDays(): int
    {
        return (int) (Setting::get(self::AUDIT_DAYS) ?? config('pharos.audit_days'));
    }

    /** How many backups a self-update keeps; 0 keeps them all. */
    public static function keepBackups(): int
    {
        return (int) (Setting::get(self::KEEP_BACKUPS) ?? config('pharos.update.keep_backups'));
    }

    /** Whether the hourly update check runs at all. */
    public static function updateCheckEnabled(): bool
    {
        $saved = Setting::get(self::UPDATE_CHECK);

        return $saved === null ? (bool) config('pharos.update.check_enabled') : $saved === '1';
    }

    /**
     * Current values in form shape, for the screen and for the audit diff.
     *
     * @return array{audit_days:int,keep_backups:int,update_check:bool}
     */
    public static function all(): array
    {
        return [
            'audit_days' => self::auditDays(),
            'keep_backups' => self::keepBackups(),
            'update_check' => self::updateCheckEnabled(),
        ];
    }

    /** @param  array{audit_days:int,keep_backups:int,update_check:bool}  $values */
    public static function save(array $values): void
    {
        Setting::put(self::AUDIT_DAYS, (string) $values['audit_days']);
        Setting::put(self::KEEP_BACKUPS, (string) $values['keep_backups']);
        Setting::put(self::UPDATE_CHECK, $values['update_check'] ? '1' : '0');
    }
}
