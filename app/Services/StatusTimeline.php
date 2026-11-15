<?php

namespace App\Services;

use App\Enums\ComponentStatus;
use App\Models\Incident;
use App\Models\Maintenance;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The numbers behind the public status page: how tall a day stands in the chart,
 * where each message sits on an entry's course strip, and the sentence under the
 * headline. The view only lays them out.
 */
class StatusTimeline
{
    private const DAY_MINUTES = 1440;

    /** Lost minutes below this, with no incident, are a brief blip; from here a disruption. */
    public const BLIP_MINUTES = 5;

    /** From this many lost minutes a day without an incident counts as an outage. */
    public const OUTAGE_MINUTES = 60;

    /** A blip stands a little above a good day and never grows: it is not news. */
    private const BLIP_HEIGHT = 30;

    /** The worst tone wins a day: one red service makes the whole day red. */
    private const TONE_ORDER = ['unknown' => 0, 'ok' => 1, 't' => 2, 'w' => 3, 'p' => 4, 'b' => 5];

    /**
     * The colour of one service on one day, the single rule behind every day
     * strip. An incident that touched the day sets the floor: slow, disruption
     * or outage, as it was reported. Without one the minutes lost decide, so a
     * single missed check is a blip and not a slow day.
     *
     * @param  float  $lostMinutes  (100 - pct) / 100 × the minutes measured that day
     * @param  string|null  $incidentTone  'w', 'p' or 'b' of the worst incident on the day
     */
    public static function dayTone(float $lostMinutes, ?string $incidentTone = null): string
    {
        $measured = match (true) {
            $lostMinutes <= 0 => 'ok',
            $lostMinutes < self::BLIP_MINUTES => 't',
            $lostMinutes < self::OUTAGE_MINUTES => 'p',
            default => 'b',
        };

        return in_array($incidentTone, ['w', 'p', 'b'], true) ? self::worse($measured, $incidentTone) : $measured;
    }

    /**
     * The legend under every day strip, public and admin alike, mildest first.
     *
     * @return array<string, string> tone => label
     */
    public static function dayToneLabels(): array
    {
        return [
            't' => __('Brief blip (under :n min)', ['n' => self::BLIP_MINUTES]),
            'w' => __('Slow'),
            'p' => __('Disruption'),
            'b' => __('Outage'),
        ];
    }

    /** Of two day tones the one that weighs more. */
    public static function worse(string $a, string $b): string
    {
        return (self::TONE_ORDER[$b] ?? 0) > (self::TONE_ORDER[$a] ?? 0) ? $b : $a;
    }

    /**
     * Height of a day in percent of the track, or null for a day at rest.
     * Log scale on the minutes lost: a single lost minute is still visible, ten
     * hours fill the track. A blip keeps one short height whatever it lost.
     *
     * @param  array{tone: string, known: bool, lost?: float}  $day
     */
    public static function height(array $day): ?int
    {
        if (! $day['known'] || in_array($day['tone'], ['ok', 'unknown'], true)) {
            return null;
        }
        if ($day['tone'] === 't') {
            return self::BLIP_HEIGHT;
        }

        return (int) min(100, round(34 + 24 * log10(1 + ($day['lost'] ?? 0.0))));
    }

    /** Whether something that ran from $from to $to (or until now) touched the given day, in the zone of the bars. */
    public static function overlaps(CarbonInterface $from, ?CarbonInterface $to, string $day, CarbonInterface $now): bool
    {
        $start = Carbon::parse($day, 'UTC');

        return $from->lt($start->copy()->addDay()) && ($to ?? $now)->gt($start);
    }

    /**
     * One row for all services: per day the worst tone, the lowest percentage
     * and the most minutes lost by any one of them.
     *
     * @param  array<int, list<array{day: string, tone: string, pct: float, known: bool, lost: float}>>  $bars
     * @return list<array{day: string, tone: string, pct: float, known: bool, lost: float}>
     */
    public static function overall(array $bars): array
    {
        $result = [];
        foreach ($bars as $bar) {
            foreach ($bar as $i => $day) {
                $seen = $result[$i] ?? null;
                if ($seen === null) {
                    $result[$i] = $day;

                    continue;
                }
                $known = $seen['known'] || $day['known'];
                $result[$i] = [
                    'day' => $day['day'],
                    'tone' => self::worse($seen['tone'], $day['tone']),
                    'pct' => ! $seen['known'] ? $day['pct'] : (! $day['known'] ? $seen['pct'] : min($seen['pct'], $day['pct'])),
                    'known' => $known,
                    'lost' => max($seen['lost'], $day['lost']),
                ];
            }
        }

        return array_values($result);
    }

