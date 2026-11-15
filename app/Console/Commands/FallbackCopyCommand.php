<?php

namespace App\Console\Commands;

use App\Services\FallbackCopy;
use App\Support\Bytes;
use Illuminate\Console\Command;

class FallbackCopyCommand extends Command
{
    protected $signature = 'pharos:fallback-copy {--force : Upload every page, also the ones that did not change}';

    protected $description = 'Upload a static copy of every published status page to the fallback destination';

    public function handle(FallbackCopy $copy): int
    {
        if (! FallbackCopy::enabled()) {
            $this->warn(__('The fallback copy is off. Switch it on under Settings, General.'));

            return self::SUCCESS;
        }

        $result = $copy->run((bool) $this->option('force'));
        foreach ($result['uploaded'] as $path => $bytes) {
            $this->line('  '.$path.'  '.Bytes::human($bytes));
        }
        if ($result['error'] !== null) {
            $this->error($result['error']);

            return self::FAILURE;
        }
        if ($result['uploaded'] === []) {
            $this->info(__('Nothing changed; nothing uploaded.'));
        }

        return self::SUCCESS;
    }
}
