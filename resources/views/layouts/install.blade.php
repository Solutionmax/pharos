@php
$branding = app(\App\Services\Branding::class);
$adminTheme = in_array(auth()->user()?->theme, \App\Models\User::THEMES, true) ? auth()->user()->theme : $branding->theme();
$current ??= 6;
// The same seven steps as the web installer's rail: steps 1 to 5 happened there
// (or on the command line), 6 and 7 happen here.
$journey = [
    ['Unlock', 'Prove this is your hosting'],
    ['Check the server', 'Nothing is written yet'],
    ['Download', 'Signed and verified'],
    ['Configure', 'Address and database'],
    ['Scheduler', 'One cron line'],
    ['Your account', 'Name the page, sign in'],
    ['Done', 'Open Pharos'],
];
@endphp
<!doctype html>
<html lang="en" @if ($adminTheme !== 'system') data-theme="{{ $adminTheme }}" @endif>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
@include('partials.theme-early', ['theme' => $adminTheme])
<title>@yield('title', 'Set up') · {{ $branding->name() }}</title>
<link rel="icon" href="{{ $branding->faviconUrl() }}">
<link rel="stylesheet" href="{{ asset('fonts/fonts.css') }}">
@include('partials.tokens')
<link rel="stylesheet" href="{{ asset('assets/pharos-install.css') }}?v={{ @filemtime(public_path('assets/pharos-install.css')) }}">
<script>document.documentElement.classList.add('js')</script>
</head>
<body>
<div class="pi">
  <aside class="pi-rail">
    @include('partials.logo', ['size' => 30])
    <div class="pi-tag">Setup · {{ config('pharos.version') }}</div>
    <ol class="pi-steps" data-pi-steps>
      <span class="pi-fill" data-pi-fill aria-hidden="true"></span>
      @foreach ($journey as $i => [$label, $hint])
        @php $n = $i + 1; @endphp
        <li @class(['done' => $n < $current, 'now' => $n === $current]) @if ($n === $current) aria-current="step" @endif>
          <span class="pi-n" aria-hidden="true">{{ $n < $current ? '✓' : $n }}</span>
          <span><b>{{ $label }}</b><small>{{ $hint }}</small></span>
        </li>
      @endforeach
    </ol>
    <svg class="pi-ekg" viewBox="0 0 268 40" preserveAspectRatio="none" aria-hidden="true" focusable="false"><path d="M0 20h96l8-14 10 28 9-20 7 6h138"/></svg>
    <div class="pi-foot">Steps 1 to 5 ran on your hosting. These last two happen inside Pharos.</div>
  </aside>
  <main class="pi-main">
    @yield('content')
  </main>
</div>
@include('partials.theme-script', ['theme' => $adminTheme])
<script defer src="{{ asset('assets/pharos-install.js') }}?v={{ @filemtime(public_path('assets/pharos-install.js')) }}"></script>
</body>
</html>
