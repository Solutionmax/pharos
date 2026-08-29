<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class RecoveryCode extends Model
{
    protected $fillable = ['user_id', 'code_hash', 'used_at'];

    protected function casts(): array
    {
        return ['used_at' => 'datetime'];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public static function hash(string $code): string
    {
        return hash('sha256', strtolower(str_replace('-', '', trim($code))));
    }

    /**
     * Replaces the whole set and returns the plaintext codes, which is the only
     * moment they exist: after this only hashes are stored.
     *
     * @return array<int, string>
     */
    public static function replaceFor(User $user, int $count = 10): array
    {
        $user->recoveryCodes()->delete();

        $codes = [];

        for ($i = 0; $i < $count; $i++) {
            $code = strtolower(Str::random(5).'-'.Str::random(5));
            $codes[] = $code;
            $user->recoveryCodes()->create(['code_hash' => static::hash($code)]);
        }

        return $codes;
    }

    /** True once, per code: the second attempt with the same code finds nothing. */
    public static function consume(User $user, string $code): bool
    {
        return $user->recoveryCodes()
            ->whereNull('used_at')
            ->where('code_hash', static::hash($code))
            ->limit(1)
            ->update(['used_at' => now()]) === 1;
    }
}
