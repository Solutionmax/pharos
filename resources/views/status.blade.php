<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}"
      @if (($theme ?? $branding->theme()) !== 'system') data-theme="{{ $theme ?? $branding->theme() }}" @endif>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
@include('partials.theme-early', ['theme' => $theme ?? $branding->theme(), 'rememberTheme' => $chrome ?? true])
<title>{{ $branding->name() }} {{ __('Status') }}</title>
<link rel="icon" href="{{ $branding->faviconUrl() }}">
@if ($branding->name() === 'Pharos' && ! $branding->logoUrl())
<link rel="apple-touch-icon" href="{{ $branding->builtInAssetUrl('apple-touch-icon.png') }}">
@endif
<link rel="stylesheet" href="{{ asset('fonts/fonts.css') }}">
@include('partials.tokens')
@include('partials.messages')
@stack('head-assets')
<link rel="stylesheet" href="{{ asset('assets/pharos-v06.css') }}?v={{ @filemtime(public_path('assets/pharos-v06.css')) }}">
<link rel="stylesheet" href="{{ asset('assets/pharos-status.css') }}?v={{ @filemtime(public_path('assets/pharos-status.css')) }}">
</head>
<body>
@if (($chrome ?? true) && auth()->check())
<div class="adminbar">
  <div class="in">
    <span>{{ __('Signed in as') }} {{ auth()->user()->name }}</span>
    <a href="{{ \App\Services\PageUrls::route('admin.components') }}">{{ __('← Back to admin') }}</a>
  </div>
</div>
@endif

@php
  $daysShown = count($overallBar);
  $hasTracks = $daysShown > 0 && ($modules['page.show_uptime'] || $modules['page.show_component_uptime']);
  $hasRows = $modules['page.show_services'] && ($groups->isNotEmpty() || $loose->isNotEmpty());
  $nextWork = $planned->first();
