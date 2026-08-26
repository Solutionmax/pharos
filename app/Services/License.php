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

        return Cache::remember('license.payload', 300, fn () => $this->verify($key));
    }

    public function has(string $feature): bool
    {
        return in_array($feature, $this->payload()['features'] ?? [], true);
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
    public function verify(string $key): ?array
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

        return $data;
    }

    public function store(string $key): bool
    {
        if ($this->verify($key) === null) {
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
