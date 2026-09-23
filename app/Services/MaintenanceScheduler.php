<?php

namespace App\Services;

use App\Enums\ComponentStatus;
use App\Models\Component;
use App\Models\Maintenance;
use App\Models\StatusPage;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Moves maintenance windows along: announce, start, complete. Every step claims
 * its own timestamp with a conditional update first, so a run that is late, or
 * one that overlaps another, finds the step already taken and does nothing.
 */
class MaintenanceScheduler
{
    public function __construct(protected OutgoingWebhook $webhook, protected MaintenanceNotifier $notifier) {}

    /** @return array{announced: int, started: int, completed: int} */
    public function run(): array
    {
        $counts = ['announced' => 0, 'started' => 0, 'completed' => 0];
        $lock = Cache::lock('pharos:maintenance', 300);
        if (! $lock->get()) {
            return $counts;
        }

        try {
            foreach (StatusPage::query()->whereNull('archived_at')->orderBy('id')->pluck('id') as $pageId) {
                app(PageContext::class)->run($pageId, function () use (&$counts) {
                    foreach (Maintenance::whereNull('cancelled_at')->whereNull('completed_at')->orderBy('starts_at')->get() as $maintenance) {
                        $this->advance($maintenance, $counts);
                    }
                });
            }
        } finally {
            $lock->release();
        }

        return $counts;
    }

    /** @param array{announced: int, started: int, completed: int} $counts */
    protected function advance(Maintenance $maintenance, array &$counts): void
    {
        $now = now();

        // Over before it was ever started (the scheduler was down): close it quietly.
        // Setting components to maintenance for a window that already ended would only lie.
        if ($maintenance->ends_at->lte($now)) {
            if ($maintenance->started_at === null) {
                $this->claim($maintenance, 'completed_at');

                return;
            }
            if ($this->complete($maintenance)) {
                $counts['completed']++;
            }

            return;
        }

        $announceFrom = $maintenance->starts_at->subMinutes($maintenance->announce_minutes);
        if ($maintenance->announce_minutes > 0 && $maintenance->announced_at === null && $announceFrom->lte($now) && $this->announce($maintenance)) {
            $counts['announced']++;
        }

        if ($maintenance->started_at === null && $maintenance->starts_at->lte($now) && $this->start($maintenance)) {
            $counts['started']++;
        }
    }

    public function announce(Maintenance $maintenance): bool
    {
        if (! $this->claim($maintenance, 'announced_at')) {
            return false;
        }
        $page = app(PageContext::class)->page();
        if ($page->is_published && $page->archived_at === null && Subscriptions::enabled()) {
            $this->notifier->queue($maintenance);
        }
        $this->webhook->maintenanceChanged($maintenance->fresh('components'), 'maintenance.scheduled');

        return true;
    }

    public function start(Maintenance $maintenance): bool
    {
        $started = DB::transaction(function () use ($maintenance) {
            if (! $this->claim($maintenance, 'started_at')) {
                return false;
            }
            foreach ($maintenance->components()->get() as $component) {
                $maintenance->components()->updateExistingPivot($component->id, [
                    'previous_status' => $this->statusBefore($component, $maintenance),
                    'applied_status' => ComponentStatus::UnderMaintenance->value,
                ]);
                if ($component->status !== ComponentStatus::UnderMaintenance) {
                    $component->update(['status' => ComponentStatus::UnderMaintenance]);
                }
            }

            return true;
        });
        if ($started) {
            $this->webhook->maintenanceChanged($maintenance->fresh('components'), 'maintenance.started');
        }

        return $started;
    }

    public function complete(Maintenance $maintenance): bool
    {
        $done = DB::transaction(function () use ($maintenance) {
            if (! $this->claim($maintenance, 'completed_at')) {
                return false;
            }
            $this->restore($maintenance);

            return true;
        });
        if ($done) {
            $this->webhook->maintenanceChanged($maintenance->fresh('components'), 'maintenance.completed');
        }

        return $done;
    }

    /** False when it was already over or cancelled; nothing changes then. */
    public function cancel(Maintenance $maintenance): bool
    {
        $fresh = $maintenance->fresh();
        if ($fresh === null || $fresh->completed_at !== null || $fresh->ends_at->lte(now())) {
            return false;
        }
        $cancelled = DB::transaction(function () use ($fresh) {
            if (! $this->claim($fresh, 'cancelled_at')) {
                return false;
            }
            if ($fresh->started_at !== null) {
                $this->restore($fresh);
            }

            return true;
        });
        // Only worth telling if anyone was told it would happen.
        if ($cancelled && ($fresh->announced_at !== null || $fresh->started_at !== null)) {
            $this->webhook->maintenanceChanged($fresh->fresh('components'), 'maintenance.cancelled');
        }

        return $cancelled;
    }

    /**
     * Puts each component back, unless someone changed it during the window or
     * another running window still holds it.
     */
    protected function restore(Maintenance $maintenance): void
    {
        foreach ($maintenance->components()->get() as $component) {
            $pivot = $component->getRelation('pivot');
            $applied = $pivot->getAttribute('applied_status');
            $previous = $pivot->getAttribute('previous_status');
            if ($applied === null || $previous === null || $component->status->value !== (int) $applied) {
                continue;
            }
            if ($this->heldElsewhere($component, $maintenance)) {
                continue;
            }
            $component->update(['status' => ComponentStatus::from((int) $previous)]);
        }
    }

    /** With overlapping windows the status to go back to is the one before the first. */
    protected function statusBefore(Component $component, Maintenance $maintenance): int
    {
        if ($component->status === ComponentStatus::UnderMaintenance) {
            $other = $this->otherRunning($component, $maintenance)->first();
            $previous = $other?->components->firstWhere('id', $component->id)?->getRelation('pivot')->getAttribute('previous_status');
            if ($previous !== null) {
                return (int) $previous;
            }
        }

        return $component->status->value;
    }

    protected function heldElsewhere(Component $component, Maintenance $maintenance): bool
    {
        return $this->otherRunning($component, $maintenance)->isNotEmpty();
    }

    /** @return Collection<int, Maintenance> */
    protected function otherRunning(Component $component, Maintenance $maintenance)
    {
        return Maintenance::with('components')->whereKeyNot($maintenance->id)
            ->whereNotNull('started_at')->whereNull('completed_at')->whereNull('cancelled_at')
            ->whereHas('components', fn ($q) => $q->whereKey($component->id))->get();
    }

    /** One conditional update: whoever gets the row count of one owns the step. */
    protected function claim(Maintenance $maintenance, string $column): bool
    {
        $claimed = Maintenance::whereKey($maintenance->id)->whereNull($column)->whereNull('cancelled_at')
            ->update([$column => now()->utc()->format('Y-m-d H:i:s')]) === 1;
        if ($claimed) {
            $maintenance->refresh();
        }

        return $claimed;
    }
}