    /** The severity an incident carried: what its components were set to when it opened. */
    public static function tone(Incident $incident): string
    {
        $worst = (int) ($incident->components->max('pivot.status') ?? 0);

        return $worst >= 2 && $worst <= 5 ? ComponentStatus::from($worst)->tone() : 'p';
    }

    /** "Major outage", "Degraded performance": the word for an incident's tone. */
    public static function toneLabel(string $tone): string
    {
        return match ($tone) {
            'w' => ComponentStatus::PerformanceIssues->label(),
            'b' => ComponentStatus::MajorOutage->label(),
            'm' => ComponentStatus::UnderMaintenance->label(),
            default => ComponentStatus::PartialOutage->label(),
        };
    }

    public static function minutes(CarbonInterface $from, CarbonInterface $to): int
    {
        return max(1, (int) round($from->diffInMinutes($to, true)));
    }

    /** "12 min", "1.5 h", "3 d". */
    public static function duration(int $minutes): string
    {
        return match (true) {
            $minutes < 60 => __(':n min', ['n' => $minutes]),
            $minutes < self::DAY_MINUTES => __(':n h', ['n' => rtrim(rtrim(number_format($minutes / 60, 1, '.', ''), '0'), '.')]),
            default => __(':n d', ['n' => (int) round($minutes / self::DAY_MINUTES)]),
        };
    }

    /**
     * How long an incident ran, or has been running.
     */
    public static function incidentMinutes(Incident $incident, CarbonInterface $now): int
    {
        return self::minutes($incident->occurred_at, $incident->resolved_at ?? $now);
    }

    /**
     * The course of an incident: where each message sits between the first one
     * and the last (or now, while it is open), from 0 to 1, oldest first.
     *
     * @return array{from: string, to: string, points: list<float>, open: bool}
     */
    public static function strip(Incident $incident, CarbonInterface $now): array
    {
        $times = $incident->updates->sortBy('created_at')->pluck('created_at')->values();
        $open = $incident->isOpen();
        $start = $times->first() ?? $incident->occurred_at;
        $end = $open ? $now : ($times->last() ?? $start);
        $span = max(1, $start->diffInSeconds($end, true));

        return [
            'from' => self::clock($start, $end),
            'to' => $open ? __('now') : self::clock($end, $start),
            'points' => $times->map(fn (CarbonInterface $t) => round(min(1, max(0, $start->diffInSeconds($t, true) / $span)), 4))->all(),
            'open' => $open,
        ];
    }

    /**
     * Planned work: from the day it was announced to the day it starts (still to
     * come), or from its start to its end (under way). Today sits on the line.
     *
     * @return array{from: string, to: string, now: float, live: bool}
     */
    public static function maintenanceStrip(Maintenance $maintenance, CarbonInterface $now): array
    {
        $live = $maintenance->starts_at->lte($now);
        [$from, $to] = $live
            ? [$maintenance->starts_at, $maintenance->ends_at]
            : [$maintenance->created_at ?? $now, $maintenance->starts_at];
        $span = max(1, $from->diffInSeconds($to, true));

        return [
            'from' => $live ? $from->format('H:i') : $from->translatedFormat('j M'),
            'to' => $live ? $to->format('H:i') : $to->translatedFormat('j M'),
            'now' => round(min(1, max(0, $from->diffInSeconds($now, true) / $span)), 4),
            'live' => $live,
        ];
    }

    /** Whole days until planned work starts, counted in the reader's calendar. */
    public static function daysUntil(CarbonInterface $start, CarbonInterface $now): int
    {
        return (int) max(0, $now->copy()->startOfDay()->diffInDays($start->copy()->setTimezone($now->getTimezone())->startOfDay(), false));
    }

    /**
     * The sentence under the headline.
     *
     * @param  Collection<int, Incident>  $open  open public incidents, newest first
     * @param  Collection<int, Maintenance>  $live  planned work under way
     * @param  Collection<int, int>  $troubled  ids of components that are not operational
     * @return array{tone: string, headline: string, since: ?string, rest: ?string, latest: mixed, ago: ?int, lasted: ?string}
     */
    public static function hero(ComponentStatus $worst, Collection $open, Collection $live, ?Incident $last, Collection $troubled, CarbonInterface $now): array
    {
        $incident = $open->first();
        if ($incident) {
            $outside = $troubled->diff($incident->components->pluck('id'));

            return [
                'tone' => self::tone($incident),
                'headline' => $incident->name,
                'since' => __('Since :time', ['time' => self::clock($incident->occurred_at, $now)]),
                'rest' => __(':duration so far.', ['duration' => self::duration(self::incidentMinutes($incident, $now))])
                    .($outside->isEmpty() && $open->count() === 1 ? ' '.__('Everything else works.') : ''),
                'latest' => $incident->updates->first(),
                'ago' => null,
                'lasted' => null,
            ];
        }

        $work = $live->first();
        if ($work) {
            return [
                'tone' => 'm',
                'headline' => $work->title,
                'since' => __('Until :time', ['time' => $work->ends_at->isSameDay($now) ? $work->ends_at->format('H:i') : $work->ends_at->translatedFormat('j M H:i')]),
                'rest' => '.',
                'latest' => null,
                'ago' => null,
                'lasted' => null,
            ];
        }

        $ok = $worst === ComponentStatus::Operational;

        return [
            'tone' => $worst->tone(),
            'headline' => __($ok ? 'All systems operational' : $worst->label()),
            'since' => null,
            'rest' => null,
            'latest' => null,
            'ago' => $last?->resolved_at ? (int) $last->resolved_at->copy()->startOfDay()->diffInDays($now->copy()->startOfDay()) : null,
            'lasted' => $last?->resolved_at ? self::duration(self::incidentMinutes($last, $now)) : null,
        ];
    }

