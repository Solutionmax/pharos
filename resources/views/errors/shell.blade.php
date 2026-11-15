{{--
  Every error page, in the shell of the sign in screen: the card on one side,
  the wall on the other. Standalone on purpose: no admin stylesheet and no
  script beyond the theme, so it stays light (the 404 used to weigh 51 KB) and
  still renders when the application around it does not. What needs the
  database (name, colour, logo, who is signed in) falls back to plain Pharos
  when it cannot be read.

  Takes: $code, $title, $heading, $lede, $caption ([line, emphasis]), and
  optionally $retry (offer the same address again) or $back (['url', 'label']).
--}}
@php
  $safe = fn (callable $read, mixed $fallback) => rescue($read, $fallback, false);
  $errorName = $safe(fn () => $branding->name(), 'Pharos');
  $errorTheme = $safe(fn () => $branding->theme(), 'system');
  $whiteLabel = $safe(fn () => $branding->creditHidden(), false);
  $asked = \Illuminate\Support\Str::limit('/'.ltrim(request()->decodedPath(), '/'), 60);
  if (! isset($back)) {
      // Someone signed in on an admin address is shown the way back into the admin.
      $viewer = request()->is('admin', 'admin/*') ? $safe(fn () => auth()->user(), null) : null;
      $back = match (true) {
          ($retry ?? false) && request()->isMethod('GET') => ['url' => request()->fullUrl(), 'label' => __('Try again')],
          $viewer === null => ['url' => url('/'), 'label' => __('Go to the status page')],
          $viewer->isAdmin() => ['url' => route('admin.pages.index'), 'label' => __('Back to Status pages')],
          default => ['url' => route('admin.overview'), 'label' => __('Back to the admin')],
      };
  }
@endphp
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @if ($errorTheme !== 'system') data-theme="{{ $errorTheme }}" @endif>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex">
@include('partials.theme-early', ['theme' => $errorTheme])
<title>{{ $title }} · {{ $errorName }}</title>
<link rel="icon" href="{{ $safe(fn () => $branding->faviconUrl(), asset('brand/pharos-favicon.svg')) }}">
<link rel="stylesheet" href="{{ asset('fonts/fonts.css') }}">
@include('partials.tokens', ['accentOverride' => $safe(fn () => $branding->accent(), '#0079d2')])
<style>
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--ink);font-family:var(--sans);font-size:15px;line-height:1.6;-webkit-font-smoothing:antialiased}
h1,p{margin:0}
:focus-visible{outline:2px solid var(--brand);outline-offset:3px;border-radius:6px}
/* An error wall: every cell off. */
.pa-grid{--pa-cell:var(--pa-cell-off)}
</style>
<link rel="stylesheet" href="{{ asset('assets/pharos-auth.css') }}?v={{ @filemtime(public_path('assets/pharos-auth.css')) }}">
</head>
<body>
<div class="pa">
  <main class="pa-side">
    <div class="pa-body">
      <div class="pa-brand">
        {!! $safe(fn () => view('partials.logo', ['size' => 28])->render(), '<span>'.e($errorName).'</span>') !!}
        <svg class="pa-trace" viewBox="0 0 120 28" aria-hidden="true" focusable="false"><path d="M0 14h34l6-10 8 20 7-14 5 4h60"/></svg>
      </div>
      <p class="pa-kicker"><i aria-hidden="true"></i>{{ __('Error :code', ['code' => $code]) }}</p>
      <h1 class="pa-h1">{{ $heading }}</h1>
      <p class="pa-lede">{{ $lede }}</p>
      <div class="pa-form">
        <a class="pa-btn nf-btn" href="{{ $back['url'] }}">{{ $back['label'] }}</a>
      </div>
    </div>
    <footer class="pa-foot">
      <a href="{{ url('/') }}">{{ __('Status page') }}</a>
      @unless ($whiteLabel)
        <a href="{{ config('pharos.docs_url') }}" target="_blank" rel="noopener">{{ __('Documentation') }}</a>
        <span>{{ __('Powered by Pharos') }}</span>
      @endunless
    </footer>
  </main>

  {{-- The wall: cells drawn by CSS (an empty .pa-grid), a flat line, and the
       address that was asked for. The 404 lays its relief over it. --}}
  <aside class="pa-wall" aria-hidden="true" @if ($scene ?? false) data-nf-scene @endif>
    @if ($scene ?? false)<canvas class="nf-canvas"></canvas>@endif
    <div class="pa-grid"></div>
    <svg class="pa-ekg" viewBox="0 0 1200 140" preserveAspectRatio="none" focusable="false">
      <path class="pa-ekg-glow" d="M0 70L404 70L410 64L416 58L422 68L428 86L434 70L1200 70"/>
      <path class="pa-ekg-line" d="M0 70L404 70L410 64L416 58L422 68L428 86L434 70L1200 70"/>
    </svg>
    <div class="pa-cap">
      <p class="pa-cap-line">{{ $caption[0] }} <em>{{ $caption[1] }}</em></p>
      <p class="pa-cap-kinds"><b class="nf-path">{{ $asked }}</b><span>{{ __('requested address') }}</span></p>
    </div>
  </aside>
</div>
@if ($scene ?? false)
<script @nonce defer src="{{ asset('assets/not-found/scene.js') }}?v={{ @filemtime(public_path('assets/not-found/scene.js')) }}"></script>
@endif
</body>
</html>
