<?php

namespace App\Services;

use App\Models\CheckResult;
use App\Models\Component;
use Illuminate\Support\Str;

/**
 * The beat strip on the component screen: the last runs of a check as one
 * sliver each, so a flapping target or a slow one is visible before it
 * becomes an incident.
 */
class CheckHistory
{
    public const LIMIT = 40;

    /** A run slower than 2× the median of the strip is amber — but never under this, so a 40 ms → 90 ms wobble stays green. */
    public const SLOW_FLOOR_MS = 1000;

    /** How much of an error fits in a tooltip. */
    public const ERROR_CHARS = 60;

    /**
     * @return array{limit:int,count:int,failed:int,median:int|null,beats:list<array{tone:'ok'|'w'|'b',tip:string}>,summary:string}
     */
    public static function strip(Component $component, int $limit = self::LIMIT): array
    {
        $runs = CheckResult::recentFor($component, $limit);

        // Slow = above 2× the median latency of these runs, with a floor of one
        // second. Relative, because 400 ms is fine for one target and an alarm
        // for another; floored, because doubling a tiny number means nothing.
        $latencies = $runs->pluck('latency_ms')->filter(fn ($ms) => $ms !== null)->sort()->values();
        $median = $latencies->isEmpty() ? null : (int) $latencies->median();
        $slowAbove = max(2 * ($median ?? 0), self::SLOW_FLOOR_MS);

        $beats = $runs->map(fn (CheckResult $run) => self::beat($run, $slowAbove))->values()->all();
        $failed = $runs->where('ok', false)->count();

        return [
            'limit' => $limit,
            'count' => $runs->count(),
            'failed' => $failed,
            'median' => $median,
            'beats' => $beats,
            'summary' => $runs->isEmpty() ? '' : self::summary($runs->last(), $runs->count(), $failed, $median),
        ];
    }

    /** @return array{tone:'ok'|'w'|'b',tip:string} */
    protected static function beat(CheckResult $run, int $slowAbove): array
    {
        $time = $run->checked_at->format('H:i:s'); // LocalTime cast: already in the customer's zone

        if (! $run->ok) {
            $error = trim((string) $run->message);

            return ['tone' => 'b', 'tip' => $time.' · failed'.($error !== '' ? ' · '.Str::limit($error, self::ERROR_CHARS) : '')];
        }

        $ms = $run->latency_ms;

        return [
            'tone' => $ms !== null && $ms > $slowAbove ? 'w' : 'ok',
            'tip' => $time.' · '.($ms === null ? 'ok' : number_format($ms).' ms'),
        ];
    }

    /** "Last run 2 minutes ago · 40/40 ok · median 91 ms" — or "3 failed" in the middle. */
    protected static function summary(CheckResult $last, int $count, int $failed, ?int $median): string
    {
        return implode(' · ', array_filter([
            'Last run '.$last->checked_at->diffForHumans(),
            $failed ? "$failed failed" : "$count/$count ok",
            $median === null ? null : 'median '.number_format($median).' ms',
        ]));
    }
}
