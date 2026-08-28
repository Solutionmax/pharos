<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

class Subscriber extends Model
{
    use Auditable;

    protected $guarded = [];

    protected $hidden = ['verify_token'];

    protected $casts = ['verified_at' => 'datetime'];

    public function scopeVerified($query)
    {
        return $query->whereNotNull('verified_at');
    }
}
