<?php

namespace App\Services;

use App\Models\Component;
use Illuminate\Database\Eloquent\Builder;

/** One public visibility boundary shared by badges, widget, subscriptions and reports. */
class PublicComponents
{
    public static function query(): Builder
    {
        return Component::query()->where('enabled', true)
            ->where(fn ($q) => $q->whereNull('component_group_id')
                ->orWhereHas('group', fn ($group) => $group->where('visible', true)));
    }
}
