<?php

namespace App\Models;

use App\Enums\Impact;
use App\Enums\IncidentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Incident extends Model
{
    protected $guarded = [];

    protected $casts = [
        'status' => IncidentStatus::class,
        'impact' => Impact::class,
        'pinned' => 'boolean',
        'auto_resolve' => 'boolean',
        'occurred_at' => 'datetime',
        'resolved_at' => 'datetime',
    ];

    public function updates(): HasMany
    {
        return $this->hasMany(IncidentUpdate::class)->orderByDesc('created_at');
    }

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
