<?php

namespace App\Services;

use App\Models\Maintenance;
use DateTimeInterface;

/** The union of recorded, actually started maintenance within an observed interval. */
class MaintenanceIntervals
{
    /** @param iterable<Maintenance> $windows */
    public static function excluded(iterable $windows, int $componentId, DateTimeInterface $start, DateTimeInterface $end): int
    {
        $intervals = [];
        foreach ($windows as $window) {
            if ($window->started_at === null || ! $window->components->contains($componentId)) {
                continue;
            }
            $from = max($start->getTimestamp(), $window->starts_at->timestamp, $window->started_at->timestamp);
            $to = min($end->getTimestamp(), $window->ends_at->timestamp, $window->completed_at->timestamp ?? PHP_INT_MAX, $window->cancelled_at->timestamp ?? PHP_INT_MAX);
            if ($to > $from) {
                $intervals[] = [$from, $to];
            }
        }
        sort($intervals);
        $excluded = 0;
        $lastEnd = 0;
        foreach ($intervals as [$from, $to]) {
            $excluded += max(0, $to - max($from, $lastEnd));
            $lastEnd = max($lastEnd, $to);
        }

        return $excluded;
    }
}
