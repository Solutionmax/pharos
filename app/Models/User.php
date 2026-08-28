<?php

namespace App\Models;

use App\Enums\UserRole;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Auditable;

    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role'];

    protected $auditIgnore = ['totp_secret', 'totp_last_step', 'totp_confirmed_at'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'totp_secret' => 'encrypted',
            'totp_confirmed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // The account that sets the install up owns it. Without this the setup
        // wizard would hand its own creator an account that cannot manage anything.
        static::creating(function (self $user) {
            $user->role ??= static::count() === 0 ? UserRole::Admin : UserRole::User;
        });
    }

    public function isAdmin(): bool
    {
        return $this->role === UserRole::Admin;
    }

    public function recoveryCodes(): HasMany
    {
        return $this->hasMany(RecoveryCode::class);
    }

    /** A secret that was never confirmed with a real code does not count. */
    public function hasTwoFactor(): bool
    {
        return $this->totp_secret !== null && $this->totp_confirmed_at !== null;
    }
}
