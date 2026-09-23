<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Carbon;

/**
 * The display zone. Storage is UTC everywhere; this is only about what a human
 * reads on the page and what a typed wall time means.
 *
 * Two zones exist. The installation zone (Setting app.timezone) is what the
 * status pages, the API, every mail, webhook and the scheduler use. A signed in
 * user may pick a personal zone for the admin screens; the UsePersonalTimezone
 * middleware switches it on for admin requests only, through DisplayZone,
 * which lives for one request. Clock::timezone() is the one resolver every
 * reader and parser asks, so display and parsing always agree.
 *
 * Anything rendered for other people during an admin request (a mail, a
 * webhook payload, a public page preview, an audit entry) goes through
 * withInstallationZone(), so one admin's choice never reaches anyone else.
 */
class Clock
{
    /** The zone in effect right now: the admin's own during an admin request, else the installation's. */
    public static function timezone(): string
    {
        return app(DisplayZone::class)->personal() ?? self::installationTimezone();
    }

    /** The installation zone from Settings, whatever request is running. */
    public static function installationTimezone(): string
    {
        $zone = (string) Setting::get('app.timezone', 'UTC');

        // A zone PHP does not know would throw on every page; fall back rather than crash.
        return self::isValid($zone) ? $zone : 'UTC';
    }

    /** True while a personal zone other than null is in effect. */
    public static function isPersonal(): bool
    {
        return app(DisplayZone::class)->personal() !== null;
    }

    public static function isValid(?string $zone): bool
    {
        return $zone !== null && in_array($zone, \DateTimeZone::listIdentifiers(), true);
    }

    /**
     * Runs $callback with the installation zone, whatever the current user chose.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withInstallationZone(callable $callback): mixed
    {
        return self::withZone(null, $callback);
    }

    /**
     * Runs $callback with the given personal zone (null: the installation zone),
     * then puts back whatever was in effect.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function withZone(?string $zone, callable $callback): mixed
    {
        $display = app(DisplayZone::class);
        $previous = $display->personal();
        $display->set($zone);

        try {
            return $callback();
        } finally {
            $display->set($previous);
        }
    }

    /** now(), read in the zone in effect. For display only: compare against storage with plain now(). */
    public static function now(): Carbon
    {
        return now()->setTimezone(self::timezone());
    }

    /** "UTC+02:00": what the zone is doing right now, DST included. Defaults to the zone in effect. */
    public static function offsetLabel(?string $zone = null): string
    {
        return 'UTC'.now()->setTimezone($zone ?? self::timezone())->format('P');
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
