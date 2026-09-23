<?php

namespace App\Models;

use App\Casts\LocalTime;
use App\Models\Concerns\BelongsToStatusPage;
use App\Models\Concerns\LocalTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One announcement mail owed to one subscriber about one maintenance window.
 *
 * @property int $status_page_id
 */
class MaintenanceNotification extends Model
{
    use BelongsToStatusPage, LocalTimestamps;

    protected $guarded = [];

    protected $casts = [
        'sent_at' => LocalTime::class,
        'created_at' => LocalTime::class,
        'updated_at' => LocalTime::class,
    ];

    /** @return BelongsTo<Subscriber, $this> */
    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class);
    }

    /** @return BelongsTo<Maintenance, $this> */
    public function maintenance(): BelongsTo
    {
        return $this->belongsTo(Maintenance::class);
    }

    public function scopeDue(Builder $query, int $maxAttempts): Builder
    {
        return $query->whereNull('sent_at')->where('attempts', '<', $maxAttempts);
    }
}
