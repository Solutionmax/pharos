<?php

namespace App\Services;

use Illuminate\Support\Facades\RateLimiter;

/**
 * How many confirmation mails the public sign up form may send. The route is
 * limited per IP already; that does not stop someone with many addresses from
 * flooding one victim with mails from this page's sender, so there is a limit
 * per email address and one for the page as a whole.
 *
 * The address is never a cache key as typed: the key holds a keyed hash of the
 * lower case address, so the limiter table is no list of who signed up.
 */
class SubscribeLimits
{
    /** At most one confirmation per address in ten minutes ... */
    public const PER_ADDRESS_SHORT = [1, 10 * 60];

    /** ... and three a day. */
    public const PER_ADDRESS_DAY = [3, 24 * 60 * 60];

    /** All addresses together, per page, per hour. */
    public const PER_PAGE_HOUR = [100, 60 * 60];

    /**
     * Counts one confirmation mail for the current page. False, with nothing
     * counted, when the address or the page has had its share.
     */
    public static function reserve(string $email): bool
    {
        $counted = [];

        foreach (self::limits($email) as $key => [$max, $decay]) {
            $counted[$key] = $decay;

            if (RateLimiter::hit($key, $decay) > $max) {
                self::undo($counted);

                return false;
            }
        }

        return true;
    }

    /** Gives a reservation back, for a mail that could not be sent after all. */
    public static function release(string $email): void
    {
        self::undo(array_map(fn (array $limit) => $limit[1], self::limits($email)));
    }

    /**
     * Limiter keys with their [max, decay seconds].
     *
     * @return array<string, array{int, int}>
     */
    public static function limits(string $email): array
    {
        $page = app(PageContext::class)->id();
        $address = hash_hmac('sha256', mb_strtolower(trim($email)), (string) config('app.key'));

        return [
            "subscribe-mail:{$page}:{$address}:short" => self::PER_ADDRESS_SHORT,
            "subscribe-mail:{$page}:{$address}:day" => self::PER_ADDRESS_DAY,
            "subscribe-mail:{$page}" => self::PER_PAGE_HOUR,
        ];
    }

    /** @param  array<string, int>  $keys  key => decay seconds */
    protected static function undo(array $keys): void
    {
        foreach ($keys as $key => $decay) {
            if (RateLimiter::attempts($key) > 0) {
                RateLimiter::decrement($key, $decay);
            }
        }
    }
}
