<?php

namespace App\Models;

use App\Enums\IncidentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncidentUpdate extends Model
{
    protected $guarded = [];

    protected $casts = ['status' => IncidentStatus::class, 'automatic' => 'boolean'];

    public function incident(): BelongsTo
    {
        return $this->belongsTo(Incident::class);
    }
}
