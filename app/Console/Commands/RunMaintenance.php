<?php

namespace App\Console\Commands;

use App\Services\MaintenanceScheduler;
use Illuminate\Console\Command;

class RunMaintenance extends Command
{
    protected $signature = 'pharos:maintenance';

    protected $description = 'Announce, start and complete scheduled maintenance on every page';

    public function handle(MaintenanceScheduler $scheduler): int
    {
        $counts = $scheduler->run();
        $this->info("Announced {$counts['announced']}, started {$counts['started']}, completed {$counts['completed']}.");

        return self::SUCCESS;
    }
}
