<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Signs a release manifest. Vendor side only; the private key never ships.
 */
class SignRelease extends Command
{
    protected $signature = 'pharos:release:sign
        {version : The version being released, e.g. 1.1.0}
        {url : Where the release archive can be downloaded}
        {archive : Local path to that archive, to hash it}
        {--notes= : One line for the operator}
        {--key= : Ed25519 secret key (hex), defaults to PHAROS_LICENSE_SECRET_FILE}';

    protected $description = 'Sign a release manifest (vendor side)';

    public function handle(): int
    {
        $path = $this->option('key') ?: env('PHAROS_LICENSE_SECRET_FILE');

        if (! $path || ! is_readable($path)) {
            $this->error('No readable secret key. Pass --key=/path/to/key or set PHAROS_LICENSE_SECRET_FILE.');

            return self::FAILURE;
        }

        $archive = $this->argument('archive');

        if (! is_readable($archive)) {
            $this->error("Cannot read {$archive}.");

            return self::FAILURE;
        }

        $payload = json_encode([
            'purpose' => 'pharos-release',
            'version' => ltrim($this->argument('version'), 'v'),
            'url' => $this->argument('url'),
            'sha256' => hash_file('sha256', $archive),
            'notes' => $this->option('notes') ?: '',
            'released_at' => now()->toDateString(),
        ], JSON_UNESCAPED_SLASHES);

        $signature = sodium_crypto_sign_detached($payload, sodium_hex2bin(trim(file_get_contents($path))));

        $b64 = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        $this->line($b64($payload).'.'.$b64($signature));

        return self::SUCCESS;
    }
}
