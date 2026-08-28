<?php

namespace App\Console\Commands;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class MakeUser extends Command
{
    protected $signature = 'pharos:user {email} {--name=Admin} {--password=} {--role=}';

    protected $description = 'Create or update an admin user';

    public function handle(): int
    {
        $password = $this->option('password') ?: \Illuminate\Support\Str::random(16);

        $role = $this->option('role');

        if ($role !== null && UserRole::tryFrom($role) === null) {
            $this->error('Unknown role. Use admin or user.');

            return self::FAILURE;
        }

        // The way back in when the last administrator was demoted or lost.
        $attributes = ['name' => $this->option('name'), 'password' => Hash::make($password)];

        if ($role !== null) {
            $attributes['role'] = UserRole::from($role);
        }

        $user = User::updateOrCreate(['email' => $this->argument('email')], $attributes);

        $this->info("User {$user->email} ready as {$user->role->label()}.");

        if (! $this->option('password')) {
            $this->line('');
            $this->line("  password: {$password}");
            $this->line('');
            $this->warn('Shown once. It is stored hashed.');
        }

        return self::SUCCESS;
    }
}
