<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BackupDestination extends Model
{
    protected $guarded = [];

    protected $hidden = ['credentials', 'configuration'];

    protected $casts = ['configuration' => 'array', 'credentials' => 'encrypted:array', 'enabled' => 'boolean', 'last_attempt_at' => 'datetime', 'last_success_at' => 'datetime'];
}
