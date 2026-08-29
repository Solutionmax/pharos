<?php

namespace App\Models;

use App\Enums\ComponentStatus;
use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ComponentGroup extends Model
{
    use Auditable;

    protected $guarded = [];

    protected $attributes = ['collapsed' => true, 'visible' => true, 'position' => 0];

    protected $casts = ['collapsed' => 'boolean', 'visible' => 'boolean'];

    /** @return HasMany<Component, $this> */
    public function components(): HasMany
    {
        return $this->hasMany(Component::class)->orderBy('position');
    }

    /** A group is only as healthy as its worst member. */
    public function status(): ComponentStatus
    {
        // Compare the enum values, not the enum objects — objects have no order.
        $worst = $this->components->where('enabled', true)->max(fn ($c) => $c->status->value) ?? 1;

        return ComponentStatus::from((int) $worst);
    }
}
