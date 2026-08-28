<?php

namespace App\Services;

use App\Mail\IncidentNoticeMail;
use App\Models\IncidentUpdate;
use App\Models\Subscriber;
use App\Models\SubscriberNotification;
use Illuminate\Support\Facades\Mail;

/**
 * Subscriber mail in two halves, deliberately apart: an incident update *queues*
 * one row per active subscriber and returns at once, and pharos:notify *sends*
 * from that outbox a minute later. Nothing in the request that posts the update
 * ever talks to an SMTP server.
 */
class SubscriberNotifier
{
    /** Mails per pharos:notify run. Sized for shared hosting's per-minute limits. */
    public const BATCH_SIZE = 50;

    /** After this many failures a row is left alone; the error stays on it. */
    public const MAX_ATTEMPTS = 3;

    /** Rows inserted per query while queueing a large list. */
    protected const INSERT_CHUNK = 500;

    /** Returns how many subscribers were queued. */
    public function queue(IncidentUpdate $update): int
    {
        // Silent on purpose: an internal or authenticated incident is not the
        // subscribers' business, and neither is an incident nobody published.
        if ($update->incident?->visibility !== 'public') {
            return 0;
        }

        // Master switch off: queue nothing new. sendPending() is deliberately
        // not guarded, so rows queued before the flip still go out.
        if (! Subscriptions::enabled()) {
            return 0;
        }

        $queued = 0;
        $now = now();

        Subscriber::active()->select('id')->chunkById(self::INSERT_CHUNK, function ($subscribers) use ($update, $now, &$queued) {
            $rows = $subscribers->map(fn ($s) => [
                'subscriber_id' => $s->id,
                'incident_update_id' => $update->id,
                'created_at' => $now,
                'updated_at' => $now,
            ])->all();

            // insertOrIgnore: a re-fired event must not double a row the unique
            // index already holds.
            $queued += SubscriberNotification::insertOrIgnore($rows);
        });

        return $queued;
    }

    /** Sends one batch from the outbox. Returns [sent, failed]. */
    public function sendPending(): array
    {
        $sent = 0;
        $failed = 0;

        $due = SubscriberNotification::due(self::MAX_ATTEMPTS)
            ->with(['subscriber', 'incidentUpdate.incident.components'])
            ->orderBy('id')
            ->limit(self::BATCH_SIZE)
            ->get();

        foreach ($due as $notification) {
            $this->send($notification) ? $sent++ : $failed++;
        }

        return [$sent, $failed];
    }

    protected function send(SubscriberNotification $notification): bool
    {
        $subscriber = $notification->subscriber;
        $update = $notification->incidentUpdate;

        // Unsubscribed between queueing and sending, or the update was deleted:
        // close the row rather than retry it three times.
        if (! $subscriber?->isActive() || $update?->incident === null) {
            $notification->forceFill([
                'attempts' => self::MAX_ATTEMPTS,
                'error' => 'Skipped: subscriber no longer active or update gone',
            ])->save();

            return false;
        }

        try {
            Mail::to($subscriber->email)->send(new IncidentNoticeMail($update, $subscriber));

            $notification->forceFill([
                'sent_at' => now(),
                'error' => null,
                'attempts' => $notification->attempts + 1,
            ])->save();

            return true;
        } catch (\Throwable $e) {
            $notification->forceFill([
                'attempts' => $notification->attempts + 1,
                'error' => mb_substr($e->getMessage(), 0, 500),
            ])->save();

            return false;
        }
    }

    /** Addresses that never clicked their confirmation are not kept. GDPR minimalism, and hygiene. */
    public function prunePending(): int
    {
        // updated_at, not created_at: a fresh confirmation mail restarts the clock.
        return Subscriber::pending()
            ->where('updated_at', '<', now()->subDays(Subscriber::PENDING_DAYS))
            ->delete();
    }
}
