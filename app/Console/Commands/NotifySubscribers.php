<?php

namespace App\Console\Commands;

use App\Services\SubscriberNotifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class NotifySubscribers extends Command
{
    protected $signature = 'pharos:notify';

    protected $description = 'Send queued incident e-mails to subscribers and forget stale sign-ups';

    public function handle(SubscriberNotifier $notifier): int
    {
        // Same guard as pharos:check: two senders on one outbox means one
        // person gets the same mail twice.
        $lock = Cache::lock('pharos:notify', 300);

        if (! $lock->get()) {
            $this->warn('Another notify run is still going; skipped.');

            return self::SUCCESS;
        }

        try {
            [$sent, $failed] = $notifier->sendPending();
            $pruned = $notifier->prunePending();

            $this->info("Sent {$sent}, failed {$failed}, forgot {$pruned} unconfirmed.");

            return self::SUCCESS;
        } finally {
            $lock->release();
        }
    }
}
