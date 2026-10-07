<?php

namespace App\Services;

use App\Enums\ComponentStatus;
use App\Models\Component;
use App\Models\Setting;
use App\Models\StatusPage;
use App\Models\UptimeDay;
use Illuminate\Support\Carbon;

/**
 * Uptime for components whose status something else writes. Pharos cannot probe
 * them, but it knows what it was told: every minute the time since the last tick
 * counts as up or down, according to the status they have right now. The check
 * runner does the same for components it probes itself; the two never meet
 * because a component with an enabled check is left out here.
 */
class ReportedUptime
{
    /** Setting that holds the previous tick, so a restart or a late cron does not lose or double the gap. */
    public const LAST_TICK = 'uptime.reported_last_tick';

    /**
     * The longest stretch one tick may credit. The scheduler runs every minute;
     * two minutes absorbs a late cron, and a stalled one cannot back fill hours
     * it did not observe.
     */
    public const MAX_CREDIT_SECONDS = 120;

    public function tick(?\DateTimeInterface $now = null): void
    {
        $now = Carbon::instance($now ?? now());
        $last = Setting::get(self::LAST_TICK);
        Setting::put(self::LAST_TICK, $now->toIso8601String());

        // The first tick only starts the clock: history starts when this ships.
        if ($last === null) {
            return;
        }

        // UTC days on purpose, like the check runner: the roll-up is a storage concept.
        $dayStart = $now->copy()->startOfDay();
        $since = Carbon::parse($last)->max($dayStart);
        $seconds = (int) min(max(0, $since->diffInSeconds($now)), self::MAX_CREDIT_SECONDS);
        if ($seconds === 0) {
            return;
        }

        // The same pages pharos:check watches: an archived page is not monitored.
        foreach (StatusPage::query()->whereNull('archived_at')->orderBy('id')->pluck('id') as $pageId) {
            app(PageContext::class)->run($pageId, fn () => $this->credit($dayStart, $seconds));
        }
    }

    protected function credit(Carbon $dayStart, int $seconds): void
    {
        foreach (Component::query()->where('enabled', true)->setFromOutside()->get() as $component) {
            if ($component->status === ComponentStatus::UnderMaintenance) {
                continue;
            }

            $day = UptimeDay::firstOrCreate(
                ['component_id' => $component->id, 'day' => $dayStart],
                ['up_seconds' => 0, 'down_seconds' => 0, 'worst_status' => ComponentStatus::Operational->value],
            );

            if (! $component->status->isDown()) {
                $day->increment('up_seconds', $seconds);

                continue;
            }

            $day->increment('down_seconds', $seconds);
            if ($component->status->value > $day->worst_status->value) {
                $day->worst_status = $component->status;
                $day->save();
            }
        }
    }
}
