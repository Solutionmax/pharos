<?php

namespace App\Services;

use App\Models\Setting;
use App\Support\IpAddress;
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
     * @param  array<int, string>|null  $allowedHosts  hosts an administrator vouched
     *                                                 for; null reads the setting at the moment of
     *                                                 the lookup, so saving the screen takes effect
     *                                                 on that same request instead of the next one.
     */
    public function __construct(protected int $timeout = 6, protected ?array $allowedHosts = null) {}

    /**
     * Hosts an administrator marked as internal on the single sign-on screen.
     *
     * @return array<int, string>
     */
    public static function configuredHosts(): array
    {
        $raw = (string) Setting::get('sso.internal_hosts', '');

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

        return $this->pinned($url, $host, $this->resolveOwn($host));
    }

    /** Resolve once, vet every answer, and never delegate an unchecked lookup. */
    public function resolveOwn(string $host): string
    {
        $addresses = $this->addresses($host);
        if ($addresses === []) {
            throw new \RuntimeException("Could not resolve {$host}.");
        }
        if (($ip = $this->forbiddenAmong($addresses)) !== null) {
            throw new \RuntimeException("{$host} resolves to {$ip}, which is never allowed.");
        }

        return $addresses[0];
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
                throw new \RuntimeException("{$host} resolves to {$candidate}, which is link local or this machine. That address is never allowed.");
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

    /** The ranges live in IpAddress, shared with the page SMTP check. */
    public function isNeverReachable(string $ip): bool
    {
        return IpAddress::isNeverReachable($ip);
    }

    public function isPublic(string $ip): bool
    {
        return IpAddress::isPublic($ip);
    }

    protected function canonical(string $ip): string
    {
        return IpAddress::canonical($ip);
    }
}
