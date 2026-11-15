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
            $excluded = MaintenanceIntervals::excluded($windows, $component->id, $start, $end);
            $eligible = max(0, $elapsed - $excluded);
            $measured = $up + $down;
            $rows[] = ['id' => $component->id, 'name' => $component->name, 'up_seconds' => $up, 'down_seconds' => $down,
                'uptime' => $measured > 0 ? round($up / $measured * 100, 2) : null,
                'coverage' => $eligible > 0 ? round(min(100, $measured / $eligible * 100), 2) : null,
                'excluded_seconds' => $excluded, 'unobserved_seconds' => max(0, $eligible - $measured)];
        }

        return ['month' => $month, 'timezone' => 'UTC', 'rows' => $rows, 'elapsed_seconds' => $elapsed];
    }

    /**
     * What a visitor reads beside the numbers: the day measuring began when the
     * month was not measured whole (or is still under way), whether planned
     * maintenance was left out, and the public incidents of each service.
     *
     * @return array{measured: bool, since: ?CarbonImmutable, maintenance: bool, incidents: array<int, int>}
     */
    public function visitorNotes(array $report): array
    {
        $start = CarbonImmutable::createFromFormat('!Y-m', $report['month'], 'UTC');
        $ids = array_column($report['rows'], 'id');
        $first = UptimeDay::whereIn('component_id', $ids)->where('day', '>=', $start)->where('day', '<', $start->addMonth())
            ->whereRaw('up_seconds + down_seconds > 0')->min('day');
        $since = $first ? CarbonImmutable::parse($first, 'UTC')->startOfDay() : null;
        $whole = $since?->equalTo($start) && $report['month'] !== now('UTC')->format('Y-m');

        return [
            'measured' => $since !== null,
            'since' => $whole ? null : $since,
            'maintenance' => array_sum(array_column($report['rows'], 'excluded_seconds')) > 0,
            'incidents' => Component::whereKey($ids)
                ->withCount(['incidents' => fn ($q) => $q->public()->where('occurred_at', '>=', $start)->where('occurred_at', '<', $start->addMonth())])
                ->pluck('incidents_count', 'id')->all(),
        ];
    }

    /** Downtime the way a person says it: "none", "6 min", "1 h 12 min", "2 d 3 h". */
    public static function downtime(int $seconds): string
    {
        if ($seconds <= 0) {
            return __('none');
        }
        if ($seconds < 60) {
            return __('< 1 min');
        }
        $minutes = (int) round($seconds / 60);
        [$days, $hours, $rest] = [intdiv($minutes, 1440), intdiv($minutes % 1440, 60), $minutes % 60];
        $parts = match (true) {
            $days > 0 => [__(':n d', ['n' => $days]), $hours > 0 ? __(':n h', ['n' => $hours]) : null],
            $hours > 0 => [__(':n h', ['n' => $hours]), $rest > 0 ? __(':n min', ['n' => $rest]) : null],
            default => [__(':n min', ['n' => $rest])],
        };

        return implode(' ', array_filter($parts));
    }
}
