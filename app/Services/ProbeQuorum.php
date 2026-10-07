<?php

namespace App\Services;

use App\Models\Check;
use App\Models\ProbeSample;

class ProbeQuorum
{
    public function combine(Check $check, ProbeResult $local): ProbeResult
    {
        $locations = $check->locations()->where('enabled', true)->get();
        if ($locations->isEmpty()) {
            return $local;
        }
        $up = $local->ok ? 1 : 0;
        $down = $local->ok ? 0 : 1;
        $stale = 0;
        foreach ($locations as $location) {
            if (! $location->canProbe()) {
                $stale++;

                continue;
            }
            $sample = ProbeSample::where('check_id', $check->id)->where('probe_location_id', $location->id)->latest('checked_at')->latest('id')->first();
            if (! $sample || $sample->checked_at < now()->subSeconds(max(120, $check->interval_seconds * 2))) {
                $stale++;

                continue;
            } $sample->ok ? $up++ : $down++;
        }
        $needed = (int) floor(($locations->count() + 1) / 2) + 1;
        $summary = "$up healthy, $down failed, $stale missing or stale; $needed required";
        $check->update(['quorum_summary' => $summary]);
        if ($up >= $needed) {
            return new ProbeResult(true, $local->latencyMs, 'Location quorum healthy', $local->tlsExpiresAt, $local->degraded);
        }
        if ($down >= $needed) {
            return new ProbeResult(false, $local->latencyMs, 'Location quorum failed', $local->tlsExpiresAt);
        }

        return new ProbeResult(false, null, 'Waiting for location quorum', null, false, true);
    }
}
