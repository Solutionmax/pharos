<?php

namespace App\Models;

use App\Casts\LocalTime;
use App\Models\Concerns\LocalTimestamps;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** One mail owed to one subscriber about one incident update. */
class SubscriberNotification extends Model
{
    use LocalTimestamps;

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

    /** @return BelongsTo<IncidentUpdate, $this> */
    public function incidentUpdate(): BelongsTo
    {
        return $this->belongsTo(IncidentUpdate::class, 'incident_update_id');
    }

    /** Still owed: never sent, and not yet given up on. */
    public function scopeDue(Builder $query, int $maxAttempts): Builder
    {
        return $query->whereNull('sent_at')->where('attempts', '<', $maxAttempts);
    }
}
