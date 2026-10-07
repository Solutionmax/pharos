<?php

namespace App\Services;

use App\Models\Component;
use App\Models\Maintenance;
use App\Models\UptimeDay;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** Calendar months in UTC: daily rollups cannot establish local intraday boundaries. */
class MonthlyUptimeReport
{
    public function month(Request $request): string
    {
        $month = $request->validate(['month' => ['sometimes', 'date_format:Y-m']])['month'] ?? now('UTC')->format('Y-m');
        if ($month < '2000-01' || $month > now('UTC')->format('Y-m')) {
            throw ValidationException::withMessages(['month' => __('Choose a month between January 2000 and this month.')]);
        }

        return $month;
    }

    public function build(string $month, bool $public = true): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $month, 'UTC');
        $end = $start->addMonth()->min(CarbonImmutable::now('UTC'));
        $query = $public ? PublicComponents::query() : Component::query();
        $components = $query->orderBy('position')->limit(501)->get();
        if ($components->count() > 500) {
            throw ValidationException::withMessages(['month' => __('Reports support up to 500 components per page. Split this page to report all components.')]);
        }
        $totals = UptimeDay::whereIn('component_id', $components->modelKeys())->where('day', '>=', $start)->where('day', '<', $start->addMonth())
            ->groupBy('component_id')->selectRaw('component_id, SUM(up_seconds) as up_seconds, SUM(down_seconds) as down_seconds')->get()->keyBy('component_id');
        $windows = Maintenance::with('components')->whereNotNull('started_at')->where('starts_at', '<', $end)->where('ends_at', '>', $start)->limit(2001)->get();
        if ($windows->count() > 2000) {
            throw ValidationException::withMessages(['month' => __('This month has too many maintenance windows to report accurately.')]);
        }
        $elapsed = max(0, (int) $start->diffInSeconds($end));
        $rows = [];
        foreach ($components as $component) {
            $up = (int) ($totals->get($component->id)->up_seconds ?? 0);
            $down = (int) ($totals->get($component->id)->down_seconds ?? 0);
            $intervals = [];
            foreach ($windows as $window) {
                if (! $window->components->contains($component->id)) {
                    continue;
                }
                $from = max($start->timestamp, $window->starts_at->timestamp, $window->started_at->timestamp);
                $to = min($end->timestamp, $window->ends_at->timestamp, $window->completed_at->timestamp ?? PHP_INT_MAX, $window->cancelled_at->timestamp ?? PHP_INT_MAX);
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
            $eligible = max(0, $elapsed - $excluded);
            $measured = $up + $down;
            $rows[] = ['id' => $component->id, 'name' => $component->name, 'up_seconds' => $up, 'down_seconds' => $down,
                'uptime' => $measured > 0 ? round($up / $measured * 100, 2) : null,
                'coverage' => $eligible > 0 ? round(min(100, $measured / $eligible * 100), 2) : null,
                'excluded_seconds' => $excluded, 'unobserved_seconds' => max(0, $eligible - $measured)];
        }

        return ['month' => $month, 'timezone' => 'UTC', 'rows' => $rows, 'elapsed_seconds' => $elapsed];
    }
}
