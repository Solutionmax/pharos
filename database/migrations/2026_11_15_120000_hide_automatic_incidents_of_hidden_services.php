<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Before 1.2.0 an incident opened by a check was always public, also for a
 * service in a hidden group or a switched off one: its name and outage showed
 * on the public page. New ones are internal in that case (CheckRunner); this
 * puts the existing ones right.
 *
 * Deliberately narrow: only incidents a check opened (a person or an API call
 * may have meant public), only while they are open or recent enough to still
 * show, and only when every service on them is outside the public page today.
 * A check incident could not be made public by hand, so nothing chosen on
 * purpose is undone.
 */
return new class extends Migration
{
    /** The span of the public page's chart and day notes (Uptime::WINDOW_DAYS). */
    private const RECENT_DAYS = 90;

    public function up(): void
    {
        DB::table('incidents')
            ->where('source', 'check')
            ->where('visibility', 'public')
            ->where(fn (Builder $q) => $q->whereNull('resolved_at')
                ->orWhere('occurred_at', '>=', now('UTC')->subDays(self::RECENT_DAYS)->format('Y-m-d H:i:s')))
            ->whereExists(fn (Builder $q) => $q->select(DB::raw(1))->from('component_incident as ci')
                ->whereColumn('ci.incident_id', 'incidents.id'))
            ->whereNotExists(fn (Builder $q) => $q->select(DB::raw(1))->from('component_incident as ci')
                ->join('components as c', 'c.id', '=', 'ci.component_id')
                ->whereColumn('ci.incident_id', 'incidents.id')
                // Same boundary as App\Services\PublicComponents.
                ->where('c.enabled', true)
                ->where(fn (Builder $public) => $public->whereNull('c.component_group_id')
                    ->orWhereExists(fn (Builder $group) => $group->select(DB::raw(1))->from('component_groups as g')
                        ->whereColumn('g.id', 'c.component_group_id')
                        ->where('g.visible', true))))
            ->update(['visibility' => 'internal']);
    }

    /** Which incidents were public before cannot be told apart afterwards, and they should not be. */
    public function down(): void {}
};
