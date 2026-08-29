<?php

namespace App\Models;

use App\Casts\LocalTime;
use App\Enums\Impact;
use App\Enums\IncidentStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\LocalTimestamps;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Incident extends Model
{
    use Auditable, LocalTimestamps;

    protected $guarded = [];

    protected $casts = [
        'status' => IncidentStatus::class,
        'impact' => Impact::class,
        'pinned' => 'boolean',
        'auto_resolve' => 'boolean',
        'occurred_at' => LocalTime::class,
        'resolved_at' => LocalTime::class,
        'created_at' => LocalTime::class,
        'updated_at' => LocalTime::class,
    ];

    /** @return HasMany<IncidentUpdate, $this> */
    public function updates(): HasMany
    {
        return $this->hasMany(IncidentUpdate::class)->orderByDesc('created_at');
    }

    /** @return BelongsToMany<Component, $this> */
    public function components(): BelongsToMany
    {
        return $this->belongsToMany(Component::class)->withPivot('status');
    }

    public function scopePublic($query)
    {
        return $query->where('visibility', 'public');
    }

    public function isOpen(): bool
    {
        return $this->status !== IncidentStatus::Resolved;
    }
}
