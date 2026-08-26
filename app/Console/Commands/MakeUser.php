<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class MakeUser extends Command
{
    protected $signature = 'pharos:user {email} {--name=Admin} {--password=}';

    protected $description = 'Create or update an admin user';

    public function handle(): int
    {
        $password = $this->option('password') ?: \Illuminate\Support\Str::random(16);

        $user = User::updateOrCreate(
            ['email' => $this->argument('email')],
            ['name' => $this->option('name'), 'password' => Hash::make($password)],
        );

        $this->info("User {$user->email} ready.");

        if (! $this->option('password')) {
            $this->line('');
            $this->line("  password: {$password}");
            $this->line('');
            $this->warn('Shown once. It is stored hashed.');
        }

        return self::SUCCESS;
    }
}
