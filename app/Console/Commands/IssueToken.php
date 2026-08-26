<?php

namespace App\Console\Commands;

use App\Models\ApiToken;
use Illuminate\Console\Command;

class IssueToken extends Command
{
    protected $signature = 'pharos:token {name : What this token is for, e.g. "n8n"}';

    protected $description = 'Issue an API token (shown once, stored hashed)';

    public function handle(): int
    {
        [$token, $plain] = ApiToken::issue($this->argument('name'));

        $this->info("Token '{$token->name}' created.");
        $this->line('');
        $this->line("  {$plain}");
        $this->line('');
        $this->warn('Copy it now. Only a SHA-256 hash is stored, so it cannot be shown again.');

        return self::SUCCESS;
    }
}
