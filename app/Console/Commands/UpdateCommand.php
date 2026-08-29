<?php

namespace App\Console\Commands;

use App\Services\SelfUpdater;
use App\Services\Updater;
use Illuminate\Console\Command;

class UpdateCommand extends Command
{
    protected $signature = 'pharos:update {--check : Only report what is available}';

    protected $description = 'Check for, and install, a signed update';

    public function handle(Updater $updater, SelfUpdater $selfUpdater): int
    {
        $this->line("Installed: <fg=cyan>{$updater->current()}</>");

        $latest = $updater->latest(fresh: true);

        if ($latest === null) {
            $this->warn('No verified release information available.');

            return self::SUCCESS;
        }

        $this->line("Available: <fg=cyan>{$latest['version']}</>");

        if (! $updater->updateAvailable()) {
            $this->info('Already up to date.');

            return self::SUCCESS;
        }

        if ($latest['notes'] !== '') {
            $this->line('');
            $this->line($latest['notes']);
            $this->line('');
        }

        if ($this->option('check')) {
            return self::SUCCESS;
        }

        if ($updater->managed()) {
            // The image belongs to the host, not to us.
            $this->warn('This install is managed from the outside. Update it with:');
            $this->line('  docker compose pull && docker compose up -d');

            return self::SUCCESS;
        }

        if (! $this->confirm("Install {$latest['version']} now?", true)) {
            return self::SUCCESS;
        }

        $result = $selfUpdater->apply($latest);

        $result['ok'] ? $this->info($result['message']) : $this->error($result['message']);

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
