<?php

namespace Tests\Support;

use App\Models\Setting;
use App\Services\License;
use Illuminate\Support\Facades\Cache;

/** Signs a throwaway brand-pack key for a test; the real private key never goes near CI. */
trait GrantsBrandPack
{
    protected function grantBrandPack(): void
    {
        $pair = sodium_crypto_sign_keypair();
        config(['pharos.license_public_key' => sodium_bin2hex(sodium_crypto_sign_publickey($pair))]);

        $json = json_encode([
            'product' => 'pharos',
            'issued_to' => 'klant@example.com',
            'features' => [License::FEATURE_BRAND_PACK],
            'issued_at' => '2026-08-25',
        ], JSON_UNESCAPED_SLASHES);
        $b64 = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        Setting::put('license.key', $b64($json).'.'.$b64(sodium_crypto_sign_detached($json, sodium_crypto_sign_secretkey($pair))));
        Cache::forget('license.payload');
    }
}
