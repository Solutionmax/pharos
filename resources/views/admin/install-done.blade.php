@extends('layouts.install', ['current' => 7])
@section('title', __('Installed'))

@section('content')
<div class="pi-kicker pi-kicker-ok">{{ __('Installed') }}</div>
<h1>{{ $site }} {{ __('is live.') }}</h1>
<p class="pi-lede">{{ __('Pharos is installed and you are signed in. Add your first service next; the overview walks you through it.') }}</p>

{{-- Decoration only: a wall of uptime cells turning green, column by column. --}}
<div class="pi-wall" data-pi-wall aria-hidden="true">@for ($i = 0; $i < 200; $i++)<i></i>@endfor</div>

<dl class="pi-facts">
  <div><dt>{{ __('Version') }}</dt><dd>{{ $version }}</dd></div>
  <div><dt>{{ __('Address') }}</dt><dd>{{ $address }}</dd></div>
  <div><dt>{{ __('Database') }}</dt><dd>{{ $database }}</dd></div>
  <div><dt>{{ __('Scheduler') }}</dt><dd @class(['pi-wait' => ! $schedulerRunning])>{{ $schedulerRunning ? 'Running' : 'Waiting for the first run' }}</dd></div>
</dl>

@unless ($schedulerRunning)
  <div class="pi-note pi-note-warn"><b aria-hidden="true">!</b><span>{{ __('The scheduler has not run yet. Until the cron line from step 5 runs every minute, nothing is checked. The overview reminds you until it does.') }}</span></div>
@endunless
<div class="pi-note pi-note-warn"><b aria-hidden="true">!</b><span>{{ __('Keep your installation key somewhere safe. You need it only if you ever reinstall on this hosting.') }}</span></div>

<div class="pi-actions">
  <a class="pi-btn" href="{{ route('admin.overview') }}">{{ __('Open Pharos') }}</a>
</div>
@endsection
