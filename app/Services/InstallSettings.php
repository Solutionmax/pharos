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

    public const ADMIN_TWO_FACTOR = 'auth.admin_two_factor';

    public const EDITOR_PRIVATE_TARGETS = 'network.editor_private_targets';

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
     * Whether every administrator has to use two factor. Off until an admin
     * switches it on, so an update never locks anybody out; with it on, an
     * admin without it is kept on the profile until it is set up.
     */
    public static function adminTwoFactorRequired(): bool
    {
        return Setting::get(self::ADMIN_TWO_FACTOR) === '1';
    }

    /**
     * May page editors (anyone who is not an installation administrator) point
     * checks and notification destinations at private network addresses? Off
     * unless an administrator turned it on, or the 1.2.0 update found such
     * targets already in use. Unreadable counts as off.
     */
    public static function editorsMayTargetPrivate(): bool
    {
        try {
            return Setting::get(self::EDITOR_PRIVATE_TARGETS) === '1';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Current values in form shape, for the screen and for the audit diff.
     *
     * @return array{audit_days:int,keep_backups:int,update_check:bool,admin_two_factor:bool,private_targets:bool}
     */
    public static function all(): array
    {
        return [
            'audit_days' => self::auditDays(),
            'keep_backups' => self::keepBackups(),
            'update_check' => self::updateCheckEnabled(),
            'admin_two_factor' => self::adminTwoFactorRequired(),
            'private_targets' => self::editorsMayTargetPrivate(),
        ];
    }

    /** @param  array{audit_days:int,keep_backups:int,update_check:bool,admin_two_factor:bool,private_targets:bool}  $values */
    public static function save(array $values): void
    {
        Setting::put(self::AUDIT_DAYS, (string) $values['audit_days']);
        Setting::put(self::KEEP_BACKUPS, (string) $values['keep_backups']);
        Setting::put(self::UPDATE_CHECK, $values['update_check'] ? '1' : '0');
        Setting::put(self::ADMIN_TWO_FACTOR, $values['admin_two_factor'] ? '1' : '0');
        Setting::put(self::EDITOR_PRIVATE_TARGETS, $values['private_targets'] ? '1' : '0');
    }
}