    /**
     * What the readout says for each day of the chart: which services had trouble
     * that day and for how long, or how the day measured when nothing was reported.
     * Services, not incident titles: the log below holds the titles, for its own window.
     *
     * @param  list<array{day: string, tone: string, pct: float, known: bool, lost: float}>  $overall
     * @param  Collection<int, Incident>  $incidents  public incidents of the window
     * @param  array<int, list<array{day: string, tone: string, pct: float, known: bool, lost: float}>>  $bars  per component, what $overall was built from
     * @param  array<int, string>  $names  component names by id
     * @return list<array{label: string, text: string, day: string}>
     */
    public static function dayNotes(array $overall, Collection $incidents, CarbonInterface $now, array $bars = [], array $names = []): array
    {
        return array_map(function (array $day, int $index) use ($incidents, $now, $bars, $names) {
            $label = Carbon::parse($day['day'])->translatedFormat('D j M');
            // Every incident that ran on the day, also one that started the day before:
            // the same test that coloured the day.
            $hits = $incidents->filter(fn (Incident $i) => self::overlaps($i->occurred_at, $i->resolved_at, $day['day'], $now));

            $text = match (true) {
                $hits->isNotEmpty() => $hits->map(function (Incident $i) use ($now) {
                    $what = __(self::toneLabel(self::tone($i))).' '.__('for :duration', ['duration' => self::duration(self::incidentMinutes($i, $now))]);

                    return $i->components->isEmpty() ? $what : $i->components->pluck('name')->join(', ').': '.mb_strtolower($what);
                })->join(' · ').'.',
                ! $day['known'] => __('No measurements this day.'),
                $day['tone'] === 'ok' => __('No problems.'),
                default => self::lossNote(array_map(fn (array $bar) => $bar[$index], $bars), $names)
                    ?? __('No incident reported. Lowest uptime :pct.', ['pct' => Uptime::format($day['pct'])]),
            };

            return ['label' => $label, 'text' => $text, 'day' => $day['day']];
        }, $overall, array_keys($overall));
    }

    /**
     * "Disruption of 12 min on API. A brief blip of about 1 min on Docs. No incident."
     * Worst first; per tone the longest loss of the services named.
     *
     * @param  array<int, array{tone: string, known: bool, lost: float}>  $days  one day per component id
     * @param  array<int, string>  $names
     */
    private static function lossNote(array $days, array $names): ?string
    {
        $byTone = [];
        foreach ($days as $id => $day) {
            if ($day['known'] && ! in_array($day['tone'], ['ok', 'unknown'], true) && isset($names[$id])) {
                $byTone[$day['tone']][] = ['name' => $names[$id], 'lost' => $day['lost']];
            }
        }
        if ($byTone === []) {
            return null;
        }
        uksort($byTone, fn ($a, $b) => (self::TONE_ORDER[$b] ?? 0) <=> (self::TONE_ORDER[$a] ?? 0));

        $parts = [];
        foreach ($byTone as $tone => $hits) {
            $replace = [
                'duration' => self::duration(max(1, (int) round(max(array_column($hits, 'lost'))))),
                'names' => implode(', ', array_column($hits, 'name')),
            ];
            $parts[] = match ($tone) {
                'b' => __('Outage of :duration on :names.', $replace),
                'p' => __('Disruption of :duration on :names.', $replace),
                'w' => __('Slow on :names.', $replace),
                default => __('A brief blip of about :duration on :names.', $replace),
            };
        }

        return implode(' ', $parts).' '.__('No incident.');
    }

    /** Times on a strip: just the clock, or the date as well when the ends fall on different days. */
    private static function clock(CarbonInterface $time, CarbonInterface $other): string
    {
        return $time->isSameDay($other) ? $time->format('H:i') : $time->translatedFormat('j M H:i');
    }
}
