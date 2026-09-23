<?php

namespace App\Casts;

use App\Services\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * A datetime column that is stored in UTC and read in the customer's zone.
 *
 * Reading gives a Carbon already in the display zone, so a view can call
 * ->format() without thinking. Writing accepts anything: a Carbon in any
 * zone is converted, a string with an offset keeps it, and a bare string —
 * what a datetime-local input sends — means the customer's own wall time.
 *
 * Immutable on purpose, and not cached on the model: the zone in effect can
 * change within one request (Clock::withInstallationZone() around a mail or a
 * webhook while an admin with a personal zone is signed in), so every read
 * converts again. With no cached object there is also nothing for Eloquent
 * to write back on save().
 */
class LocalTime implements CastsAttributes
{
    /** Read the attribute afresh each time, in the zone in effect at that moment. */
    public bool $withoutObjectCaching = true;

    public function get(Model $model, string $key, mixed $value, array $attributes): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse($value, 'UTC')->setTimezone(Clock::timezone());
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        $moment = $value instanceof \DateTimeInterface
            ? Carbon::instance($value)
            // The zone argument only applies when the string carries none of its own.
            : Carbon::parse((string) $value, Clock::timezone());

        return $moment->utc()->format('Y-m-d H:i:s');
    }
}
