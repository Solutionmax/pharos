<?php

namespace App\Services;

/** Result of a single probe. */
final class ProbeResult
{
    public function __construct(
        public readonly bool $ok,
        public readonly ?int $latencyMs = null,
        public readonly ?string $message = null,
    ) {}
}
