<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStatusPage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class ProbeLocation extends Model
{
    use BelongsToStatusPage;

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected $casts = ['enabled' => 'boolean', 'last_seen_at' => 'datetime'];

    /** @return BelongsToMany<Check, $this> */
    public function checks(): BelongsToMany
    {
        return $this->belongsToMany(Check::class, 'check_probe_location');
    }
}