@endphp
<div class="page"><div class="wrap">
  <header class="top">
    <span class="who">@include('partials.logo', ['size' => 28])<small>{{ __('Status') }}</small></span>
    <div class="top-acts">
      {{-- Three things, all needed: the page module, the master switch on the Subscribers screen,
           and a mail transport that can actually send: a form that ends in a 500 helps nobody. --}}
      @if ($modules['page.show_subscribe'] && \App\Services\Subscriptions::enabled() && app(\App\Services\MailConfig::class)->configured())
        <details class="updates" @if (session('subscribed') || $errors->has('email')) open @endif>
          <summary class="btn">{{ __('Get notified') }}</summary>
          <div class="pop">
            @if (session('subscribed'))
              <p>{{ session('subscribed') }}</p>
            @else
              <form method="POST" action="{{ \App\Services\PageUrls::route('subscribe') }}" style="display:grid;gap:10px">
                @csrf
                <p>{{ __('Get an email when an incident is reported, and when it is resolved.') }}</p>
                <label for="sub-email">{{ __('Email address') }}</label>
                <span class="field">
                  <input id="sub-email" name="email" type="email" value="{{ old('email') }}" required autocomplete="email">
                  <button class="go" type="submit">{{ __('Subscribe') }}</button>
                </span>
                <input class="hp" name="website" type="text" tabindex="-1" autocomplete="off" aria-hidden="true">
                @error('email')<span class="err">{{ $message }}</span>@enderror
                <input type="hidden" name="all_services" value="0">
                <label><input type="checkbox" name="all_services" value="1" checked> {{ __('All services') }}</label>
                @if ($modules['page.show_services'])
                <details><summary>{{ __('Choose services') }}</summary>
                @foreach (\App\Services\PublicComponents::query()->orderBy('position')->get() as $subscriptionComponent)
                  <label><input type="checkbox" name="component_ids[]" value="{{ $subscriptionComponent->id }}"> {{ $subscriptionComponent->name }}</label>
                @endforeach
                </details>
                @endif
                <span class="fine">{{ __('A confirmation link comes first. Every mail carries an unsubscribe link.') }}</span>
              </form>
            @endif
          </div>
        </details>
      @endif
      @include('partials.theme-toggle')
    </div>
  </header>
  @include('partials.theme-script', ['rememberTheme' => $chrome ?? true])

  <main @if ($chrome) id="pharos-live" @endif>
  @if ($modules['page.show_overall'])
    <section class="now is-{{ $hero['tone'] }}" data-live-key="overall" data-live-value="{{ $worst->value }}:{{ $overall }}">
      <svg class="ring" viewBox="0 0 54 54" role="img" aria-label="{{ __('Refreshes every 30 seconds') }}"><circle class="rail" cx="27" cy="27" r="23"/><circle class="sweep" cx="27" cy="27" r="23"/><circle class="core" cx="27" cy="27" r="7"/></svg>
      <div>
        <h1>{{ $hero['headline'] }}</h1>
        <p class="facts">
          @if ($hero['since'])
            <b>{{ $hero['since'] }}</b>@if ($hero['rest'] === '.').@else, <span class="when">{{ $hero['rest'] }}</span>@endif
          @else
            {{ __('Checked at') }} <span class="when">{{ $now->format('H:i') }}</span>.
            @if ($hero['ago'] !== null)
              <span class="when">{{ match (true) {
                $hero['ago'] === 0 => __('The last problem ended today and lasted :duration.', ['duration' => $hero['lasted']]),
                $hero['ago'] === 1 => __('The last problem was yesterday and lasted :duration.', ['duration' => $hero['lasted']]),
                default => __('The last problem was :n days ago and lasted :duration.', ['n' => $hero['ago'], 'duration' => $hero['lasted']]),
              } }}</span>
            @endif
          @endif
        </p>
      </div>
      @if ($hero['latest'])
        <div class="latest">
          <time>{{ __('Latest message') }}, {{ $hero['latest']->created_at->isSameDay($now) ? $hero['latest']->created_at->format('H:i') : $hero['latest']->created_at->translatedFormat('j M H:i') }}</time>
          <div class="md">{!! $hero['latest']->messageHtml() !!}</div>
        </div>
      @endif
      @if ($nextWork)
        @php $toGo = \App\Services\StatusTimeline::daysUntil($nextWork->starts_at, $now); @endphp
        <a class="next" href="#maintenance-{{ $nextWork->id }}">
          <svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 17v5M9 3h6l-1 6 3 3v2H7v-2l3-3-1-6Z"/></svg>
          <span class="when">{{ match ($toGo) { 0 => __('Coming up today:'), 1 => __('Coming up tomorrow:'), default => __('Coming up in :n days:', ['n' => $toGo]) } }}</span>
          <b>{{ $nextWork->title }}</b>
        </a>
      @endif
    </section>
  @endif

  @if ($hasTracks || $hasRows)
    <section aria-labelledby="h-days">
      <div class="chart-head">
        <h2 id="h-days">{{ $hasTracks ? __('Past :n days', ['n' => $daysShown]) : __('Services') }}</h2>
        @if ($hasTracks)
          <div class="key" aria-hidden="true"><span><i class="k-w"></i>{{ __('Slow') }}</span><span><i class="k-p"></i>{{ __('Partly down') }}</span><span><i class="k-b"></i>{{ __('Down') }}</span><span>{{ __('Taller means longer') }}</span></div>
        @endif
      </div>
      <div class="chart" @if ($hasTracks) tabindex="0" role="group" data-hint="{{ __('Point at a day, or use the arrow keys, to read what happened.') }}"
           aria-label="{{ __('Uptime per day for every service. Use the left and right arrow keys to read a day.') }}" @endif>
        @if ($hasTracks)
          <div class="readout" aria-live="polite">{{ __('Point at a day, or use the arrow keys, to read what happened.') }}</div>
          <script type="application/json" data-notes class="when">@json($dayNotes)</script>
        @endif

        @if ($modules['page.show_uptime'] && $daysShown > 0)
          <div class="row all">
            <div class="nm">{{ __('All services') }}</div>
            @include('partials.status-track', ['bar' => $overallBar])
            <div class="pc">{{ \App\Services\Uptime::format($overall) }}</div>
          </div>
        @endif

        @if ($hasRows)
          {{-- Components that belong to no service still belong on the page. Leaving
               them out made "Ungrouped" a silent way to publish nothing. --}}
          @foreach ($loose as $component)
            @include('partials.status-row', ['component' => $component])
          @endforeach

          @foreach ($groups as $group)
            @continue($group->components->isEmpty())
            @php $gs = $group->status(); @endphp
            <details id="service-{{ $group->id }}" class="grp is-{{ $gs->tone() }}" @if (! $group->collapsed || $gs->isDown()) open @endif>
              <summary class="row">
                <span class="nm">
                  <svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="m9 6 6 6-6 6"/></svg>
                  <span class="label">{{ $group->name }}</span>
                  @if ($gs !== \App\Enums\ComponentStatus::Operational)<em>{{ __($gs->label() ?? '') }}</em>@endif
                </span>
                @if ($modules['page.show_component_uptime'] && $daysShown > 0)
                  @php $serviceBar = app(\App\Services\Uptime::class)->aggregate($group->components->mapWithKeys(fn ($c) => [$c->id => $bars[$c->id]])->all()); @endphp
                  @include('partials.status-track', ['bar' => $serviceBar])
                  <span class="pc">{{ \App\Services\Uptime::format(app(\App\Services\Uptime::class)->percentageOf($serviceBar)) }}</span>
                @else
                  <span class="track"></span><span class="pc"></span>
                @endif
              </summary>
              @foreach ($group->components as $component)
                @include('partials.status-row', ['component' => $component])
              @endforeach
            </details>
          @endforeach
        @endif

        @if ($hasTracks)
          <div class="axis"><div class="ticks">
            <span style="left:0">{{ \Illuminate\Support\Carbon::parse($overallBar[0]['day'])->translatedFormat('j M') }}</span>
            @foreach ($overallBar as $i => $d)
              @if ($i >= 10 && $i < $daysShown - 8 && str_ends_with($d['day'], '-01'))
                <span style="left:{{ round($i / $daysShown * 100, 3) }}%">{{ \Illuminate\Support\Carbon::parse($d['day'])->translatedFormat('M') }}</span>
              @endif
            @endforeach
            <span class="end">{{ __('Today') }}</span>
          </div></div>
          <div class="cross"></div>
        @endif
      </div>
    </section>
  @endif

  @if ($modules['page.show_incidents'] || $live->isNotEmpty() || $planned->isNotEmpty())
    <section aria-labelledby="h-log">
      <div class="log-head">
        <h2 id="h-log">{{ __('Incident log') }}</h2>
        @if ($ongoing->isEmpty() && $live->isEmpty() && ($hero['ago'] ?? 0) >= 2)
          <p class="when">{{ __('Nothing in the past :n days', ['n' => $hero['ago']]) }}</p>
        @endif
      </div>
      <div class="log">
        @if ($ongoing->isNotEmpty() || $live->isNotEmpty())
          <h3 class="label sect">{{ __('Ongoing') }}</h3>
          @foreach ($ongoing as $incident)
            @include('partials.timeline-incident', ['incident' => $incident])
          @endforeach
          @foreach ($live as $maintenance)
            @include('partials.timeline-maintenance', ['maintenance' => $maintenance])
          @endforeach
        @endif

        @if ($planned->isNotEmpty())
          <h3 class="label sect">{{ __('Scheduled') }}</h3>
          @foreach ($planned as $maintenance)
            @include('partials.timeline-maintenance', ['maintenance' => $maintenance])
          @endforeach
        @endif

        @php $month = null; $anyDay = false; $openDays = $ongoing->map(fn ($i) => $i->occurred_at->format('Y-m-d'))->all(); @endphp
        @foreach ($days as $date => $incidents)
          @continue($incidents->isEmpty() && in_array($date, $openDays, true))
          @php $carbon = \Illuminate\Support\Carbon::parse($date, \App\Services\Clock::timezone()); $anyDay = true; @endphp
          @if ($carbon->format('Y-m') !== $month)
            @php $month = $carbon->format('Y-m'); @endphp
            <h3 class="label sect">{{ $carbon->isCurrentYear() ? $carbon->translatedFormat('F') : $carbon->translatedFormat('F Y') }}</h3>
          @endif
          @forelse ($incidents as $incident)
            @include('partials.timeline-incident', ['incident' => $incident])
          @empty
            <div class="entry quiet" data-day="{{ $date }}">
              <i class="node"></i>
              <div class="date"><b>{{ $carbon->format('d') }}</b><span class="label">{{ $carbon->isToday() ? __('Today') : $carbon->translatedFormat('M') }}<br>{{ $carbon->translatedFormat('D') }}</span></div>
              <p>{{ __('No incidents') }}</p>
            </div>
          @endforelse
        @endforeach
        @if (! $anyDay && $ongoing->isEmpty() && $modules['page.show_incidents'])
          <p class="label sect">{{ __('No incidents reported') }}</p>
        @endif
      </div>
      @if ($chrome && ($page > 1 || $hasOlder))
        <nav class="pager" aria-label="{{ __('Incident history') }}">
          @if ($page > 1)<a href="{{ \App\Services\PageUrls::route('status', $page === 2 ? [] : ['page' => $page - 1]) }}">{{ __('← Newer incidents') }}</a>@endif
          @if ($hasOlder)<a class="older" href="{{ \App\Services\PageUrls::route('status', ['page' => $page + 1]) }}">{{ __('Older incidents →') }}</a>@endif
        </nav>
      @endif
    </section>
  @endif
  </main>

  @unless ($branding->creditHidden())
    <div class="sign">
      @include('partials.mark')
      <p>{{ __('This page runs on Pharos.') }} <span>{{ __('Status pages you host yourself.') }}</span></p>
      <a href="https://pharos.solutionmax.net" rel="noopener">pharos.solutionmax.net <svg class="ico" viewBox="0 0 24 24" aria-hidden="true"><path d="M7 17 17 7M9 7h8v8"/></svg></a>
    </div>
  @endunless
  <footer>
    <a href="{{ \App\Services\PageUrls::route('public.feed') }}">{{ __('RSS feed') }}</a>
    <a href="{{ \App\Services\PageUrls::route('public.reports') }}">{{ __('Monthly reports') }}</a>
    @if ($modules['page.show_api_link'])<a href="{{ \App\Services\PageUrls::api('components') }}">{{ __('API') }}</a>@endif
    <span>{{ __('Times in :zone', ['zone' => \App\Services\Clock::offsetLabel()]) }}</span>
    @if ($chrome)<span id="live-refresh" class="live-refresh">{{ __('Refreshes every 30 seconds') }}</span>@endif
  </footer>
</div></div>
@include('partials.daytip')
<script defer src="{{ asset('assets/pharos-v06.js') }}?v={{ @filemtime(public_path('assets/pharos-v06.js')) }}"></script>
<script defer src="{{ asset('assets/pharos-status.js') }}?v={{ @filemtime(public_path('assets/pharos-status.js')) }}"></script>
</body>
</html>
