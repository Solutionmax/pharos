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

    /** The worst tone wins a day: one red service makes the whole day red. */
    private const TONE_ORDER = ['unknown' => 0, 'ok' => 1, 'w' => 2, 'p' => 3, 'b' => 4];

    /**
     * Height of a day in percent of the track, or null for a day without loss.
     * Log scale: a single lost minute is still visible, ten hours fill the track.
     */
    public static function height(?float $pct): ?int
    {
        if ($pct === null || $pct >= 99.99) {
            return null;
        }

        $minutes = (100 - $pct) / 100 * self::DAY_MINUTES;

        return (int) min(100, round(34 + 24 * log10(1 + $minutes)));
    }

    /**
     * One row for all services: per day the worst tone and the lowest percentage.
     *
     * @param  array<int, list<array{day: string, tone: string, pct: float, known: bool}>>  $bars
     * @return list<array{day: string, tone: string, pct: float, known: bool}>
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
                    'tone' => self::TONE_ORDER[$day['tone']] > self::TONE_ORDER[$seen['tone']] ? $day['tone'] : $seen['tone'],
                    'pct' => ! $seen['known'] ? $day['pct'] : (! $day['known'] ? $seen['pct'] : min($seen['pct'], $day['pct'])),
                    'known' => $known,
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
     * @param  list<array{day: string, tone: string, pct: float, known: bool}>  $overall
     * @param  Collection<int, Incident>  $incidents  public incidents of the window
     * @return list<array{label: string, text: string, day: string}>
     */
    public static function dayNotes(array $overall, Collection $incidents, CarbonInterface $now): array
    {
        $byDay = $incidents->groupBy(fn (Incident $i) => $i->occurred_at->format('Y-m-d'));

        return array_map(function (array $day) use ($byDay, $now) {
            $label = Carbon::parse($day['day'])->translatedFormat('D j M');
            $hits = $byDay->get($day['day'], collect());

            $text = match (true) {
                $hits->isNotEmpty() => $hits->map(function (Incident $i) use ($now) {
                    $what = __(self::toneLabel(self::tone($i))).' '.__('for :duration', ['duration' => self::duration(self::incidentMinutes($i, $now))]);

                    return $i->components->isEmpty() ? $what : $i->components->pluck('name')->join(', ').': '.mb_strtolower($what);
                })->join(' · ').'.',
                ! $day['known'] => __('No measurements this day.'),
                $day['tone'] === 'ok' => __('No problems.'),
                default => __('No incident reported. Lowest uptime :pct.', ['pct' => Uptime::format($day['pct'])]),
            };

            return ['label' => $label, 'text' => $text, 'day' => $day['day']];
        }, $overall);
    }

    /** Times on a strip: just the clock, or the date as well when the ends fall on different days. */
    private static function clock(CarbonInterface $time, CarbonInterface $other): string
    {
        return $time->isSameDay($other) ? $time->format('H:i') : $time->translatedFormat('j M H:i');
    }
}
