<?php

namespace App\Console\Commands;

use App\Models\BackupDestination;
use App\Services\RemoteBackup;
use Illuminate\Console\Command;

class RunRemoteBackups extends Command
{
    protected $signature = 'pharos:backup-remote';

    protected $description = 'Deliver and verify backups to configured remote destinations';

    public function handle(RemoteBackup $backup): int
    {
        $failed = false;
        foreach (BackupDestination::where('enabled', true)->get() as $destination) {
            if (! $backup->run($destination)) {
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
