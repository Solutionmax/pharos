<?php

namespace App\Models\Concerns;

/**
 * For models that cast created_at/updated_at with LocalTime.
 *
 * Eloquent formats the columns in getDates() to a zone-less string *before*
 * any cast runs, so the cast would receive "2026-08-28 12:00:00" with no way
 * to tell UTC from local and read it in the customer's zone — two hours off.
 * With the list empty the cast receives the Carbon itself and converts once.
 * freshTimestampString() (used by mass updates) is unaffected and stays UTC.
 */
trait LocalTimestamps
{
    public function getDates(): array
    {
        return [];
    }
}
