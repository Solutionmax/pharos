<?php

namespace App\Services;

use App\Enums\ComponentStatus;
use App\Models\Component;

/**
 * The worst live component status of every page, in one query, for places
 * that list several pages at once (the page selector, Status pages).
 * Disabled components do not count, as on the public page.
 */
class PageStatus
{
    /** @return array<int, ComponentStatus> keyed by page id; pages without components are absent */
    public static function worstByPage(): array
    {
        return Component::query()->withoutGlobalScope('status_page')
            ->where('enabled', true)
            ->groupBy('status_page_id')
            ->selectRaw('status_page_id, max(status) as worst')
            ->pluck('worst', 'status_page_id')
            ->map(fn ($worst) => ComponentStatus::from((int) $worst))
            ->all();
    }

    /** "Operational", "Major outage", or "No components" when nothing is measured. */
    public static function label(?ComponentStatus $status): string
    {
        return $status?->label() ?? 'No components';
    }
}
