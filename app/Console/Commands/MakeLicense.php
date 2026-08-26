<?php

namespace App\Console\Commands;

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
        {--key= : Path to the Ed25519 secret key (hex), defaults to PHAROS_LICENSE_SECRET_FILE}';

    protected $description = 'Sign a licence key (vendor side)';

    public function handle(): int
    {
        $path = $this->option('key') ?: env('PHAROS_LICENSE_SECRET_FILE');

        if (! $path || ! is_readable($path)) {
            $this->error('No readable secret key. Pass --key=/path/to/key or set PHAROS_LICENSE_SECRET_FILE.');

            return self::FAILURE;
        }

        $secret = sodium_hex2bin(trim(file_get_contents($path)));

        $payload = json_encode([
            'product' => 'pharos',
            'issued_to' => $this->argument('email'),
            'features' => array_values(array_filter(array_map('trim', explode(',', $this->option('features'))))),
            'issued_at' => now()->toDateString(),
        ], JSON_UNESCAPED_SLASHES);

        $signature = sodium_crypto_sign_detached($payload, $secret);

        $this->line($this->b64($payload).'.'.$this->b64($signature));

        return self::SUCCESS;
    }

    protected function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }
}
