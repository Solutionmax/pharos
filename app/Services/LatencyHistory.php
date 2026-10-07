<?php

namespace App\Services;

use App\Models\CheckResult;
use App\Models\Component;

class LatencyHistory
{
    public function for(Component $component): array
    {
        $end = now()->startOfMinute();
        $start = $end->copy()->subHours(24);
        $sums = [];
        $counts = [];
        // At the shortest supported interval there are 2880 samples in 24 hours.
        foreach (CheckResult::where('component_id', $component->id)->where('checked_at', '>=', $start)->where('checked_at', '<=', $end)->orderByDesc('checked_at')->limit(3000)->get(['checked_at', 'latency_ms']) as $row) {
            if ($row->latency_ms === null) {
                continue;
            } $bucket = min(287, (int) floor($start->diffInSeconds($row->checked_at) / 300));
            $sums[$bucket] = ($sums[$bucket] ?? 0) + $row->latency_ms;
            $counts[$bucket] = ($counts[$bucket] ?? 0) + 1;
        }

        return array_map(fn ($i) => ['at' => $start->copy()->addMinutes($i * 5)->toIso8601String(), 'ms' => isset($counts[$i]) ? round($sums[$i] / $counts[$i], 1) : null], range(0, 287));
    }
}
