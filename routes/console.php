<?php

use App\Models\CheckResult;
use App\Models\PasskeyChallenge;
use App\Models\ProbeJob;
use App\Models\ProbeSample;
use App\Services\Audit;
use App\Services\OutgoingWebhook;
use App\Services\ReportedUptime;
use Illuminate\Support\Facades\Schedule;

// One entry point for both deployment shapes: a real scheduler on a VPS, or a
// single cPanel cron line calling `php artisan schedule:run` every minute.
Schedule::command('pharos:check')->everyMinute()->withoutOverlapping();

// The audit trail is append-only, so age is the only thing that bounds it.
Schedule::call(fn () => Audit::prune())->dailyAt('03:20')->name('prune-audit-log');

// Raw check results are only read for the beat strip; the uptime bar uses the daily roll-up.
Schedule::call(fn () => CheckResult::prune())->dailyAt('03:25')->name('prune-check-results');

// Components that something else keeps up to date have no probe to count; this credits
// the time they spent in their reported status. Never overlaps itself, or one minute counts twice.
Schedule::call(fn () => app(ReportedUptime::class)->tick())->everyMinute()->name('credit-reported-uptime')->withoutOverlapping();

// Planned work: announce, start and complete maintenance windows. Runs before
// pharos:notify in the same minute, so an announcement is mailed straight away.
Schedule::command('pharos:maintenance')->everyMinute()->withoutOverlapping();

// The subscriber outbox. An incident update only queues rows; this is what sends them.
Schedule::command('pharos:notify')->everyMinute()->withoutOverlapping();

Schedule::call(fn () => app(OutgoingWebhook::class)->sendPending())->everyMinute()->name('deliver-webhooks')->withoutOverlapping();

Schedule::command('pharos:probe-remote')->everyMinute()->withoutOverlapping();
Schedule::call(function () {
    ProbeJob::where('expires_at', '<', now()->subDay())->delete();
    ProbeSample::where('checked_at', '<', now()->subDays(2))->delete();
})->daily()->name('prune-probe-results');
Schedule::command('pharos:backup-remote')->dailyAt('02:45')->withoutOverlapping(20);

Schedule::call(fn () => PasskeyChallenge::where('expires_at', '<', now()->subDay())->delete())->daily()->name('prune-passkey-challenges');
