<?php

namespace App\Services;

use App\Models\Setting;

/**
 * The master switch for subscriber mail. Off means: no button on the status
 * page, no sign-ups, no new mail queued. Unsubscribing keeps working and the
 * addresses stay — switching off is a pause, not a purge.
 */
class Subscriptions
{
    public const KEY = 'subscribers.enabled';

    public static function enabled(): bool
    {
        return Setting::get(self::KEY, '1') === '1';
    }

    public static function set(bool $enabled): void
    {
        Setting::put(self::KEY, $enabled ? '1' : '0');
        Audit::record($enabled ? 'subscribers.enabled' : 'subscribers.disabled');
    }
}
