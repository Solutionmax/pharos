<?php

namespace App\Support;

/**
 * A byte count the way a file manager shows it. The framework's own size
 * formatter does the same but needs ext-intl, which shared hosts and the php:apache image
 * do not always have — and a backup that crashed while reporting its own size
 * looked like a failed backup.
 */
final class Bytes
{
    public static function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $value = (float) max(0, $bytes);
        $unit = 0;

        while ($value >= 1024 && $unit < count($units) - 1) {
            $value /= 1024;
            $unit++;
        }

        return $unit === 0 ? sprintf('%d B', $value) : sprintf('%.1f %s', $value, $units[$unit]);
    }
}
