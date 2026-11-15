<?php

namespace App\Services;

use App\Enums\ComponentStatus;
use App\Models\Component;
use App\Models\Incident;
use App\Models\UptimeDay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Reads the daily roll-up rather than raw check results, so a 90-day bar costs
 * 90 rows per component instead of 129,600.
 */
class Uptime
{
    public const WINDOW_DAYS = 90;

    /**
     * @return array<int, array{day: string, tone: string, pct: float, known: bool, lost: float}>
     *                                                                                            Oldest first, always exactly WINDOW_DAYS entries.
     */
    public function bar(Component $component, ?Carbon $today = null): array
    {
        return $this->barsFor([$component], $today)[$component->id];
    }

    /**
     * One query for every component on a page instead of one per component, and
     * one for the public incidents that colour their days.
     *
     * @param  iterable<Component>  $components
     * @return array<int, array<int, array{day: string, tone: string, pct: float, known: bool, lost: float}>> keyed by component id
     */
    public function barsFor(iterable $components, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $start = $today->copy()->subDays(self::WINDOW_DAYS - 1);
        $ids = array_map(fn (Component $c) => $c->id, is_array($components) ? $components : iterator_to_array($components));

        $rows = UptimeDay::whereIn('component_id', $ids)
            // Carbon bounds, not strings: the date cast stores "Y-m-d 00:00:00",
            // which sorts after a bare "Y-m-d" and silently dropped today.
            ->whereBetween('day', [$start->copy()->startOfDay(), $today->copy()->endOfDay()])
            ->get()
            ->groupBy('component_id');

        $incidents = $this->incidentTones($ids, $start, $today);

        $bars = [];
        foreach ($ids as $id) {
            $bars[$id] = $this->build($rows->get($id, collect())->keyBy(fn ($r) => $r->day->format('Y-m-d')), $start, $incidents[$id] ?? []);
        }

        return $bars;
    }

    /**
     * @param  Collection<string, UptimeDay>  $rows
     * @param  array<string, string>  $incidentTones  worst incident tone per day
     */
    protected function build($rows, Carbon $start, array $incidentTones = []): array
    {
        $bar = [];
        for ($i = 0; $i < self::WINDOW_DAYS; $i++) {
            $key = $start->copy()->addDays($i)->format('Y-m-d');
            $row = $rows->get($key);
            $known = $row && ($row->up_seconds + $row->down_seconds) > 0;
            // (100 - pct) / 100 × the minutes measured, which is the down time
            // itself; from the seconds, so rounding cannot nudge 5:00 under 5.
            $lost = $known ? $row->down_seconds / 60 : 0.0;

            $bar[] = [
                'day' => $key,
                'known' => $known,
                'pct' => $known ? $row->percentage() : 0.0,
                // A day without measurements still shows an incident that ran on it.
                'tone' => $known ? StatusTimeline::dayTone($lost, $incidentTones[$key] ?? null) : ($incidentTones[$key] ?? 'unknown'),
                'lost' => $lost,
            ];
        }

        return $bar;
    }

    /**
     * Per component, per day, the worst tone a public incident gave it: what it
     * set that component to (slow, partial or major outage), on every day it ran.
     *
     * @param  list<int>  $ids
     * @return array<int, array<string, string>>
     */
    protected function incidentTones(array $ids, Carbon $start, Carbon $today): array
    {
        if ($ids === []) {
            return [];
        }

        $from = $start->copy()->startOfDay();
        $now = now();
        $impacts = [ComponentStatus::PerformanceIssues->value, ComponentStatus::PartialOutage->value, ComponentStatus::MajorOutage->value];
        // One query: the pivot carries what each component was set to.
        $rows = Incident::public()
            ->join('component_incident', 'component_incident.incident_id', '=', 'incidents.id')
            ->whereIn('component_incident.component_id', $ids)
            ->whereIn('component_incident.status', $impacts)
            ->where('incidents.occurred_at', '<=', $today->copy()->endOfDay())
            ->where(fn ($q) => $q->whereNull('incidents.resolved_at')->orWhere('incidents.resolved_at', '>=', $from))
            ->get(['incidents.occurred_at', 'incidents.resolved_at', 'component_incident.component_id', 'component_incident.status as set_to']);

        $tones = [];
        foreach ($rows as $row) {
            $tone = ComponentStatus::from((int) $row->getAttribute('set_to'))->tone();
            $id = (int) $row->getAttribute('component_id');
            // Only the days between its start and its end, each checked with the
            // same test the day notes use.
            $day = Carbon::parse($row->occurred_at->utc()->format('Y-m-d'))->max($from);
            $last = Carbon::parse(($row->resolved_at ?? $now)->utc()->format('Y-m-d'))->min($today->copy()->startOfDay());
            for (; $day->lte($last); $day = $day->copy()->addDay()) {
                $key = $day->format('Y-m-d');
                if (StatusTimeline::overlaps($row->occurred_at, $row->resolved_at, $key, $now)) {
                    $tones[$id][$key] = StatusTimeline::worse($tones[$id][$key] ?? 'ok', $tone);
                }
            }
        }

        return $tones;
    }

    /** Uptime over the window as a percentage, days without data excluded. */
    public function percentage(Component $component, ?Carbon $today = null): ?float
    {
        return $this->percentageOf($this->bar($component, $today));
    }

    /** The same figure from a bar that is already in hand, so the page asks once. */
    public function percentageOf(array $bar): ?float
    {
        $known = array_filter($bar, fn ($d) => $d['known']);

        if ($known === []) {
            return null;
        }

        return round(array_sum(array_column($known, 'pct')) / count($known), 2);
    }

    public static function format(?float $percentage): string
    {
        return $percentage === null ? 'No data' : number_format($percentage, 2).'%';
    }

    /** Equal weight per component with observations; unknown components are excluded. */
    public static function average(iterable $percentages): ?float
    {
        $known = collect($percentages)->filter(fn ($value) => $value !== null);

        return $known->isEmpty() ? null : round($known->avg(), 2);
    }

    /**
     * Aggregate known component-days, keeping unmeasured days visibly unknown:
     * the mean percentage, coloured like "All services" by the worst of them.
     */
    public function aggregate(array $bars): array
    {
        if ($bars === []) {
            return [];
        }
        $result = [];
        foreach (reset($bars) as $index => $day) {
            $days = array_column($bars, $index);
            $pct = self::average(array_map(fn ($d) => $d['known'] ? $d['pct'] : null, $days));
            $known = array_filter($days, fn ($d) => $d['known']);
            $tones = array_filter(array_column($days, 'tone'), fn ($tone) => $tone !== 'unknown');
            $result[] = [
                'day' => $day['day'],
                'known' => $pct !== null,
                'pct' => $pct ?? 0.0,
                'tone' => $tones === [] ? 'unknown' : array_reduce($tones, StatusTimeline::worse(...), 'ok'),
                'lost' => (float) max([0.0, ...array_column($known, 'lost')]),
            ];
        }

        return $result;
    }
}
