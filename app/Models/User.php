<?php

namespace App\Models;

use App\Casts\LocalTime;
use App\Enums\UserRole;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\LocalTimestamps;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use Auditable, LocalTimestamps;

    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role'];

    protected $auditIgnore = ['totp_secret', 'totp_last_step', 'totp_confirmed_at', 'dismissed_notes'];

    protected $hidden = ['password', 'remember_token'];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'role' => UserRole::class,
            'totp_secret' => 'encrypted',
            'totp_confirmed_at' => 'datetime',
            'dismissed_notes' => 'array',
            'created_at' => LocalTime::class,
            'updated_at' => LocalTime::class,
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

    // ---------- "Good to know" notes ----------

    public function hasDismissed(string $id): bool
    {
        return in_array($id, $this->dismissed_notes ?? [], true);
    }

    public function dismissNote(string $id): void
    {
        if ($this->hasDismissed($id)) {
            return;
        }

        $this->forceFill(['dismissed_notes' => [...($this->dismissed_notes ?? []), $id]])->save();
    }

    public function restoreNote(string $id): void
    {
        $left = array_values(array_filter($this->dismissed_notes ?? [], fn ($n) => $n !== $id));
        $this->forceFill(['dismissed_notes' => $left ?: null])->save();
    }

    public function restoreNotes(): void
    {
        $this->forceFill(['dismissed_notes' => null])->save();
    }

    /** A secret that was never confirmed with a real code does not count. */
    public function hasTwoFactor(): bool
    {
        return $this->totp_secret !== null && $this->totp_confirmed_at !== null;
    }
}
