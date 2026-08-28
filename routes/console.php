<?php

use Illuminate\Support\Facades\Schedule;

// One entry point for both deployment shapes: a real scheduler on a VPS, or a
// single cPanel cron line calling `php artisan schedule:run` every minute.
Schedule::command('pharos:check')->everyMinute()->withoutOverlapping();

// The audit trail is append-only, so age is the only thing that bounds it.
Schedule::call(fn () => \App\Services\Audit::prune())->dailyAt('03:20')->name('prune-audit-log');

// The subscriber outbox. An incident update only queues rows; this is what sends them.
Schedule::command('pharos:notify')->everyMinute()->withoutOverlapping();
