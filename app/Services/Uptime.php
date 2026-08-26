<?php

namespace App\Services;

use App\Models\Component;
use App\Models\UptimeDay;
use Illuminate\Support\Carbon;

/**
 * Reads the daily roll-up rather than raw check results, so a 90-day bar costs
 * 90 rows per component instead of 129,600.
 */
class Uptime
{
    public const WINDOW_DAYS = 90;

    /**
     * @return array<int, array{day: string, tone: string, pct: float, known: bool}>
     *         Oldest first, always exactly WINDOW_DAYS entries.
     */
    public function bar(Component $component, ?Carbon $today = null): array
    {
        $today ??= Carbon::today();
        $start = $today->copy()->subDays(self::WINDOW_DAYS - 1);

        $rows = UptimeDay::where('component_id', $component->id)
            // Carbon bounds, not strings: the date cast stores "Y-m-d 00:00:00",
            // which sorts after a bare "Y-m-d" and silently dropped today.
            ->whereBetween('day', [$start->copy()->startOfDay(), $today->copy()->endOfDay()])
            ->get()
            ->keyBy(fn ($r) => $r->day->format('Y-m-d'));

        $bar = [];
        for ($i = 0; $i < self::WINDOW_DAYS; $i++) {
            $key = $start->copy()->addDays($i)->format('Y-m-d');
            $row = $rows->get($key);

            $bar[] = [
                'day' => $key,
                'known' => (bool) $row,
                'pct' => $row?->percentage() ?? 100.0,
                'tone' => $row ? $this->tone($row->percentage()) : 'unknown',
            ];
        }

        return $bar;
    }

    /** Uptime over the window as a percentage, days without data excluded. */
    public function percentage(Component $component, ?Carbon $today = null): float
    {
        $known = array_filter($this->bar($component, $today), fn ($d) => $d['known']);

        if ($known === []) {
            return 100.0;
        }

        return round(array_sum(array_column($known, 'pct')) / count($known), 2);
    }

    protected function tone(float $pct): string
    {
        return match (true) {
            $pct >= 99.99 => 'ok',
            $pct >= 99.0 => 'w',
            $pct >= 95.0 => 'p',
            default => 'b',
        };
    }
}
