<?php

namespace App\Models;

use App\Casts\LocalTime;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

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
}
