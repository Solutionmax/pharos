@php
    // The one cron line is the whole scheduler, and forgetting it is silent:
    // every component keeps the status it was given, the page says all is well,
    // and nothing has been looked at. Only worth saying once a check exists.
    $watching = \App\Models\Check::where('enabled', true)
        ->whereHas('component', fn ($q) => $q->where('enabled', true))
        ->exists();

    $stamp = \App\Models\Setting::get('checks.last_run_at');
    $lastRun = $stamp ? \Illuminate\Support\Carbon::parse($stamp) : null;

    // Five minutes: the scheduler is meant to fire every minute, whether or not
    // any check is due, so anything older than this is a real gap.
    $stalled = $watching && (! $lastRun || $lastRun->lt(now()->subMinutes(5)));
@endphp

@if ($stalled)
  <div class="alarm" role="alert">
    <strong>Nothing is being checked.</strong>
    @if ($lastRun)
      The scheduler last ran {{ $lastRun->diffForHumans() }}. It should run every minute.
    @else
      The scheduler has never run, so every status on this page is whatever someone typed.
    @endif
    Add this one line to cron:
    @if (app(\App\Services\CronSetup::class)->php())
      <code class="mono">* * * * * {{ app(\App\Services\CronSetup::class)->command() }}</code>
    @else
      <span>Ask your host for the versioned CLI PHP path, then run <code>php artisan pharos:cron</code> with that PHP. The web PHP selector does not configure cron.</span>
    @endif
    @if (\App\Models\Setting::get('checks.last_error'))
      <span>{{ \App\Models\Setting::get('checks.last_error') }}</span>
    @endif
  </div>
@endif
