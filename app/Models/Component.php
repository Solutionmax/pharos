<?php

namespace App\Models;

use App\Casts\LocalTime;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\LocalTimestamps;
use App\Enums\ComponentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Component extends Model
{
    use Auditable, LocalTimestamps;

    protected $guarded = [];

    protected $attributes = [
        'status' => 1,
        'enabled' => true,
        'show_uptime' => true,
        'source' => 'manual',
        'position' => 0,
    ];

    protected $casts = [
        'status' => ComponentStatus::class,
        'enabled' => 'boolean',
        'show_uptime' => 'boolean',
        'created_at' => LocalTime::class,
        'updated_at' => LocalTime::class,
    ];

    public function group(): BelongsTo
    {
        return $this->belongsTo(ComponentGroup::class, 'component_group_id');
    }

    public function check(): HasOne
    {
        return $this->hasOne(Check::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(CheckResult::class);
    }

    public function uptimeDays(): HasMany
    {
        return $this->hasMany(UptimeDay::class);
    }

    public function incidents(): BelongsToMany
    {
        return $this->belongsToMany(Incident::class)->withPivot('status');
    }

    public function tagList(): array
    {
        return array_values(array_filter(array_map('trim', explode(',', (string) $this->tags))));
    }
}
