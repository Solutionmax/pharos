<?php

namespace App\Console\Commands;

use App\Models\BackupDestination;
use App\Services\FallbackCopy;
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
            // Where the public fallback copy lives may be readable by anyone: skipped, not failed.
            if (FallbackCopy::holds($destination)) {
                $this->line(__('Skipped :name: used for the fallback copy. Backups do not go here.', ['name' => $destination->name]));

                continue;
            }
            if (! $backup->run($destination)) {
                $failed = true;
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
