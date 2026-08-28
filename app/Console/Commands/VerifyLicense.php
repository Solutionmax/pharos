<?php

namespace App\Console\Commands;

use App\Services\License;
use Illuminate\Console\Command;

/**
 * Reads a licence key back. For support: "is this customer's key still good,
 * and what does it cover?" Exit 0 only when the key would be accepted.
 */
class VerifyLicense extends Command
{
    protected $signature = 'pharos:license:verify {key : The licence key to inspect}';

    protected $description = 'Show what a licence key says and whether it still counts';

    public function handle(License $license): int
    {
        // Expired keys are read too: who it belonged to is the useful part.
        $data = $license->verify($this->argument('key'), ignoreExpiry: true);

        if ($data === null) {
            $this->error('invalid');

            return self::FAILURE;
        }

        $expired = $license->hasExpired($data);

        $this->table(['field', 'value'], [
            ['issued_to', $data['issued_to'] ?? '—'],
            ['features', implode(', ', $data['features'] ?? []) ?: '—'],
            ['issued_at', $data['issued_at'] ?? '—'],
            ['expires_at', $data['expires_at'] ?? 'never'],
            ['status', $expired ? 'expired' : 'valid'],
        ]);

        return $expired ? self::FAILURE : self::SUCCESS;
    }
}
