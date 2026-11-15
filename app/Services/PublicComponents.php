<?php

namespace App\Services;

use App\Models\Component;
use Illuminate\Database\Eloquent\Builder;

/** One public visibility boundary shared by badges, widget, subscriptions and reports. */
class PublicComponents
{
    /** @return Builder<Component> */
    public static function query(): Builder
    {
        return Component::query()->where('enabled', true)
            ->where(fn ($q) => $q->whereNull('component_group_id')
                ->orWhereHas('group', fn ($group) => $group->where('visible', true)));
    }

    /**
     * The visibility of an incident that a machine opens (a failing check, a
     * missed heartbeat, an integration that did not say). Public only when it
     * touches a service the public page shows: an outage of a service in a
     * hidden group, or of a switched off one, stays with the team. Without any
     * service there is nothing to judge by, so it stays public as before.
     *
     * @param  array<int, int>  $componentIds
     */
    public static function automaticVisibility(array $componentIds): string
    {
        if ($componentIds === []) {
            return 'public';
        }

        return self::query()->whereKey($componentIds)->exists() ? 'public' : 'internal';
    }
}
