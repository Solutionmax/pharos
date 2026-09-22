<?php

namespace App\Console\Commands;

use App\Models\ApiToken;
use App\Models\StatusPage;
use App\Models\User;
use Illuminate\Console\Command;

class IssueToken extends Command
{
    protected $signature = 'pharos:token {name : What this token is for, e.g. "n8n"} {--user= : Owner email address} {--page= : Status page ID} {--scope=write : Token scope: read or write}';

    protected $description = 'Issue an API token (shown once, stored hashed)';

    public function handle(): int
    {
        $scope = $this->option('scope');
        if (! in_array($scope, ['read', 'write'], true)) {
            $this->error('Scope must be read or write.');

            return self::FAILURE;
        }
        $user = $this->option('user') ? User::where('email', $this->option('user'))->first() : null;
        $page = $this->option('page') ? StatusPage::find($this->option('page')) : null;
        if (! $user || ! $page || $page->archived_at || ! $user->canAccessPage($page->id) || ($scope === 'write' && ! $user->canEditPage($page->id))) {
            $this->error('Provide --user=email and --page=id for an owner with access to that active page.');

            return self::FAILURE;
        }
        [$token, $plain] = ApiToken::issue($this->argument('name'), $user, $page->id, $scope);

        $this->info("Token '{$token->name}' created.");
        $this->line('');
        $this->line("  {$plain}");
        $this->line('');
        $this->warn('Copy it now. Only a SHA-256 hash is stored, so it cannot be shown again.');

        return self::SUCCESS;
    }
}
