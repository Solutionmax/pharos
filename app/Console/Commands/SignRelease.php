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
        {--key= : Ed25519 secret key (hex), defaults to PHAROS_LICENSE_SECRET_FILE}
        {--released-at= : UTC moment to sign (Y-m-d\\TH:i:s\\Z), e.g. the one of latest.json for a test manifest}';

    protected $description = 'Sign a release manifest (vendor side)';

    public function handle(): int
    {
        $releasedAt = $this->option('released-at') ?: now('UTC')->format('Y-m-d\\TH:i:s\\Z');
        if (! preg_match('/^\\d{4}-\\d{2}-\\d{2}T\\d{2}:\\d{2}:\\d{2}Z$/', $releasedAt)) {
            $this->error('--released-at must look like 2026-11-15T12:00:00Z.');

            return self::FAILURE;
        }

        $path = $this->option('key') ?: config('pharos.license_secret_file');

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
            // To the second, in UTC: installs refuse a manifest older than the
            // newest they accepted, so two releases on one day must still differ.
            // A test manifest takes the moment of latest.json, so an install that took the test
            // does not later refuse the real manifest of the same release as older.
            'released_at' => $releasedAt,
        ], JSON_UNESCAPED_SLASHES);

        $signature = sodium_crypto_sign_detached($payload, sodium_hex2bin(trim(file_get_contents($path))));

        $b64 = fn (string $raw) => rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');

        $this->line($b64($payload).'.'.$b64($signature));

        return self::SUCCESS;
    }
}
