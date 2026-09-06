<?php

namespace App\Console\Commands;

use App\Services\CronSetup;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;

class InstallCron extends Command
{
    protected $signature = 'pharos:cron {--install : Add the task to this user\'s crontab}';

    protected $description = 'Show or install the scheduler task without replacing other tasks';

    public function handle(CronSetup $cron): int
    {
        try {
            if ($this->option('install')) {
                $this->info(Cache::lock('pharos:cron-install', 60)->block(5, fn () => $cron->install()));
            } else {
                $this->line('* * * * * '.$cron->command());
            }
        } catch (\Throwable $e) {
            $this->error($e instanceof \RuntimeException ? $e->getMessage() : 'Cron setup unavailable. Use your hosting panel.');

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
