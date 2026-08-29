<?php

namespace App\Services;

use App\Models\Setting;
use Illuminate\Support\Facades\Cache;

/**
 * Offline licence check. A key is a signed payload, so a customer on shared
 * hosting never has to reach our server and an outage here cannot break their
 * status page. The private key stays with us; that is the whole moat.
 */
class License
{
    public const FEATURE_BRAND_PACK = 'brand_pack';

    /**
     * Features a customer keeps after a yearly key runs out. Supported carries the
     * Brand pack; when the year is over they lose support, not the logo they paid for.
     */
    public const PERPETUAL = [self::FEATURE_BRAND_PACK];

    public function key(): ?string
    {
        return Setting::get('license.key');
    }

    /** @return array{product?:string,features?:array,issued_to?:string,issued_at?:string}|null */
    public function payload(): ?array
    {
        $key = $this->key();

        if (! $key) {
            return null;
        }

        // The signature decides whether the key is ours; the date only decides
        // which of its features still count (see has()).
        return Cache::remember('license.payload', 300, fn () => $this->verify($key, ignoreExpiry: true));
    }

    /** The key is genuine but its term is over. */
    public function expired(): bool
    {
        $payload = $this->payload();

        return $payload !== null && $this->hasExpired($payload);
    }

    public function has(string $feature): bool
    {
        $features = $this->payload()['features'] ?? [];

        if ($this->expired() && ! in_array($feature, self::PERPETUAL, true)) {
            return false;
        }

        return in_array($feature, $features, true);
    }

    public function issuedTo(): ?string
    {
        return $this->payload()['issued_to'] ?? null;
    }

    /**
     * A key is "<base64url payload>.<base64url signature>". Returns null on any
     * problem — a malformed key must read as "not licensed", never as an error
     * that takes the page down.
     */
    public function verify(string $key, bool $ignoreExpiry = false): ?array
    {
        $publicKey = config('pharos.license_public_key');

        if (! $publicKey || ! str_contains($key, '.')) {
            return null;
        }

        [$payloadPart, $signaturePart] = explode('.', trim($key), 2);

        $payload = $this->b64decode($payloadPart);
        $signature = $this->b64decode($signaturePart);

        if ($payload === false || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return null;
        }

        try {
            $verified = sodium_crypto_sign_verify_detached($signature, $payload, sodium_hex2bin($publicKey));
        } catch (\SodiumException) {
            return null;
        }

        if (! $verified) {
            return null;
        }

        $data = json_decode($payload, true);

        if (! is_array($data) || ($data['product'] ?? null) !== 'pharos') {
            return null;
        }

        // A key sold for one status page must not unlock another. Keys without the
        // claim (everything issued before it existed) keep working anywhere.
        if (! $this->boundToThisHost($data)) {
            return null;
        }

        // A key signed before terms existed carries no expiry and keeps working;
        // one that names a date stops being a licence the day after it. Support
        // may still want to read such a key, which is what the flag is for.
        if (! $ignoreExpiry && $this->hasExpired($data)) {
            return null;
        }

        return $data;
    }

    /** The domain a key was sold for, or null for one that works anywhere. */
    public function boundTo(array $payload): ?string
    {
        $host = $payload['issued_for'] ?? null;

        return is_string($host) && $host !== '' ? self::normaliseHost($host) : null;
    }

    /** @param array<string, mixed> $payload */
    public function boundToThisHost(array $payload): bool
    {
        $bound = $this->boundTo($payload);

        return $bound === null || $bound === self::thisHost();
    }

    /** The host this installation answers on, from APP_URL. */
    public static function thisHost(): ?string
    {
        $host = parse_url((string) config('app.url'), PHP_URL_HOST);

        return $host ? self::normaliseHost($host) : null;
    }

    /** Case and a leading "www." are not what a customer means by "my domain". */
    public static function normaliseHost(string $host): string
    {
        $host = strtolower(trim($host));
        $host = preg_replace('~^[a-z]+://~', '', $host);   // pasted with a scheme
        $host = explode('/', $host)[0];                    // pasted with a path
        $host = explode(':', $host)[0];                    // pasted with a port

        return preg_replace('/^www\./', '', $host);
    }

