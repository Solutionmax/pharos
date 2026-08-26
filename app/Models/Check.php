<?php

namespace App\Models;

use App\Enums\CheckType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Check extends Model
{
    protected $guarded = [];

    protected $attributes = [
        'interval_seconds' => 60,
        'retries' => 2,
        'timeout_seconds' => 10,
        'consecutive_failures' => 0,
        'consecutive_successes' => 0,
        'enabled' => true,
    ];

    protected $casts = [
        'type' => CheckType::class,
        'enabled' => 'boolean',
        'last_run_at' => 'datetime',
    ];

    public function component(): BelongsTo
    {
        return $this->belongsTo(Component::class);
    }

    /** Due when it has never run, or the interval has elapsed. */
    public function isDue(?\DateTimeInterface $now = null): bool
    {
        if (! $this->enabled) {
            return false;
        }
        if ($this->last_run_at === null) {
            return true;
        }
        $now ??= now();

        return $this->last_run_at->copy()->addSeconds($this->interval_seconds) <= $now;
    }
}
