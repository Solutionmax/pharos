<?php

namespace App\Console\Commands;

use App\Models\Check;
use App\Models\Setting;
use App\Services\CheckRunner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class RunChecks extends Command
{
    protected $signature = 'pharos:check {--force : Run every enabled check, ignoring its interval}';

    protected $description = 'Run all due checks and update component status';

    public function handle(CheckRunner $runner): int
    {
        // Two runners at once open two incidents for one outage, and fire two
        // Slack messages. The scheduler guards itself, but a hand-run
        // `pharos:check` races it — which is exactly how this was found.
        // ponytail: one lock for the whole run, not per check. Go per check only
        // if a single slow probe starts holding up the rest.
        $lock = Cache::lock('pharos:checks', 300);

        if (! $lock->get()) {
            $this->warn('Another check run is still going; skipped.');

            return self::SUCCESS;
        }

        try {
            return $this->runAll($runner);
        } finally {
            $lock->release();
        }
    }

    protected function runAll(CheckRunner $runner): int
    {
        // Stamped on every run, due checks or not: this is the only evidence that
        // the one cron line exists. Without it a forgotten scheduler looks exactly
        // like a healthy install — every component green, nothing ever checked.
        Setting::put('checks.last_run_at', now()->toIso8601String());

        if ($this->option('force')) {
            $checks = Check::with('component')->where('enabled', true)->get();
            foreach ($checks as $check) {
                $result = $runner->runOne($check);
                $this->line(sprintf(
                    '  %-28s %s  %s',
                    $check->component->name,
                    $result->ok ? '<fg=green>up  </>' : '<fg=red>down</>',
                    $result->message ?? '',
                ));
            }

            $this->info("Ran {$checks->count()} checks.");

            return self::SUCCESS;
        }

        $ran = $runner->runDue();
        $this->info("Ran {$ran} due checks.");

        return self::SUCCESS;
    }
}
