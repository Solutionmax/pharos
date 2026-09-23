<?php

namespace App\Services;

use App\Mail\MaintenanceNoticeMail;
use App\Models\Maintenance;
use App\Models\MaintenanceNotification;
use App\Models\Subscriber;

/**
 * The maintenance announcement, in the same two halves as incident mail: the
 * scheduler queues one row per active subscriber, pharos:notify sends them.
 */
class MaintenanceNotifier
{
    public function queue(Maintenance $maintenance): int
    {
        $queued = 0;
        $now = now();
        $pageId = app(PageContext::class)->id();

        Subscriber::active()->select('id')->chunkById(500, function ($subscribers) use ($maintenance, $now, $pageId, &$queued) {
            $queued += MaintenanceNotification::insertOrIgnore($subscribers->map(fn ($s) => [
                'status_page_id' => $pageId,
                'subscriber_id' => $s->id,
                'maintenance_id' => $maintenance->id,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all());
        });

        return $queued;
    }

    /** @return array{0: int, 1: int} sent, failed */
    public function sendPending(): array
    {
        $sent = 0;
        $failed = 0;
        $due = MaintenanceNotification::withoutGlobalScope('status_page')->due(SubscriberNotifier::MAX_ATTEMPTS)
            ->orderBy('id')->limit(SubscriberNotifier::BATCH_SIZE)->get();

        foreach ($due as $row) {
            $ok = app(PageContext::class)->run($row->status_page_id, function () use ($row) {
                $owned = MaintenanceNotification::with(['subscriber', 'maintenance.components'])->find($row->id);

                return $owned ? $this->send($owned) : false;
            });
            $ok ? $sent++ : $failed++;
        }

        return [$sent, $failed];
    }

    protected function send(MaintenanceNotification $row): bool
    {
        $page = app(PageContext::class)->page();
        $maintenance = $row->maintenance;
        if (! $row->subscriber?->isActive() || $maintenance === null || $maintenance->cancelled_at !== null
            || $maintenance->ends_at->lte(now()) || ! $page->is_published || $page->archived_at !== null) {
            $row->forceFill(['attempts' => SubscriberNotifier::MAX_ATTEMPTS, 'error' => 'Skipped: maintenance is no longer deliverable'])->save();

            return false;
        }

        try {
            app(MailConfig::class)->sendTo($row->subscriber->email, new MaintenanceNoticeMail($maintenance, $row->subscriber));
            $row->forceFill(['sent_at' => now(), 'error' => null, 'attempts' => $row->attempts + 1])->save();

            return true;
        } catch (\Throwable $e) {
            $row->forceFill(['attempts' => $row->attempts + 1, 'error' => mb_substr($e->getMessage(), 0, 500)])->save();

            return false;
        }
    }
}
