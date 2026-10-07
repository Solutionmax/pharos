<?php

namespace App\Models;

use App\Casts\LocalTime;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class CheckResult extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['ok' => 'boolean', 'checked_at' => LocalTime::class];

    /**
     * The last $limit runs of a component, oldest first — the order a strip
     * reads in. One query on the (component_id, checked_at) index; id breaks
     * the tie when two runs share a second.
     *
     * @return Collection<int, self>
     */
    public static function recentFor(Component|int $component, int $limit = 40): Collection
    {
        $id = $component instanceof Component ? $component->id : $component;

        return self::where('component_id', $id)
            ->orderByDesc('checked_at')->orderByDesc('id')
            ->limit($limit)->get()
            ->reverse()->values();
    }

    /**
     * Deletes results older than the retention window, except the newest one of
     * each component so a paused check keeps its last run. In batches, so the
     * first run on millions of rows never holds one long write lock. The ids are
     * read first and deleted second: MySQL refuses to delete from a table it is
     * also selecting from. The default batch of 500 keeps the delete under the 999
     * bound variables SQLite before 3.32 allows (RHEL 8, CloudLinux). Returns how many rows went.
     */
    public static function prune(?int $days = null, int $batch = 500): int
    {
        $cutoff = now()->subDays($days ?? (int) config('pharos.check_result_days'));
        $deleted = 0;

        do {
            $ids = self::query()
                ->where('checked_at', '<', $cutoff)
                ->whereExists(fn ($newer) => $newer->select(DB::raw(1))
                    ->from('check_results as newer')
                    ->whereColumn('newer.component_id', 'check_results.component_id')
                    ->where(fn ($later) => $later
                        ->whereColumn('newer.checked_at', '>', 'check_results.checked_at')
                        ->orWhere(fn ($tie) => $tie
                            ->whereColumn('newer.checked_at', 'check_results.checked_at')
                            ->whereColumn('newer.id', '>', 'check_results.id'))))
                ->limit($batch)->pluck('id');

            $deleted += self::whereKey($ids)->delete();
        } while ($ids->count() === $batch);

        return $deleted;
    }
}
