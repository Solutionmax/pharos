<?php

namespace App\Services;

use App\Models\User;

/**
 * Who may point a check or a notification destination at a private network.
 *
 * Installation administrators always may. Page editors only when the
 * installation allows it (Settings, General): otherwise an HTTP or TCP check
 * is a port scanner for the server's own networks, and a keyword check a blind
 * oracle. Checked when a target is saved and again every time it is used, so
 * a name that later resolves to a private address gets nothing either.
 */
final class PrivateTargets
{
    /** May this user save a target on a private network? */
    public static function mayChoose(?User $user): bool
    {
        return ($user?->isAdmin() ?? false) || InstallSettings::editorsMayTargetPrivate();
    }

    /**
     * At run time: a target an installation administrator saved, or any target
     * while page editors are allowed private ones.
     */
    public static function allowed(bool $savedByAdmin): bool
    {
        return $savedByAdmin || InstallSettings::editorsMayTargetPrivate();
    }

    /** Why a host may not be used by someone without the permission, or null. */
    public static function problem(string $host): ?string
    {
        $ip = app(SafeHttp::class)->internalAddress($host);

        return $ip === null ? null : __('That address is on a private network (:ip). Only an installation administrator can use private addresses, unless page editors are allowed to under Settings, General.', ['ip' => $ip]);
    }

    /** The host of an HTTP URL or a TCP host:port target, or null for anything else. */
    public static function host(string $type, string $target): ?string
    {
        if ($type === 'tcp') {
            return preg_match('/^(\[[^\]]+\]|[^:]+):\d{1,5}$/', $target, $m) ? trim($m[1], '[]') : null;
        }

        $host = parse_url($target, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? trim($host, '[]') : null;
    }
}
