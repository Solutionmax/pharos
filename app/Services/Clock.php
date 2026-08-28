<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * The customer's display zone. Storage is UTC everywhere; this is only about
 * what a human reads on the page, in the admin and in a download. Reading it
 * from a setting rather than APP_TIMEZONE is what lets it change at any time
 * without rewriting a single stored value.
 */
class Clock
{
    public static function timezone(): string
    {
        $zone = (string) Setting::get('app.timezone', 'UTC');

        // A zone PHP does not know would throw on every page; fall back rather than crash.
        return in_array($zone, \DateTimeZone::listIdentifiers(), true) ? $zone : 'UTC';
    }

    /** now(), read in the customer's zone. For display only: compare against storage with plain now(). */
    public static function now(): Carbon
    {
        return now()->setTimezone(self::timezone());
    }

    /** "UTC+02:00" — what the chosen zone is doing right now, DST included. */
    public static function offsetLabel(): string
    {
        return 'UTC'.self::now()->format('P');
    }

    /**
     * Every zone PHP knows, grouped by region for an <optgroup> list, UTC first.
     *
     * @return array<string, list<string>>
     */
    public static function zones(): array
    {
        $groups = ['UTC' => ['UTC']];

        foreach (\DateTimeZone::listIdentifiers() as $id) {
            if ($id === 'UTC') {
                continue;
            }
            $groups[explode('/', $id, 2)[0]][] = $id;
        }

        return $groups;
    }
}
