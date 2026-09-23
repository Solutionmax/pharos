<?php

namespace App\Support;

/**
 * What kind of network an IP address is on. One place for the ranges, so the
 * HTTP client for typed URLs (SafeHttp) and the page SMTP check agree.
 */
final class IpAddress
{
    /**
     * Never reachable, whatever an allowlist says. Link local: 169.254.169.254
     * hands out instance credentials on most cloud providers. Loopback and the
     * null address: this very machine.
     */
    public const NEVER = [
        '169.254.0.0/16', 'fe80::/10',
        '127.0.0.0/8', '::1/128',
        '0.0.0.0/8', '::/128',
    ];

    /**
     * Not private to filter_var, but not the public internet either: carrier
     * grade NAT (and Tailscale), the IETF protocol block, benchmarking, multicast.
     */
    public const SHARED = [
        '100.64.0.0/10', '192.0.0.0/24', '198.18.0.0/15',
        '224.0.0.0/4', 'ff00::/8',
    ];

    public static function isNeverReachable(string $ip): bool
    {
        return self::inAny($ip, self::NEVER);
    }

    /** Not private and not reserved, as filter_var sees it. */
    public static function isPublic(string $ip): bool
    {
        return filter_var(
            self::canonical($ip),
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /**
     * Anything but the public internet: loopback, private, link local, CGNAT
     * and the other shared or reserved blocks. Not an address at all counts too.
     */
    public static function isInternal(string $ip): bool
    {
        return ! self::isPublic($ip) || self::isNeverReachable($ip) || self::inAny($ip, self::SHARED);
    }

    /**
     * ::ffff:169.254.169.254 is IPv6 to filter_var and inet_pton, and
     * 169.254.169.254 to the socket. Every check has to see the latter.
     */
    public static function canonical(string $ip): string
    {
        $bin = @inet_pton($ip);

        if ($bin !== false && strlen($bin) === 16 && substr($bin, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            return inet_ntop(substr($bin, 12)) ?: $ip;
        }

        return $ip;
    }

    public static function inRange(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        $ipBin = @inet_pton(self::canonical($ip));
        $subnetBin = @inet_pton($subnet);

        // Different families never overlap; by now a mapped IPv4 is plain IPv4,
        // so a length mismatch really is a family mismatch and not a disguise.
        if ($ipBin === false || $subnetBin === false || strlen($ipBin) !== strlen($subnetBin)) {
            return false;
        }

        $whole = intdiv((int) $bits, 8);
        $rest = (int) $bits % 8;

        if (substr($ipBin, 0, $whole) !== substr($subnetBin, 0, $whole)) {
            return false;
        }

        if ($rest === 0) {
            return true;
        }

        $mask = chr(0xFF << (8 - $rest) & 0xFF);

        return (($ipBin[$whole] ?? "\0") & $mask) === (($subnetBin[$whole] ?? "\0") & $mask);
    }

    /** @param list<string> $ranges */
    private static function inAny(string $ip, array $ranges): bool
    {
        foreach ($ranges as $range) {
            if (self::inRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }
}
