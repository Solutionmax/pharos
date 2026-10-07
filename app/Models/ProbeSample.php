<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProbeSample extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['ok' => 'boolean', 'checked_at' => 'datetime'];
}
