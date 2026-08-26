<?php

namespace App\Models;

use App\Enums\ComponentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComponentGroup extends Model
{
    protected $guarded = [];

    protected $attributes = ['collapsed' => true, 'visible' => true, 'position' => 0];

    protected $casts = ['collapsed' => 'boolean', 'visible' => 'boolean'];

    public function components(): HasMany
    {
        return $this->hasMany(Component::class)->orderBy('position');
    }

    /** A group is only as healthy as its worst member. */
    public function status(): ComponentStatus
    {
        $worst = $this->components->where('enabled', true)->max('status') ?? 1;

        return ComponentStatus::from($worst instanceof ComponentStatus ? $worst->value : (int) $worst);
    }
}
