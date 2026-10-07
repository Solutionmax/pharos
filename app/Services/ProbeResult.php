<?php

namespace App\Services;

/** Result of a single probe. */
final class ProbeResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?int $latencyMs = null,
        public readonly ?string $message = null,
        public readonly ?\DateTimeInterface $tlsExpiresAt = null,
        public readonly bool $degraded = false,
        public readonly bool $inconclusive = false,
    ) {}
}
