<?php

namespace App\Console\Commands;

use App\Models\ApiToken;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Console\Command;

class IssueToken extends Command
{
    protected $signature = 'pharos:token {name : What this token is for, e.g. "n8n"} {--user= : Owner email address} {--page= : Status page ID}';

    protected $description = 'Issue an API token (shown once, stored hashed)';

    public function handle(): int
    {
        $user = $this->option('user') ? User::where('email', $this->option('user'))->first() : null;
        $page = $this->option('page') ? StatusPage::find($this->option('page')) : null;
        if (! $user || ! $page || $page->archived_at || ! $user->canAccessPage($page->id)) {
            $this->error('Provide --user=email and --page=id for an owner with access to that active page.');

            return self::FAILURE;
        }
        [$token, $plain] = ApiToken::issue($this->argument('name'), $user, $page->id);

        $this->info("Token '{$token->name}' created.");
        $this->line('');
        $this->line("  {$plain}");
        $this->line('');
        $this->warn('Copy it now. Only a SHA-256 hash is stored, so it cannot be shown again.');

        return self::SUCCESS;
    }
}