    /**
     * Why a pasted key is refused, in the customer's words — or null when it is
     * fine. Verification itself stays a plain yes/no; this is for the form.
     */
    public function whyNot(string $key): ?string
    {
        $data = $this->verify($key, ignoreExpiry: true);

        if ($data === null) {
            // Either forged/mangled, or bound elsewhere: tell the two apart.
            $raw = $this->decodeWithoutHostCheck($key);

            if ($raw !== null && ! $this->boundToThisHost($raw)) {
                return 'This key was issued for '.$this->boundTo($raw).', and this status page runs on '
                    .(self::thisHost() ?? 'an unknown host').'. Keys are tied to the domain given at checkout.';
            }

            return 'That key is not valid for this product.';
        }

        $keeps = array_intersect($data['features'] ?? [], self::PERPETUAL) !== [];

        if ($this->hasExpired($data) && ! $keeps) {
            return 'That key ran out on '.$data['expires_at'].'. Renew to get a new one.';
        }

        return null;
    }

    /** The signed payload with the host check skipped, for a precise error message. */
    protected function decodeWithoutHostCheck(string $key): ?array
    {
        $publicKey = config('pharos.license_public_key');

        if (! $publicKey || ! str_contains($key, '.')) {
            return null;
        }

        [$payloadPart, $signaturePart] = explode('.', trim($key), 2);
        $payload = $this->b64decode($payloadPart);
        $signature = $this->b64decode($signaturePart);

        if ($payload === false || $signature === false || strlen($signature) !== SODIUM_CRYPTO_SIGN_BYTES) {
            return null;
        }

        try {
            if (! sodium_crypto_sign_verify_detached($signature, $payload, sodium_hex2bin($publicKey))) {
                return null;
            }
        } catch (\SodiumException) {
            return null;
        }

        $data = json_decode($payload, true);

        return is_array($data) && ($data['product'] ?? null) === 'pharos' ? $data : null;
    }

    /** @param array<string, mixed> $payload */
    public function hasExpired(array $payload): bool
    {
        return isset($payload['expires_at']) && $this->hasPassed((string) $payload['expires_at']);
    }

    /** The day the licence runs out, or null when it never does. */
    public function expiresAt(): ?\Illuminate\Support\Carbon
    {
        $date = $this->payload()['expires_at'] ?? null;

        return $date ? \Illuminate\Support\Carbon::parse((string) $date)->endOfDay() : null;
    }

    /** Whole days left, or null when there is no term to count down. */
    public function daysLeft(): ?int
    {
        $expires = $this->expiresAt();

        return $expires ? (int) now()->startOfDay()->diffInDays($expires->startOfDay()) : null;
    }

    /** Close enough that somebody should be told before it stops working. */
    public function expiringSoon(int $withinDays = 30): bool
    {
        $days = $this->daysLeft();

        return $days !== null && $days <= $withinDays;
    }

    protected function hasPassed(string $date): bool
    {
        try {
            return \Illuminate\Support\Carbon::parse($date)->endOfDay()->isPast();
        } catch (\Throwable) {
            // An unreadable date is not a licence we can vouch for.
            return true;
        }
    }

    public function store(string $key): bool
    {
        $payload = $this->verify($key, ignoreExpiry: true);

        // A lapsed key is still worth pasting when it carries something perpetual.
        $keeps = array_intersect($payload['features'] ?? [], self::PERPETUAL) !== [];

        if ($payload === null || ($this->hasExpired($payload) && ! $keeps)) {
            return false;
        }

        Setting::put('license.key', trim($key));
        Cache::forget('license.payload');

        return true;
    }

    public function forget(): void
    {
        Setting::put('license.key', null);
        Cache::forget('license.payload');
    }

    protected function b64decode(string $value): string|false
    {
        return base64_decode(strtr($value, '-_', '+/'), true);
    }
}
