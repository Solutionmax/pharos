<?php

namespace App\Services;

/**
 * The signed in user's own zone for this one request, or null for the
 * installation zone. Bound as a scoped instance, so it never outlives the
 * request that set it. Read it through Clock, not directly.
 */
class DisplayZone
{
    private ?string $personal = null;

    public function personal(): ?string
    {
        return $this->personal;
    }

    public function set(?string $zone): void
    {
        $this->personal = Clock::isValid($zone) ? $zone : null;
    }
}
