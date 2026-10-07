<?php

namespace App\Models;

use App\Models\Concerns\BelongsToStatusPage;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** @property int|null $owner_id Issuing user; null credentials are never authorized. */
class ProbeLocation extends Model
{
    use BelongsToStatusPage;

    protected $guarded = [];

    protected $hidden = ['token_hash'];

    protected $casts = ['owner_id' => 'integer', 'enabled' => 'boolean', 'last_seen_at' => 'datetime'];

    /** Always resolve current owner rights and lifecycle; no cached relation can authorize a credential. */
    public function canProbe(): bool
    {
        $page = StatusPage::find($this->status_page_id);

        return $this->enabled && $page !== null && $page->archived_at === null
            && $this->owner_id !== null && (User::find($this->owner_id)?->canAdministerPage($this->status_page_id) ?? false);
    }

    /** @return BelongsToMany<Check, $this> */
    public function checks(): BelongsToMany
    {
        return $this->belongsToMany(Check::class, 'check_probe_location');
    }
}
