<?php

use Illuminate\Support\Facades\Schedule;

// One entry point for both deployment shapes: a real scheduler on a VPS, or a
// single cPanel cron line calling `php artisan schedule:run` every minute.
Schedule::command('pharos:check')->everyMinute()->withoutOverlapping();
