<?php

namespace App\Models;

use App\Enums\ComponentStatus;
use Illuminate\Database\Eloquent\Model;

class UptimeDay extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['day' => 'date', 'worst_status' => ComponentStatus::class];

    public function percentage(): float
    {
        $total = $this->up_seconds + $this->down_seconds;

        return $total === 0 ? 100.0 : round($this->up_seconds / $total * 100, 4);
    }
}
