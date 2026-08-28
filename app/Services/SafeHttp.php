<?php

namespace App\Services;

use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * An HTTP client for URLs an administrator typed.
 *
 * Fetching those turns the server into a probe for networks the administrator
 * cannot reach himself: internal services, and cloud metadata at 169.254.169.254,
 * which on most providers hands instance credentials to anything that asks.
 *
 * So: resolve the name here, refuse anything private, pin the address that was
 * checked so a second DNS answer cannot differ from it, and follow no redirects.
 */
class SafeHttp
{
    /**
     * Shut whatever the allowlist says. Link-local: 169.254.169.254 hands out
     * instance credentials on most cloud providers, and that is the address this
     * guard exists for. Loopback and the null address: this very machine, where
     * things listen that were never meant to be reached over HTTP.
     */
    private const NEVER = [
        '169.254.0.0/16', 'fe80::/10',
        '127.0.0.0/8', '::1/128',
        '0.0.0.0/8', '::/128',
    ];

    /**
     * @param  array<int, string>|null  $allowedHosts hosts an administrator vouched
     *                                  for; null reads the setting at the moment of
     *                                  the lookup, so saving the screen takes effect
     *                                  on that same request instead of the next one.
     */
    public function __construct(protected int $timeout = 6, protected ?array $allowedHosts = null) {}

    /**
     * Hosts an administrator marked as internal on the single sign-on screen.
     *
     * @return array<int, string>
     */
    public static function configuredHosts(): array
    {
        $raw = (string) \App\Models\Setting::get('sso.internal_hosts', '');

        return array_values(array_filter(array_map(
            'trim',
            preg_split('/[\s,]+/', $raw) ?: [],
        )));
    }

    /** @throws \RuntimeException when the host is missing, unresolvable or private */
    public function to(string $url): PendingRequest
    {
        $host = $this->host($url);

        return $this->pinned($url, $host, $this->resolve($host));
    }

    /**
     * For a target the administrator owns, such as an n8n on the same LAN:
     * private networks are fine here, this machine and link-local are not. A
     * name that does not resolve is left to curl, which fails on it by itself.
     *
     * @throws \RuntimeException when the host is missing or must never be reached
     */
    public function toOwn(string $url): PendingRequest
    {
        $host = $this->host($url);
        $addresses = $this->addresses($host);

        if (($ip = $this->forbiddenAmong($addresses)) !== null) {
            throw new \RuntimeException("{$host} resolves to {$ip}, which is never allowed.");
        }

        return $this->pinned($url, $host, $addresses[0] ?? null);
    }

    /** The address behind a URL that nothing may reach, or null when there is none. */
    public function forbiddenAddress(string $url): ?string
    {
        return $this->forbiddenAmong($this->addresses($this->host($url)));
    }

    /** @throws \RuntimeException */
    protected function host(string $url): string
    {
        $host = parse_url($url, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            throw new \RuntimeException('That does not look like a URL.');
        }

        // parse_url keeps the brackets on an IPv6 literal; with them on, neither
        // filter_var nor DNS recognises it and the address would go unchecked.
        return trim($host, '[]');
    }

    protected function pinned(string $url, string $host, ?string $ip): PendingRequest
    {
        $request = Http::timeout($this->timeout)->withoutRedirecting();

        // A literal needs no pin, and an unresolved name has nothing to pin to.
        if ($ip === null || filter_var($host, FILTER_VALIDATE_IP)) {
            return $request;
        }

        $port = parse_url($url, PHP_URL_PORT) ?: (str_starts_with($url, 'http://') ? 80 : 443);

        // Pinned: curl connects to the address we vetted, not to whatever a
        // second lookup would return.
        return $request->withOptions(['curl' => [CURLOPT_RESOLVE => ["{$host}:{$port}:{$ip}"]]]);
    }

    /**
     * Every address a host answers with, or [] when it does not resolve.
     *
     * @return array<int, string>
     */
    public function addresses(string $host): array
    {
        // A literal address skips DNS but not the checks that follow.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        return array_merge(
            gethostbynamel($host) ?: [],
            array_column(@dns_get_record($host, DNS_AAAA) ?: [], 'ipv6'),
        );
    }

    /** @param array<int, string> $addresses */
    protected function forbiddenAmong(array $addresses): ?string
    {
        foreach ($addresses as $ip) {
            if ($this->isNeverReachable($ip)) {
                // The address the socket would reach, which is what the message
                // has to name.
                return $this->canonical($ip);
            }
        }

        return null;
    }

    /** @throws \RuntimeException */
    public function resolve(string $host): string
    {
        $candidates = $this->addresses($host);

        if ($candidates === []) {
            throw new \RuntimeException("Could not resolve {$host}.");
        }

        $vouchedFor = $this->isAllowed($host);

        // Every answer has to pass: one private address among them is enough to
        // make the destination unsafe.
        foreach ($candidates as $candidate) {
            if ($this->isNeverReachable($candidate)) {
                throw new \RuntimeException("{$host} resolves to {$candidate}, which is link-local or this machine. That address is never allowed.");
            }

            if (! $vouchedFor && ! $this->isPublic($candidate)) {
                throw new \RuntimeException("{$host} resolves to {$candidate}, which is on a private or local network. Add the host to the internal hosts list if that is deliberate.");
            }
        }

        return $candidates[0];
    }

    public function isAllowed(string $host): bool
    {
        $allowed = $this->allowedHosts ?? static::configuredHosts();

        return in_array(strtolower($host), array_map('strtolower', $allowed), true);
    }

    public function isNeverReachable(string $ip): bool
    {
        $ip = $this->canonical($ip);

        foreach (self::NEVER as $range) {
            if ($this->inRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    protected function inRange(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);

        $ipBin = @inet_pton($this->canonical($ip));
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

    public function isPublic(string $ip): bool
    {
        return filter_var(
            $this->canonical($ip),
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) !== false;
    }

    /**
     * ::ffff:169.254.169.254 is IPv6 to filter_var and inet_pton, and
     * 169.254.169.254 to the socket. Every check has to see the latter.
     */
    protected function canonical(string $ip): string
    {
        $bin = @inet_pton($ip);

        if ($bin !== false && strlen($bin) === 16 && substr($bin, 0, 12) === str_repeat("\0", 10)."\xff\xff") {
            return inet_ntop(substr($bin, 12)) ?: $ip;
        }

        return $ip;
    }
}
