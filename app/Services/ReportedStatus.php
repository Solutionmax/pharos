<?php

namespace App\Services;

use App\Enums\ComponentStatus;
use App\Models\ApiToken;
use App\Models\Component;

/**
 * A status that something outside Pharos reported for a component: Uptime
 * Kuma, Alertmanager, Grafana. One way to save it, so every receiver labels
 * the component and its reporter the same.
 */
class ReportedStatus
{
    /** Saves the status and who reported it; true when the status changed. */
    public static function apply(Component $component, ComponentStatus $status, ?ApiToken $token, string $source): bool
    {
        $changed = $component->status !== $status;
        $component->update([
            'status' => $status,
            ...$component->reportedAttributes($token, $source),
        ]);

        return $changed;
    }
}
