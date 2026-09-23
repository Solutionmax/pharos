<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read and end signed in sessions from the database session table.
 *
 * Every query is bound to one user id, and a session is addressed by a hash of
 * its id, never the id itself: the raw id is what the session cookie carries,
 * so it does not belong in a page. With another session driver there is
 * nothing to list and the methods return empty results.
 */
class UserSessions
{
    public static function available(): bool
    {
        return config('session.driver') === 'database' && Schema::hasTable(self::table());
    }

    public static function key(string $sessionId): string
    {
        return hash('sha256', $sessionId);
    }

    /** @return array<int, Carbon> last activity per user id */
    public function lastSeen(): array
    {
        if (! self::available()) {
            return [];
        }

        return DB::table(self::table())->whereNotNull('user_id')->groupBy('user_id')
            ->selectRaw('user_id, max(last_activity) as seen')->pluck('seen', 'user_id')
            ->map(fn ($seen) => Carbon::createFromTimestamp((int) $seen, Clock::timezone()))->all();
    }

    /**
     * @return list<array{key: string, current: bool, browser: string, platform: string, phone: bool, ip: ?string, last: Carbon}>
     */
    public function forUser(User $user, string $currentId): array
    {
        if (! self::available()) {
            return [];
        }

        return DB::table(self::table())->where('user_id', $user->getKey())->orderByDesc('last_activity')->limit(20)
            ->get(['id', 'ip_address', 'user_agent', 'last_activity'])
            ->map(fn ($row) => [
                'key' => self::key((string) $row->id),
                'current' => hash_equals((string) $row->id, $currentId),
                ...self::describe((string) $row->user_agent),
                'ip' => $row->ip_address,
                'last' => Carbon::createFromTimestamp((int) $row->last_activity, Clock::timezone()),
            ])
            ->sortByDesc('current')->values()->all();
    }

    /** Ends one of this user's other sessions. False when there is no such session. */
    public function destroy(User $user, string $key, string $currentId): bool
    {
        if (! self::available()) {
            return false;
        }
        $id = DB::table(self::table())->where('user_id', $user->getKey())->where('id', '!=', $currentId)
            ->pluck('id')->first(fn ($id) => hash_equals(self::key((string) $id), $key));

        return $id !== null && DB::table(self::table())->where('user_id', $user->getKey())->where('id', $id)->delete() > 0;
    }

    /** Ends every session of this user except the current one. Returns how many. */
    public function destroyOthers(User $user, string $currentId): int
    {
        if (! self::available()) {
            return 0;
        }

        return DB::table(self::table())->where('user_id', $user->getKey())->where('id', '!=', $currentId)->delete();
    }

    /** @return array{browser: string, platform: string, phone: bool} */
    public static function describe(string $agent): array
    {
        $browser = match (true) {
            str_contains($agent, 'Edg/') => 'Edge',
            str_contains($agent, 'OPR/') => 'Opera',
            str_contains($agent, 'Firefox/') || str_contains($agent, 'FxiOS') => 'Firefox',
            str_contains($agent, 'Chrome/') || str_contains($agent, 'CriOS') => 'Chrome',
            str_contains($agent, 'Safari/') => 'Safari',
            default => 'Unknown browser',
        };
        $platform = match (true) {
            str_contains($agent, 'iPhone') => 'iPhone',
            str_contains($agent, 'iPad') => 'iPad',
            str_contains($agent, 'Android') => 'Android',
            str_contains($agent, 'Mac OS X') || str_contains($agent, 'Macintosh') => 'macOS',
            str_contains($agent, 'Windows') => 'Windows',
            str_contains($agent, 'Linux') => 'Linux',
            default => 'an unknown system',
        };

        return ['browser' => $browser, 'platform' => $platform, 'phone' => in_array($platform, ['iPhone', 'Android'], true)];
    }

    protected static function table(): string
    {
        return (string) config('session.table', 'sessions');
    }
}
