<?php

namespace App\Console\Commands;

use App\Services\License;
use Illuminate\Console\Command;

/**
 * Signs a licence key. Runs on our side only, never at a customer.
 * The private key lives outside the repository.
 */
class MakeLicense extends Command
{
    protected $signature = 'pharos:license:sign
        {email : Who the licence is for}
        {--features=brand_pack : Comma separated}
        {--key= : Path to the Ed25519 secret key (hex), defaults to PHAROS_LICENSE_SECRET_FILE}
        {--months= : Term in months; leave off for a key that never expires}
        {--domain= : Status page domain the key is tied to; leave off for a key that works anywhere}';

    protected $description = 'Sign a licence key (vendor side)';

    public function handle(): int
    {
        $path = $this->option('key') ?: config('pharos.license_secret_file');

        if (! $path || ! is_readable($path)) {
            $this->error('No readable secret key. Pass --key=/path/to/key or set PHAROS_LICENSE_SECRET_FILE.');

            return self::FAILURE;
        }

        $secret = sodium_hex2bin(trim(file_get_contents($path)));

        $claims = [
            'product' => 'pharos',
            'issued_to' => $this->argument('email'),
            'features' => array_values(array_filter(array_map('trim', explode(',', $this->option('features'))))),
            'issued_at' => now()->toDateString(),
        ];

        // A yearly subscription needs a key that stops; a perpetual one must not
        // carry the claim at all, so leaving the option off means forever.
        if ($domain = trim((string) $this->option('domain'))) {
            $claims['issued_for'] = License::normaliseHost($domain);
        }

        if ($months = (int) $this->option('months')) {
            $claims['expires_at'] = now()->addMonths($months)->toDateString();
        }

        $payload = json_encode($claims, JSON_UNESCAPED_SLASHES);

        $signature = sodium_crypto_sign_detached($payload, $secret);

        $this->line($this->b64($payload).'.'.$this->b64($signature));

        return self::SUCCESS;
    }

    protected function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
