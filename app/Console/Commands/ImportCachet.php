<?php

namespace App\Console\Commands;

use App\Models\StatusPage;
use App\Services\CachetImporter;
use App\Services\PageContext;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class ImportCachet extends Command
{
    protected $signature = 'pharos:cachet-import {file : Local JSON export file} {--page= : Destination page ID} {--apply : Apply the validated import; without this flag only preview}';

    protected $description = 'Preview or transactionally import a Cachet 2.x JSON export without notifications';

    public function handle(CachetImporter $importer): int
    {
        $path = realpath($this->argument('file'));
        if (! $path || ! is_file($path) || ! is_readable($path) || filesize($path) > CachetImporter::MAX_BYTES) {
            $this->error('Choose a readable local file no larger than 4 MB.');

            return self::FAILURE;
        }
        $page = StatusPage::find($this->option('page') ?: StatusPage::defaultId());
        if (! $page || $page->archived_at) {
            $this->error('Choose an active destination page.');

            return self::FAILURE;
        }
        try {
            return app(PageContext::class)->run($page->id, function () use ($path, $importer) {
                $data = $importer->decode(file_get_contents($path));
                $preview = $importer->preview($data);
                $this->table(['Resource', 'Rows'], collect($preview['counts'])->map(fn ($count, $resource) => [$resource, $count])->all());
                if ($this->option('apply')) {
                    $importer->apply($data);
                    $this->info('Import completed. No notifications were sent.');
                } else {
                    $this->info('Dry run only. No data changed. Use --apply to import these records.');
                }

                return self::SUCCESS;
            });
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->error($field.': '.implode(' ', $messages));
            }

            return self::FAILURE;
        }
    }
}
