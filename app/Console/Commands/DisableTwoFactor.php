<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

/**
 * The way back in when the phone with the authenticator is gone and the recovery
 * codes went with it. Vendor-free, offline, and the only door that bypasses 2FA —
 * which is why it needs shell access to the server to use.
 */
class DisableTwoFactor extends Command
{
    protected $signature = 'pharos:2fa:disable {email : Whose second factor to switch off}';

    protected $description = 'Switch off two-factor authentication for one account';

    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->error("No account with the email {$this->argument('email')}.");

            return self::FAILURE;
        }

        $user->forceFill(['totp_secret' => null, 'totp_confirmed_at' => null, 'totp_last_step' => null])->save();
        $user->recoveryCodes()->delete();

        $this->info("Two-factor authentication is off for {$user->email}.");
        $this->warn('Set it up again from the profile screen as soon as you are back in.');

        return self::SUCCESS;
    }
}
