<?php

namespace App\Support;

use Illuminate\Support\Timebox;

/**
 * Runs work whose length depends on whether an account or an address exists
 * (a lookup, a token, a mail over SMTP) in at least a fixed time, so the
 * response time does not tell a stranger which it was.
 *
 * The mail stays synchronous inside the box rather than going to a queue:
 * nothing runs a queue worker here, and on shared hosting the one cron line
 * only runs the scheduler, so a queued reset mail would never leave.
 */
final class EqualTime
{
    /**
     * @template T
     *
     * @param  callable(): T  $work
     * @return T
     */
    public static function run(callable $work): mixed
    {
        return app(Timebox::class)->call(fn () => $work(), self::microseconds());
    }

    /** pharos.equal_time_ms, in microseconds as Timebox wants them. */
    public static function microseconds(): int
    {
        return max(0, (int) config('pharos.equal_time_ms')) * 1000;
    }
}
