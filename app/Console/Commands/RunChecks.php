<?php

namespace App\Console\Commands;

use App\Services\CheckRunner;
use Illuminate\Console\Command;

class RunChecks extends Command
{
    protected $signature = 'pharos:check {--force : Run every enabled check, ignoring its interval}';

    protected $description = 'Run all due checks and update component status';

    public function handle(CheckRunner $runner): int
    {
        if ($this->option('force')) {
            $checks = \App\Models\Check::with('component')->where('enabled', true)->get();
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
