<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PasskeyChallenge extends Model
{
    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected $casts = ['expires_at' => 'datetime', 'consumed_at' => 'datetime'];
}
